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

namespace Plugin\AiChatAssistant42\Service;

/**
 * AI プロバイダ共通のエージェントインターフェース。
 *
 * OpenAI / Anthropic / Gemini など、各プロバイダの実装がこのインターフェースを満たすことで、
 * チャットアシスタント側からプロバイダを意識せずに利用できる。
 */
interface AiAgentInterface
{
    /**
     * チャットメッセージを処理し、AI からの応答を返す。
     *
     * ツール呼び出し（function calling / tool use）が発生した場合は、
     * 実装側でループ処理を行い、最終的なテキスト応答を返す。
     *
     * @param string   $message      ユーザー入力メッセージ
     * @param array    $tools        ツール定義配列（MCP 形式）
     * @param callable $toolExecutor ツール実行クロージャ fn(string $name, array $args): array
     * @param array    $history      過去の会話履歴 [{role: 'user'|'assistant', content: string}, ...]
     *
     * @return array{reply: string, tools_used: string[], token_input: int|null, token_output: int|null}
     */
    public function chat(
        string $message,
        array $tools,
        callable $toolExecutor,
        array $history = []
    ): array;
}
