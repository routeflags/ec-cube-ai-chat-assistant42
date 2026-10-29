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

namespace Plugin\AiChatAssistant44\Service;

use Plugin\AiChatAssistant44\Repository\McpAuditRepository;
use Plugin\AiChatAssistant44\Repository\ProductRepository;

/**
 * MCP HTTP 共通ロジック。
 *
 * STDIO (McpServerService) と Streamable HTTP (McpHttpController) で共用する
 * JSON-RPC ハンドラと定数を集約する正本。
 * 定数 PROTOCOL_VERSION / SERVER_NAME / SERVER_VERSION は本クラスが正本であり、
 * McpServerService は本クラスに委譲する。
 */
class McpHttpService
{
    /** MCP プロトコルバージョン */
    public const PROTOCOL_VERSION = '2024-11-05';

    /** サーバー名 */
    public const SERVER_NAME = 'ec-mcp';

    /** サーバーバージョン — eccube-plugin.yaml と同期 */
    public const SERVER_VERSION = '1.1.3';

    /**
     * Discovery 文書の表示名フォールバック。
     *
     * ショップ名未設定・DB 不可時は汎用名を返し、配布物の汎用性を保つ。
     */
    public const FALLBACK_DISPLAY_NAME = 'EC-CUBE MCP';

    public function __construct(
        private ProductRepository $productRepository,
        private ?McpAuditRepository $mcpAuditRepository = null,
        private ?ShopContextService $shopContextService = null,
    ) {
    }

