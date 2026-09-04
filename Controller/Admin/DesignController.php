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
use Plugin\AiChatAssistant42\Service\DesignSettingsSyncService;
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
        'license_lead' => 'AiChatAssistant42（チャットソフトウェア）の著作権は '
            . '<a href="https://blog.routeflags.com/%e5%88%a9%e7%94%a8%e8%a6%8f%e7%b4%84/"'
            . ' target="_blank" rel="noopener">ROUTE FLAGS Co., Ltd.</a> に帰属し、'
            . 'GNU General Public License v2 (GPL-2.0-only) に基づき提供されています。',
        'license_item1_heading' => '著作権',
        'license_item1_body' => '© 2024-2026 ROUTE FLAGS Co., Ltd. All Rights Reserved.',
        'license_item2_heading' => 'ライセンス (GPL-2.0-only)',
        'license_item2_body' => '本ソフトウェアのソースコードは GPL-2.0-only で提供されています。'
            . '複製・改変・再配布する際は GPL-2.0 の条件（著作権表示とライセンス条文の保持、'
            . '改変時の変更明示、ソースコードの提供等）を遵守してください。',
        'license_item3_heading' => 'オープンソースソフトウェアの利用',
        'license_item3_body' => '本ソフトウェアは以下のOSSを利用しています: EC-CUBE 4.2 (GPL-2.0-only)、'
            . 'Symfony 5.4 (MIT)、Doctrine ORM/DBAL (MIT)、Twig 2.x (BSD-3-Clause)、'
            . 'GuzzleHTTP (MIT)、Monolog (MIT)、KnpPaginatorBundle (MIT) ほか '
            . 'composer.json 記載のライブラリ。各OSSのライセンス詳細は各プロジェクトの配布物を'
            . 'ご参照ください。',
    ];

    public function __construct(
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
        $syncMeta = ['last_synced_at' => null, 'etag' => null, 'last_modified' => null];
        if ($this->syncService !== null) {
            try {
                $this->syncService->trySyncIfStale();
            } catch (\Throwable $e) {
                // 同期失敗はログのみで画面表示を継続（フロントは DEFAULTS + PluginData でフォールバック）
            }
            $syncMeta = $this->syncService->getSyncMeta();
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
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function save(Request $request): RedirectResponse
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_design_index');
        }

        // I-15/I-22: 入力バリデーション + ライセンスHTMLサニタイズ（ガイドライン §2-2）
        $existing = $this->loadDesignSettings();
        $rawInput = [
            'widget_color' => $request->request->get(
                'widget_color',
                $existing['widget_color'] ?? self::DEFAULTS['widget_color']
            ),
            'widget_size' => $request->request->get(
                'widget_size',
                $existing['widget_size'] ?? self::DEFAULTS['widget_size']
            ),
            'position' => $request->request->get(
                'position',
                $existing['position'] ?? self::DEFAULTS['position']
            ),
            'greeting_message' => $request->request->get(
                'greeting_message',
                $existing['greeting_message'] ?? self::DEFAULTS['greeting_message']
            ),
            'assistant_display_name' => $request->request->get(
                'assistant_display_name',
                $existing['assistant_display_name'] ?? self::DEFAULTS['assistant_display_name']
            ),
            'license_footer_label' => $request->request->get(
                'license_footer_label',
                $existing['license_footer_label'] ?? self::DEFAULTS['license_footer_label']
            ),
            'license_title' => $request->request->get(
                'license_title',
                $existing['license_title'] ?? self::DEFAULTS['license_title']
            ),
            'license_lead' => $request->request->get(
                'license_lead',
                $existing['license_lead'] ?? self::DEFAULTS['license_lead']
            ),
            'license_item1_heading' => $request->request->get(
                'license_item1_heading',
                $existing['license_item1_heading'] ?? self::DEFAULTS['license_item1_heading']
            ),
            'license_item1_body' => $request->request->get(
                'license_item1_body',
                $existing['license_item1_body'] ?? self::DEFAULTS['license_item1_body']
            ),
            'license_item2_heading' => $request->request->get(
                'license_item2_heading',
                $existing['license_item2_heading'] ?? self::DEFAULTS['license_item2_heading']
            ),
            'license_item2_body' => $request->request->get(
                'license_item2_body',
                $existing['license_item2_body'] ?? self::DEFAULTS['license_item2_body']
            ),
            'license_item3_heading' => $request->request->get(
                'license_item3_heading',
                $existing['license_item3_heading'] ?? self::DEFAULTS['license_item3_heading']
            ),
            'license_item3_body' => $request->request->get(
                'license_item3_body',
                $existing['license_item3_body'] ?? self::DEFAULTS['license_item3_body']
            ),
        ];

        // バリデーション
        $validation = DesignSettingsSyncService::validateInput($rawInput);
        // 追加: widget 固有の検証（色・サイズ・位置）
        $extraErrors = $this->validateWidgetSettings($rawInput);
        $allErrors = array_merge($validation['errors'], $extraErrors);
        if (!empty($allErrors)) {
            foreach ($allErrors as $msg) {
                $this->addError($msg, 'admin');
            }

            return $this->redirectToRoute('admin_ai_chat_assistant_design_index');
        }

        // ライセンス系はサニタイズ（HTMLタグは除去しプレーンに — I-22）
        $designSettings = $this->sanitizeLicenseFields($validation['sanitized']);

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
     * widget 固有フィールドを追加検証する（I-15）。
     *
     * @param array<string,mixed> $input
     * @return string[]
     */
    private function validateWidgetSettings(array $input): array
    {
        $errors = [];
        if (isset($input['widget_color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $input['widget_color'])) {
            $errors[] = 'ウィジェットカラーは #RRGGBB 形式で入力してください。';
        }
        if (isset($input['widget_size']) && !in_array($input['widget_size'], ['small', 'medium', 'large'], true)) {
            $errors[] = 'ウィジェットサイズは small / medium / large のいずれかで指定してください。';
        }
        if (isset($input['position']) && !in_array($input['position'], ['bottom-right', 'bottom-left'], true)) {
            $errors[] = '表示位置は bottom-right / bottom-left のいずれかで指定してください。';
        }
        if (isset($input['greeting_message']) && mb_strlen((string) $input['greeting_message']) > 500) {
            $errors[] = '挨拶メッセージは500文字以内で入力してください。';
        }
        if (isset($input['assistant_display_name']) && mb_strlen((string) $input['assistant_display_name']) > 64) {
            $errors[] = 'アシスタント表示名は64文字以内で入力してください。';
        }

        return $errors;
    }

    /**
     * ライセンス系フィールドをサニタイズする（I-22: license_html でリンクのみ許可）。
     *
     * JSON は配信者が正本だが、テンプレ側の license_html フィルタと二重で防御する。
     * <a> 以外は除去し、<a> の href は許可URLのみ通す。
     *
     * @param array<string,string> $sanitized
     * @return array<string,string>
     */
    private function sanitizeLicenseFields(array $sanitized): array
    {
        $allowedHref = 'https://blog.routeflags.com/%e5%88%a9%e7%94%a8%e8%a6%8f%e7%b4%84/';
        $licenseKeys = [
            'license_footer_label', 'license_title', 'license_lead',
            'license_item1_heading', 'license_item1_body',
            'license_item2_heading', 'license_item2_body',
            'license_item3_heading', 'license_item3_body',
        ];
        foreach ($licenseKeys as $k) {
            if (!isset($sanitized[$k])) {
                continue;
            }
            $html = trim($sanitized[$k]);
            // <a> 以外除去
            $html = strip_tags($html, '<a>');
            // <a> の href を検証 — 許可 href 以外はテキスト化
            $html = (string) preg_replace_callback(
                '/<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
                static function (array $m) use ($allowedHref): string {
                    $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $text = $m[3];
                    if ($href !== $allowedHref) {
                        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                    $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $safeHref = htmlspecialchars($allowedHref, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', $safeHref, $safeText);
                },
                $html
            );
            // 残った <a> 以外の < > はエスケープ済み（strip_tags で除去済み）
            $sanitized[$k] = $html;
        }

        return $sanitized;
    }

    /**
     * デザイン設定を JSON ファイルに保存する.
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
