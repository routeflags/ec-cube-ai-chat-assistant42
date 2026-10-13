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

namespace Plugin\AiChatAssistant42\Tests\Unit\EventListener;

use Eccube\Event\TemplateEvent;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Entity\Config;
use Plugin\AiChatAssistant42\EventListener\ChatWidgetListener;
use Plugin\AiChatAssistant42\Repository\ConfigRepository;

/**
 * C2 回帰ガード: WebMCP discovery の <link rel="mcp"> は head (addAsset) 出力。
 *
 * - 有効時: snippet (body) + asset (head) の両方が登録されること
 * - 無効時: どちらも登録されないこと
 */
class ChatWidgetListenerTest extends TestCase
{
    private function createListener(?Config $config): ChatWidgetListener
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->method('get')->willReturn($config);

        return new ChatWidgetListener($repository);
    }

    public function testEnabledRegistersSnippetAndHeadAsset(): void
    {
        $config = new Config();
        $config->setIsEnabled(1);

        $event = new TemplateEvent('default_frame.twig');
        $this->createListener($config)->onDefaultFrame($event);

        $this->assertContains(
            '@AiChatAssistant42/default/chat_widget.twig',
            $event->getSnippets(),
            'body snippet must be registered'
        );
        $this->assertArrayHasKey(
            '@AiChatAssistant42/default/mcp_link.twig',
            $event->getAssets(),
            'mcp link must be registered as head asset (C2)'
        );
    }

    public function testDisabledRegistersNeitherSnippetNorAsset(): void
    {
        $config = new Config();
        $config->setIsEnabled(0);

        $event = new TemplateEvent('default_frame.twig');
        $this->createListener($config)->onDefaultFrame($event);

        $this->assertSame([], $event->getSnippets());
        $this->assertSame([], $event->getAssets());
    }
}
