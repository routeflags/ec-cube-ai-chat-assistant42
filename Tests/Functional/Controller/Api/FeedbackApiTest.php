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

namespace Plugin\AiChatAssistant42\Tests\Functional\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Plugin\AiChatAssistant42\Controller\Api\ChatApiController;
use Plugin\AiChatAssistant42\Entity\Feedback;
use Plugin\AiChatAssistant42\Repository\ConfigRepository;
use Plugin\AiChatAssistant42\Repository\ProductRepository;
use Plugin\AiChatAssistant42\Service\AiAgentFactory;
use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use Plugin\AiChatAssistant42\Service\ChatFlowService;
use Plugin\AiChatAssistant42\Service\ChatLogger;
use Plugin\AiChatAssistant42\Service\EmailReplyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * フィードバック API (POST /api/ai-chat-assistant/feedback) のテスト。
 *
 * 正常系: positive/negative 保存で 200
 * 異常系: 400 (バリデーション), 409 (二重投稿)
 *
 * Functional と名乗るが AbstractWebTestCase が環境に無い場合でも
 * コントローラを直接呼ぶ Unit スタイルで検証する。
 */
class FeedbackApiTest extends TestCase
{
    private function createController(EntityManagerInterface $em, ?LoggerInterface $logger = null): ChatApiController
    {
        $aiAgentFactory = $this->createMock(AiAgentFactory::class);
        $productRepository = $this->createMock(ProductRepository::class);
        $configRepository = $this->createMock(ConfigRepository::class);
        $chatLogger = $this->createMock(ChatLogger::class);
        $aiModelRegistry = $this->createMock(AiModelRegistry::class);
        $chatFlowService = $this->createMock(ChatFlowService::class);
        $emailReplyService = $this->createMock(EmailReplyService::class);
        $logger = $logger ?? $this->createMock(LoggerInterface::class);

        return new ChatApiController(
            $aiAgentFactory,
            $productRepository,
            $configRepository,
            $chatLogger,
            $aiModelRegistry,
            $em,
            $chatFlowService,
            $emailReplyService,
            $logger
        );
    }

    private function createRequest(array $data): Request
    {
        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
        return $request;
    }

