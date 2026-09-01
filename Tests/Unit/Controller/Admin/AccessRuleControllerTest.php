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
use Plugin\AiChatAssistant42\Controller\Admin\AccessRuleController;
use Plugin\AiChatAssistant42\Repository\AccessRuleRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * AccessRuleController の CSRF テスト。
 *
 * create/edit/delete は isTokenValid() で保護される。無効時は例外を捕捉し
 * addError + redirect する（403 ではなくフラッシュエラーでリダイレクト）。
 */
class AccessRuleControllerTest extends TestCase
{
    /** @var AccessRuleRepository&MockObject */
    private AccessRuleRepository $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AccessRuleRepository::class);
    }

    private function createController(): MockObject
    {
        /** @var AccessRuleController&MockObject $controller */
        $controller = $this->getMockBuilder(AccessRuleController::class)
            ->onlyMethods(['isTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        return $controller;
    }

    // ================================================================
    //  create
    // ================================================================

    public function testCreateWithInvalidTokenRedirectsWithError(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_access_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/access', 302));

        $this->repository->expects($this->never())->method('save');

        $request = Request::create('/admin-dev/ai-chat-assistant/access/create', 'POST', [
            'rule_value' => '192.168.1.1',
        ]);

        $response = $controller->create($request);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testCreateSucceedsWhenTokenValid(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/access', 302));
        $controller->expects($this->once())->method('addSuccess')->with('アクセスルールを作成しました。', 'admin');

        $this->repository->expects($this->once())->method('save');

        $request = Request::create('/admin-dev/ai-chat-assistant/access/create', 'POST', [
            'rule_type' => 'ip',
            'rule_value' => '10.0.0.1',
            'action' => 'deny',
        ]);

        $response = $controller->create($request);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testCreateDoesNotSaveWhenRuleValueEmptyAndTokenValid(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->expects($this->once())->method('addError')->with('ルール値を入力してください。', 'admin');
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/access', 302));

        $this->repository->expects($this->never())->method('save');

        $request = Request::create('/admin-dev/ai-chat-assistant/access/create', 'POST', [
            'rule_value' => '',
        ]);

        $response = $controller->create($request);

        $this->assertEquals(302, $response->getStatusCode());
    }

    // ================================================================
    //  edit
    // ================================================================

    public function testEditWithInvalidTokenRedirectsWithError(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_access_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/access', 302));

        $this->repository->expects($this->never())->method('save');

        $request = Request::create('/admin-dev/ai-chat-assistant/access/1/edit', 'POST', [
            'rule_value' => 'updated',
        ]);

        $response = $controller->edit($request, 1);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testEditWithValidTokenUpdatesRule(): void
    {
        $existing = $this->createMock(\Plugin\AiChatAssistant42\Entity\AccessRule::class);
        $existing->method('getRuleValue')->willReturn('old');
        $existing->method('getRuleType')->willReturn('ip');
        $existing->method('getAction')->willReturn('deny');
        $existing->method('getIsActive')->willReturn(1);
        $existing->expects($this->once())->method('setRuleValue')->with('new-value');
        $existing->expects($this->once())->method('setUpdateDate');

        $this->repository->method('find')->with(1)->willReturn($existing);
        $this->repository->expects($this->once())->method('save')->with($existing);

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/access', 302));
        $controller->expects($this->once())->method('addSuccess');

        $request = Request::create('/admin-dev/ai-chat-assistant/access/1/edit', 'POST', [
            'rule_value' => 'new-value',
        ]);

        $response = $controller->edit($request, 1);

        $this->assertEquals(302, $response->getStatusCode());
    }

    // ================================================================
    //  delete
    // ================================================================

    public function testDeleteWithInvalidTokenRedirectsWithError(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_access_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/access', 302));

        $this->repository->expects($this->never())->method('delete');

        $request = Request::create('/admin-dev/ai-chat-assistant/access/1/delete', 'POST');

        $response = $controller->delete($request, 1);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteWithValidTokenRemovesRule(): void
    {
        $existing = $this->createMock(\Plugin\AiChatAssistant42\Entity\AccessRule::class);
        $this->repository->method('find')->with(5)->willReturn($existing);
        $this->repository->expects($this->once())->method('delete')->with($existing);

        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/access', 302));
        $controller->expects($this->once())->method('addSuccess')->with('アクセスルールを削除しました。', 'admin');

        $request = Request::create('/admin-dev/ai-chat-assistant/access/5/delete', 'POST');

        $response = $controller->delete($request, 5);

        $this->assertEquals(302, $response->getStatusCode());
    }
}
