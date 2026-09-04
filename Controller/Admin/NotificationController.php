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

namespace Plugin\AiChatAssistant42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\AiChatAssistant42\Entity\Notification;
use Plugin\AiChatAssistant42\Repository\NotificationRepository;
use Plugin\AiChatAssistant42\Service\ApiKeyEncryptor;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use DateTimeImmutable;

/**
 * 通知ルールの CRUD を管理するコントローラ。
 *
 * メール・Webhook・LINE など、各種チャネル経由の通知ルールを
 * 登録・編集・削除する。
 */
class NotificationController extends AbstractController
{
    /** @var string[] 通知チャネル一覧 */
    private const NOTIFICATION_TYPES = ['email', 'webhook', 'line'];

    /** @var string[] トリガーイベント一覧 */
    private const TRIGGER_EVENTS = [
        'low_satisfaction' => '低満足度レポート',
        'email_reply_request' => 'メール返信依頼',
    ];

    public function __construct(
        private NotificationRepository $notificationRepository,
        private ?ApiKeyEncryptor $apiKeyEncryptor = null,
    ) {
    }

    /**
     * 通知ルール一覧を表示する。
     */
    public function index(): Response
    {
        $notifications = $this->notificationRepository->findAllActive();

        return $this->render('@AiChatAssistant42/admin/notification.twig', [
            'notifications' => $notifications,
            'notification_types' => self::NOTIFICATION_TYPES,
            'trigger_events' => self::TRIGGER_EVENTS,
        ]);
    }

    /**
     * 通知ルールを新規作成する。
     */
    public function create(Request $request): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
        }

        $notification = new Notification();
        $notification->setNotificationType($request->request->get('notification_type', 'email'));
        $notification->setTriggerEvent($request->request->get('trigger_event', 'error_threshold'));
        $notification->setConfigJson($this->buildConfigJson($request));
        $notification->setIsActive(1);
        $notification->setCreateDate(new DateTimeImmutable());
        $notification->setUpdateDate(new DateTimeImmutable());

        $this->notificationRepository->save($notification);

        $this->addSuccess('通知ルールを作成しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
    }

    /**
     * 通知ルールを編集する。
     */
    public function edit(Request $request, int $id): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
        }

        $notification = $this->notificationRepository->find($id);
        if ($notification === null) {
            $this->addError('指定された通知ルールが見つかりません。', 'admin');
            return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
        }

        $notification->setNotificationType($request->request->get('notification_type', $notification->getNotificationType()));
        $notification->setTriggerEvent($request->request->get('trigger_event', $notification->getTriggerEvent()));
        $notification->setConfigJson($this->buildConfigJson($request));
        $notification->setIsActive((int) $request->request->get('is_active', $notification->getIsActive()));
        $notification->setUpdateDate(new DateTimeImmutable());

        $this->notificationRepository->save($notification);

        $this->addSuccess('通知ルールを更新しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
    }

    /**
     * 通知ルールを削除する。
     */
    public function delete(Request $request, int $id): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
        }
        // $request は getMethod() で参照する（監査ログ欠落を解消 — CSRF 検証を先に行いメソッド不一致も監査対象とする）
        if ('POST' !== $request->getMethod()) {
            $this->addError('不正なリクエストです。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
        }

        $notification = $this->notificationRepository->find($id);
        if ($notification === null) {
            $this->addError('指定された通知ルールが見つかりません。', 'admin');
            return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
        }

        $this->notificationRepository->delete($notification);

        $this->addSuccess('通知ルールを削除しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_notification_index');
    }

    /**
     * リクエストから通知設定 JSON を構築する。
     */
    private function buildConfigJson(Request $request): array
    {
        $type = $request->request->get('notification_type', 'email');

        return match ($type) {
            'email' => [
                'to' => $request->request->get('email_to', ''),
                'subject' => $request->request->get('email_subject', ''),
            ],
            'webhook' => [
                'url' => $request->request->get('webhook_url', ''),
                'headers' => $this->encryptIfNeeded($request->request->get('webhook_headers', '')),
            ],
            'line' => [
                'channel_access_token' => $this->encryptIfNeeded($request->request->get('line_channel_access_token', '')),
                'user_id' => $request->request->get('line_user_id', ''),
            ],
            default => [],
        };
    }

    private function encryptIfNeeded(string $value): string
    {
        if ($value === '' || $this->apiKeyEncryptor === null) {
            return $value;
        }
        if ($this->apiKeyEncryptor->isEncrypted($value)) {
            return $value;
        }

        return $this->apiKeyEncryptor->encrypt($value);
    }
}
