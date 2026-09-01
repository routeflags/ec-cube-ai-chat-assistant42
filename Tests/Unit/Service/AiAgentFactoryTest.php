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

namespace Plugin\AiChatAssistant42\Tests\Unit\Service;

use Plugin\AiChatAssistant42\Service\AiAgent\AnthropicAgent;
use Plugin\AiChatAssistant42\Service\AiAgent\GeminiAgent;
use Plugin\AiChatAssistant42\Service\AiAgent\OpenAiAgent;
use Plugin\AiChatAssistant42\Service\AiAgentFactory;
use Plugin\AiChatAssistant42\Service\AiAgentInterface;
use PHPUnit\Framework\TestCase;

/**
 * AiAgentFactory の単体テスト。
 *
 * プロバイダ別エージェント生成と不正プロバイダへのエラー確認を検証する。
 */
class AiAgentFactoryTest extends TestCase
{
    private AiAgentFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AiAgentFactory();
    }

    // ================================================================
    //  create — 正常系
    // ================================================================

    public function testCreateReturnsOpenAiAgent(): void
    {
        $agent = $this->factory->create('openai', 'test-key', 'gpt-4o');

        $this->assertInstanceOf(OpenAiAgent::class, $agent);
        $this->assertInstanceOf(AiAgentInterface::class, $agent);
    }

    public function testCreateReturnsAnthropicAgent(): void
    {
        $agent = $this->factory->create('anthropic', 'test-key', 'claude-sonnet-4-20250514');

        $this->assertInstanceOf(AnthropicAgent::class, $agent);
        $this->assertInstanceOf(AiAgentInterface::class, $agent);
    }

    public function testCreateReturnsGeminiAgent(): void
    {
        $agent = $this->factory->create('gemini', 'test-key', 'gemini-2.5-flash');

        $this->assertInstanceOf(GeminiAgent::class, $agent);
        $this->assertInstanceOf(AiAgentInterface::class, $agent);
    }

    // ================================================================
    //  create — 異常系
    // ================================================================

    public function testCreateThrowsInvalidArgumentExceptionForUnknownProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown AI provider: unknown_provider');

        $this->factory->create('unknown_provider', 'test-key', 'some-model');
    }

    public function testCreateThrowsInvalidArgumentExceptionForEmptyProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory->create('', 'test-key', 'some-model');
    }

    // ================================================================
    //  create — maxTokens パラメータ
    // ================================================================

    public function testCreateAcceptsCustomMaxTokens(): void
    {
        // デフォルト値 (4096) とカスタム値の両方が例外なく通ることを確認
        $agentDefault = $this->factory->create('openai', 'key', 'gpt-4o');
        $this->assertInstanceOf(AiAgentInterface::class, $agentDefault);

        $agentCustom = $this->factory->create('openai', 'key', 'gpt-4o', 8192);
        $this->assertInstanceOf(AiAgentInterface::class, $agentCustom);
    }
}
