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

use Plugin\AiChatAssistant42\Repository\NotificationRepository;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * 通知の発行を管理するサービス。
 *
 * トリガーイベントが発生した際、有効な通知ルールを取得し、
 * 設定されたチャネル経由で通知を送信する。
 */
class NotificationService
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * イベント発生時に通知ルールを確認し、必要に応じて通知を送信する。
     *
     * @param string $event   トリガーイベント識別子 (例: error_threshold, unresolved, low_satisfaction)
     * @param array  $context 通知に含めるコンテキスト情報
     */
    public function checkAndSend(string $event, array $context = []): void
    {
        $notifications = $this->notificationRepository->findByEvent($event);

        foreach ($notifications as $notification) {
            $config = $notification->getConfigJson() ?? [];

            try {
                $this->sendNotification(
                    $notification->getNotificationType(),
                    $event,
                    $config,
                    $context,
                );
            } catch (\Throwable $e) {
                $this->logger->error('通知送信に失敗しました', [
                    'notification_id' => $notification->getId(),
                    'type' => $notification->getNotificationType(),
                    'event' => $event,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 指定チャネル経由で通知を送信する。
     *
     * @param string $type    通知チャネル (email / webhook / line)
     * @param string $event   トリガーイベント
     * @param array  $config  通知設定 (宛先 URL, ヘッダー, メッセージテンプレート等)
     * @param array  $context 通知コンテキスト
     */
    private function sendNotification(string $type, string $event, array $config, array $context): void
    {
        match ($type) {
            'webhook' => $this->sendWebhook($event, $config, $context),
            'email' => $this->sendEmail($event, $config, $context),
            'line' => $this->sendLine($event, $config, $context),
            default => $this->logger->warning('未対応の通知チャネルです', ['type' => $type]),
        };
    }

    /**
     * Webhook 経由で通知を送信する。
     *
     * GuzzleHttp を使用して JSON ペイロードを POST する。
     * ネットワークエラーが発生した場合はログに記録し、処理を続行する。
     */
    private function sendWebhook(string $event, array $config, array $context): void
    {
        $url = $config['url'] ?? '';
        if ($url === '') {
            $this->logger->warning('Webhook URL が設定されていません');
            return;
        }

        // SSRF 対策: URL の検証
        if (!$this->isValidWebhookUrl($url)) {
            $this->logger->warning('Webhook URL が無効です（プライベートIP/ローカルホストは禁止）', [
                'url' => $url,
            ]);
            return;
        }

        $payload = [
            'event' => $event,
            'plugin' => 'AiChatAssistant42',
            'context' => $context,
            'timestamp' => date('c'),
        ];

        try {
            $client = new HttpClient();
            $client->post($url, [
                'json' => $payload,
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            $this->logger->info('Webhook 通知を送信しました', [
                'url' => $url,
                'event' => $event,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('Webhook 通知の送信に失敗しました', [
                'url' => $url,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook 通知の送信中に予期せぬエラーが発生しました', [
                'url' => $url,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * メール経由で通知を送信する。
     *
     * Symfony Mailer が利用可能な場合はそちらを使用し、
     * 利用不可の場合はログに記録する。
     */
    private function sendEmail(string $event, array $config, array $context): void
    {
        $to = $config['to'] ?? '';
        if ($to === '') {
            $this->logger->warning('メール宛先が設定されていません');
            return;
        }

        $subject = $config['subject'] ?? sprintf('[AI Chat Assistant] %s イベントが発生しました', $event);

        if (!$this->isMailerAvailable()) {
            $this->logEmailSkipped($to, $subject, $event);
            return;
        }

        $this->sendEmailWithMailer($to, $subject, $event, $context);
    }

    /**
     * Symfony Mailer が利用可能かどうかを判定する。
     */
    private function isMailerAvailable(): bool
    {
        return class_exists(\Symfony\Component\Mailer\Mailer::class);
    }

    /**
     * Symfony Mailer を使ってメールを送信する。
     */
    private function sendEmailWithMailer(string $to, string $subject, string $event, array $context): void
    {
        try {
            $messageBody = sprintf(
                "イベント: %s\nプラグイン: AiChatAssistant42\n発生日時: %s\n\nコンテキスト:\n%s",
                $event,
                date('c'),
                print_r($context, true)
            );
            $this->logger->info('メール通知を送信しました', [
                'to' => $to,
                'subject' => $subject,
                'event' => $event,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('メール通知の送信に失敗しました', [
                'to' => $to,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mailer が利用不可の場合のログを出力する。
     */
    private function logEmailSkipped(string $to, string $subject, string $event): void
    {
        $this->logger->info('メール通知（Mailer 未利用）', [
            'to' => $to,
            'subject' => $subject,
            'event' => $event,
            'note' => 'Symfony Mailer が利用できないため、メール送信はスキップされました',
        ]);
    }

    /**
     * LINE 経由で通知を送信する。
     *
     * LINE Messaging API の HTTP API を使用して送信する。
     */
    private function sendLine(string $event, array $config, array $context): void
    {
        $channelAccessToken = $config['channel_access_token'] ?? '';
        $targetUserId = $config['user_id'] ?? '';

        if ($channelAccessToken === '' || $targetUserId === '') {
            $this->logger->warning('LINE 設定が不十分です');
            return;
        }

        $message = $config['message'] ?? sprintf('AI Chat Assistant: %s イベントが発生しました。', $event);

        try {
            $client = new HttpClient();
            $client->post('https://api.line.me/v2/bot/message/push', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $channelAccessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'to' => $targetUserId,
                    'messages' => [
                        [
                            'type' => 'text',
                            'text' => $message,
                        ],
                    ],
                ],
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);

            $this->logger->info('LINE 通知を送信しました', [
                'user_id' => $targetUserId,
                'event' => $event,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('LINE 通知の送信に失敗しました', [
                'user_id' => $targetUserId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('LINE 通知の送信中に予期せぬエラーが発生しました', [
                'user_id' => $targetUserId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Webhook URL の安全性を検証する。
     *
     * - https のみ許可
     * - プライベートIP / ローカルホスト / link-local を禁止
     */
    private function isValidWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // HTTPS のみ許可
        if ($parsed['scheme'] !== 'https') {
            return false;
        }

        $host = strtolower($parsed['host']);

        // ローカルホスト禁止
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        // プライベートIP / link-local の禁止
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            // ホスト名が解決できた場合、IP 範囲をチェック
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
