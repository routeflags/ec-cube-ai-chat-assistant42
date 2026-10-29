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
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * 通知の発行を管理するサービス。
 *
 * トリガーイベントが発生した際、有効な通知ルールを取得し、
 * 設定されたチャネル経由で通知を送信する。
 */
class NotificationService
{
    private WebhookUrlGuard $urlGuard;

    public function __construct(
        private NotificationRepository $notificationRepo,
        private LoggerInterface $logger,
        private ?MailerInterface $mailer = null,
        private ?ApiKeyEncryptor $apiKeyEncryptor = null,
        ?WebhookUrlGuard $urlGuard = null,
    ) {
        $this->urlGuard = $urlGuard ?? new WebhookUrlGuard();
    }

    /**
     * イベント発生時に通知ルールを確認し、必要に応じて通知を送信する。
     *
     * @param string $event   トリガーイベント識別子 (例: error_threshold, unresolved, low_satisfaction)
     * @param array<string, mixed>  $context 通知に含めるコンテキスト情報
     */
    public function checkAndSend(string $event, array $context = []): void
    {
        try {
            $notifications = $this->notificationRepo->findByEvent($event);
        } catch (\Throwable $e) {
            $this->logger->error('通知ルールの取得に失敗しました', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
            return;
        }

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
     * @param array<string, mixed>  $config  通知設定 (宛先 URL, ヘッダー, メッセージテンプレート等)
     * @param array<string, mixed>  $context 通知コンテキスト
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
     *
     * @param array<string, mixed> $config 通知設定
     * @param array<string, mixed> $context 通知コンテキスト
     */
    private function sendWebhook(string $event, array $config, array $context): void
    {
        $url = $config['url'] ?? '';
        if ($url === '') {
            $this->logger->warning('Webhook URL が設定されていません');
            return;
        }

        // SSRF 対策: URL の検証
        if (!$this->urlGuard->isSafe($url)) {
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

        // Controller で暗号化された headers を復号し、送信ヘッダーに付与する（空なら何もしない — 後方互換）
        $headers = $this->resolveWebhookHeaders($config);

        try {
            $client = new HttpClient();
            $requestOptions = [
                'json' => $payload,
                'timeout' => 10,
                'connect_timeout' => 5,
                // M3: リダイレクトを追従しない — SSRF 経由のリダイレクト悪用を防止
                'allow_redirects' => false,
            ];
            if ($headers !== []) {
                $requestOptions['headers'] = $headers;
            }
            $client->post($url, $requestOptions);

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
     *
     * @param array<string, mixed> $config 通知設定
     * @param array<string, mixed> $context 通知コンテキスト
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
     *
     * @param array<string, mixed> $context 通知コンテキスト
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

            if ($this->mailer === null) {
                $this->logger->warning('メール通知の送信に失敗しました: Mailer が注入されていません', [
                    'to' => $to,
                    'event' => $event,
                ]);
                return;
            }

            $email = (new Email())
                ->from('no-reply@example.com')
                ->to($to)
                ->subject($subject)
                ->text($messageBody);

            $this->mailer->send($email);

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
     *
     * @param array<string, mixed> $config 通知設定
     * @param array<string, mixed> $context 通知コンテキスト
     */
    private function sendLine(string $event, array $config, array $context): void
    {
        $channelAccessToken = $this->decryptIfNeeded($config['channel_access_token'] ?? '');
        $targetUserId = $config['user_id'] ?? '';

        if ($channelAccessToken === '' || $targetUserId === '') {
            $this->logger->warning('LINE 設定が不十分です');
            return;
        }

        $eventLabels = [
            'low_satisfaction' => '低満足度レポート',
            'email_reply_request' => 'メール返信依頼',
        ];
        $eventLabel = $eventLabels[$event] ?? $event;
        $message = $config['message'] ?? sprintf('【AIチャットアシスタント】%sが発生しました。', $eventLabel);
        $this->logger->debug('sendLine context', $context);

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
     * Webhook 用のカスタムヘッダーを config から復号して取得する.
     *
     * Controller では webhook_headers 全体を encryptIfNeeded() で暗号化して保存する。
     * 文字列（JSON）の場合は全体を decrypt 後に json_decode し、各値も個別に
     * decryptIfNeeded() で復号する（値が個別に暗号化されているケースにも対応）。
     * 配列で保存されているレガシーデータも考慮する。空/未設定なら空配列を返す。
     *
     * @param array<string, mixed> $config 通知設定
     *
     * @return array<string, string>
     */
    private function resolveWebhookHeaders(array $config): array
    {
        $rawHeaders = $config['headers'] ?? null;
        if ($rawHeaders === null || $rawHeaders === '') {
            return [];
        }

        // レガシー: 既に配列で保存されている場合（各値を個別に復号）
        if (is_array($rawHeaders)) {
            return $this->buildHeaders($rawHeaders);
        }

        // 文字列: 全体が暗号化されている可能性があるためまず復号を試みる
        $decrypted = $this->decryptIfNeeded((string) $rawHeaders);
        if (trim($decrypted) === '') {
            return [];
        }

        $decoded = json_decode($decrypted, true);
        if (!is_array($decoded)) {
            $this->logger->warning('Webhook headers の JSON 解析に失敗しました', [
                'headers_preview' => substr($decrypted, 0, 100),
            ]);

            return [];
        }

        return $this->buildHeaders($decoded);
    }

    /**
     * ヘッダー行（key => value）を検証し、値を復号して返す。
     *
     * 値が個別に暗号化されているレガシーデータにも対応する（防御的）。
     *
     * @param array<mixed, mixed> $rows
     *
     * @return array<string, string>
     */
    private function buildHeaders(array $rows): array
    {
        $headers = [];
        foreach ($rows as $key => $value) {
            $headerKey = trim((string) $key);
            if ($headerKey === '') {
                continue;
            }
            $headers[$headerKey] = $this->decryptIfNeeded((string) $value);
        }

        return $headers;
    }

    private function decryptIfNeeded(string $value): string
    {
        if ($value === '' || $this->apiKeyEncryptor === null) {
            return $value;
        }
        if (!$this->apiKeyEncryptor->isEncrypted($value)) {
            return $value;
        }
        $decrypted = $this->apiKeyEncryptor->decrypt($value);
        if ($decrypted === $value) {
            $this->logger->warning('通知トークンの復号に失敗しました。APP_SECRET が変更された可能性があります。', [
                'encrypted_prefix' => substr($value, 0, 8) . '...',
            ]);
        }

        return $decrypted;
    }
}