    private function createMockEntityManager(
        ?Feedback $existingFeedback = null,
        bool $throwUniqueOnFlush = false,
        bool $expectUpdate = false,
        string $expectedSid = '',
        bool $throwOnUpdate = false
    ): EntityManagerInterface {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existingFeedback);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Feedback::class)->willReturn($repo);

        if ($throwUniqueOnFlush) {
            $uniqueEx = $this->createMock(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
            $em->method('flush')->willThrowException($uniqueEx);
            $em->method('persist')->willReturnCallback(function () {});
        } else {
            $em->method('persist')->willReturnCallback(function () {});
            $em->method('flush')->willReturnCallback(function () {});
        }

        // wrapInTransaction はクロージャを実行するモック（同一トランザクション化）
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $func) {
            return $func();
        });

        // Connection mock for is_resolved update
        $conn = $this->createMock(Connection::class);
        if ($throwOnUpdate) {
            $conn->method('executeStatement')->willThrowException(new \RuntimeException('DB update failed'));
        } elseif ($expectUpdate) {
            $conn->expects($this->once())
                ->method('executeStatement')
                ->with(
                    $this->stringContains('UPDATE plg_ai_chat_assistant_log'),
                    $this->equalTo(['sid' => $expectedSid])
                )
                ->willReturn(1);
        } else {
            $conn->expects($this->never())->method('executeStatement');
        }
        $em->method('getConnection')->willReturn($conn);

        return $em;
    }

    // ================================================================
    //  正常系
    // ================================================================

    public function testFeedbackReturns200WhenPositive(): void
    {
        $em = $this->createMockEntityManager(null, false, true, 'test-session-123');
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'test-session-123', 'feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('message', $data);
    }

    public function testFeedbackReturns200WhenNegative(): void
    {
        $em = $this->createMockEntityManager(null, false, false);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'test-session-456', 'feedback' => 'negative']);
        $response = $controller->feedback($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    // ================================================================
    //  追加: Task1 ポジティブで is_resolved 更新
    // ================================================================

    public function testFeedbackPositiveMarksLogResolved(): void
    {
        $sessionId = 'sess-positive-001';
        $em = $this->createMockEntityManager(null, false, true, $sessionId);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => $sessionId, 'feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testFeedbackNegativeDoesNotMarkResolved(): void
    {
        $sessionId = 'sess-negative-001';
        $em = $this->createMockEntityManager(null, false, false, $sessionId);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => $sessionId, 'feedback' => 'negative']);
        $response = $controller->feedback($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testFeedbackDuplicateKeeps409AndResolved(): void
    {
        $existing = new Feedback();
        $existing->setSessionId('duplicate-session');
        $existing->setFeedback('positive');
        $existing->setCreatedAt(new \DateTimeImmutable());

        $em = $this->createMockEntityManager($existing, false, false);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'duplicate-session', 'feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(409, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testFeedbackPositiveStillReturns200WhenUpdateFails(): void
    {
        $sessionId = 'sess-update-fail-001';
        $em = $this->createMockEntityManager(null, false, false, $sessionId, true);
        // update が例外でも 200 を返すこと。logger に warning が出ること
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with(
                $this->stringContains('Failed to mark chat log as resolved'),
                $this->arrayHasKey('session_id')
            );

        $controller = $this->createController($em, $logger);

        $request = $this->createRequest(['session_id' => $sessionId, 'feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    // ================================================================
    //  異常系: 400
    // ================================================================

    public function testFeedbackReturns400WhenSessionIdIsEmpty(): void
    {
        $em = $this->createMockEntityManager(null);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => '', 'feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testFeedbackReturns400WhenFeedbackIsEmpty(): void
    {
        $em = $this->createMockEntityManager(null);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'test123', 'feedback' => '']);
        $response = $controller->feedback($request);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testFeedbackReturns400WhenFeedbackIsInvalid(): void
    {
        $em = $this->createMockEntityManager(null);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'test123', 'feedback' => 'invalid_value']);
        $response = $controller->feedback($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('positive', $data['error']);
    }

    public function testFeedbackReturns400WhenSessionIdMissing(): void
    {
        $em = $this->createMockEntityManager(null);
        $controller = $this->createController($em);

        $request = $this->createRequest(['feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testFeedbackReturns400WhenFeedbackMissing(): void
    {
        $em = $this->createMockEntityManager(null);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'test123']);
        $response = $controller->feedback($request);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testFeedbackReturns400WhenBodyIsNotJson(): void
    {
        $em = $this->createMockEntityManager(null);
        $controller = $this->createController($em);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $response = $controller->feedback($request);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testFeedbackReturns400WhenBodyIsEmptyArray(): void
    {
        $em = $this->createMockEntityManager(null);
        $controller = $this->createController($em);

        $request = $this->createRequest([]);
        $response = $controller->feedback($request);

        $this->assertEquals(400, $response->getStatusCode());
    }

    // ================================================================
    //  異常系: 409 二重投稿
    // ================================================================

    public function testFeedbackReturns409WhenDuplicateSession(): void
    {
        $existing = new Feedback();
        $existing->setSessionId('duplicate-session');
        $existing->setFeedback('positive');
        $existing->setCreatedAt(new \DateTimeImmutable());

        $em = $this->createMockEntityManager($existing);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'duplicate-session', 'feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(409, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('フィードバック済み', $data['error']);
    }

    public function testFeedbackReturns409OnUniqueConstraintViolationDuringFlush(): void
    {
        // findOneBy は null だが flush 時にユニーク制約違反が発生するレースケース
        $em = $this->createMockEntityManager(null, true);
        $controller = $this->createController($em);

        $request = $this->createRequest(['session_id' => 'race-session', 'feedback' => 'positive']);
        $response = $controller->feedback($request);

        $this->assertEquals(409, $response->getStatusCode());
    }
}
