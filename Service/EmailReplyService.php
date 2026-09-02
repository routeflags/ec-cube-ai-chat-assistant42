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

use Eccube\Repository\BaseInfoRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * AIチャットの「メールで返信依頼」2通送信を担うサービス.
 *
 * - ユーザー宛: 受付確認
 * - 管理者宛: 通知（履歴付き）
 *
 * BaseInfo はコンストラクタでキャッシュせず、メソッド内で都度取得する
 * （管理画面で更新される可能性に対応）。
 */
class EmailReplyService
{
    private const FALLBACK_FROM_DOMAIN = 'example.com';
    private const FALLBACK_FROM = 'no-reply@example.com';

    public function __construct(
        private MailerInterface $mailer,
        private BaseInfoRepository $baseInfoRepository,
        private ChatLogger $chatLogger,
        private LoggerInterface $logger,
        private ?ShopContextService $shopContextService = null,
        private ?EmailHashService $emailHashService = null,
    ) {
    }

    /**
     * ユーザー宛と管理者宛の2通を送信する.
     *
     * 1通失敗してももう1通は試行し、例外は再スローせず warning に留める。
     */
    public function sendBoth(string $sessionId, string $userEmail): void
    {
        $history = $this->chatLogger->fetchSessionHistory($sessionId, 10);
        $baseInfo = $this->baseInfoRepository->get();

        try {
            $this->sendUserConfirmation($sessionId, $userEmail, $history, $baseInfo);
        } catch (TransportExceptionInterface|\InvalidArgumentException $e) {
            $this->logger->warning('ユーザー宛確認メールの送信に失敗しました', [
                'session_id' => $sessionId,
                'email' => $userEmail,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->sendAdminNotification($sessionId, $userEmail, $history, $baseInfo);
        } catch (TransportExceptionInterface|\InvalidArgumentException $e) {
            $this->logger->warning('管理者宛通知メールの送信に失敗しました', [
                'session_id' => $sessionId,
                'email' => $userEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ユーザー宛の受付確認メールを送信する.
     */
    private function sendUserConfirmation(string $sessionId, string $userEmail, array $history, $baseInfo): void
    {
        $fromEmail = $this->resolveFromAddress($baseInfo);
        $shopName = $baseInfo->getShopName() ?: 'このショップ';
        $adminTo = $this->resolveAdminTo($baseInfo);

        $email = (new Email())
            ->from(new Address($fromEmail, $shopName . ' サポート'))
            ->to($this->toAddress($userEmail))
            ->subject(sprintf('【%s】お問い合わせを承りました（セッション: %s）', $shopName, $sessionId))
            ->text($this->buildUserBody($sessionId, $userEmail, $history, $shopName));

        // Reply-To は管理者宛（なければ From と同じ）
        $replyTo = $adminTo ?: $fromEmail;
        $email->replyTo($this->toAddress($replyTo));

        $this->mailer->send($email);
    }

    /**
     * 管理者宛の通知メールを送信する.
     *
     * 管理者宛アドレスが空の場合はスキップし warning を残す。
     */
    private function sendAdminNotification(string $sessionId, string $userEmail, array $history, $baseInfo): void
    {
        $adminTo = $this->resolveAdminTo($baseInfo);
        if ($adminTo === '') {
            $this->logger->warning('管理者宛メールアドレスが未設定のため通知をスキップしました', [
                'session_id' => $sessionId,
            ]);
            return;
        }

        $fromEmail = $this->resolveFromAddress($baseInfo);
        $shopName = $baseInfo->getShopName() ?: 'このショップ';

        $email = (new Email())
            ->from(new Address($fromEmail, $shopName . ' サポート'))
            ->to($this->toAddress($adminTo))
            ->replyTo($this->toAddress($userEmail))
            ->subject(sprintf('[要対応] AIチャットでメール返信依頼: %s (%s)', $userEmail, $sessionId))
            ->text($this->buildAdminBody($sessionId, $userEmail, $history));

        $this->mailer->send($email);
    }

    /**
     * RFC違反の local part を "" でクォートして Address を生成する.
     *
     * MailService::convertRFCViolatingEmail 相当。
     * eccube_rfc_email_check が false のときのみクォートが必要だが、
     * 本サービスは false を前提に常に検査する。
     *
     * @internal 外部公開APIではない。テストのため public としている。
     */
    public function toAddress(string $email): Address
    {
        // eccube_rfc_email_check=false 前提で RFC 準拠チェック
        // MailService::convertRFCViolatingEmail 相当を再実装
        $wsp = '[\x20\x09]';
        $vchar = '[\x21-\x7e]';
        $quotedPair = "\\\\(?:$vchar|$wsp)";
        $qtext = '[\x21\x23-\x5b\x5d-\x7e]';
        $qcontent = "(?:$qtext|$quotedPair)";
        $quotedString = "\"$qcontent*\"";
        $atext = '[a-zA-Z0-9!#$%&\'*+\-\/\=?^_`{|}~]';
        $dotAtom = "$atext+(?:[.]$atext+)*";
        $localPart = "(?:$dotAtom|$quotedString)";
        $domain = $dotAtom;
        $addrSpec = "{$localPart}[@]$domain";
        $regexp = "/\\A{$addrSpec}\\z/";

        if (!preg_match($regexp, $email)) {
            // local part をエスケープしてからクォートする（" と \ をエスケープ）
            $email = preg_replace_callback('/^(.*)@(.*)$/', static function (array $m): string {
                $local = str_replace(['\\', '"'], ['\\\\', '\\"'], $m[1]);
                return '"' . $local . '"@' . $m[2];
            }, $email);
        }

        return new Address($email);
    }

    private function resolveFromAddress($baseInfo): string
    {
        $from = $baseInfo->getEmail03();
        if (!empty($from)) {
            return $from;
        }
        $from = $baseInfo->getEmail01();
        if (!empty($from)) {
            return $from;
        }
        return self::FALLBACK_FROM;
    }

    private function resolveAdminTo($baseInfo): string
    {
        $to = $baseInfo->getEmail02();
        if (!empty($to)) {
            return $to;
        }
        $to = $baseInfo->getEmail01();
        if (!empty($to)) {
            return $to;
        }
        return '';
    }

    private function buildUserBody(string $sessionId, string $userEmail, array $history, string $shopName): string
    {
        $now = (new \DateTime())->format('Y-m-d H:i');
        $historyText = $this->formatHistory($history);

        return <<<BODY
{$userEmail} 様

お問い合わせありがとうございます。
以下の内容で承りました。2営業日以内にご連絡いたします。

────────────────────
セッションID: {$sessionId}
お問い合わせ日時: {$now}
チャット履歴:
{$historyText}
────────────────────

このメールは送信専用です。ご返信の際は別途お問い合わせフォームをご利用ください。

{$shopName}
{$this->getShopUrl()}
BODY;
    }

    private function buildAdminBody(string $sessionId, string $userEmail, array $history): string
    {
        $now = (new \DateTime())->format('Y-m-d H:i');
        $historyText = $this->formatHistory($history);

        return <<<BODY
新規メール返信依頼が届きました。

ユーザー: {$userEmail}
セッション: {$sessionId}
日時: {$now}

チャット履歴:
────────────────────
{$historyText}
────────────────────

管理画面: {$this->getAdminHistoryUrl()}
BODY;
    }

    private function formatHistory(array $history): string
    {
        if (empty($history)) {
            return '履歴なし';
        }

        $lines = [];
        foreach ($history as $entry) {
            $role = ($entry['role'] ?? '') === 'user' ? 'ユーザー' : 'AI';
            $content = $entry['content'] ?? '';
            $lines[] = "{$role}: {$content}";
        }

        return implode("\n", $lines);
    }

    private function getShopUrl(): string
    {
        if ($this->shopContextService !== null) {
            $baseUrl = $this->shopContextService->getBaseUrl();
            if ($baseUrl !== '') {
                return rtrim($baseUrl, '/') . '/';
            }
        }
        // フォールバック: 管理画面URLはルーティングから生成できない場合でも相対で案内
        return '/';
    }

    private function getAdminHistoryUrl(): string
    {
        if ($this->shopContextService !== null) {
            return $this->shopContextService->getAdminHistoryUrl();
        }
        return '/admin/ai-chat-assistant/history';
    }
}
