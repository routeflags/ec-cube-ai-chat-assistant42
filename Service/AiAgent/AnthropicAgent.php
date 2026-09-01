<?php

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

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Service\AiAgent;

use Plugin\AiChatAssistant42\Service\AiAgentInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Anthropic Claude API を利用する AI エージェント実装。
 *
 * Claude Sonnet / Opus 等のモデルに対応し、Tool Use によるツール呼び出しをサポートする。
 * ツール呼び出しが発生した場合はループで再送し、最終的なテキスト応答を返す。
 */
class AnthropicAgent implements AiAgentInterface
{
    private Client $httpClient;
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private string $apiBase;
    private string $customSystemPrompt;

    public function __construct(
        string $apiKey,
        string $model = 'claude-sonnet-4-20250514',
        int $maxTokens = 4096,
        string $systemPrompt = '',
        string $apiBase = 'https://api.anthropic.com/v1'
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->customSystemPrompt = $systemPrompt;
        $this->apiBase = rtrim($apiBase, '/');
        $this->httpClient = new Client([
            'base_uri' => $this->apiBase,
            'timeout' => 120,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function chat(
        string $message,
        array $tools,
        callable $toolExecutor,
        array $history = []
    ): array {
        $messages = $this->buildInitialMessages($message, $history);
        $convertedTools = $this->convertToolsToAnthropicFormat($tools);
        $toolsUsed = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        while (true) {
            $payload = $this->buildRequestPayload($messages, $convertedTools);
            $response = $this->sendRequest($payload);

            // トークン使用量を集計
            $this->accumulateTokenUsage($response, $totalInputTokens, $totalOutputTokens);

            $stopReason = $response['stop_reason'] ?? '';
            $contentBlocks = $response['content'] ?? [];

            // ツール呼び出しがなければ最終応答を返す
            if ($stopReason !== 'tool_use') {
                return $this->buildResult(
                    $this->extractTextFromContent($contentBlocks),
                    $toolsUsed,
                    $totalInputTokens,
                    $totalOutputTokens
                );
            }

            // アシスタントメッセージを履歴に追加（tool_use.input が [] の場合は {} に正規化）
            $normalizedBlocks = array_map(function (array $block): array {
                if (($block['type'] ?? '') === 'tool_use' && isset($block['input']) && $block['input'] === []) {
                    $block['input'] = new \stdClass();
                }
                return $block;
            }, $contentBlocks);
            $messages[] = ['role' => 'assistant', 'content' => $normalizedBlocks];

            // ツール呼び出しを実行し、結果を履歴に追加
            $toolResults = $this->processToolUseBlocks($contentBlocks, $toolExecutor, $toolsUsed);
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }
    }

    /**
     * トークン使用量を累積する。
     */
    private function accumulateTokenUsage(
        array $response,
        int &$totalInputTokens,
        int &$totalOutputTokens
    ): void {
        $usage = $response['usage'] ?? [];
        $totalInputTokens += $usage['input_tokens'] ?? 0;
        $totalOutputTokens += $usage['output_tokens'] ?? 0;
    }

    /**
     * Anthropic API の tool_use ブロックを処理し、tool_result 配列を構築する。
     *
     * @param array<int, array<string, mixed>> $contentBlocks
     * @param callable $toolExecutor
     * @param array    $toolsUsed 使用したツール名のリスト（参照渡しで追加）
     *
     * @return array<int, array{type: string, tool_use_id: string, content: string}>
     */
    private function processToolUseBlocks(
        array $contentBlocks,
        callable $toolExecutor,
        array &$toolsUsed
    ): array {
        $toolResults = [];
        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }

            $toolName = $block['name'] ?? '';
            $toolArgs = is_array($block['input'] ?? []) ? $block['input'] : [];
            $toolId = $block['id'] ?? '';

            $toolsUsed[] = $toolName;
            $toolResult = $toolExecutor($toolName, $toolArgs);

            $toolResults[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolId,
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $toolResults;
    }

    /**
     * 統一されたチャット結果配列を構築する。
     */
    private function buildResult(
        string $reply,
        array $toolsUsed,
        int $totalInputTokens,
        int $totalOutputTokens
    ): array {
        return [
            'reply' => $reply,
            'tools_used' => $toolsUsed,
            'token_input' => $totalInputTokens,
            'token_output' => $totalOutputTokens,
        ];
    }

    /**
     * 初期メッセージ配列を構築する。
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function buildInitialMessages(string $userMessage, array $history = []): array
    {
        $messages = [];
        foreach ($history as $entry) {
            $messages[] = [
                'role' => $entry['role'],
                'content' => $entry['content'],
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        return $messages;
    }

    /**
     * システムプロンプトを返す。
     *
     * サブクラスや設定で上書き可能にするため protected とする。
     */
    protected function getSystemPrompt(): string
    {
        if (!empty($this->customSystemPrompt)) {
            return $this->customSystemPrompt;
        }

        return 'あなたは親切なアシスタントです。ユーザーの質問に丁寧に回答してください。';
    }

    /**
     * MCP 形式のツール定義を Anthropic 形式に変換する。
     *
     * Anthropic の input_schema は MCP の inputSchema と互換性があるため、
     * 最小限の変換で済む。
     *
     * @param array<int, array{name: string, description: string, inputSchema: array}> $mcpTools
     *
     * @return array<int, array{name: string, description: string, input_schema: array}>
     */
    private function convertToolsToAnthropicFormat(array $mcpTools): array
    {
        $converted = [];
        foreach ($mcpTools as $tool) {
            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => new \stdClass(),
            ];
            if (isset($schema['properties']) && $schema['properties'] === []) {
                $schema['properties'] = new \stdClass();
            }
            $converted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'input_schema' => $schema,
            ];
        }
        return $converted;
    }

    /**
     * Anthropic API リクエストペイロードを構築する。
     *
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param array<int, array{name: string, description: string, input_schema: array}> $tools
     *
     * @return array<string, mixed>
     */
    private function buildRequestPayload(array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $this->getSystemPrompt(),
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    /**
     * Anthropic API の content ブロックからテキストを抽出する。
     *
     * @param array<int, array{type: string, text?: string}> $contentBlocks
     */
    private function extractTextFromContent(array $contentBlocks): string
    {
        $parts = [];
        foreach ($contentBlocks as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = $block['text'];
            }
        }
        return implode("\n", $parts);
    }

    /**
     * Anthropic API にリクエストを送信する。
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> API レスポンス（JSON パース済み）
     *
     * @throws \RuntimeException API 呼び出しが失敗した場合
     */
    private function sendRequest(array $payload): array
    {
        try {
            $response = $this->httpClient->post($this->apiBase . '/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Anthropic API returned non-array response');
            }

            return $decoded;
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                sprintf('Anthropic API request failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
