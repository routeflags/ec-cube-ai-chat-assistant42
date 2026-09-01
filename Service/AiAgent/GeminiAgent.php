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
 * Google Gemini API を利用する AI エージェント実装。
 *
 * Gemini 2.5 Flash / Pro 等のモデルに対応し、Function Calling によるツール呼び出しをサポートする。
 * ツール呼び出しが発生した場合はループで再送し、最終的なテキスト応答を返す。
 */
class GeminiAgent implements AiAgentInterface
{
    private Client $httpClient;
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private string $apiBase;
    private string $customSystemPrompt;

    public function __construct(
        string $apiKey,
        string $model = 'gemini-2.5-flash',
        int $maxTokens = 4096,
        string $systemPrompt = '',
        string $apiBase = 'https://generativelanguage.googleapis.com/v1beta'
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
        $contents = $this->buildInitialContents($message, $history);
        $convertedTools = $this->convertToolsToGeminiFormat($tools);
        $toolsUsed = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        while (true) {
            $payload = $this->buildRequestPayload($contents, $convertedTools);
            $response = $this->sendRequest($payload);

            $candidate = $response['candidates'][0] ?? null;
            if ($candidate === null) {
                break;
            }

            // トークン使用量を集計
            $this->accumulateTokenUsage($response, $totalInputTokens, $totalOutputTokens);

            $contentParts = $candidate['content']['parts'] ?? [];
            $functionCalls = $this->extractFunctionCalls($contentParts);

            // ツール呼び出しがなければ最終応答を返す
            if ($this->isTerminalResponse($candidate, $functionCalls)) {
                return $this->buildResult(
                    $this->extractTextFromParts($contentParts),
                    $toolsUsed,
                    $totalInputTokens,
                    $totalOutputTokens
                );
            }

            // アシスタントの応答を履歴に追加（args が [] の場合は {} に正規化）
            $normalizedParts = array_map(function (array $part): array {
                if (isset($part['functionCall']['args']) && $part['functionCall']['args'] === []) {
                    $part['functionCall']['args'] = new \stdClass();
                }
                return $part;
            }, $contentParts);
            $contents[] = ['role' => 'model', 'parts' => $normalizedParts];

            // ツール呼び出しを実行し、結果を履歴に追加
            $functionResponseParts = $this->processFunctionCalls($functionCalls, $toolExecutor, $toolsUsed);
            $contents[] = ['role' => 'user', 'parts' => $functionResponseParts];
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
        $usageMetadata = $response['usageMetadata'] ?? [];
        $totalInputTokens += $usageMetadata['promptTokenCount'] ?? 0;
        $totalOutputTokens += $usageMetadata['candidatesTokenCount'] ?? 0;
    }

    /**
     * レスポンスが最終応答（ツール呼び出しがない）かどうかを判定する。
     */
    private function isTerminalResponse(array $candidate, array $functionCalls): bool
    {
        $finishReason = $candidate['finishReason'] ?? '';

        // 予期しない finishReason の場合も安全にループを抜ける
        if ($finishReason !== 'FUNCTION_CALL' && $finishReason !== 'STOP') {
            return true;
        }

        return empty($functionCalls);
    }

    /**
     * ツール呼び出しを実行し、Gemini API 形式の functionResponse parts を構築する。
     *
     * @param array<int, array{name: string, args: mixed}> $functionCalls
     * @param callable $toolExecutor
     * @param array    $toolsUsed   使用したツール名のリスト（参照渡しで追加）
     *
     * @return array<int, array{functionResponse: array{name: string, response: array{result: mixed}}}>
     */
    private function processFunctionCalls(
        array $functionCalls,
        callable $toolExecutor,
        array &$toolsUsed
    ): array {
        $parts = [];
        foreach ($functionCalls as $functionCall) {
            $toolName = $functionCall['name'] ?? '';
            $toolArgs = is_array($functionCall['args'] ?? []) ? $functionCall['args'] : [];

            $toolsUsed[] = $toolName;
            $toolResult = $toolExecutor($toolName, $toolArgs);

            $parts[] = [
                'functionResponse' => [
                    'name' => $toolName,
                    'response' => ['result' => $toolResult],
                ],
            ];
        }

        return $parts;
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
     * 初期 contents 配列を構築する。
     *
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private function buildInitialContents(string $userMessage, array $history = []): array
    {
        $contents = [];
        foreach ($history as $entry) {
            $role = $entry['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $entry['content']],
                ],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $userMessage],
            ],
        ];

        return $contents;
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
     * MCP 形式のツール定義を Gemini functionDeclarations 形式に変換する。
     *
     * @param array<int, array{name: string, description: string, inputSchema: array}> $mcpTools
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    private function convertToolsToGeminiFormat(array $mcpTools): array
    {
        $converted = [];
        foreach ($mcpTools as $tool) {
            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? $tool['parameters'] ?? [
                'type' => 'OBJECT',
                'properties' => new \stdClass(),
            ];
            if (isset($schema['properties']) && $schema['properties'] === []) {
                $schema['properties'] = new \stdClass();
            }
            $converted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $schema,
            ];
        }
        return $converted;
    }

    /**
     * Gemini API リクエストペイロードを構築する。
     *
     * @param array<int, array{role: string, parts: array}> $contents
     * @param array<int, array{name: string, description: string, parameters: array}> $tools
     *
     * @return array<string, mixed>
     */
    private function buildRequestPayload(array $contents, array $tools): array
    {
        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $this->maxTokens,
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $this->getSystemPrompt()],
                ],
            ],
        ];

        if (!empty($tools)) {
            $payload['tools'] = [
                'functionDeclarations' => $tools,
            ];
        }

        return $payload;
    }

    /**
     * content parts から functionCall を抽出する。
     *
     * @param array<int, array<string, mixed>> $contentParts
     *
     * @return array<int, array{name: string, args: array}>
     */
    private function extractFunctionCalls(array $contentParts): array
    {
        $calls = [];
        foreach ($contentParts as $part) {
            if (isset($part['functionCall'])) {
                $args = $part['functionCall']['args'] ?? [];
                // 空配列 [] は JSON で [] になるが、Gemini API は object を期待するため {} に正規化
                if (is_array($args) && $args === []) {
                    $args = new \stdClass();
                    $args = (array) $args;
                }
                // 連想配列でない場合も正規化
                if (!is_array($args)) {
                    $args = [];
                }
                $calls[] = [
                    'name' => $part['functionCall']['name'] ?? '',
                    'args' => $args,
                ];
            }
        }
        return $calls;
    }

    /**
     * content parts からテキストを抽出する。
     *
     * @param array<int, array<string, mixed>> $contentParts
     */
    private function extractTextFromParts(array $contentParts): string
    {
        $parts = [];
        foreach ($contentParts as $part) {
            if (isset($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        return implode("\n", $parts);
    }

    /**
     * Gemini API にリクエストを送信する。
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
            $modelPath = rawurlencode($this->model);
            $response = $this->httpClient->post(
                $this->apiBase . "/models/{$modelPath}:generateContent?key={$this->apiKey}",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            $body = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Gemini API returned non-array response');
            }

            return $decoded;
        } catch (GuzzleException $e) {
            throw new \RuntimeException(
                sprintf('Gemini API request failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
