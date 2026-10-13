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

namespace Plugin\AiChatAssistant44\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant44\Repository\ProductRepository;
use Plugin\AiChatAssistant44\Service\McpHttpService;

/**
 * 公式MCP (コア4.4 + api44) との共存ガード。
 *
 * - Discovery は既存キーを保持した上で coexistence 注記を含む (additive のみ)
 * - 本プラグインは #[McpTool] / mcp.tool を追加しない (追加時は本テストが赤になり承認契機)
 * - routes.yaml は /admin/mcp を定義しない (コア動作不変)
 *
 * 公式MCPの同名ツール search_products との衝突回避のため、
 * 本プラグインの商品検索ツールは aichat_search_products に改名済み
 * (承認済みの破壊的変更)。旧名呼び出しはエラーになること。
 */
class McpCoexistenceTest extends TestCase
{
    public function testWellKnownPayloadKeepsExistingKeysAndAddsCoexistenceNote(): void
    {
        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('getToolDefinitions')->willReturn([]);

        $service = new McpHttpService($productRepository);
        $payload = $service->buildWellKnownPayload('https://example.test');

        // 既存キー維持
        foreach (['name', 'protocolVersion', 'serverInfo', 'transport', 'capabilities', 'baseUrl', 'tools'] as $key) {
            $this->assertArrayHasKey($key, $payload, "existing key {$key} must be kept");
        }
        $this->assertSame('ec-mcp', $payload['serverInfo']['name']);

        // additive 注記
        $this->assertArrayHasKey('description', $payload);
        $this->assertArrayHasKey('coexistence', $payload);
        $this->assertStringContainsString('ec-mcp', (string) $payload['description']);

        $servers = $payload['coexistence']['servers'] ?? [];
        $this->assertCount(2, $servers);
        $this->assertSame('ec-mcp', $servers[0]['name']);
        $this->assertSame('/mcp', $servers[0]['endpoint']);
        $this->assertSame('EC-CUBE MCP Server', $servers[1]['name']);
        $this->assertStringContainsString('OAuth2', (string) $servers[1]['auth']);

        $notes = implode("\n", $payload['coexistence']['notes'] ?? []);
        $this->assertStringContainsString('aichat_search_products', $notes);
        $this->assertStringContainsString('renamed', $notes);
    }

    public function testPluginAddsNoCoreMcpToolRegistrations(): void
    {
        $pluginRoot = dirname(__DIR__, 3);
        $targets = ['Service', 'Controller', 'EventListener', 'EventSubscriber', 'Repository', 'Command'];
        $violations = [];

        foreach ($targets as $dir) {
            $base = $pluginRoot . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = file_get_contents($file->getPathname());
                if ($code === false) {
                    continue;
                }
                if (str_contains($code, '#[McpTool') || str_contains($code, 'Mcp\\Capability\\Attribute\\McpTool')) {
                    $violations[] = $file->getPathname() . ': #[McpTool] must not be added without approval';
                }
                if (str_contains($code, 'mcp.tool')) {
                    $violations[] = $file->getPathname() . ': mcp.tool tag must not be added without approval';
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    public function testPluginRoutesDoNotDefineAdminMcp(): void
    {
        $routesYaml = dirname(__DIR__, 3) . '/Resource/config/routes.yaml';
        $this->assertFileExists($routesYaml);
        $yaml = file_get_contents($routesYaml);
        $this->assertIsString($yaml);
        // コアの /{admin_route}/mcp を本プラグイン側で定義してはならない
        $this->assertStringNotContainsString('%eccube_admin_route%/mcp', $yaml);
        $this->assertStringNotContainsString('admin/mcp', $yaml);
        // 本プラグインの MCP 系ルートはこの3つのみ
        $this->assertStringContainsString('path: /.well-known/mcp.json', $yaml);
        $this->assertStringContainsString('path: /mcp', $yaml);
    }
}
