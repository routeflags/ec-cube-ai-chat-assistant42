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
use Plugin\AiChatAssistant42\Controller\Admin\KnowledgeController;
use Plugin\AiChatAssistant42\Repository\KnowledgeRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * KnowledgeController の CSRF テスト。
 *
 * delete() は isCsrfTokenValid('admin_ai_chat_assistant_knowledge_' . $id) で保護される。
 * 無効時は例外ではなく addError + redirect、全体が remove されないことを検証する。
 */
class KnowledgeControllerTest extends TestCase
{
    /** @var KnowledgeRepository&MockObject */
    private KnowledgeRepository $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(KnowledgeRepository::class);
    }

    private function createController(bool $isCsrfValid): MockObject
    {
        /** @var KnowledgeController&MockObject $controller */
        $controller = $this->getMockBuilder(KnowledgeController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->method('isCsrfTokenValid')
            ->with('admin_ai_chat_assistant_knowledge_42', 'invalid-token')
            ->willReturn($isCsrfValid);

        // token が null / 空の場合も考慮してデフォルトを valid にするが、テストでは上記の特定値で分岐
        $controller->method('isCsrfTokenValid')->willReturn($isCsrfValid);

        return $controller;
    }

    public function testDeleteWithInvalidCsrfDoesNotRemoveAndRedirectsWithError(): void
    {
        $controller = $this->getMockBuilder(KnowledgeController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->expects($this->once())
            ->method('isCsrfTokenValid')
            ->with('admin_ai_chat_assistant_knowledge_42', 'bad-token')
            ->willReturn(false);

        $controller->expects($this->once())->method('addError')->with('不正なリクエストです。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_knowledge_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/knowledge', 302));

        $this->repository->expects($this->never())->method('remove');
        $this->repository->expects($this->never())->method('find');

        $request = Request::create('/admin-dev/ai-chat-assistant/knowledge/42/delete', 'POST', [
            '_token' => 'bad-token',
        ]);

        $response = $controller->delete($request, 42);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteWithValidCsrfRemovesEntity(): void
    {
        $existing = $this->createMock(\Plugin\AiChatAssistant42\Entity\Knowledge::class);
        $this->repository->method('find')->with(42)->willReturn($existing);
        $this->repository->expects($this->once())->method('remove')->with($existing);

        $controller = $this->getMockBuilder(KnowledgeController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->method('isCsrfTokenValid')
            ->with('admin_ai_chat_assistant_knowledge_42', 'valid-token')
            ->willReturn(true);

        $controller->expects($this->once())->method('addSuccess')->with('削除が完了しました。', 'admin');
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/knowledge', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/knowledge/42/delete', 'POST', [
            '_token' => 'valid-token',
        ]);

        $response = $controller->delete($request, 42);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteWithValidCsrfButNotFoundDoesNotRemove(): void
    {
        $this->repository->method('find')->with(999)->willReturn(null);
        $this->repository->expects($this->never())->method('remove');

        $controller = $this->getMockBuilder(KnowledgeController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/knowledge', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/knowledge/999/delete', 'POST', [
            '_token' => 'valid-token',
        ]);

        $response = $controller->delete($request, 999);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testDeleteUsesCorrectCsrfTokenId(): void
    {
        $controller = $this->getMockBuilder(KnowledgeController::class)
            ->onlyMethods(['isCsrfTokenValid', 'addError', 'redirectToRoute'])
            ->setConstructorArgs([$this->repository])
            ->getMock();

        $controller->expects($this->once())
            ->method('isCsrfTokenValid')
            ->with(
                $this->equalTo('admin_ai_chat_assistant_knowledge_123'),
                $this->equalTo('my-token')
            )
            ->willReturn(false);

        $controller->method('addError')->willReturn(null);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/knowledge', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/knowledge/123/delete', 'POST', [
            '_token' => 'my-token',
        ]);

        $controller->delete($request, 123);
    }
}
