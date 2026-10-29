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
 * RateLimit は CacheInterface (cache.app) で mcp.ratelimit.{ip}.{tool}.{minute}
 * キー管理（PSR-6 合法、IP は ":" "." "/" を "_" にサニタイズ）。
 */
class McpHttpController
{
    public function __construct(
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
        } catch (RateLimitExceededException $exception) {
            return $this->wellKnownRateLimitResponse($exception);
        }

        $baseUrl = $this->resolveBaseUrl($request);
        // DRY: ツールマッピングは McpHttpService::buildWellKnownPayload() に集約
        $data = $this->mcpHttpService->buildWellKnownPayload($baseUrl);
        // TODO: 残5ツール (get_news 等) は本PRスコープ外。将来的に ProductToolDefinition に追記されたら自動で含まれる。

        $response = new JsonResponse($data, 200, [], false);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->addCorsHeaders($response);
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
            return $this->optionsResponse();
        }

        // Phase1: GET /mcp は 405 で明示（SSE は Phase3 延期）、POST 以外も 405
        if ($request->getMethod() !== 'POST') {
            return $this->methodNotAllowedResponse($request->getMethod() === 'GET');
        }

        $contentTypeError = $this->contentTypeErrorResponse($request);
        if ($contentTypeError !== null) {
            return $contentTypeError;
        }

        $requestData = $this->decodeRequestBody($request->getContent());
        if ($requestData instanceof Response) {
            return $requestData;
        }

        $method = $requestData['method'] ?? null;
        $id = $requestData['id'] ?? null;

        $versionError = $this->jsonRpcVersionErrorResponse($requestData, $id);
        if ($versionError !== null) {
            return $versionError;
        }

        // notifications/initialized は通知（id 無し）→ 204 で応答不要
        if ($method === 'notifications/initialized') {
            return $this->noContentResponse();
        }

        // TODO: Authorization ヘッダーの受け口。write 系では Bearer 検証して 401 を返す
        // （現行は read-only のためヘッダーは参照しない）

        $ip = $request->getClientIp() ?? 'unknown';
        try {
            $this->rateLimitService->enforce($ip, $this->resolveRateLimitTool($method, $requestData));
        } catch (RateLimitExceededException $exception) {
            return $this->rateLimitResponse($id, $exception);
        }

        $result = $this->dispatchJsonRpc($method, $requestData, $id, $ip);

        return $this->jsonResponseWithCors($result, 200);
    }

    /**
     * POST 以外（GET は明示的に 405）のレスポンスを返す。
     */
    private function methodNotAllowedResponse(bool $isGet): Response
    {
        $message = $isGet ? 'Method Not Allowed. Use POST /mcp' : 'Method Not Allowed';
        $response = new JsonResponse(['error' => $message], 405);
        $response->headers->set('Allow', 'POST, OPTIONS');
        $this->addCorsHeaders($response);

        if ($isGet) {
            $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        }

        return $response;
    }

    /**
     * Content-Type が JSON 以外なら 415 を返す（軽量チェック）。
     *
     * MCP Inspector は JSON を送るため影響なし。空ヘッダーは許容する。
     */
    private function contentTypeErrorResponse(Request $request): ?Response
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType === '' || stripos($contentType, 'application/json') !== false) {
            return null;
        }

        $error = $this->mcpHttpService->writeErrorResponse(
            null,
            -32700,
            'Parse error: Content-Type must be application/json'
        );
        $response = $this->jsonResponseWithCors($error, 415);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }

    /**
     * リクエストボディを JSON-RPC オブジェクトとして復号する。
     *
     * 失敗時は JSON-RPC エラーレスポンスを返す（batch は未対応）。
     *
     * @return array<string, mixed>|Response 復号結果、またはエラーレスポンス
     */
    private function decodeRequestBody(string $content): array|Response
    {
        if ($content === '') {
            return $this->jsonResponseWithCors(
                $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: empty body'),
                200
            );
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return $this->jsonResponseWithCors(
                $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: ' . $exception->getMessage()),
                200
            );
        }

        // batch リクエスト（配列）は未対応 → -32600
        // json_decode('{}', true) は [] を返すため array_is_list だけでは空オブジェクトを batch と誤判定する。
        // 元の JSON テキストが "[" で始まる場合のみ batch とみなす。
        if (is_array($decoded) && str_starts_with(ltrim($content), '[') && array_is_list($decoded)) {
            return $this->jsonResponseWithCors(
                $this->mcpHttpService->writeErrorResponse(null, -32600, 'Invalid Request: batch not supported'),
                200
            );
        }

        if (!is_array($decoded)) {
            return $this->jsonResponseWithCors(
                $this->mcpHttpService->writeErrorResponse(null, -32700, 'Parse error: invalid JSON'),
                200
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * jsonrpc バージョンが 2.0 以外なら -32600 を返す（存在しない場合は許容）。
     *
     * @param array<string, mixed> $requestData
     */
    private function jsonRpcVersionErrorResponse(array $requestData, mixed $id): ?Response
    {
        if (!array_key_exists('jsonrpc', $requestData) || $requestData['jsonrpc'] === '2.0') {
            return null;
        }

        return $this->jsonResponseWithCors(
            $this->mcpHttpService->writeErrorResponse($id, -32600, 'Invalid Request: jsonrpc must be "2.0"'),
            200
        );
    }

    /**
     * 通知（id 無し）に対する 204 応答を返す。
     */
    private function noContentResponse(): Response
    {
        $response = new Response('', 204);
        $this->addCorsHeaders($response);

        return $response;
    }

    /**
     * レート制限のバケット名（ツール名）を決める。
     *
     * @param array<string, mixed> $requestData
     */
    private function resolveRateLimitTool(mixed $method, array $requestData): string
    {
        $isToolCall = $method === 'tools/call';
        $toolName = $requestData['params']['name'] ?? null;
        if ($isToolCall && is_string($toolName)) {
            return $toolName;
        }

        return 'default';
    }

    /**
     * JSON-RPC メソッドを実行し、レスポンス配列を返す。
     *
     * @param array<string, mixed> $requestData
     *
     * @return array<string, mixed>
     */
    private function dispatchJsonRpc(mixed $method, array $requestData, mixed $id, string $ip): array
    {
        try {
            return match ($method) {
                'initialize' => $this->mcpHttpService->handleInitialize($id),
                'tools/list' => $this->mcpHttpService->handleToolsList($id),
                'tools/call' => $this->mcpHttpService->handleToolsCall($requestData, $id, $ip),
                null => $this->mcpHttpService->writeErrorResponse($id, -32600, 'Invalid Request: method is required'),
                default => $this->mcpHttpService->writeErrorResponse($id, -32601, "Method not found: {$method}"),
            };
        } catch (\Throwable $exception) {
            return $this->mcpHttpService->writeErrorResponse(
                $id,
                -32603,
                'Internal error: ' . $exception->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $data JSON レスポンス本文
     */
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

    private function rateLimitResponse(mixed $id, RateLimitExceededException $exception): JsonResponse
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
        $response->headers->set('Retry-After', (string) $exception->getRetryAfterSeconds());
        $response->headers->set('X-RateLimit-Limit', (string) $exception->getLimit());
        $response->headers->set('X-RateLimit-Remaining', '0');
        $this->addCorsHeaders($response);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);

        return $response;
    }

    private function wellKnownRateLimitResponse(RateLimitExceededException $exception): JsonResponse
    {
        // Discovery (GET /.well-known/mcp.json) は REST なので JSON-RPC ラップせず REST 形式で返す
        $response = new JsonResponse(['error' => 'Too Many Requests'], 429, [], false);
        $response->headers->set('Retry-After', (string) $exception->getRetryAfterSeconds());
        $response->headers->set('X-RateLimit-Limit', (string) $exception->getLimit());
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
        // WebMCP tools permission: top-level documents may register tools; cross-origin iframes need allow="tools"
        $response->headers->set('Permissions-Policy', 'tools=self');
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
