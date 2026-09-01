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

namespace Plugin\AiChatAssistant42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\AiChatAssistant42\Repository\ConfigRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * チャットウィジェットのデザイン設定を管理するコントローラ。
 *
 * ウィジェットの色・サイズ・位置・挨拶メッセージを設定し、
 * フロントエンドのチャットウィジェットに反映する。
 *
 * 設定は design_settings.json ファイルに保存される。
 */
class DesignController extends AbstractController
{
    /** デザイン設定 JSON ファイルのパス（永続化: PluginData） */
    private const PLUGIN_DATA_PATH = '/app/PluginData/AiChatAssistant42/design_settings.json';
    /** 旧パス（Resource/config）— 移行用フォールバック */
    private const LEGACY_PATH = __DIR__ . '/../../Resource/config/design_settings.json';

    /** デフォルト値 */
    private const DEFAULTS = [
        'widget_color' => '#2ec9bb',
        'widget_size' => 'medium',
        'position' => 'bottom-right',
        'greeting_message' => 'こんにちは！商品についてお気軽にご質問ください。',
        'assistant_display_name' => '商品アドバイザー',
        'license_footer_label' => 'ライセンスについて',
        'license_title' => 'ソフトウェアライセンスについて',
        'license_lead' => 'AiChatAssistant42（チャットソフトウェア）の著作権は <a href="https://blog.routeflags.com/%e5%88%a9%e7%94%a8%e8%a6%8f%e7%b4%84/" target="_blank" rel="noopener">ROUTE FLAGS Co., Ltd.</a> に帰属し、GNU General Public License v2 (GPL-2.0-only) に基づき提供されています。',
        'license_item1_heading' => '著作権',
        'license_item1_body' => '© 2024-2026 ROUTE FLAGS Co., Ltd. All Rights Reserved.',
        'license_item2_heading' => 'ライセンス (GPL-2.0-only)',
        'license_item2_body' => '本ソフトウェアのソースコードは GPL-2.0-only で提供されています。複製・改変・再配布する際は GPL-2.0 の条件（著作権表示とライセンス条文の保持、改変時の変更明示、ソースコードの提供等）を遵守してください。',
        'license_item3_heading' => 'オープンソースソフトウェアの利用',
        'license_item3_body' => '本ソフトウェアは以下のOSSを利用しています: EC-CUBE 4.2 (GPL-2.0-only)、Symfony 5.4 (MIT)、Doctrine ORM/DBAL (MIT)、Twig 2.x (BSD-3-Clause)、GuzzleHTTP (MIT)、Monolog (MIT)、KnpPaginatorBundle (MIT) ほか composer.json 記載のライブラリ。各OSSのライセンス詳細は各プロジェクトの配布物をご参照ください。',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
        private string $projectDir = '',
        private ?\Plugin\AiChatAssistant42\Service\DesignSettingsSyncService $syncService = null,
    ) {
    }

    private function getDesignSettingsPath(): string
    {
        // PluginData は .gitignore 対象でデプロイの git reset で消えない
        if ($this->projectDir !== '') {
            return $this->projectDir . self::PLUGIN_DATA_PATH;
        }
        // フォールバック（テスト等で projectDir が注入されない場合）
        return dirname(__DIR__, 3) . '/PluginData/AiChatAssistant42/design_settings.json';
    }

    /**
     * デザイン設定フォームを表示する。管理画面アクセス時に1日1回リモート同期を試行（CRONなし）。
     */
    public function index(): Response
    {
        // 管理画面アクセス時に1日1回だけリモート同期（失敗しても表示は継続）
        if ($this->syncService !== null) {
            try {
                $this->syncService->trySyncIfStale();
            } catch (\Throwable $e) {
                // 同期失敗はログのみで画面表示を継続（フロントは DEFAULTS + PluginData でフォールバック）
            }
            $syncMeta = $this->syncService->getSyncMeta();
        } else {
            $syncMeta = ['last_synced_at' => null, 'etag' => null, 'last_modified' => null];
        }

        $designSettings = $this->loadDesignSettings();

        return $this->render('@AiChatAssistant42/admin/design.twig', [
            'design_settings' => $designSettings,
            'sync_meta' => $syncMeta,
            'remote_url' => \Plugin\AiChatAssistant42\Service\DesignSettingsSyncService::REMOTE_URL,
        ]);
    }

    /**
     * デザイン設定を保存する。
     */
    public function save(Request $request, UrlGeneratorInterface $urlGenerator): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_design_index');
        }

        // ライセンス設定UIは削除したため、license_* は既存値を保持（リモート同期が正本）
        $existing = $this->loadDesignSettings();
        $designSettings = [
            'widget_color' => $request->request->get('widget_color', $existing['widget_color'] ?? self::DEFAULTS['widget_color']),
            'widget_size' => $request->request->get('widget_size', $existing['widget_size'] ?? self::DEFAULTS['widget_size']),
            'position' => $request->request->get('position', $existing['position'] ?? self::DEFAULTS['position']),
            'greeting_message' => $request->request->get('greeting_message', $existing['greeting_message'] ?? self::DEFAULTS['greeting_message']),
            'assistant_display_name' => $request->request->get('assistant_display_name', $existing['assistant_display_name'] ?? self::DEFAULTS['assistant_display_name']),
            'license_footer_label' => $request->request->get('license_footer_label', $existing['license_footer_label'] ?? self::DEFAULTS['license_footer_label']),
            'license_title' => $request->request->get('license_title', $existing['license_title'] ?? self::DEFAULTS['license_title']),
            'license_lead' => $request->request->get('license_lead', $existing['license_lead'] ?? self::DEFAULTS['license_lead']),
            'license_item1_heading' => $request->request->get('license_item1_heading', $existing['license_item1_heading'] ?? self::DEFAULTS['license_item1_heading']),
            'license_item1_body' => $request->request->get('license_item1_body', $existing['license_item1_body'] ?? self::DEFAULTS['license_item1_body']),
            'license_item2_heading' => $request->request->get('license_item2_heading', $existing['license_item2_heading'] ?? self::DEFAULTS['license_item2_heading']),
            'license_item2_body' => $request->request->get('license_item2_body', $existing['license_item2_body'] ?? self::DEFAULTS['license_item2_body']),
            'license_item3_heading' => $request->request->get('license_item3_heading', $existing['license_item3_heading'] ?? self::DEFAULTS['license_item3_heading']),
            'license_item3_body' => $request->request->get('license_item3_body', $existing['license_item3_body'] ?? self::DEFAULTS['license_item3_body']),
        ];

        $this->saveDesignSettings($designSettings);

        $this->addSuccess('デザイン設定を保存しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_design_index');
    }

    /**
     * JSON ファイルからデザイン設定を読み込む。
     *
     * ファイルが存在しない場合はデフォルト値を返す。
     * 不正な JSON の場合はログを出力しデフォルト値にフォールバックする。
     */
    private function loadDesignSettings(): array
    {
        $path = $this->getDesignSettingsPath();
        // 永続化パスが存在すればそちらを優先、なければ旧パスから移行を試みる
        if (!file_exists($path) && file_exists(self::LEGACY_PATH)) {
            $path = self::LEGACY_PATH;
        }
        if (!file_exists($path)) {
            return self::DEFAULTS;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return self::DEFAULTS;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::DEFAULTS;
        }

        return array_merge(self::DEFAULTS, $decoded);
    }

    /**
     * デザイン設定を JSON ファイルに保存する。
     *
     * @param array<string, string> $settings 設定配列
     */
    private function saveDesignSettings(array $settings): void
    {
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $path = $this->getDesignSettingsPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $json);
    }
}
