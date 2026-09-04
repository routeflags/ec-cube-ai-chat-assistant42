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

use Eccube\Entity\BaseInfo;
use Eccube\Repository\BaseInfoRepository;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Repository\ChatLogRepository;
use Plugin\AiChatAssistant42\Service\ChatLogger;
use Plugin\AiChatAssistant42\Service\EmailHashService;
use Plugin\AiChatAssistant42\Service\EmailReplyService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * EmailReplyService の単体テスト.
 *
 * 2通送信、RFC 対応、フォールバック、履歴なし、例外握りつぶしを検証する。
 */
class EmailReplyServiceTest extends TestCase
{
    private MailerInterface $mailer;
    private BaseInfoRepository $baseInfoRepository;
    private ChatLogger $chatLogger;
    private LoggerInterface $logger;
    private EmailReplyService $service;

    /** @var Email[] */
    private array $sentEmails = [];

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->baseInfoRepository = $this->createMock(BaseInfoRepository::class);
        $this->chatLogger = $this->createMock(ChatLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->sentEmails = [];
        $this->mailer->method('send')->willReturnCallback(function (Email $email): void {
            $this->sentEmails[] = $email;
        });

        $this->service = new EmailReplyService(
            $this->mailer,
            $this->baseInfoRepository,
            $this->chatLogger,
            $this->logger,
        );
    }

    // ================================================================
    //  正常系: 2通送信
    // ================================================================

