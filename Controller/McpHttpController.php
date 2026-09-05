<?php

declare(strict_types=1);

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\AiChatAssistant42\Controller;

use Plugin\AiChatAssistant42\Repository\ProductRepository;
use Plugin\AiChatAssistant42\Service\McpHttpService;
use Plugin\AiChatAssistant42\Service\RateLimitExceededException;
use Plugin\AiChatAssistant42\Service\RateLimitService;
use Plugin\AiChatAssistant42\Service\ShopContextService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web MCP HTTP コントローラー。
 *
 * Streamable HTTP (POST /mcp) と Discovery (GET /.well-known/mcp.json) を提供する。
 * CORS は Controller 直付与方式で付与し、EventListener 新設はしない。
 * RateLimit は CacheInterface (cache.app) で mcp.ratelimit.{ip}.{tool}.{minute} キー管理（PSR-6 合法、IP は ":" "." "/" を "_" にサニタイズ）。
 */
class McpHttpController
{
    public function __construct(
        private ProductRepository $productRepository,
        private McpHttpService $mcpHttpService,
        private RateLimitService $rateLimitService,
        private ?ShopContextService $shopContextService = null,
    ) {
    }

    /**
     * GET /.well-known/mcp.json — Discovery カタログ。
     *
     * 5ツール（残5は TODO）は本PRスコープ外としてコメント化。
     * ProductToolDefinition::DEFINITIONS は現行 7件。
     */
    public function wellKnown(Request $request): JsonResponse
    {
        // trusted_proxies が正しく設定されている前提で getClientIp() は X-Forwarded-For を信頼する。
        // 未設定時は全ユーザが同一バケット (127.0.0.1) を共有し誤爆するため framework.yaml で設定すること。
        $ip = $request->getClientIp() ?? 'unknown';
        try {
            $this->rateLimitService->enforce($ip, 'well_known');
        } catch (RateLimitExceededException $e) {
            return $this->wellKnownRateLimitResponse($e);
        }

        $baseUrl = $this->resolveBaseUrl($request);
        // DRY: ツールマッピングは McpHttpService::buildWellKnownPayload() に集約
        $data = $this->mcpHttpService->buildWellKnownPayload($baseUrl);
        // TODO: 残5ツール (get_news 等) は本PRスコープ外。将来的に ProductToolDefinition に追記されたら自動で含まれる。

        $response = new JsonResponse($data, 200, [], false);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->addCorsHeaders($response);
        // Cache-Control は setPublic()/setMaxAge() で付与し private との不整合を避ける
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    /**
     * POST /mcp / GET /mcp / OPTIONS /mcp — JSON-RPC ハンドラ。
     *
     * GET は Phase1 では 405 を明示（SSE は Phase3 延期）。
     * OPTIONS は 204 で Allow-Methods/Headers を返す。
     */
    public function handle(Request $request): Response
    {
        // CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            return $this->optionsResponse();
        }

        // Phase1: GET /mcp は 405 で明示（SSE は Phase3 延期）
        if ($request->getMethod() === 'GET') {
            $response = new JsonResponse(['error' => 'Method Not Allowed. Use POST /mcp'], 405);
            $response->headers->set('Allow', 'POST, OPTIONS');
            $this->addCorsHeaders($response);
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');

            return $response;
        }

        // POST 以外は 405（念のため）
        if ($request->getMethod() !== 'POST') {
            $response = new JsonResponse(['error' => 'Method Not Allowed'], 405);
            $response->headers->set('Allow', 'POST, OPTIONS');
            $this->addCorsHeaders($response);

            return $response;
        }

        // 軽量 Content-Type 415: JSON 以外は 415 で拒否（MCP Inspector は JSON を送るため影響なし）
        $contentType = $request->headers->get('Content-Type', '');
        if ($contentType !== '' && stripos($contentType, 'application/json') === false) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: Content-Type must be application/json');
            $resp = $this->jsonResponseWithCors($error, 415);
            $resp->headers->set('Content-Type', 'application/json; charset=utf-8');

            return $resp;
        }

        $content = $request->getContent();

        if ($content === '') {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: empty body');
            return $this->jsonResponseWithCors($error, 200);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: ' . $e->getMessage());
            return $this->jsonResponseWithCors($error, 200);
        }

