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

use Plugin\AiChatAssistant42\Service\AiAgent\AnthropicAgent;
use Plugin\AiChatAssistant42\Service\AiAgent\GeminiAgent;
use Plugin\AiChatAssistant42\Service\AiAgent\OpenAiAgent;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * AI エージェントのファクトリ。
 *
 * プロバイダ名から対応する AiAgentInterface 実装を生成する。
 * チャットアシスタントはこのファクトリ経由でプロバイダを生成し、
 * プロバイダ固有の実装を意識せずに利用できる。
 */
class AiAgentFactory
{
    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    /**
     * 指定プロバイダの AI エージェントを生成する。
     *
     * @param string $provider   プロバイダキー（'openai' | 'anthropic' | 'gemini'）
     * @param string $apiKey     API キー
     * @param string $model      モデル ID
     * @param int    $maxTokens  最大出力トークン数
     *
     * @throws InvalidArgumentException サポートされていないプロバイダが指定された場合
     */
    public function create(
        string $provider,
        string $apiKey,
        string $model,
        int $maxTokens = 4096,
        string $systemPrompt = ''
    ): AiAgentInterface {
        return match ($provider) {
            'openai' => new OpenAiAgent(
                $apiKey,
                $model,
                $maxTokens,
                $systemPrompt,
                'https://api.openai.com/v1',
                null,
                null,
                $this->logger
            ),
            'anthropic' => new AnthropicAgent(
                $apiKey,
                $model,
                $maxTokens,
                $systemPrompt,
                'https://api.anthropic.com/v1',
                $this->logger
            ),
            'gemini' => new GeminiAgent(
                $apiKey,
                $model,
                $maxTokens,
                $systemPrompt,
                'https://generativelanguage.googleapis.com/v1beta',
                $this->logger
            ),
            default => throw new InvalidArgumentException(
                sprintf('Unknown AI provider: %s', $provider)
            ),
        };
    }
}
