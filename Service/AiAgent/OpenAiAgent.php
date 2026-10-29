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
use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use stdClass;
use RuntimeException;

/**
 * OpenAI API を利用する AI エージェント実装。
 *
 * GPT-4o 等のモデルに対応し、Function Calling によるツール呼び出しをサポートする。
 * ツール呼び出しが発生した場合はループで再送し、最終的なテキスト応答を返す。
 */
class OpenAiAgent implements AiAgentInterface
{
    private const MAX_TOOL_ITERATIONS = 10;

    private Client $httpClient;
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private string $apiBase;
    private string $customSystemPrompt;
    /** @var string|null reasoning effort (e.g. "low" / "medium" / "high") */
    private ?string $reasoningEffort;
    private ?AiModelRegistry $modelRegistry;
    /**
     * @var bool|null Capability cache for this model
     */
    private ?bool $cachedReasoningFlag = null;
    private ?LoggerInterface $logger;

    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        int $maxTokens = 4096,
        string $systemPrompt = '',
        string $apiBase = 'https://api.openai.com/v1',
        ?string $reasoningEffort = null,
        ?AiModelRegistry $modelRegistry = null,
        ?LoggerInterface $logger = null
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->customSystemPrompt = $systemPrompt;
        $this->apiBase = rtrim($apiBase, '/');
        $this->reasoningEffort = $reasoningEffort;
        $this->modelRegistry = $modelRegistry;
        $this->logger = $logger;
        $this->httpClient = new Client([
            'base_uri' => $this->apiBase,
            'timeout' => 120,
        ]);
    }

    /**
     * reasoning effort を設定する（実行時に動的に変更可能）。
     */
    public function setReasoningEffort(?string $reasoningEffort): void
    {
        $this->reasoningEffort = $reasoningEffort;
        // キャッシュはモデル依存なのでクリア不要だが、念のため維持
    }

    /**
     * モデルレジストリを差し替える（テストや DI 用）。
     */
    public function setModelRegistry(?AiModelRegistry $registry): void
    {
        $this->modelRegistry = $registry;
        $this->cachedReasoningFlag = null;
    }

    /**
     * {@inheritdoc}
     *
     * @param mcpToolDefinition[] $tools
     * @param array<int, array{role: string, content: string}> $history
     *
     * @return array{reply: string, tools_used: string[], token_input: int, token_output: int}
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
        $iterations = 0;
        $lastReply = '';

        while (true) {
            if ($iterations >= self::MAX_TOOL_ITERATIONS) {
                $this->logger?->warning('OpenAI tool loop reached max iterations, truncating', [
                    'iterations' => $iterations,
                    'model' => $this->model,
                ]);
                return $this->buildResult($lastReply, $toolsUsed, $totalInputTokens, $totalOutputTokens);
            }
            $iterations++;

            $payload = $this->buildRequestPayload($messages, $convertedTools);
            $response = $this->sendRequest($payload);

            $choice = $response['choices'][0] ?? null;
            if ($choice === null) {
                break;
            }

            // トークン使用量を集計
            $this->accumulateTokenUsage($response, $totalInputTokens, $totalOutputTokens);

            $message = $choice['message'] ?? [];
            $lastReply = $message['content'] ?? $lastReply;
            $finishReason = $choice['finish_reason'] ?? '';

            // ツール呼び出しがなければ最終応答を返す
            if ($finishReason !== 'tool_calls' || empty($message['tool_calls'])) {
                return $this->buildResult(
                    $lastReply,
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

        return $this->buildResult($lastReply, $toolsUsed, $totalInputTokens, $totalOutputTokens);
    }

    /**
     * トークン使用量を累積する。
     *
     * @param array<string, mixed> $response
     */
    private function accumulateTokenUsage(
        array $response,
        int &$totalInputTokens,
        int &$totalOutputTokens
    ): void {
        $usage = $response['usage'] ?? [];
        $totalInputTokens += (int) ($usage['prompt_tokens'] ?? 0);
        $totalOutputTokens += (int) ($usage['completion_tokens'] ?? 0);
    }

    /**
     * OpenAI の tool_calls を処理し、結果メッセージ配列を構築する。
     *
     * @param array<int, array{id: string, function: array{name: string, arguments: string}}> $toolCalls
     * @param callable $toolExecutor
     * @param string[] $toolsUsed 使用したツール名のリスト（参照渡しで追加）
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
            $toolCallId = (string) ($toolCall['id'] ?? '');

            $toolsUsed[] = $toolName;
            try {
                $toolResult = $toolExecutor($toolName, is_array($toolArgs) ? $toolArgs : []);
            } catch (\Throwable $toolError) {
                // 1つのツールの失敗でターン全体を殺さない。エラー内容を結果として返し、AI が継続できるようにする
                $this->logger?->warning('Tool execution failed, continuing without result', [
                    'tool' => $toolName,
                    'error' => $toolError->getMessage(),
                ]);
                $toolResult = ['error' => 'ツール実行中にエラーが発生しました。別の方法で回答してください。'];
            }

            $resultMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => (string) json_encode($toolResult, JSON_UNESCAPED_UNICODE),
            ];
        }

        return $resultMessages;
    }

    /**
     * 統一されたチャット結果配列を構築する。
     *
     * @param string[] $toolsUsed
     *
     * @return array{reply: string, tools_used: string[], token_input: int, token_output: int}
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
     * @param array<int, array{role: string, content: string}> $history
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
     * @param array<int, array<string, mixed>> $mcpTools inputSchema / input_schema / parameters のいずれかを含むツール定義
     *
     * @return array<int, array{
     *     type: string,
     *     function: array{name: string, description: string, parameters: array<string, mixed>|object}
     * }>
     */
    private function convertToolsToOpenAiFormat(array $mcpTools): array
    {
        $converted = [];
        foreach ($mcpTools as $tool) {
            $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => new stdClass(),
            ];
            // 空配列 [] は JSON で [] になるため、空オブジェクト {} に変換
            if (isset($schema['properties']) && $schema['properties'] === []) {
                $schema['properties'] = new stdClass();
            }
            $converted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $schema,
                ],
            ];
        }
        return $converted;
    }

    /**
     * OpenAI API リクエストペイロードを構築する。
     *
     * Capability Matrix を参照し、ツール併用時に reasoning が非対応のモデルでは
     * reasoning_effort / reasoningEffort を付与しない（400 エラー回避）。
     *
     * @param array<int, array{
     *     role: string,
     *     content?: string,
     *     tool_calls?: array<int, array{id: string, type: string, function: array{name: string, arguments: string}}>,
     *     tool_call_id?: string
     * }> $messages
     * @param array<int, array{
     *     type: string,
     *     function: array{name: string, description: string, parameters: array<string, mixed>|object}
     * }> $tools
     *
     * @return array<string, mixed>
     */
    private function buildRequestPayload(array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        // gpt-5 / o1 / o3 系は max_tokens ではなく max_completion_tokens を使用（API の破壊的変更）
        $payload['max_tokens'] = $this->maxTokens;
        // gpt-5 / o1 / o3 系は max_completion_tokens を使う（API の破壊的変更）
        $usesCompletionTokens = (bool) preg_match('/^(?:gpt-5|o1|o3)/', $this->model);
        if ($usesCompletionTokens) {
            $payload['max_completion_tokens'] = $this->maxTokens;
            unset($payload['max_tokens']);
        }

        // reasoning effort が設定されていれば付与（後段の capability チェックで必要に応じ除去）
        if (!in_array($this->reasoningEffort, [null, ''], true)) {
            $payload['reasoning_effort'] = $this->reasoningEffort;
        }

        if (empty($tools)) {
            return $payload;
        }

        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';

        // Capability Matrix: reasoning 非対応モデルでは reasoning 系キーを除去
        if (!$this->supportsReasoningWithTools()) {
            unset($payload['reasoning_effort']);
        }

        return $payload;
    }

    /**
     * 当該モデルがツール併用時に reasoning をサポートしているか判定する。
     *
     * AiModelRegistry が注入されていればそれを優先し、未注入の場合は
     * ai_models.json を直接読み込むフォールバックで判定する。
     * 未知モデルやキー欠損時は true（後方互換）を返す。
     */
    private function supportsReasoningWithTools(): bool
    {
        if ($this->cachedReasoningFlag !== null) {
            return $this->cachedReasoningFlag;
        }

        // 1. Registry 経由（DI されている場合）
        if ($this->modelRegistry !== null) {
            $result = $this->modelRegistry->supportsReasoningWithTools('openai', $this->model);
            $this->cachedReasoningFlag = $result;
            return $result;
        }

        // 2. フォールバック: ai_models.json を直接参照
        $result = $this->resolveCapabilityFromJson();
        $this->cachedReasoningFlag = $result;
        return $result;
    }

    /**
     * ai_models.json から supports_reasoning_with_tools を解決するフォールバック。
     *
     * ファイルが見つからない、JSON が壊れている、モデルが未定義の場合は true を返す。
     *
     * 将来的には AiModelRegistry 必須化し、本ファイル探索自体を削除する方針。
     * 現状は PluginData（リモート同期の正本）を最優先で探索する。
     *
     * @see \Plugin\AiChatAssistant42\Service\AiModelRegistry::resolveConfigPath()
     * @todo Registry 必須化後は本メソッドのファイル探索を削除し、Registry 委譲のみにする
     */
    private function resolveCapabilityFromJson(): bool
    {
        $configPath = $this->findCapabilityConfigPath();
        if ($configPath === null) {
            return true;
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            return true;
        }

        $decoded = json_decode($raw, true);
        $models = is_array($decoded) ? ($decoded['providers']['openai']['models'] ?? null) : null;
        if (!is_array($models)) {
            return true;
        }

        return $this->findReasoningSupportFlag($models);
    }

    /**
     * ai_models.json の候補パスのうち、存在する最初のものを返す。
     */
    private function findCapabilityConfigPath(): ?string
    {
        // 将来は AiModelRegistry 必須化し本探索を削除する方針（@todo）。
        // 現状は PluginData（リモート同期の正本）を最優先で探索する。
        // 12候補の膨張を整理し、代表6候補に集約（MN04）
        $candidates = array_unique([
            // PluginData 同期ファイル（最優先）— プロジェクトルートからの相対で探索
            dirname(__DIR__, 2) . '/app/PluginData/AiChatAssistant42/ai_models.json',
            dirname(__DIR__, 3) . '/app/PluginData/AiChatAssistant42/ai_models.json',
            dirname(__DIR__, 4) . '/app/PluginData/AiChatAssistant42/ai_models.json',
            // Resource フォールバック
            dirname(__DIR__, 2) . '/Resource/config/ai_models.json',
            dirname(__DIR__, 3) . '/Resource/config/ai_models.json',
            __DIR__ . '/../../Resource/config/ai_models.json',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * モデル定義から supports_reasoning_with_tools を読む（未定義は true）。
     *
     * @param array<mixed> $models ai_models.json の providers.openai.models
     */
    private function findReasoningSupportFlag(array $models): bool
    {
        foreach ($models as $model) {
            if (!is_array($model) || ($model['id'] ?? null) !== $this->model) {
                continue;
            }
            if (!array_key_exists('supports_reasoning_with_tools', $model)) {
                return true;
            }

            return (bool) $model['supports_reasoning_with_tools'];
        }

        // 未知モデルは制限なし
        return true;
    }

    /**
     * OpenAI API にリクエストを送信する。
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> API レスポンス（JSON パース済み）
     *
     * @throws RuntimeException API 呼び出しが失敗した場合
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
                throw new RuntimeException('OpenAI API returned non-array response');
            }

            return $decoded;
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                sprintf('OpenAI API request failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