    /**
     * initialize リクエストに応答する配列を返す。
     *
     * @param mixed $id JSON-RPC リクエスト ID
     *
     * @return array{jsonrpc: string, id: mixed, result: array<string, mixed>}
     */
    public function handleInitialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name' => self::SERVER_NAME,
                    'version' => self::SERVER_VERSION,
                ],
            ],
        ];
    }

    /**
     * tools/list リクエストに応答する配列を返す。
     *
     * @param mixed $id JSON-RPC リクエスト ID
     *
     * @return array{jsonrpc: string, id: mixed, result: array<string, mixed>}
     */
    public function handleToolsList(mixed $id): array
    {
        $toolDefinitions = $this->productRepository->getToolDefinitions();

        $mcpTools = array_map(
            fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['input_schema'],
            ],
            $toolDefinitions
        );

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $mcpTools],
        ];
    }

    /**
     * tools/call リクエストに応答する配列を返す。
     *
     * 監査ログを記録する（失敗しても本処理に影響させない）。
     *
     * @param array<string, mixed>       $request   JSON-RPC リクエスト配列
     * @param mixed       $id        JSON-RPC リクエスト ID
     * @param string|null $clientIp  呼び出し元IP（ハッシュ化して記録、null可）
     *
     * @return array{jsonrpc: string, id: mixed, result: array<string, mixed>}
     */
    public function handleToolsCall(array $request, mixed $id, ?string $clientIp = null): array
    {
        $params = $request['params'] ?? [];
        $toolName = $params['name'] ?? '';
        $toolArgs = $params['arguments'] ?? [];

        if (!is_array($toolArgs)) {
            $toolArgs = [];
        }

        $startedAt = (int) (microtime(true) * 1000);
        try {
            $result = $this->productRepository->executeTool($toolName, $toolArgs);
            $this->recordAudit((string) $toolName, 'ok', null, $startedAt, $clientIp);

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                        ],
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            $this->recordAudit((string) $toolName, 'error', get_class($e), $startedAt, $clientIp);
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode(['error' => $this->sanitizeErrorMessage($e->getMessage())], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                    'isError' => true,
                ],
            ];
        }
    }

    /**
     * MCP 監査1行を記録する。記録失敗は握りつぶす。
     */
    private function recordAudit(string $toolName, string $result, ?string $errorCode, int $startedAtMs, ?string $clientIp): void
    {
        if ($this->mcpAuditRepository === null) {
            return;
        }
        try {
            $durationMs = (int) (microtime(true) * 1000) - $startedAtMs;
            $ipHash = $clientIp !== null && $clientIp !== '' ? hash('sha256', $clientIp) : null;
            $this->mcpAuditRepository->record($toolName !== '' ? $toolName : '(unknown)', $result, $errorCode, $durationMs, $ipHash);
        } catch (\Throwable $e) {
            // 監査失敗は本処理に影響させない
        }
    }

    /**
     * JSON-RPC エラーレスポンス配列を返す。
     *
     * @param mixed  $id      JSON-RPC リクエスト ID
     * @param int    $code    JSON-RPC エラーコード
     * @param string $message エラーメッセージ
     *
     * @return array{jsonrpc: string, id: mixed, error: array<string, mixed>}
     */
    public function writeErrorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $this->sanitizeErrorMessage($message),
            ],
        ];
    }

    /**
     * エラーメッセージをサニタイズし情報漏洩を防ぐ。
     *
     * SQL キーワードやテーブル名、パス、スタックトレースを含む場合は Internal error に置換する。
     * ChatApiController::redactedMessage() と同等の水準で漏洩を防止する。
     */
    public function sanitizeErrorMessage(string $message): string
    {
        $pattern = '/SQLSTATE|Doctrine|PDO|plg_|dtb_|SELECT|INSERT|UPDATE|DELETE|FROM|TABLE|column|syntax'
            . '|Duplicate|Unknown database|Connection|\/var\/www|\.php:/i';
        if (preg_match($pattern, $message)) {
            return 'Internal error';
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $message) ?? '';

        return mb_substr($clean, 0, 200);
    }

    /**
     * wellKnown Discovery 用のペイロードを構築する（DRY 共用化）。
     *
     * Controller と handleToolsList で重複していた name/description/inputSchema マッピングを
     * 本メソッドに集約し、将来のキー名変更時の片方だけ壊れるリスクを防ぐ。
     *
     * @return array<string, mixed>
     */
    public function buildWellKnownPayload(string $baseUrl): array
    {
        $toolDefinitions = $this->productRepository->getToolDefinitions();

        $mcpTools = array_map(
            fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['input_schema'],
            ],
            $toolDefinitions
        );

        $mcpUrl = rtrim($baseUrl, '/') . '/mcp';

        return [
            'name' => $this->resolveDisplayName(),
            'protocolVersion' => self::PROTOCOL_VERSION,
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
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
            // 共存注記 (additive のみ。既存キーは不変。ツール名・パス変更なし)。
            // 公式MCP (コア4.4 + api44: POST /{admin_route}/mcp, OAuth2必須) とは
            // 別サーバ・別エンドポイントとして共存する。未知キーを無視するクライ
            // アントへの互換性影響はない。詳細は Documents/plans/dev/20261013_mcp-coexistence_plan.md
            'description' => 'EC-CUBE storefront public MCP server ('
                . self::SERVER_NAME
                . '). Coexists with the official admin MCP server '
                . '(EC-CUBE MCP Server, POST /{admin_route}/mcp, OAuth2 required). '
                . 'They are separate servers/endpoints; register the intended one.',
            'coexistence' => [
                'servers' => [
                    [
                        'name' => self::SERVER_NAME,
                        'endpoint' => '/mcp',
                        'discovery' => '/.well-known/mcp.json',
                        'auth' => 'none (public storefront)',
                    ],
                    [
                        'name' => 'EC-CUBE MCP Server',
                        'endpoint' => '/{admin_route}/mcp',
                        'auth' => 'OAuth2 required (api44, scopes mcp:*:read)',
                    ],
                ],
                'notes' => [
                    'Our product-search tool is named aichat_search_products '
                    . '(renamed from search_products to avoid confusion with the '
                    . 'EC-CUBE MCP Server tool of the same name). '
                    . 'Calls to the old name return an error; '
                    . 'compare serverInfo.name and the endpoint URL '
                    . 'when registering clients.',
                ],
            ],
        ];
    }

    /**
     * Discovery 文書の表示名を解決する。
     *
     * DB のショップ名を優先し、未設定・DB 不可・未注入時は汎用名に
     * フォールバックする。serverInfo.name（機械向け識別子）は変えない。
     */
    private function resolveDisplayName(): string
    {
        if ($this->shopContextService === null) {
            return self::FALLBACK_DISPLAY_NAME;
        }
        try {
            return $this->shopContextService->getConfiguredShopName() ?? self::FALLBACK_DISPLAY_NAME;
        } catch (\Throwable) {
            // DB 不可時も Discovery 応答自体は壊さない
            return self::FALLBACK_DISPLAY_NAME;
        }
    }

    /**
     * MCP ツール定義をそのまま返す（wellKnown 用の委譲ヘルパ）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getToolDefinitions(): array
    {
        return $this->productRepository->getToolDefinitions();
    }
}
