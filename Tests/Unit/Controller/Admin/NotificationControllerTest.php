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

namespace Plugin\AiChatAssistant42\Tests\Unit\Controller\Admin;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Controller\Admin\NotificationController;
use Plugin\AiChatAssistant42\Repository\NotificationRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * NotificationController の CSRF テスト。
 *
 * create/edit/delete は isTokenValid() で保護される。無効時は例外を捕捉し addError + redirect。
 */
class NotificationControllerTest extends TestCase
{
    /** @var NotificationRepository&MockObject */
    private NotificationRepository $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(NotificationRepository::class);
    }

    private function createController(): MockObject
    {
        /** @var NotificationController&MockObject $controller */
        $controller = $this->getMockBuilder(NotificationController::class)
            ->onlyMethods(['isTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        return $controller;
    }

    public function testCreateWithInvalidTokenRedirectsWithError(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_notification_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/notification', 302));

        $this->repository->expects($this->never())->method('save');

        $request = Request::create('/admin-dev/ai-chat-assistant/notification/create', 'POST', [
            'notification_type' => 'email',
        ]);

        $response = $controller->create($request);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testCreateSucceedsWhenTokenValid(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/notification', 302));
        $controller->expects($this->once())->method('addSuccess')->with('通知ルールを作成しました。', 'admin');

        $this->repository->expects($this->once())->method('save');

        $request = Request::create('/admin-dev/ai-chat-assistant/notification/create', 'POST', [
            'notification_type' => 'webhook',
            'trigger_event' => 'error_threshold',
        ]);

        $response = $controller->create($request);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testEditWithInvalidTokenRedirectsWithError(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_notification_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/notification', 302));

        $this->repository->expects($this->never())->method('save');

        $request = Request::create('/admin-dev/ai-chat-assistant/notification/1/edit', 'POST');

        $response = $controller->edit($request, 1);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testEditReturnsErrorWhenNotFoundAndTokenValid(): void
    {
        $this->repository->method('find')->with(999)->willReturn(null);

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->expects($this->once())->method('addError')->with('指定された通知ルールが見つかりません。', 'admin');
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/notification', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/notification/999/edit', 'POST');

        $response = $controller->edit($request, 999);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteWithInvalidTokenRedirectsWithError(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_notification_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/notification', 302));

        $this->repository->expects($this->never())->method('delete');

        $request = Request::create('/admin-dev/ai-chat-assistant/notification/1/delete', 'POST');

        $response = $controller->delete($request, 1);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteSucceedsWhenTokenValidAndFound(): void
    {
        $existing = $this->createMock(\Plugin\AiChatAssistant42\Entity\Notification::class);
        $this->repository->method('find')->with(3)->willReturn($existing);
        $this->repository->expects($this->once())->method('delete')->with($existing);

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/notification', 302));
        $controller->expects($this->once())->method('addSuccess')->with('通知ルールを削除しました。', 'admin');

        $request = Request::create('/admin-dev/ai-chat-assistant/notification/3/delete', 'POST');

        $response = $controller->delete($request, 3);

        $this->assertEquals(302, $response->getStatusCode());
    }
}
