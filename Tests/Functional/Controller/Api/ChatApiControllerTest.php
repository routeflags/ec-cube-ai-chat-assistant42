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

use Eccube\Tests\Web\AbstractWebTestCase;

/**
 * ChatApiController の機能テスト。
 *
 * チャット API のエンドポイント（/chat, /email-reply-request）の
 * HTTP レスポンス・認証・バリデーションを検証する。
 *
 * 注意: このテストは EC-CUBE のフルスタック環境（DB + DI）を前提とする。
 *       プラグインが有効化されていない場合、403 が返ることを確認するテストは
 *       プラグイン無効状態で実行する。
 */
class ChatApiControllerTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    // ================================================================
    //  POST /api/ai-chat-assistant/chat
    // ================================================================

    public function testChatEndpointReturns400WhenMessageIsEmpty(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_chat'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['message' => ''])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testChatEndpointReturns400WhenMessageFieldIsMissing(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_chat'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['wrong_field' => 'hello'])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testChatEndpointReturns400WhenBodyIsNotJson(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_chat'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'not json at all'
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    // ================================================================
    //  POST /api/ai-chat-assistant/chat — プラグイン無効
    // ================================================================

    public function testChatEndpointReturns403WhenPluginIsDisabled(): void
    {
        // プラグイン設定が無効（is_enabled = 0）の場合、403 を返すことを確認
        // 実際の DB 状態に依存するが、デフォルト設定では無効
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_chat'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'message' => 'こんにちは',
                'session_id' => 'test-session-functional',
            ])
        );

        $response = $this->client->getResponse();
        $content = json_decode($response->getContent(), true);

        // プラグインが無効なら 403、有効なら 200 or 400（API キー未設定）
        if ($response->getStatusCode() === 403) {
            $this->assertFalse($content['success']);
            $this->assertStringContainsString('無効', $content['error']);
        } else {
            // プラグインが有効な場合は、API キー未設定で 400 が返る
            $this->assertContains($response->getStatusCode(), [400, 429, 500]);
        }
    }

    // ================================================================
    //  POST /api/ai-chat-assistant/email-reply-request
    // ================================================================

    public function testEmailReplyRequestReturns400WhenSessionIdIsEmpty(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'session_id' => '',
                'email' => 'user@example.com',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testEmailReplyRequestReturns400WhenEmailIsEmpty(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'session_id' => 'test-session-123',
                'email' => '',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testEmailReplyRequestReturns400WhenEmailIsInvalid(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'session_id' => 'test-session-123',
                'email' => 'not-an-email',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testEmailReplyRequestReturns400WhenSessionIdIsMissing(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'user@example.com',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testEmailReplyRequestReturns400WhenEmailFieldIsMissing(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'session_id' => 'test-session-123',
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testEmailReplyRequestReturns400WhenBodyIsEmpty(): void
    {
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testEmailReplyRequestReturns400WhenEmailContainsNewline(): void
    {
        // ヘッダーインジェクション: 改行を含むメールは 400 で拒否されること
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'session_id' => 'test-session-123',
                'email' => "a@example.com\nBcc:evil",
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    // ================================================================
    //  POST /api/ai-chat-assistant/email-reply-request — 正常系 + DB 検証
    // ================================================================

    /**
     * 正常系: DB にチャットログが存在する場合、メール依頼が成功し DB が更新されること.
     *
     * MailerInterface の送信回数は Unit 責務とし、Functional では DB と HTTP のみ検証する。
     * Mailer の実送信は null 輸送（MAILER_DSN=null://null）で問題なし。
     * DAMA DoctrineTestBundle によりトランザクション分離される。
     */
    public function testEmailReplyRequestSucceedsAndUpdatesDb(): void
    {
        $conn = $this->entityManager->getConnection();
        $sessionId = 'review-test-123';
        // 事前にチャットログを1行 INSERT
        $conn->executeStatement(
            "INSERT INTO plg_ai_chat_assistant_log (session_id, provider, model, user_message, assistant_reply, created_at) VALUES (:sid, 'openai','gpt-4o','hi','hello', NOW())",
            ['sid' => $sessionId]
        );

        // 1回目は 200
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['session_id' => $sessionId, 'email' => 'mail@webns.info'])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        // DB が更新されていること — EntityManager の getConnection() を使用
        $addr = $conn->fetchOne(
            'SELECT email_reply_address FROM plg_ai_chat_assistant_log WHERE session_id=:sid ORDER BY id DESC LIMIT 1',
            ['sid' => $sessionId]
        );
        $this->assertEquals('mail@webns.info', $addr);

        // 2回目は二重送信防止で 404
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['session_id' => $sessionId, 'email' => 'mail@webns.info'])
        );

        $secondResponse = $this->client->getResponse();
        $this->assertEquals(404, $secondResponse->getStatusCode());
        $secondData = json_decode($secondResponse->getContent(), true);
        $this->assertFalse($secondData['success']);
    }

    public function testEmailReplyRequestReturns404WhenSessionNotFound(): void
    {
        // 存在しない session_id では 404 を返すこと
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_email_reply_request'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['session_id' => 'non-existent-session-xyz', 'email' => 'user@example.com'])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    // ================================================================
    //  POST /api/ai-chat-assistant/feedback — Task1 ポジティブ反映
    // ================================================================

    /**
     * positive フィードバックで同一 session_id の ChatLog が is_resolved=1 に更新されること。
     * DAMA によりトランザクション分離。複数行ある場合は全行が更新されることを検証する。
     */
    public function testFeedbackPositiveMarksLogResolved(): void
    {
        $conn = $this->entityManager->getConnection();
        $sessionId = 'sess-feedback-positive-' . bin2hex(random_bytes(4));

        // 事前に同一セッションで2行INSERT（is_resolved=0）
        $conn->executeStatement(
            "INSERT INTO plg_ai_chat_assistant_log (session_id, provider, model, user_message, assistant_reply, is_resolved, created_at) VALUES (:sid, 'openai','gpt-4o','hi','hello', 0, NOW())",
            ['sid' => $sessionId]
        );
        $conn->executeStatement(
            "INSERT INTO plg_ai_chat_assistant_log (session_id, provider, model, user_message, assistant_reply, is_resolved, created_at) VALUES (:sid, 'openai','gpt-4o','hi2','hello2', 0, NOW())",
            ['sid' => $sessionId]
        );

        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_feedback'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['session_id' => $sessionId, 'feedback' => 'positive'])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        // 全行が is_resolved=1 になっていること（冪等: 2回目は0行でも成功扱いだが、ここでは1回目で全行1）
        $unresolvedCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM plg_ai_chat_assistant_log WHERE session_id = :sid AND is_resolved = 0',
            ['sid' => $sessionId]
        );
        $this->assertEquals(0, $unresolvedCount, 'positive 後に未解決行が0であること');

        $resolvedCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM plg_ai_chat_assistant_log WHERE session_id = :sid AND is_resolved = 1',
            ['sid' => $sessionId]
        );
        $this->assertEquals(2, $resolvedCount);

        // feedback 行が1行存在すること
        $fbCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM plg_ai_chat_assistant_feedback WHERE session_id = :sid AND feedback = :fb',
            ['sid' => $sessionId, 'fb' => 'positive']
        );
        $this->assertEquals(1, $fbCount);
    }

    /**
     * negative フィードバックでは is_resolved を更新しないこと。
     */
    public function testFeedbackNegativeDoesNotMarkResolved(): void
    {
        $conn = $this->entityManager->getConnection();
        $sessionId = 'sess-feedback-negative-' . bin2hex(random_bytes(4));

        $conn->executeStatement(
            "INSERT INTO plg_ai_chat_assistant_log (session_id, provider, model, user_message, assistant_reply, is_resolved, created_at) VALUES (:sid, 'openai','gpt-4o','hi','hello', 0, NOW())",
            ['sid' => $sessionId]
        );

        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_feedback'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['session_id' => $sessionId, 'feedback' => 'negative'])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);

        $isResolved = (int) $conn->fetchOne(
            'SELECT is_resolved FROM plg_ai_chat_assistant_log WHERE session_id = :sid LIMIT 1',
            ['sid' => $sessionId]
        );
        $this->assertEquals(0, $isResolved, 'negative では is_resolved が0のままであること');
    }

    /**
     * 同一 session_id の2回目 positive は409を返しつつ、is_resolved は1のまま維持されること（冪等）。
     */
    public function testFeedbackDuplicateKeeps409AndResolved(): void
    {
        $conn = $this->entityManager->getConnection();
        $sessionId = 'sess-feedback-dup-' . bin2hex(random_bytes(4));

        $conn->executeStatement(
            "INSERT INTO plg_ai_chat_assistant_log (session_id, provider, model, user_message, assistant_reply, is_resolved, created_at) VALUES (:sid, 'openai','gpt-4o','hi','hello', 0, NOW())",
            ['sid' => $sessionId]
        );

        // 1回目 positive -> 200
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_feedback'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['session_id' => $sessionId, 'feedback' => 'positive'])
        );
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());

        // 2回目 positive (duplicate) -> 409
        $this->client->request(
            'POST',
            $this->generateUrl('ai_chat_assistant_api_feedback'),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['session_id' => $sessionId, 'feedback' => 'positive'])
        );
        $second = $this->client->getResponse();
        $this->assertEquals(409, $second->getStatusCode());
        $secondData = json_decode($second->getContent(), true);
        $this->assertFalse($secondData['success']);

        // is_resolved は1のまま（2回目はUPDATE 0行でも成功扱い）
        $isResolved = (int) $conn->fetchOne(
            'SELECT is_resolved FROM plg_ai_chat_assistant_log WHERE session_id = :sid LIMIT 1',
            ['sid' => $sessionId]
        );
        $this->assertEquals(1, $isResolved);
    }
}
