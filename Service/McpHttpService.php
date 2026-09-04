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

namespace Plugin\AiChatAssistant42\Service;

use Plugin\AiChatAssistant42\Repository\ProductRepository;

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
    public const SERVER_VERSION = '1.0.1';

    public function __construct(
        private ProductRepository $productRepository,
    ) {
    }

    /**
     * initialize リクエストに応答する配列を返す。
     *
     * @param mixed $id JSON-RPC リクエスト ID
     *
     * @return array{jsonrpc: string, id: mixed, result: array}
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
     * @return array{jsonrpc: string, id: mixed, result: array}
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
     * @param array $request JSON-RPC リクエスト配列
     * @param mixed $id      JSON-RPC リクエスト ID
     *
     * @return array{jsonrpc: string, id: mixed, result: array}
     */
    public function handleToolsCall(array $request, mixed $id): array
    {
        $params = $request['params'] ?? [];
        $toolName = $params['name'] ?? '';
        $toolArgs = $params['arguments'] ?? [];

        if (!is_array($toolArgs)) {
            $toolArgs = [];
        }

        try {
            $result = $this->productRepository->executeTool($toolName, $toolArgs);

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
     * JSON-RPC エラーレスポンス配列を返す。
     *
     * @param mixed  $id      JSON-RPC リクエスト ID
     * @param int    $code    JSON-RPC エラーコード
     * @param string $message エラーメッセージ
     *
     * @return array{jsonrpc: string, id: mixed, error: array}
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

        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $message);

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
            'name' => 'EC-CUBE MCP',
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
        ];
    }

    /**
     * MCP ツール定義をそのまま返す（wellKnown 用の委譲ヘルパ）。
     *
     * @return array<int, array>
     */
    public function getToolDefinitions(): array
    {
        return $this->productRepository->getToolDefinitions();
    }
}
