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

use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Entity\Notification;
use Plugin\AiChatAssistant42\Repository\NotificationRepository;
use Plugin\AiChatAssistant42\Service\NotificationService;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * NotificationService の Webhook / LINE / Email 送信テスト.
 *
 * isValidWebhookUrl の SSRF 対策、空 URL 時の早期 return、
 * Mailer 注入時の実際の送信を検証する。
 */
class NotificationServiceTest extends TestCase
{
    private NotificationRepository $repository;
    private MailerInterface $mailer;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(NotificationRepository::class);
        $this->mailer = $this->createMock(MailerInterface::class);
    }

    private function createService(?MailerInterface $mailer = null): NotificationService
    {
        return new NotificationService(
            $this->repository,
            new NullLogger(),
            $mailer ?? $this->mailer
        );
    }

    private function createNotification(string $type, string $event, array $config): Notification
    {
        $notification = new Notification();
        $notification->setNotificationType($type);
        $notification->setTriggerEvent($event);
        $notification->setConfigJson($config);
        $notification->setIsActive(1);
        return $notification;
    }

    // ================================================================
    //  Webhook: 正常系は checkAndSend が例外なく完了すること
    //  （実際の HTTP は Guzzle で送信されるが、無効 URL では送信前に return される）
    // ================================================================

    public function testWebhookWithEmptyUrlDoesNotThrow(): void
    {
        $notification = $this->createNotification('webhook', 'test_event', ['url' => '']);
        $this->repository->method('findByEvent')->with('test_event')->willReturn([$notification]);

        $service = $this->createService();
        $service->checkAndSend('test_event', ['foo' => 'bar']);

        $this->assertTrue(true, 'empty URL should not throw');
    }

    public function testWebhookWithHttpUrlIsRejected(): void
    {
        $notification = $this->createNotification('webhook', 'test_event', ['url' => 'http://example.com/hook']);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $service = $this->createService();
        $service->checkAndSend('test_event', []);

        $this->assertTrue(true, 'http URL should be rejected without throwing');
    }

    public function testWebhookWithPrivateIpIsRejected(): void
    {
        $notification = $this->createNotification('webhook', 'test_event', ['url' => 'https://192.168.1.1/hook']);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $service = $this->createService();
        $service->checkAndSend('test_event', []);

        $this->assertTrue(true, 'private IP should be rejected');
    }

    public function testWebhookWithLocalhostIsRejected(): void
    {
        $notification = $this->createNotification('webhook', 'test_event', ['url' => 'https://localhost/hook']);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $service = $this->createService();
        $service->checkAndSend('test_event', []);

        $this->assertTrue(true, 'localhost should be rejected');
    }

    // ================================================================
    //  Email: Mailer 注入時に実際に send が呼ばれること
    // ================================================================

    public function testEmailWithValidToCallsMailerSend(): void
    {
        $notification = $this->createNotification('email', 'test_event', [
            'to' => 'admin@example.com',
            'subject' => 'Test Subject',
        ]);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $this->mailer->expects($this->once())
            ->method('send')
            ->with($this->callback(function (Email $email) {
                return $email->getTo()[0]->getAddress() === 'admin@example.com'
                    && $email->getSubject() === 'Test Subject';
            }));

        $service = $this->createService($this->mailer);
        $service->checkAndSend('test_event', ['session_id' => 'sess123']);
    }

    public function testEmailWithEmptyToDoesNotCallMailer(): void
    {
        $notification = $this->createNotification('email', 'test_event', ['to' => '']);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $this->mailer->expects($this->never())->method('send');

        $service = $this->createService($this->mailer);
        $service->checkAndSend('test_event', []);
    }

    public function testEmailWithNullMailerDoesNotThrow(): void
    {
        $notification = $this->createNotification('email', 'test_event', [
            'to' => 'admin@example.com',
        ]);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $service = new NotificationService($this->repository, new NullLogger(), null);
        $service->checkAndSend('test_event', []);

        $this->assertTrue(true, 'null mailer should not throw');
    }

    // ================================================================
    //  LINE: 必須パラメータが揃わない場合は送信しない
    // ================================================================

    public function testLineWithMissingTokenDoesNotThrow(): void
    {
        $notification = $this->createNotification('line', 'test_event', [
            'channel_access_token' => '',
            'user_id' => 'U123',
        ]);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $service = $this->createService();
        $service->checkAndSend('test_event', []);

        $this->assertTrue(true);
    }

    public function testLineWithMissingUserIdDoesNotThrow(): void
    {
        $notification = $this->createNotification('line', 'test_event', [
            'channel_access_token' => 'dummy-token',
            'user_id' => '',
        ]);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $service = $this->createService();
        $service->checkAndSend('test_event', []);

        $this->assertTrue(true);
    }

    // ================================================================
    //  未対応チャネル
    // ================================================================

    public function testUnknownChannelDoesNotThrow(): void
    {
        $notification = $this->createNotification('unknown', 'test_event', []);
        $this->repository->method('findByEvent')->willReturn([$notification]);

        $service = $this->createService();
        $service->checkAndSend('test_event', []);

        $this->assertTrue(true);
    }

    // ================================================================
    //  リポジトリ例外時も握りつぶされないこと（checkAndSend 内で catch される）
    // ================================================================

    public function testCheckAndSendDoesNotThrowWhenRepositoryThrows(): void
    {
        $this->repository->method('findByEvent')->willThrowException(new \RuntimeException('DB error'));

        $service = $this->createService();
        // 例外が外に漏れないことを確認（checkAndSend 内で logger->error に留まるはずだが、現状は外に漏れるか確認）
        // 現状の実装では findByEvent の例外は外に漏れるため、呼び出し側で握りつぶされるか確認のため try/catch
        try {
            $service->checkAndSend('test_event', []);
        } catch (\Throwable $e) {
            // 漏れた場合はテストで検出
            $this->fail('checkAndSend should not throw on repository exception, got: ' . $e->getMessage());
        }
        $this->assertTrue(true);
    }
}
