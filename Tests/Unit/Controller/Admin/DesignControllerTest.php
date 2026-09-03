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
use Plugin\AiChatAssistant42\Controller\Admin\DesignController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * DesignController の CSRF テスト。
 *
 * save() は isTokenValid() で保護される。無効時は例外を捕捉し addError + redirect。
 */
class DesignControllerTest extends TestCase
{
    /** @var UrlGeneratorInterface&MockObject */
    private UrlGeneratorInterface $urlGenerator;

    protected function setUp(): void
    {
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    }

    private function createController(): MockObject
    {
        /** @var DesignController&MockObject $controller */
        $controller = $this->getMockBuilder(DesignController::class)
            ->onlyMethods(['isTokenValid', 'addError', 'addSuccess', 'redirectToRoute'])
            ->setConstructorArgs(['', null])
            ->getMock();

        return $controller;
    }

    public function testSaveWithInvalidTokenRedirectsWithError(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')
            ->willThrowException(new AccessDeniedHttpException('CSRF token is invalid.'));
        $controller->expects($this->once())->method('addError')->with('CSRFトークンが無効です。', 'admin');
        $controller->method('redirectToRoute')
            ->with('admin_ai_chat_assistant_design_index')
            ->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/design', 302));

        $request = Request::create('/admin-dev/ai-chat-assistant/design/save', 'POST', [
            'widget_color' => '#ff0000',
        ]);

        $response = $controller->save($request, $this->urlGenerator);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testSaveSucceedsWhenTokenValid(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/design', 302));
        $controller->expects($this->once())->method('addSuccess')->with('デザイン設定を保存しました。', 'admin');

        $request = Request::create('/admin-dev/ai-chat-assistant/design/save', 'POST', [
            'widget_color' => '#ff0000',
            'widget_size' => 'large',
            'position' => 'bottom-left',
            'greeting_message' => 'Hello',
            'assistant_display_name' => 'AI',
        ]);

        $response = $controller->save($request, $this->urlGenerator);

        $this->assertEquals(302, $response->getStatusCode());
    }

    public function testSaveUsesDefaultsWhenParamsMissingAndTokenValid(): void
    {
        $controller = $this->createController();
        $controller->method('isTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin-dev/ai-chat-assistant/design', 302));
        $controller->expects($this->once())->method('addSuccess');

        $request = Request::create('/admin-dev/ai-chat-assistant/design/save', 'POST', []);

        $response = $controller->save($request, $this->urlGenerator);

        $this->assertEquals(302, $response->getStatusCode());
    }
}
