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

namespace Plugin\AiChatAssistant44\Tests\Unit;

use Eccube\Common\EccubeConfig;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant44\PluginManager;
use Psr\Container\ContainerInterface;

/**
 * C5 回帰ガード: projectDir 解決の EccubeConfig フォールバック (4.2/4.3/4.4 三対応)。
 *
 * - SymfonyContainerInterface を実装しないコンテナ (4.3型) でも
 *   EccubeConfig['kernel.project_dir'] 経由で assets がミラーされること
 * - 解決不能時は例外なく return すること (有効化を妨げない)
 */
class PluginManagerTest extends TestCase
{
    private function createEccubeConfigContainer(EccubeConfig $config): ContainerInterface
    {
        return new class ($config) implements ContainerInterface {
            public function __construct(private EccubeConfig $config)
            {
            }

            public function get(string $id)
            {
                if ($id === EccubeConfig::class) {
                    return $this->config;
                }

                throw new \RuntimeException('not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return $id === EccubeConfig::class;
            }
        };
    }

    public function testEnableMirrorsAssetsViaEccubeConfigFallback(): void
    {
        $tmp = sys_get_temp_dir() . '/aichat-c5-' . uniqid();
        mkdir($tmp . '/app/Plugin/AiChatAssistant44/Resource/assets/css', 0777, true);
        mkdir($tmp . '/html/plugin', 0777, true);
        file_put_contents($tmp . '/app/Plugin/AiChatAssistant44/Resource/assets/css/probe.css', 'probe');

        $config = new EccubeConfig(['kernel.project_dir' => $tmp]);
        $manager = new PluginManager();
        $manager->enable(['code' => 'AiChatAssistant44'], $this->createEccubeConfigContainer($config));

        $this->assertFileExists($tmp . '/html/plugin/AiChatAssistant44/assets/css/probe.css');
    }

    public function testEnableWithoutResolvableProjectDirDoesNotThrow(): void
    {
        $container = new class () implements ContainerInterface {
            public function get(string $id)
            {
                throw new \RuntimeException('not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        $manager = new PluginManager();
        $manager->enable(['code' => 'AiChatAssistant44'], $container);
        $manager->disable(['code' => 'AiChatAssistant44'], $container);
        $manager->update(['code' => 'AiChatAssistant44'], $container);
        $manager->uninstall(['code' => 'AiChatAssistant44'], $container);
        $this->assertTrue(true);
    }
}