    public function testSendBothSendsTwoEmails(): void
    {
        $sessionId = '7033a68d-ba9b-4c6d-aed9-aafa50e621c5';
        $userEmail = 'user@example.com';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')
            ->with($sessionId, 10)
            ->willReturn([
                ['role' => 'user', 'content' => 'お勧めのCBDは？'],
                ['role' => 'assistant', 'content' => '以下は...'],
            ]);

        $this->service->sendBoth($sessionId, $userEmail);

        $this->assertCount(2, $this->sentEmails, '2通送信されること');

        // ユーザー宛
        $userMail = $this->sentEmails[0];
        $this->assertEquals([$userEmail], $this->extractToAddresses($userMail));
        $this->assertStringContainsString($sessionId, $userMail->getSubject());
        $this->assertStringContainsString('お問い合わせを承りました', $userMail->getSubject());
        $this->assertStringContainsString($sessionId, $userMail->getTextBody());
        $this->assertStringContainsString('お勧めのCBDは？', $userMail->getTextBody());
        $this->assertCount(1, $userMail->getReplyTo());
        $this->assertEquals('support@example.com', $userMail->getReplyTo()[0]->getAddress());

        // From は email03
        $this->assertEquals('noreply@example.com', $userMail->getFrom()[0]->getAddress());

        // 管理者宛
        $adminMail = $this->sentEmails[1];
        $this->assertEquals(['support@example.com'], $this->extractToAddresses($adminMail));
        $this->assertStringContainsString($userEmail, $adminMail->getSubject());
        $this->assertStringContainsString($sessionId, $adminMail->getSubject());
        $this->assertStringContainsString('[要対応]', $adminMail->getSubject());
        $this->assertCount(1, $adminMail->getReplyTo());
        $this->assertEquals($userEmail, $adminMail->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('お勧めのCBDは？', $adminMail->getTextBody());
    }

    // ================================================================
    //  RFC ケース
    // ================================================================

    public function testSendBothDoesNotThrowWithPlusAddress(): void
    {
        $sessionId = 'rfc-plus-test';
        $userEmail = 'user+tag@example.com';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        // 例外が投げられないこと（握りつぶし or 正常送信）
        try {
            $this->service->sendBoth($sessionId, $userEmail);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('user+tag@example.com で例外が投げられてはならない: ' . $e->getMessage());
        }

        // plus アドレスは RFC 準拠なので toAddress は例外を投げず、送信が試行される
        $this->assertGreaterThanOrEqual(1, count($this->sentEmails));
    }

    public function testSendBothDoesNotThrowWithQuotedLocalPart(): void
    {
        $sessionId = 'rfc-quote-test';
        // ダブルクォートを含む local part（RFC 違反だがクォートで救済）
        $userEmail = 'a"b@example.com';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        try {
            $this->service->sendBoth($sessionId, $userEmail);
        } catch (\Throwable $e) {
            $this->fail('a"b@example.com で例外が伝播してはならない: ' . $e->getMessage());
        }

        // RFC クォート対応により 2通とも送信されること
        $this->assertCount(2, $this->sentEmails, 'クォート対応でも2通送信されること');
        // To がクォートされた形式であること
        $userToAddress = $this->sentEmails[0]->getTo()[0]->getAddress();
        $this->assertStringContainsString('@example.com', $userToAddress);
        $this->assertStringContainsString('\"', $userToAddress, 'ダブルクォートがエスケープされていること');
        // エスケープ後の完全形を検証
        $this->assertEquals('"a\"b"@example.com', $userToAddress);
    }

    public function testToAddressHandlesPlusAndQuote(): void
    {
        // user+tag は valid なのでそのまま
        $address = $this->service->toAddress('user+tag@example.com');
        $this->assertEquals('user+tag@example.com', $address->getAddress());

        // a"b はクォートされて Address 生成されること
        $address2 = $this->service->toAddress('a"b@example.com');
        // クォート後は "@example.com" を含み、エスケープが正しいこと
        $this->assertEquals('"a\"b"@example.com', $address2->getAddress());
        $this->assertStringContainsString('@example.com', $address2->getAddress());
    }

    public function testSendBothRejectsHeaderInjection(): void
    {
        // FILTER_VALIDATE_EMAIL は改行混入を false と判定すること
        $this->assertFalse(filter_var("a@test.com\r\nBcc:evil", FILTER_VALIDATE_EMAIL));
        $this->assertFalse(filter_var("a@example.com\nBcc:evil", FILTER_VALIDATE_EMAIL));

        // Service の toAddress は改行混入で InvalidArgumentException を投げること
        $this->expectException(\InvalidArgumentException::class);
        $this->service->toAddress("a@test.com\r\nBcc:evil@example.com");
    }

    public function testSendBothDoesNotThrowWithHeaderInjectionViaWarning(): void
    {
        // sendBoth 経由では InvalidArgumentException が warning で握りつぶされること
        $sessionId = 'header-injection-test';
        $userEmail = "a@test.com\r\nBcc:evil@example.com";
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        // sendBoth は例外を外に伝播しない
        try {
            $this->service->sendBoth($sessionId, $userEmail);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('ヘッダーインジェクションでも例外が伝播してはならない: ' . $e->getMessage());
        }
    }

    // ================================================================
    //  フォールバック
    // ================================================================

    public function testSendBothSkipsAdminWhenEmail02AndEmail01Empty(): void
    {
        $sessionId = 'fallback-test';
        $userEmail = 'user@example.com';
        // email02 と email01 が空
        $baseInfo = $this->createBaseInfo('', '', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->service->sendBoth($sessionId, $userEmail);

        // 管理者宛はスキップされ、ユーザー宛のみ 1通
        $this->assertCount(1, $this->sentEmails);
        $this->assertEquals([$userEmail], $this->extractToAddresses($this->sentEmails[0]));
    }

    public function testFromFallsBackToEmail01WhenEmail03Empty(): void
    {
        $sessionId = 'from-fallback-test';
        $userEmail = 'user@example.com';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', '', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $this->service->sendBoth($sessionId, $userEmail);

        $this->assertCount(2, $this->sentEmails);
        // From は email01 にフォールバック
        $this->assertEquals('shop@example.com', $this->sentEmails[0]->getFrom()[0]->getAddress());
        $this->assertEquals('shop@example.com', $this->sentEmails[1]->getFrom()[0]->getAddress());
    }

    public function testFromFallsBackToNoReplyWhenBothEmpty(): void
    {
        $sessionId = 'from-fallback-noreply';
        $userEmail = 'user@example.com';
        $baseInfo = $this->createBaseInfo('', 'support@example.com', '', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $this->service->sendBoth($sessionId, $userEmail);

        $this->assertCount(2, $this->sentEmails);
        $this->assertEquals('no-reply@example.com', $this->sentEmails[0]->getFrom()[0]->getAddress());
    }

    // ================================================================
    //  履歴0件
    // ================================================================

    public function testSendBothWithEmptyHistoryContainsNoHistoryText(): void
    {
        $sessionId = 'empty-history';
        $userEmail = 'user@example.com';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $this->service->sendBoth($sessionId, $userEmail);

        $this->assertCount(2, $this->sentEmails);
        $this->assertStringContainsString('履歴なし', $this->sentEmails[0]->getTextBody());
        $this->assertStringContainsString('履歴なし', $this->sentEmails[1]->getTextBody());
    }

    // ================================================================
    //  異常系: TransportException は warning で握りつぶし
    // ================================================================

    public function testSendBothContinuesOnTransportException(): void
    {
        $sessionId = 'transport-fail';
        $userEmail = 'user@example.com';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([
            ['role' => 'user', 'content' => 'test'],
        ]);

        // 1通目は TransportException、2通目は成功するモック
        $transportException = $this->createMock(TransportExceptionInterface::class);
        $callCount = 0;
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->mailer->expects($this->exactly(2))->method('send')->willReturnCallback(function () use (&$callCount, $transportException): void {
            $callCount++;
            if ($callCount === 1) {
                throw $transportException;
            }
        });

        $this->service = new EmailReplyService(
            $this->mailer,
            $this->baseInfoRepository,
            $this->chatLogger,
            $this->logger,
        );

        $this->logger->expects($this->once())->method('warning');

        // 例外は外に伝播しない
        try {
            $this->service->sendBoth($sessionId, $userEmail);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('TransportException は握りつぶされるべき: ' . $e->getMessage());
        }

        $this->assertEquals(2, $callCount, '2通目は試行されること');
    }

    public function testSendBothCatchesInvalidArgumentException(): void
    {
        $sessionId = 'invalid-arg-test';
        // 不正なメールを Reply-To に使うことで InvalidArgumentException を誘発しても握りつぶされる
        // ここでは mailer が InvalidArgumentException を投げるケースをシミュレート
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $this->mailer = $this->createMock(MailerInterface::class);
        $this->mailer->method('send')->willThrowException(new \InvalidArgumentException('Invalid address'));

        $this->service = new EmailReplyService(
            $this->mailer,
            $this->baseInfoRepository,
            $this->chatLogger,
            $this->logger,
        );

        $this->logger->expects($this->exactly(2))->method('warning');

        try {
            $this->service->sendBoth($sessionId, 'user@example.com');
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('InvalidArgumentException は握りつぶされるべき: ' . $e->getMessage());
        }
    }

    // ================================================================
    //  I-30: EmailHashService 経由の復号経路
    // ================================================================

    public function testSendBothUsesDecryptedEmailWhenHashServicePresent(): void
    {
        $sessionId = 'hash-decrypt-test';
        $fallbackEmail = 'fallback@example.com';
        $decryptedEmail = 'decrypted@example.com';
        $encValue = 'enc_dummy_value_not_email';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $chatLogRepo = $this->createMock(ChatLogRepository::class);
        $chatLogRepo->method('fetchLatestEmailEnc')
            ->with($sessionId)
            ->willReturn($encValue);

        $hashService = $this->createMock(EmailHashService::class);
        $hashService->method('decrypt')
            ->with($encValue)
            ->willReturn($decryptedEmail);

        $this->service = new EmailReplyService(
            $this->mailer,
            $this->baseInfoRepository,
            $this->chatLogger,
            $this->logger,
            null,
            $hashService,
            $chatLogRepo,
        );

        $this->service->sendBoth($sessionId, $fallbackEmail);

        $this->assertCount(2, $this->sentEmails);
        // 復号されたメールが To / ReplyTo に使われること
        $this->assertEquals([$decryptedEmail], $this->extractToAddresses($this->sentEmails[0]));
        $this->assertEquals($decryptedEmail, $this->sentEmails[1]->getReplyTo()[0]->getAddress());
    }

    public function testSendBothFallsBackWhenDecryptFails(): void
    {
        $sessionId = 'hash-decrypt-fail-test';
        $fallbackEmail = 'fallback@example.com';
        $encValue = 'enc_invalid';
        $baseInfo = $this->createBaseInfo('shop@example.com', 'support@example.com', 'noreply@example.com', 'thch-vape.shop');

        $this->baseInfoRepository->method('get')->willReturn($baseInfo);
        $this->chatLogger->method('fetchSessionHistory')->willReturn([]);

        $chatLogRepo = $this->createMock(ChatLogRepository::class);
        $chatLogRepo->method('fetchLatestEmailEnc')->willReturn($encValue);

        $hashService = $this->createMock(EmailHashService::class);
        $hashService->method('decrypt')->willThrowException(new \RuntimeException('decrypt failed'));

        $this->service = new EmailReplyService(
            $this->mailer,
            $this->baseInfoRepository,
            $this->chatLogger,
            $this->logger,
            null,
            $hashService,
            $chatLogRepo,
        );

        $this->service->sendBoth($sessionId, $fallbackEmail);

        $this->assertCount(2, $this->sentEmails);
        // 復号失敗時は fallback が使われる
        $this->assertEquals([$fallbackEmail], $this->extractToAddresses($this->sentEmails[0]));
    }

    // ================================================================
    //  ヘルパー
    // ================================================================

    private function createBaseInfo(?string $email01, ?string $email02, ?string $email03, ?string $shopName): BaseInfo
    {
        $baseInfo = new BaseInfo();
        $baseInfo->setEmail01($email01);
        $baseInfo->setEmail02($email02);
        $baseInfo->setEmail03($email03);
        $baseInfo->setShopName($shopName);

        return $baseInfo;
    }

    /**
     * @return string[]
     */
    private function extractToAddresses(Email $email): array
    {
        return array_map(fn ($addr) => $addr->getAddress(), $email->getTo());
    }
}
