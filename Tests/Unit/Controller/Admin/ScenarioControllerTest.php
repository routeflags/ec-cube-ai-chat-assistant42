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
use Plugin\AiChatAssistant42\Controller\Admin\ScenarioController;
use Plugin\AiChatAssistant42\Repository\ScenarioRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * ScenarioController の CSRF テスト。
 *
 * delete() は isCsrfTokenValid('admin_ai_chat_assistant_scenario_' . $id) で保護される。
 */
class ScenarioControllerTest extends TestCase
{
    /** @var ScenarioRepository&MockObject */
    private ScenarioRepository $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ScenarioRepository::class);
    }

    public function testDeleteWithInvalidCsrfDoesNotRemoveAndRedirectsWithError(): void
    {
        $controller = $this->getMockBuilder(ScenarioController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->expects($this->once())
            ->method('isCsrfTokenValid')
            ->with('admin_ai_chat_assistant_scenario_7', 'bad-token')
            ->willReturn(false);

        $controller->expects($this->once())->method('addError')->with('不正なリクエストです。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_scenario_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/scenario', 302));

        $this->repository->expects($this->never())->method('remove');

        $request = Request::create('/admin-dev/ai-chat-assistant/scenario/7/delete', 'POST', [
            '_token' => 'bad-token',
        ]);

        $response = $controller->delete($request, 7);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteWithValidCsrfRemovesEntity(): void
    {
        $existing = $this->createMock(\Plugin\AiChatAssistant42\Entity\Scenario::class);
        $this->repository->method('find')->with(7)->willReturn($existing);
        $this->repository->expects($this->once())->method('remove')->with($existing);

        $controller = $this->getMockBuilder(ScenarioController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->method('isCsrfTokenValid')
            ->with('admin_ai_chat_assistant_scenario_7', 'valid-token')
            ->willReturn(true);

        $controller->expects($this->once())->method('addSuccess')->with('削除が完了しました。', 'admin');
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/scenario', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/scenario/7/delete', 'POST', [
            '_token' => 'valid-token',
        ]);

        $response = $controller->delete($request, 7);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteUsesCorrectCsrfTokenId(): void
    {
        $controller = $this->getMockBuilder(ScenarioController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->expects($this->once())
            ->method('isCsrfTokenValid')
            ->with(
                $this->equalTo('admin_ai_chat_assistant_scenario_99'),
                $this->equalTo('token-99')
            )
            ->willReturn(false);

        $controller->method('addError')->willReturn(null);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/scenario', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/scenario/99/delete', 'POST', [
            '_token' => 'token-99',
        ]);

        $controller->delete($request, 99);
    }

    public function testDeleteWithValidCsrfButNotFoundDoesNotRemove(): void
    {
        $this->repository->method('find')->with(999)->willReturn(null);
        $this->repository->expects($this->never())->method('remove');

        $controller = $this->getMockBuilder(ScenarioController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/scenario', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/scenario/999/delete', 'POST', [
            '_token' => 'valid-token',
        ]);

        $response = $controller->delete($request, 999);

        $this->assertEquals(302, $response->getStatusCode());
    }
}
