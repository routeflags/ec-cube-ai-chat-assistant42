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
 * RateLimit は CacheInterface (cache.app) で mcp:ratelimit:{ip}:{tool}:{minute} キー管理。
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
        $ip = $request->getClientIp() ?? 'unknown';
        try {
            $this->rateLimitService->enforce($ip, 'well_known');
        } catch (RateLimitExceededException $e) {
            return $this->rateLimitResponse(null, $e);
        }

        $toolDefinitions = $this->productRepository->getToolDefinitions();
        $mcpTools = array_map(
            fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['input_schema'],
            ],
            $toolDefinitions
        );

        $baseUrl = $this->resolveBaseUrl($request);
        $mcpUrl = rtrim($baseUrl, '/') . '/mcp';

        $data = [
            'name' => 'EC-CUBE MCP',
            'protocolVersion' => McpHttpService::PROTOCOL_VERSION,
            'serverInfo' => [
                'name' => McpHttpService::SERVER_NAME,
                'version' => McpHttpService::SERVER_VERSION,
            ],
            'transport' => [
                'type' => 'streamable-http',
                'url' => $mcpUrl,
            ],
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'baseUrl' => $baseUrl,
            'tools' => $mcpTools,
            // TODO: 残5ツール (get_news 等) は本PRスコープ外。将来的に ProductToolDefinition に追記されたら自動で含まれる。
        ];

        $response = new JsonResponse($data, 200, [], false);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->addCorsHeaders($response);
        // Cache-Control は setPublic()/setMaxAge() で付与し private との不整合を避ける
        $response->setPublic();
        $response->setMaxAge(300);
        // JsonResponse はデフォルトで private/no-cache を付けるため上記で上書き
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

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
        if (is_array($data) && array_is_list($data)) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32600, 'Invalid Request: batch not supported');
            return $this->jsonResponseWithCors($error, 200);
        }

        if (!is_array($data)) {
            $error = $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: invalid JSON');
            return $this->jsonResponseWithCors($error, 200);
        }

        $method = $data['method'] ?? null;
        $id = $data['id'] ?? null;

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
        // Do not set Allow-Credentials with wildcard
    }

    private function resolveBaseUrl(Request $request): string
    {
        if ($this->shopContextService !== null) {
            $baseUrl = $this->shopContextService->getBaseUrl();
            if ($baseUrl !== '') {
                return $baseUrl;
            }
        }

        // Fallback to request scheme+host
        $schemeAndHost = $request->getSchemeAndHttpHost();
        if ($schemeAndHost !== '') {
            return rtrim($schemeAndHost . $request->getBaseUrl(), '/');
        }

        return '';
    }
}
