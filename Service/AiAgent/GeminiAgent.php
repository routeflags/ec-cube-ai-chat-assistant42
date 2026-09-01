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
use Psr\Log\LoggerInterface;

/**
 * Google Gemini API を利用する AI エージェント実装。
 *
 * Gemini 2.5 Flash / Pro 等のモデルに対応し、Function Calling によるツール呼び出しをサポートする。
 * ツール呼び出しが発生した場合はループで再送し、最終的なテキスト応答を返す。
 */
class GeminiAgent implements AiAgentInterface
{
    private const MAX_TOOL_ITERATIONS = 10;

    private Client $httpClient;
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private string $apiBase;
    private string $customSystemPrompt;
    private ?LoggerInterface $logger;

    public function __construct(
        string $apiKey,
        string $model = 'gemini-2.5-flash',
        int $maxTokens = 4096,
        string $systemPrompt = '',
        string $apiBase = 'https://generativelanguage.googleapis.com/v1beta',
        ?LoggerInterface $logger = null
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->customSystemPrompt = $systemPrompt;
        $this->apiBase = rtrim($apiBase, '/');
        $this->logger = $logger;
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
        // 検証用の許可ツール名セット（TOOL_DEFINITIONS 準拠）
        $allowedToolNames = array_flip(array_column($convertedTools, 'name'));
        $toolsUsed = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $iterations = 0;
        $lastReply = '';

        while (true) {
            if ($iterations >= self::MAX_TOOL_ITERATIONS) {
                $this->logger?->warning('Gemini tool loop reached max iterations, truncating', [
                    'iterations' => $iterations,
                    'model' => $this->model,
                ]);
                return $this->buildResult($lastReply, $toolsUsed, $totalInputTokens, $totalOutputTokens);
            }
            $iterations++;

            $payload = $this->buildRequestPayload($contents, $convertedTools);
            $response = $this->sendRequest($payload);

            $candidate = $response['candidates'][0] ?? null;
            if ($candidate === null) {
                break;
            }

            // トークン使用量を集計
            $this->accumulateTokenUsage($response, $totalInputTokens, $totalOutputTokens);

            $contentParts = $candidate['content']['parts'] ?? [];
            $lastReply = $this->extractTextFromParts($contentParts);
            $functionCalls = $this->extractFunctionCalls($contentParts);

            // ツール呼び出しがなければ最終応答を返す
            if ($this->isTerminalResponse($candidate, $functionCalls)) {
                return $this->buildResult(
                    $lastReply,
                    $toolsUsed,
                    $totalInputTokens,
                    $totalOutputTokens
                );
            }

            // 未知ツールの事前検証（日本語で簡潔に記録し早期 return）
            $this->validateFunctionCalls($functionCalls, $allowedToolNames);

            // アシスタントの応答を履歴に追加
            // Google仕様: thoughtSignature は表示用 thought と分離し、検証用として透過的に保持する。
            // parts 全体（thought, thoughtSignature 含む）を落とさず round-trip する。args が [] の場合は {} に正規化するのみ。
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

        return $this->buildResult($lastReply, $toolsUsed, $totalInputTokens, $totalOutputTokens);
    }

    /**
     * 受信した functionCall の name が TOOL_DEFINITIONS に存在するか検証する。
     *
     * 未知ツールの場合は ChatLog.error_message に残るよう日本語で簡潔にログし、例外で早期 return する。
     *
     * @param array<int, array{name: string, args: mixed}> $functionCalls
     * @param array<string, int>                             $allowedToolNames  name => index のフリップ配列
     *
     * @throws \RuntimeException 未知ツールが含まれている場合
     */
    private function validateFunctionCalls(array $functionCalls, array $allowedToolNames): void
    {
        foreach ($functionCalls as $call) {
            $toolName = $call['name'] ?? '';
            if ($toolName === '' || !isset($allowedToolNames[$toolName])) {
                $message = sprintf('未知のツールが呼び出されました: %s', $toolName !== '' ? $toolName : '(空)');
                $this->logger?->warning($message, ['tool_name' => $toolName]);
                throw new \RuntimeException($message);
            }
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
     * 正規化:
     * - properties: [] → new stdClass()  ({} でエンコードするため)
     * - functionDeclaration 二重ラップ解消（Gemini 形式が既にラップされている場合に剥がす）
     *
     * @param array<int, array{name: string, description: string, inputSchema: array}> $mcpTools
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    private function convertToolsToGeminiFormat(array $mcpTools): array
    {
        $converted = [];
        foreach ($mcpTools as $tool) {
            // functionDeclaration 二重ラップ解消: 既に Gemini 形式でラップされていたら剥がす
            if (isset($tool['functionDeclaration']) && is_array($tool['functionDeclaration'])) {
                $tool = $tool['functionDeclaration'];
            }

            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? $tool['parameters'] ?? [
                'type' => 'OBJECT',
                'properties' => new \stdClass(),
            ];

            // 入れ子で functionDeclaration を含むケース（例: inputSchema が {functionDeclaration: {parameters: ...}}）も解消
            if (isset($schema['functionDeclaration']) && is_array($schema['functionDeclaration'])) {
                $inner = $schema['functionDeclaration'];
                // parameters があればそれを、なければ inner 自体をスキーマとする
                $schema = $inner['parameters'] ?? $inner;
            }

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
     * 正規化:
     * - args: [] → {} は model parts の round-trip 時に行う（ここでは内部用に配列のまま保持）
     * - 非配列 args は [] にフォールバック
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
                // 連想配列でない場合や null の場合は空配列にフォールバック
                if (!is_array($args)) {
                    $args = [];
                }
                // 空配列 [] は内部では [] のまま保持する（Gemini API 送信用に {} へ変換するのは
                // chat() の normalizedParts 構築時）。ここで stdClass にしても (array) で戻すと
                // 意味がなくなるため、単純に配列のまま返す。
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
    /**
     * API キー等の機微情報をメッセージから除去する。
     */
    private function redactSensitive(string $message): string
    {
        $redacted = preg_replace('/((?:api[_-]?key|key)\s*=\s*)[^&\s"\']+/i', '$1[REDACTED]', $message);
        if ($redacted !== null) {
            $message = $redacted;
        }
        $redacted = preg_replace('/(x-goog-api-key\s*[:=]\s*)[^\s"\']+/i', '$1[REDACTED]', $message);
        return $redacted !== null ? $redacted : $message;
    }

    private function sendRequest(array $payload): array
    {
        try {
            $modelPath = rawurlencode($this->model);
            $response = $this->httpClient->post(
                $this->apiBase . "/models/{$modelPath}:generateContent",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $this->apiKey,
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
            $safeMessage = $this->redactSensitive($e->getMessage());
            $this->logger?->warning('Gemini API request failed', ['error' => $safeMessage]);
            throw new \RuntimeException(
                sprintf('Gemini API request failed: %s', $safeMessage),
                0,
                $e
            );
        }
    }
}
