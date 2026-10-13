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

namespace Plugin\AiChatAssistant44;

use Eccube\Plugin\AbstractPluginManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * PluginManager for AiChatAssistant44.
 *
 * EC-CUBE の PluginService::copyAssets() は install / update 時のみ呼ばれ、
 * enable では呼ばれないため、ボリュームマウントや git pull での
 * アセット更新が html/plugin に追従しない。
 * 本マネージャで enable / update 時に Resource/assets をミラーすることで
 * verify 環境および再有効化時の 404 を防ぐ。
 */
class PluginManager extends AbstractPluginManager
{
    #[\Override]
    public function enable(array $meta, ContainerInterface $container): void
    {
        $pluginCode = $this->resolvePluginCode($meta);
        $this->logPluginAction('enable', $pluginCode, $container);
        $this->copyAssets($container);
        $this->migrateLegacyPluginData($container);
    }

    #[\Override]
    public function update(array $meta, ContainerInterface $container): void
    {
        $pluginCode = $this->resolvePluginCode($meta);
        $this->logPluginAction('update', $pluginCode, $container);
        $this->copyAssets($container);
        $this->migrateLegacyPluginData($container);
    }

    #[\Override]
    public function disable(array $meta, ContainerInterface $container): void
    {
        $pluginCode = $this->resolvePluginCode($meta);
        $this->logPluginAction('disable', $pluginCode, $container);
        $projectDir = '';
        if ($container instanceof SymfonyContainerInterface && $container->hasParameter('kernel.project_dir')) {
            $projectDir = (string) $container->getParameter('kernel.project_dir');
        }
        if ($pluginCode === '' && $projectDir === '') {
            return;
        }
        // アセットは残す（再有効化で再利用）。削除する場合は removeAssets() を呼ぶ。
    }

    private function resolvePluginCode(array $meta): string
    {
        $pluginCode = $meta['code'] ?? 'AiChatAssistant44';
        if (!is_string($pluginCode) || $pluginCode === '') {
            return 'AiChatAssistant44';
        }

        return $pluginCode;
    }

    private function logPluginAction(string $action, string $pluginCode, ContainerInterface $container): void
    {
        if (!$container->has('logger')) {
            return;
        }
        try {
            $logger = $container->get('logger');
            if ($logger instanceof \Psr\Log\LoggerInterface) {
                $logger->info(sprintf('AiChatAssistant44 plugin %s', $action), ['code' => $pluginCode]);
            }
        } catch (\Throwable $e) {
            // ログ取得失敗は無視（プラグイン有効化を妨げない）
        }
    }

    private function copyAssets(ContainerInterface $container): void
    {
        $projectDir = '';
        if ($container instanceof SymfonyContainerInterface && $container->hasParameter('kernel.project_dir')) {
            $projectDir = (string) $container->getParameter('kernel.project_dir');
        }
        if ($projectDir === '') {
            return;
        }
        // PluginService と同等のパス解決
        $pluginHtmlDir = $projectDir . '/html/plugin';
        if ($container instanceof SymfonyContainerInterface && $container->hasParameter('eccube.plugin_html_realdir')) {
            $pluginHtmlDir = rtrim((string) $container->getParameter('eccube.plugin_html_realdir'), '/');
        }

        $source = $projectDir . '/app/Plugin/AiChatAssistant44/Resource/assets';
        // フォールバック: 本リポジトリ直下で動作させる場合（単体テスト等）
        if (!file_exists($source)) {
            $source = __DIR__ . '/Resource/assets';
        }

        if (!file_exists($source)) {
            return;
        }

        $target = $pluginHtmlDir . '/AiChatAssistant44/assets';
        $fs = new Filesystem();
        $fs->mirror($source, $target);
    }

    /**
     * S-07 データ移行: 旧code (AiChatAssistant42) の PluginData JSON を新code側へ引き継ぐ。
     *
     * - テーブル `plg_ai_chat_assistant_*` は改名しないためDB移行は不要 (T-02)。
     * - JSON (`design_settings.json` / `ai_models.json`) のみ、新側に存在せず旧側に存在する場合にコピーする。
     * - 新側が既にある場合は上書きしない (無移行リリース禁止・消失禁止)。
     */
    private function migrateLegacyPluginData(ContainerInterface $container): void
    {
        $projectDir = '';
        if ($container instanceof SymfonyContainerInterface && $container->hasParameter('kernel.project_dir')) {
            $projectDir = (string) $container->getParameter('kernel.project_dir');
        }
        if ($projectDir === '') {
            return;
        }
        $fs = new Filesystem();
        foreach (['design_settings.json', 'ai_models.json'] as $file) {
            $legacy = $projectDir . '/app/PluginData/AiChatAssistant42/' . $file;
            $current = $projectDir . '/app/PluginData/AiChatAssistant44/' . $file;
            try {
                if (is_file($legacy) && !is_file($current)) {
                    $fs->mkdir(dirname($current));
                    $fs->copy($legacy, $current);
                }
            } catch (\Throwable $e) {
                // 移行失敗は有効化を妨げない (T-01 異常系: 例外なく return 相当)
            }
        }
    }

    #[\Override]
    public function uninstall(array $meta, ContainerInterface $container): void
    {
        $pluginCode = $this->resolvePluginCode($meta);
        $this->logPluginAction('uninstall', $pluginCode, $container);
        // PluginData / テーブルは保持する (履歴・ナレッジ断絶防止 R-05)。後片付けはEC-CUBE本体に委譲。
    }
}
