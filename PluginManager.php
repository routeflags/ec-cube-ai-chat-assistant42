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

namespace Plugin\AiChatAssistant42;

use Eccube\Plugin\AbstractPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * PluginManager for AiChatAssistant42.
 *
 * EC-CUBE の PluginService::copyAssets() は install / update 時のみ呼ばれ、
 * enable では呼ばれないため、ボリュームマウントや git pull での
 * アセット更新が html/plugin に追従しない。
 * 本マネージャで enable / update 時に Resource/assets をミラーすることで
 * verify 環境および再有効化時の 404 を防ぐ。
 */
class PluginManager extends AbstractPluginManager
{
    public function enable(array $meta, ContainerInterface $container)
    {
        $this->copyAssets($container);
    }

    public function update(array $meta, ContainerInterface $container)
    {
        $this->copyAssets($container);
    }

    public function disable(array $meta, ContainerInterface $container)
    {
        // アセットは残す（再有効化で再利用）。削除する場合は removeAssets() を呼ぶ。
    }

    private function copyAssets(ContainerInterface $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        // PluginService と同等のパス解決
        if ($container->hasParameter('eccube.plugin_html_realdir')) {
            $pluginHtmlDir = rtrim($container->getParameter('eccube.plugin_html_realdir'), '/');
        } else {
            $pluginHtmlDir = $projectDir.'/html/plugin';
        }

        $source = $projectDir.'/app/Plugin/AiChatAssistant42/Resource/assets';
        // フォールバック: 本リポジトリ直下で動作させる場合（単体テスト等）
        if (!file_exists($source)) {
            $source = __DIR__.'/Resource/assets';
        }

        if (!file_exists($source)) {
            return;
        }

        $target = $pluginHtmlDir.'/AiChatAssistant42/assets';
        $fs = new Filesystem();
        $fs->mirror($source, $target);
    }
}
