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
 * MCP (Model Context Protocol) サーバーサービス。
 *
 * STDIO transport で JSON-RPC 2.0 ベースの MCP プロトコルを実装し、
 * AI アシスタントから EC-CUBE 商品データへのアクセスを提供する。
 *
 * 対応する JSON-RPC メソッド:
 *   - initialize            — プロトコルバージョンと能力のネゴシエーション
 *   - notifications/initialized — クライアントからの初期化完了通知（応答不要）
 *   - tools/list            — 利用可能なツール一覧の返却
 *   - tools/call            — ツールの実行
 */
class McpServerService
{
    /** MCP プロトコルバージョン — 正本は McpHttpService */
    private const PROTOCOL_VERSION = McpHttpService::PROTOCOL_VERSION;

    /** サーバー名 — 正本は McpHttpService */
    private const SERVER_NAME = McpHttpService::SERVER_NAME;

    /** サーバーバージョン — 正本は McpHttpService */
    private const SERVER_VERSION = McpHttpService::SERVER_VERSION;

    private McpHttpService $mcpHttpService;

    public function __construct(
        private ProductRepository $productRepository,
        ?McpHttpService $mcpHttpService = null,
    ) {
        $this->mcpHttpService = $mcpHttpService ?? new McpHttpService($productRepository);
    }

    /**
     * MCP サーバーのメインループを起動する。
     *
     * STDIN から JSON-RPC リクエストを1行ずつ読み、
     * STDIN へ JSON レスポンスを1行ずつ書き出す。
     * STDIN が閉じられる（EOF）か、ゼロバイトが返されたら終了する。
     */
    public function run(): void
    {
        while (true) {
            $input = fgets(STDIN);
            if ($input === false) {
                break;
            }

            $input = trim($input);
            if ($input === '') {
                continue;
            }

            // JSON パースエラーでプロセスが落ちないようにする
            try {
                $request = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $this->writeErrorResponse(null, -32700, 'Parse error: ' . $e->getMessage());
                continue;
            }

            if (!is_array($request)) {
                continue;
            }

            $method = $request['method'] ?? '';
            $id = $request['id'] ?? null;

            try {
                match ($method) {
                    'initialize' => $this->handleInitialize($id),
                    'tools/list' => $this->handleToolsList($id),
                    'tools/call' => $this->handleToolsCall($request, $id),
                    'notifications/initialized' => null,
                    default => $this->writeErrorResponse($id, -32601, "Method not found: {$method}"),
                };
            } catch (\Throwable $e) {
                $this->writeErrorResponse($id, -32603, 'Internal error: ' . $e->getMessage());
            }
        }
    }

    /**
     * initialize リクエストに応答する。
     *
     * クライアントとのプロトコルバージョンとサーバー能力を返す。
     * MCP 仕様では最初にこのハンドシェイクが要求される。
     *
     * @param mixed $id JSON-RPC リクエスト ID
     */
    private function handleInitialize(mixed $id): void
    {
        $response = $this->mcpHttpService->handleInitialize($id);
        $this->writeResponse($response);
    }

    /**
     * tools/list リクエストに応答する。
     *
     * ProductRepository からツール定義を取得し、MCP 形式に変換して返す。
     * ツール定義は ProductRepository::getToolDefinitions() の
     * Claude / OpenAI 互換フォーマットを MCP の inputSchema 形式にマッピングする。
     *
     * @param mixed $id JSON-RPC リクエスト ID
     */
    private function handleToolsList(mixed $id): void
    {
        $response = $this->mcpHttpService->handleToolsList($id);
        $this->writeResponse($response);
    }

    /**
     * tools/call リクエストに応答する。
     *
     * パラメータからツール名と引数を抽出し、ProductRepository に委譲して実行する。
     * 実行結果は MCP の content 配列形式（type: text）で返す。
     * エラーが発生した場合は isError フラグを立ててエラー内容を返す。
     *
     * @param array  $request JSON-RPC リクエスト配列
     * @param mixed  $id      JSON-RPC リクエスト ID
     */
    private function handleToolsCall(array $request, mixed $id): void
    {
        $response = $this->mcpHttpService->handleToolsCall($request, $id);
        $this->writeResponse($response);
    }

    /**
     * JSON-RPC 正常レスポンスを STDIN に書き出す。
     *
     * @param array $response JSON-RPC レスポンス配列
     */
    private function writeResponse(array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES) . "\n";
        fwrite(STDOUT, $json);
        fflush(STDOUT);
    }

    /**
     * JSON-RPC エラーレスポンスを STDIN に書き出す。
     *
     * @param mixed $id      JSON-RPC リクエスト ID
     * @param int   $code    JSON-RPC エラーコード（例: -32601 = Method not found）
     * @param string $message エラーメッセージ
     */
    private function writeErrorResponse(mixed $id, int $code, string $message): void
    {
        $response = $this->mcpHttpService->writeErrorResponse($id, $code, $message);
        $this->writeResponse($response);
    }
}
