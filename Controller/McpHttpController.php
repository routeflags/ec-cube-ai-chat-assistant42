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
use Plugin\AiChatAssistant42\Service\McpAccessPolicy;
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
        private ?McpAccessPolicy $accessPolicy = null,
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
            $this->rateLimitService->enforce($this->rateLimitKey($request, $ip), 'well_known');
        } catch (RateLimitExceededException $e) {
            return $this->wellKnownRateLimitResponse($e, $this->corsOrigin($request));
        }

        $baseUrl = $this->resolveBaseUrl($request);
        // DRY: ツールマッピングは McpHttpService::buildWellKnownPayload() に集約
        $data = $this->mcpHttpService->buildWellKnownPayload($baseUrl);
        // TODO: 残5ツール (get_news 等) は本PRスコープ外。将来的に ProductToolDefinition に追記されたら自動で含まれる。

        $response = new JsonResponse($data, 200, [], false);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->addCorsHeaders($response, $this->corsOrigin($request));
        // WebMCP discovery: RFC 8288 link to the MCP endpoint
        $response->headers->set('Link', '</mcp>; rel="mcp"');
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
            return $this->optionsResponse($this->corsOrigin($request));
        }

        // Phase1: GET /mcp は 405 で明示（SSE は Phase3 延期）
        if ($request->getMethod() === 'GET') {
            $response = new JsonResponse(['error' => 'Method Not Allowed. Use POST /mcp'], 405);
            $response->headers->set('Allow', 'POST, OPTIONS');
            $this->addCorsHeaders($response, $this->corsOrigin($request));
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');

            return $response;
        }

        // POST 以外は 405（念のため）
        if ($request->getMethod() !== 'POST') {
            $response = new JsonResponse(['error' => 'Method Not Allowed'], 405);
            $response->headers->set('Allow', 'POST, OPTIONS');
            $this->addCorsHeaders($response, $this->corsOrigin($request));

            return $response;
        }

        $origin = $this->corsOrigin($request);

        // 軽量 Content-Type 415: JSON 以外は 415 で拒否（MCP Inspector は JSON を送るため影響なし）
        $contentType = $request->headers->get('Content-Type', '');
        if ($contentType !== '' && stripos($contentType, 'application/json') === false) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: Content-Type must be application/json');
            $resp = $this->jsonResponseWithCors($error, 415, $origin);
            $resp->headers->set('Content-Type', 'application/json; charset=utf-8');

            return $resp;
        }

        $content = $request->getContent();

        if ($content === '') {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: empty body');
            return $this->jsonResponseWithCors($error, 200, $origin);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: ' . $e->getMessage());
            return $this->jsonResponseWithCors($error, 200, $origin);
        }

        // batch リクエスト（配列）は未対応 → -32600
        // json_decode('{}', true) は [] を返すため array_is_list だけでは空オブジェクトを batch と誤判定する。
        // 元の JSON テキストが "[" で始まる場合のみ batch とみなす。
        if (str_starts_with(ltrim($content), '[') && is_array($data) && array_is_list($data)) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32600, 'Invalid Request: batch not supported');
            return $this->jsonResponseWithCors($error, 200, $origin);
        }

        if (!is_array($data)) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: invalid JSON');
            return $this->jsonResponseWithCors($error, 200, $origin);
        }

        $method = $data['method'] ?? null;
        $id = $data['id'] ?? null;

        // 軽量 jsonrpc:"2.0" 検証 — 存在し 2.0 以外なら -32600
        if (array_key_exists('jsonrpc', $data) && $data['jsonrpc'] !== '2.0') {
            $error = $this->mcpHttpService->writeErrorResponse($id, -32600, 'Invalid Request: jsonrpc must be "2.0"');
            return $this->jsonResponseWithCors($error, 200, $origin);
        }

        // 管理画面でトークンを設定している場合のみ認証を要求する（未設定は従来どおり公開）
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse($id, $origin);
        }

        // notifications/initialized は通知（id 無し）→ 204 で応答不要
        if ($method === 'notifications/initialized') {
            $response = new Response('', 204);
            $this->addCorsHeaders($response, $origin);

            return $response;
        }

        // レート制限 — toolName で分岐（get_stock は 60、他は 120）
        $toolName = 'default';
        if ($method === 'tools/call' && isset($data['params']['name']) && is_string($data['params']['name'])) {
            $toolName = $data['params']['name'];
        } elseif ($method === 'initialize' || $method === 'tools/list') {
            $toolName = 'default';
        }

        $ip = $request->getClientIp() ?? 'unknown';
        try {
            $this->rateLimitService->enforce($this->rateLimitKey($request, $ip), $toolName);
        } catch (RateLimitExceededException $e) {
            return $this->rateLimitResponse($id, $e, $origin);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->mcpHttpService->handleInitialize(
                    $id,
                    isset($data['params']['protocolVersion']) && is_string($data['params']['protocolVersion'])
                        ? $data['params']['protocolVersion']
                        : null
                ),
                'tools/list' => $this->mcpHttpService->handleToolsList($id),
                'tools/call' => $this->mcpHttpService->handleToolsCall($data, $id, $ip),
                null => $this->mcpHttpService->writeErrorResponse($id, -32600, 'Invalid Request: method is required'),
                default => $this->mcpHttpService->writeErrorResponse($id, -32601, "Method not found: {$method}"),
            };
        } catch (\Throwable $e) {
            $result = $this->mcpHttpService->writeErrorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
        }

        return $this->jsonResponseWithCors($result, 200, $origin);
    }

    private function jsonResponseWithCors(array $data, int $status, ?string $origin = null): JsonResponse
    {
        $response = new JsonResponse($data, $status, [], false);
        $this->addCorsHeaders($response, $origin);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        // POST /mcp の tools/call は no-store
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    private function rateLimitResponse(mixed $id, RateLimitExceededException $e, ?string $origin = null): JsonResponse
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

    private function wellKnownRateLimitResponse(RateLimitExceededException $e, ?string $origin = null): JsonResponse
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

    private function optionsResponse(?string $origin = null): Response
    {
        $response = new Response('', 204);
        $this->addCorsHeaders($response);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-MCP-Token, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function addCorsHeaders(Response $response, ?string $origin = null): void
    {
        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        $response->headers->set('Vary', 'Origin');
        // Do not set Allow-Credentials with wildcard
        // WebMCP tools permission: top-level documents may register tools; cross-origin iframes need allow="tools"
        $response->headers->set('Permissions-Policy', 'tools=self');
    }

    /**
     * リクエストがアクセストークンを満たしているか。
     *
     * ポリシー未接続またはトークン未設定の場合は常に true（従来動作）。
     */
    private function isAuthorized(Request $request): bool
    {
        if ($this->accessPolicy === null) {
            return true;
        }

        return $this->accessPolicy->isAuthorized(
            $request->headers->get('Authorization'),
            $request->headers->get('X-MCP-Token')
        );
    }

    /**
     * レート制限のキー。トークン提示時はトークン単位、未提示時は IP 単位。
     */
    private function rateLimitKey(Request $request, string $clientIp): string
    {
        if ($this->accessPolicy === null) {
            return $clientIp;
        }

        return $this->accessPolicy->rateLimitKey(
            $clientIp,
            $request->headers->get('Authorization'),
            $request->headers->get('X-MCP-Token')
        );
    }

    /**
     * CORS の応答値を決める。ポリシー未接続時は従来どおり `*`。
     */
    private function corsOrigin(Request $request): ?string
    {
        if ($this->accessPolicy === null) {
            return '*';
        }

        return $this->accessPolicy->allowedOrigin($request->headers->get('Origin'));
    }

    /**
     * アクセストークン未提示・不一致時の 401。
     *
     * WWW-Authenticate は MCP の認可仕様が期待する入口。
     * 将来 OAuth を入れる際は resource_metadata を指すよう拡張する。
     */
    private function unauthorizedResponse(mixed $id, ?string $origin): JsonResponse
    {
        $error = $this->mcpHttpService->writeErrorResponse($id, -32001, 'Unauthorized: MCP access token required');
        $response = $this->jsonResponseWithCors($error, 401, $origin);
        $response->headers->set('WWW-Authenticate', 'Bearer realm="mcp"');

        return $response;
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
