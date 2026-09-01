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

use Doctrine\ORM\EntityManagerInterface;
use Plugin\AiChatAssistant42\Entity\ChatLog;
use Plugin\AiChatAssistant42\Service\ChatLogger;
use PHPUnit\Framework\TestCase;

/**
 * ChatLogger の単体テスト。
 *
 * チャットログの永続化（persist + flush）が正しく行われることを検証する。
 */
class ChatLoggerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private ChatLogger $chatLogger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->chatLogger = new ChatLogger($this->entityManager);
    }

    // ================================================================
    //  log — 基本的な永続化
    // ================================================================

    public function testLogPersistsChatLogEntity(): void
    {
        // persist と flush がそれぞれ1回呼ばれることを期待
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->chatLogger->log([
            'session_id' => 'test-session-001',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'user_message' => 'こんにちは',
            'assistant_reply' => 'こんにちは！何かお手伝いできますか？',
        ]);
    }

    // ================================================================
    //  log — オプションフィールド付き
    // ================================================================

    public function testLogIncludesOptionalFieldsWhenProvided(): void
    {
        $persistedEntity = null;

        $this->entityManager->method('persist')->willReturnCallback(
            function (ChatLog $entity) use (&$persistedEntity): void {
                $persistedEntity = $entity;
            }
        );
        $this->entityManager->method('flush');

        $this->chatLogger->log([
            'session_id' => 'test-session-002',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-20250514',
            'user_message' => '商品を検索して',
            'assistant_reply' => '検索結果をお示しします。',
            'tools_used' => ['search_products'],
            'response_time_ms' => 1500,
            'token_input' => 100,
            'token_output' => 200,
            'error_message' => null,
            'product_id' => 42,
            'order_id' => null,
        ]);

        $this->assertNotNull($persistedEntity);
        $this->assertEquals('test-session-002', $persistedEntity->getSessionId());
        $this->assertEquals('anthropic', $persistedEntity->getProvider());
        $this->assertEquals('claude-sonnet-4-20250514', $persistedEntity->getModel());
        $this->assertEquals('商品を検索して', $persistedEntity->getUserMessage());
        $this->assertEquals('検索結果をお示しします。', $persistedEntity->getAssistantReply());
        $this->assertEquals(['search_products'], $persistedEntity->getToolsUsed());
        $this->assertEquals(1500, $persistedEntity->getResponseTimeMs());
        $this->assertEquals(100, $persistedEntity->getTokenInput());
        $this->assertEquals(200, $persistedEntity->getTokenOutput());
        $this->assertEquals(42, $persistedEntity->getProductId());
        $this->assertNull($persistedEntity->getOrderId());
    }

    // ================================================================
    //  log — エラーログ記録
    // ================================================================

    public function testLogRecordsErrorMessageWhenPresent(): void
    {
        $persistedEntity = null;

        $this->entityManager->method('persist')->willReturnCallback(
            function (ChatLog $entity) use (&$persistedEntity): void {
                $persistedEntity = $entity;
            }
        );
        $this->entityManager->method('flush');

        $this->chatLogger->log([
            'session_id' => 'test-session-003',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'user_message' => 'テスト',
            'assistant_reply' => '',
            'error_message' => 'API タイムアウト',
        ]);

        $this->assertNotNull($persistedEntity);
        $this->assertEquals('API タイムアウト', $persistedEntity->getErrorMessage());
        $this->assertEquals('', $persistedEntity->getAssistantReply());
    }

    // ================================================================
    //  log — created_at が自動設定されること
    // ================================================================

    public function testLogSetsCreatedAtTimestamp(): void
    {
        $persistedEntity = null;

        $this->entityManager->method('persist')->willReturnCallback(
            function (ChatLog $entity) use (&$persistedEntity): void {
                $persistedEntity = $entity;
            }
        );
        $this->entityManager->method('flush');

        $before = new \DateTimeImmutable();

        $this->chatLogger->log([
            'session_id' => 'test-session-004',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'user_message' => '時刻テスト',
            'assistant_reply' => 'OK',
        ]);

        $after = new \DateTimeImmutable();

        $this->assertNotNull($persistedEntity->getCreatedAt());
        $this->assertGreaterThanOrEqual(
            $before->getTimestamp(),
            $persistedEntity->getCreatedAt()->getTimestamp()
        );
        $this->assertLessThanOrEqual(
            $after->getTimestamp(),
            $persistedEntity->getCreatedAt()->getTimestamp()
        );
    }

    // ================================================================
    //  log — オプション未指定時のデフォルト値
    // ================================================================

    public function testLogHandlesMissingOptionalFieldsGracefully(): void
    {
        $persistedEntity = null;

        $this->entityManager->method('persist')->willReturnCallback(
            function (ChatLog $entity) use (&$persistedEntity): void {
                $persistedEntity = $entity;
            }
        );
        $this->entityManager->method('flush');

        $this->chatLogger->log([
            'session_id' => 'minimal-session',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'user_message' => '最小限テスト',
            'assistant_reply' => 'OK',
        ]);

        $this->assertNotNull($persistedEntity);
        $this->assertNull($persistedEntity->getToolsUsed());
        $this->assertNull($persistedEntity->getResponseTimeMs());
        $this->assertNull($persistedEntity->getTokenInput());
        $this->assertNull($persistedEntity->getTokenOutput());
        $this->assertNull($persistedEntity->getErrorMessage());
        $this->assertNull($persistedEntity->getProductId());
        $this->assertNull($persistedEntity->getOrderId());
    }
}