        // batch リクエスト（配列）は未対応 → -32600
        // json_decode('{}', true) は [] を返すため array_is_list だけでは空オブジェクトを batch と誤判定する。
        // 元の JSON テキストが "[" で始まる場合のみ batch とみなす。
        if (str_starts_with(ltrim($content), '[') && is_array($data) && array_is_list($data)) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32600, 'Invalid Request: batch not supported');
            return $this->jsonResponseWithCors($error, 200);
        }

        if (!is_array($data)) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: invalid JSON');
            return $this->jsonResponseWithCors($error, 200);
        }

        $method = $data['method'] ?? null;
        $id = $data['id'] ?? null;

        // 軽量 jsonrpc:"2.0" 検証 — 存在し 2.0 以外なら -32600
        if (array_key_exists('jsonrpc', $data) && $data['jsonrpc'] !== '2.0') {
            $error = $this->mcpHttpService->writeErrorResponse($id, -32600, 'Invalid Request: jsonrpc must be "2.0"');
            return $this->jsonResponseWithCors($error, 200);
        }

        // notifications/initialized は通知（id 無し）→ 204 で応答不要
        if ($method === 'notifications/initialized') {
            $response = new Response('', 204);
            $this->addCorsHeaders($response);

            return $response;
        }

        // Authorization 受け口（将来の write 系で 401 に分岐する TODO を残す）
        $authHeader = $request->headers->get('Authorization');
        // TODO: write 系では Bearer 検証して 401 を返す（現行 read-only のため無視して 200 を返す）
        // if ($authHeader !== null) { // 将来用: log or validate }

        // レート制限 — toolName で分岐（get_stock は 60、他は 120）
        $toolName = 'default';
        if ($method === 'tools/call' && isset($data['params']['name']) && is_string($data['params']['name'])) {
            $toolName = $data['params']['name'];
        } elseif ($method === 'initialize' || $method === 'tools/list') {
            $toolName = 'default';
        }

        $ip = $request->getClientIp() ?? 'unknown';
        try {
            $this->rateLimitService->enforce($ip, $toolName);
        } catch (RateLimitExceededException $e) {
            return $this->rateLimitResponse($id, $e);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->mcpHttpService->handleInitialize($id),
                'tools/list' => $this->mcpHttpService->handleToolsList($id),
                'tools/call' => $this->mcpHttpService->handleToolsCall($data, $id),
                null => $this->mcpHttpService->writeErrorResponse($id, -32600, 'Invalid Request: method is required'),
                default => $this->mcpHttpService->writeErrorResponse($id, -32601, "Method not found: {$method}"),
            };
        } catch (\Throwable $e) {
            $result = $this->mcpHttpService->writeErrorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
        }

        return $this->jsonResponseWithCors($result, 200);
    }

    private function jsonResponseWithCors(array $data, int $status): JsonResponse
    {
        $response = new JsonResponse($data, $status, [], false);
        $this->addCorsHeaders($response);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        // POST /mcp の tools/call は no-store
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    private function rateLimitResponse(mixed $id, RateLimitExceededException $e): JsonResponse
    {
        $body = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32000,
                'message' => 'Rate limit exceeded. Retry after 60 seconds.',
            ],
        ];
        $response = new JsonResponse($body, 429, [], false);
        $response->headers->set('Retry-After', (string) $e->getRetryAfterSeconds());
        $response->headers->set('X-RateLimit-Limit', (string) $e->getLimit());
        $response->headers->set('X-RateLimit-Remaining', '0');
        $this->addCorsHeaders($response);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);

        return $response;
    }

    private function wellKnownRateLimitResponse(RateLimitExceededException $e): JsonResponse
    {
        // Discovery (GET /.well-known/mcp.json) は REST なので JSON-RPC ラップせず REST 形式で返す
        $response = new JsonResponse(['error' => 'Too Many Requests'], 429, [], false);
        $response->headers->set('Retry-After', (string) $e->getRetryAfterSeconds());
        $response->headers->set('X-RateLimit-Limit', (string) $e->getLimit());
        $response->headers->set('X-RateLimit-Remaining', '0');
        $this->addCorsHeaders($response);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);

        return $response;
    }

    private function optionsResponse(): Response
    {
        $response = new Response('', 204);
        $this->addCorsHeaders($response);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function addCorsHeaders(Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Vary', 'Origin');
        // Do not set Allow-Credentials with wildcard
    }

    private function resolveBaseUrl(Request $request): string
    {
        // 正本は ShopContextService::getBaseUrl()。fallback は https を優先する。
        // Cloudflare 等で X-Forwarded-Proto: https が付与される環境では
        // framework.trusted_proxies / trusted_headers が正しく設定されている必要がある。
        // 未設定時は getSchemeAndHttpHost() が http を返し transport.url が http になるため
        // mixed content で接続失敗する。framework.yaml で以下を設定すること:
        //   framework:
        //     trusted_proxies: ['127.0.0.1', 'REMOTE_ADDR']
        //     trusted_headers: ['x-forwarded-for', 'x-forwarded-proto']
        if ($this->shopContextService !== null) {
            $baseUrl = $this->shopContextService->getBaseUrl();
            if ($baseUrl !== '') {
                // ShopContextService も trusted_proxies 未設定時は http を返す可能性があるため
                // https 強制: X-Forwarded-Proto が https なら http:// を https:// に置換
                if (str_starts_with($baseUrl, 'http://') && $request->headers->get('X-Forwarded-Proto') === 'https') {
                    $baseUrl = 'https://' . substr($baseUrl, 7);
                }

                return $baseUrl;
            }
        }

        // Fallback to request scheme+host — trusted_proxies 設定時は https を正しく得られる
        $schemeAndHost = $request->getSchemeAndHttpHost();
        if ($schemeAndHost !== '') {
            $baseUrl = rtrim($schemeAndHost . $request->getBaseUrl(), '/');
            // https 強制: X-Forwarded-Proto が https なら置換（trusted_proxies 未設定時の保険）
            if (str_starts_with($baseUrl, 'http://') && $request->headers->get('X-Forwarded-Proto') === 'https') {
                $baseUrl = 'https://' . substr($baseUrl, 7);
            }

            return $baseUrl;
        }

        return '';
    }
}
