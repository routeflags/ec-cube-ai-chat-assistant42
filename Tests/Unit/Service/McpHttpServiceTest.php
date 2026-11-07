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
use Plugin\AiChatAssistant42\Service\ProductToolDefinition;
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

    /**
     * @param array<int, array> $definitions
     */
    private function createService(array $definitions = []): McpHttpService
    {
        $repository = $this->createMock(ProductRepository::class);
        $repository->method('getToolDefinitions')->willReturn($definitions);

        return new McpHttpService($repository);
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

    // ------------------------------------------------------------------
    //  ツールメタデータ（ChatGPT / Claude の登録要件）
    // ------------------------------------------------------------------

    public function testToolCatalogExposesTitleAndAnnotations(): void
    {
        $service = $this->createService(ProductToolDefinition::all());
        $catalog = $service->buildToolCatalog();

        $this->assertCount(7, $catalog);
        foreach ($catalog as $tool) {
            $this->assertNotSame('', $tool['title']);
            $this->assertTrue($tool['annotations']['readOnlyHint'], $tool['name']);
            $this->assertFalse($tool['annotations']['destructiveHint'], $tool['name']);
            $this->assertFalse($tool['annotations']['openWorldHint'], $tool['name']);
            $this->assertArrayHasKey('type', $tool['inputSchema']);
            $this->assertSame([['type' => 'noauth']], $tool['_meta']['securitySchemes'], $tool['name']);
        }
    }

    public function testToolsListAndDiscoveryShareOneCatalog(): void
    {
        $service = $this->createService(ProductToolDefinition::all());

        $list = $service->handleToolsList(1);
        $wellKnown = $service->buildWellKnownPayload('https://example.test');

        $this->assertSame($wellKnown['tools'], $list['result']['tools']);
    }

    public function testToolDescriptionsCarryNoBehaviouralInstructions(): void
    {
        foreach (ProductToolDefinition::all() as $tool) {
            $this->assertStringNotContainsString('してください', $tool['description'], $tool['name']);
            $this->assertStringNotContainsString('example.com', $tool['description'], $tool['name']);
        }
    }

    // ------------------------------------------------------------------
    //  プロトコルバージョン交渉
    // ------------------------------------------------------------------

    public function testInitializeEchoesSupportedProtocolVersion(): void
    {
        $service = $this->createService();
        $result = $service->handleInitialize(1, '2025-06-18');

        $this->assertSame('2025-06-18', $result['result']['protocolVersion']);
        $this->assertNotSame('', $result['result']['instructions']);
    }

    public function testInitializeAcceptsEveryAdvertisedVersion(): void
    {
        $service = $this->createService();

        foreach (McpHttpService::SUPPORTED_PROTOCOL_VERSIONS as $version) {
            $result = $service->handleInitialize(1, $version);
            $this->assertSame($version, $result['result']['protocolVersion']);
        }
    }

    public function testInitializeFallsBackForUnsupportedVersion(): void
    {
        $service = $this->createService();
        $result = $service->handleInitialize(1, '1999-01-01');

        $this->assertSame(McpHttpService::PROTOCOL_VERSION, $result['result']['protocolVersion']);
    }

    public function testInitializeFallsBackWithoutVersion(): void
    {
        $service = $this->createService();
        $result = $service->handleInitialize(1);

        $this->assertSame(McpHttpService::PROTOCOL_VERSION, $result['result']['protocolVersion']);
    }
}
