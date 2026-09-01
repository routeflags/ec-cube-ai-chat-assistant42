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
 * OpenAI API を利用する AI エージェント実装。
 *
 * GPT-4o 等のモデルに対応し、Function Calling によるツール呼び出しをサポートする。
 * ツール呼び出しが発生した場合はループで再送し、最終的なテキスト応答を返す。
 */
class OpenAiAgent implements AiAgentInterface
{
    private Client $httpClient;
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private string $apiBase;
    private string $customSystemPrompt;

    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        int $maxTokens = 4096,
        string $systemPrompt = '',
        string $apiBase = 'https://api.openai.com/v1'
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
        $convertedTools = $this->convertToolsToOpenAiFormat($tools);
        $toolsUsed = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        while (true) {
            $payload = $this->buildRequestPayload($messages, $convertedTools);
            $response = $this->sendRequest($payload);

            $choice = $response['choices'][0] ?? null;
            if ($choice === null) {
                break;
            }

            // トークン使用量を集計
            $this->accumulateTokenUsage($response, $totalInputTokens, $totalOutputTokens);

            $message = $choice['message'] ?? [];
            $finishReason = $choice['finish_reason'] ?? '';

            // ツール呼び出しがなければ最終応答を返す
            if ($finishReason !== 'tool_calls' || empty($message['tool_calls'])) {
                return $this->buildResult(
                    $message['content'] ?? '',
                    $toolsUsed,
                    $totalInputTokens,
                    $totalOutputTokens
                );
            }

            // アシスタントメッセージを履歴に追加
            $messages[] = $message;

            // ツール呼び出しを実行し、結果を履歴に追加
            $toolResultMessages = $this->processToolCalls($message['tool_calls'], $toolExecutor, $toolsUsed);
            foreach ($toolResultMessages as $toolResultMessage) {
                $messages[] = $toolResultMessage;
            }
        }

        return $this->buildResult('', $toolsUsed, $totalInputTokens, $totalOutputTokens);
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
        $totalInputTokens += $usage['prompt_tokens'] ?? 0;
        $totalOutputTokens += $usage['completion_tokens'] ?? 0;
    }

    /**
     * OpenAI の tool_calls を処理し、結果メッセージ配列を構築する。
     *
     * @param array<int, array{id: string, function: array{name: string, arguments: string}}> $toolCalls
     * @param callable $toolExecutor
     * @param array    $toolsUsed 使用したツール名のリスト（参照渡しで追加）
     *
     * @return array<int, array{role: string, tool_call_id: string, content: string}>
     */
    private function processToolCalls(
        array $toolCalls,
        callable $toolExecutor,
        array &$toolsUsed
    ): array {
        $resultMessages = [];
        foreach ($toolCalls as $toolCall) {
            $toolName = $toolCall['function']['name'] ?? '';
            $toolArgs = json_decode(
                $toolCall['function']['arguments'] ?? '{}',
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $toolCallId = $toolCall['id'] ?? '';

            $toolsUsed[] = $toolName;
            $toolResult = $toolExecutor($toolName, is_array($toolArgs) ? $toolArgs : []);

            $resultMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $resultMessages;
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
        $messages = [
            [
                'role' => 'system',
                'content' => $this->getSystemPrompt(),
            ],
        ];
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
     * MCP 形式のツール定義を OpenAI functions 形式に変換する。
     *
     * @param array<int, array{name: string, description: string, inputSchema: array}> $mcpTools
     *
     * @return array<int, array{type: string, function: array{name: string, description: string, parameters: array}}>
     */
    private function convertToolsToOpenAiFormat(array $mcpTools): array
    {
        $converted = [];
        foreach ($mcpTools as $tool) {
            $converted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['inputSchema'] ?? $tool['parameters'] ?? [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ];
        }
        return $converted;
    }

    /**
     * OpenAI API リクエストペイロードを構築する。
     *
     * @param array<int, array{role: string, content?: string, tool_calls?: array, tool_call_id?: string}> $messages
     * @param array<int, array{type: string, function: array}> $tools
     *
     * @return array<string, mixed>
     */
    private function buildRequestPayload(array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * OpenAI API にリクエストを送信する。
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
            $response = $this->httpClient->post($this->apiBase . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $body = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException('OpenAI API returned non-array response');
            }

            return $decoded;
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                sprintf('OpenAI API request failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
