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

namespace Plugin\AiChatAssistant42\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Repository\ProductRepository;
use Plugin\AiChatAssistant42\Service\McpHttpService;
use Plugin\AiChatAssistant42\Service\ShopContextService;

/**
 * McpHttpService::buildWellKnownPayload() の表示名解決を検証する。
 *
 * DB のショップ名優先、未設定・未注入・DB 不可時は汎用名フォールバック、
 * serverInfo.name（機械向け識別子）は不変であることを確認する。
 */
class McpHttpServiceTest extends TestCase
{
    private ProductRepository $productRepository;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->productRepository->method('getToolDefinitions')->willReturn([]);
    }

    public function testDisplayNameUsesConfiguredShopName(): void
    {
        $shopContext = $this->createMock(ShopContextService::class);
        $shopContext->method('getConfiguredShopName')->willReturn('テスト商店');

        $service = new McpHttpService($this->productRepository, null, $shopContext);
        $payload = $service->buildWellKnownPayload('https://example.test');

        $this->assertSame('テスト商店', $payload['name']);
    }

    public function testDisplayNameFallsBackWhenShopNameUnset(): void
    {
        $shopContext = $this->createMock(ShopContextService::class);
        $shopContext->method('getConfiguredShopName')->willReturn(null);

        $service = new McpHttpService($this->productRepository, null, $shopContext);
        $payload = $service->buildWellKnownPayload('https://example.test');

        $this->assertSame(McpHttpService::FALLBACK_DISPLAY_NAME, $payload['name']);
    }

    public function testDisplayNameFallsBackWhenServiceNotInjected(): void
    {
        $service = new McpHttpService($this->productRepository);
        $payload = $service->buildWellKnownPayload('https://example.test');

        $this->assertSame('EC-CUBE MCP', $payload['name']);
    }

    public function testDisplayNameFallsBackWhenDatabaseUnavailable(): void
    {
        $shopContext = $this->createMock(ShopContextService::class);
        $shopContext->method('getConfiguredShopName')->willThrowException(new \RuntimeException('DB down'));

        $service = new McpHttpService($this->productRepository, null, $shopContext);
        $payload = $service->buildWellKnownPayload('https://example.test');

        $this->assertSame(McpHttpService::FALLBACK_DISPLAY_NAME, $payload['name']);
    }

    public function testServerInfoNameStaysMachineIdentifier(): void
    {
        $shopContext = $this->createMock(ShopContextService::class);
        $shopContext->method('getConfiguredShopName')->willReturn('テスト商店');

        $service = new McpHttpService($this->productRepository, null, $shopContext);
        $payload = $service->buildWellKnownPayload('https://example.test');

        $this->assertSame('ec-mcp', $payload['serverInfo']['name']);
    }
}
