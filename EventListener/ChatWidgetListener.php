<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Event\TemplateEvent;
use Plugin\AiChatAssistant42\Repository\ConfigRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * フロントページにチャットウィジェットを自動挿入するリスナー。
 *
 * design_settings.json からウィジェットの外観設定を読み込み、
 * Twig テンプレート変数として渡す。
 */
class ChatWidgetListener implements EventSubscriberInterface
{
    private const PLUGIN_DATA_PATH = '/app/PluginData/AiChatAssistant42/design_settings.json';
    private const LEGACY_PATH = __DIR__ . '/../Resource/config/design_settings.json';

    public function __construct(
        private ConfigRepository $configRepository,
        private string $projectDir = '',
    ) {
    }

    private function getDesignSettingsPath(): string
    {
        if ($this->projectDir !== '') {
            return $this->projectDir . self::PLUGIN_DATA_PATH;
        }
        return dirname(__DIR__, 3) . '/PluginData/AiChatAssistant42/design_settings.json';
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'default_frame.twig' => ['onDefaultFrame', 0],
        ];
    }

    public function onDefaultFrame(TemplateEvent $event): void
    {
        // Config テーブルから設定を取得（ID 固定ではなく最小 ID を使用）
        $config = $this->configRepository->get();

        if ($config === null || !$config->getIsEnabled()) {
            return;
        }

        // デザイン設定を読み込む
        $designSettings = $this->loadDesignSettings();

        $event->setParameter('chat_widget_color', $designSettings['widget_color'] ?? '#2ec9bb');
        $event->setParameter('chat_widget_size', $designSettings['widget_size'] ?? 'medium');
        $event->setParameter('chat_widget_position', $designSettings['position'] ?? 'bottom-right');
        $event->setParameter('chat_greeting_message', $designSettings['greeting_message'] ?? 'こんにちは！商品についてお気軽にご質問ください。');
        $event->setParameter('chat_assistant_display_name', $designSettings['assistant_display_name'] ?? '商品アドバイザー');
        // ライセンス情報（design_settings.json 正本、Twigでハードコード禁止）
        $event->setParameter('chat_license_footer_label', $designSettings['license_footer_label'] ?? 'ライセンスについて');
        $event->setParameter('chat_license_title', $designSettings['license_title'] ?? 'ソフトウェアライセンスについて');
        $event->setParameter(
            'chat_license_lead',
            $designSettings['license_lead'] ?? 'AiChatAssistant42（チャットソフトウェア）の著作権は ROUTE FLAGS Co., Ltd. に帰属し、'
                . 'GNU General Public License v2 (GPL-2.0-only) に基づき提供されています。'
        );
        $event->setParameter('chat_license_item1_heading', $designSettings['license_item1_heading'] ?? '著作権');
        $event->setParameter(
            'chat_license_item1_body',
            $designSettings['license_item1_body'] ?? '© 2024-2026 ROUTE FLAGS Co., Ltd. All Rights Reserved.'
        );
        $event->setParameter('chat_license_item2_heading', $designSettings['license_item2_heading'] ?? 'ライセンス (GPL-2.0-only)');
        $event->setParameter('chat_license_item2_body', $designSettings['license_item2_body'] ?? '本ソフトウェアのソースコードは GPL-2.0-only で提供されています。');
        $event->setParameter('chat_license_item3_heading', $designSettings['license_item3_heading'] ?? 'オープンソースソフトウェアの利用');
        $event->setParameter(
            'chat_license_item3_body',
            $designSettings['license_item3_body'] ?? '本ソフトウェアは EC-CUBE 4.2 ほか composer.json 記載のOSSを利用しています。'
        );
        // 将来の利用規約拡張（JSONで後から追加可能、存在時のみ描画）
        if (!empty($designSettings['terms_footer_label'])) {
            $event->setParameter('chat_terms_footer_label', $designSettings['terms_footer_label']);
            $event->setParameter(
                'chat_terms_url',
                $designSettings['terms_url'] ?? 'https://blog.routeflags.com/%e5%88%a9%e7%94%a8%e8%a6%8f%e7%b4%84/'
            );
            $event->setParameter('chat_terms_title', $designSettings['terms_title'] ?? '利用規約');
            $event->setParameter('chat_terms_body', $designSettings['terms_body'] ?? '');
        }
        $event->addSnippet('@AiChatAssistant42/default/chat_widget.twig');
    }

    /**
     * design_settings.json から設定を読み込む。DEFAULTS とマージして後方互換を担保。
     */
    private function loadDesignSettings(): array
    {
        $path = $this->getDesignSettingsPath();
        // 永続化パスがなければ旧パスから読む（移行期）
        if (!file_exists($path) && file_exists(self::LEGACY_PATH)) {
            $path = self::LEGACY_PATH;
        }
        if (!file_exists($path)) {
            return \Plugin\AiChatAssistant42\Service\DesignSettingsSyncService::DEFAULTS;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return \Plugin\AiChatAssistant42\Service\DesignSettingsSyncService::DEFAULTS;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return \Plugin\AiChatAssistant42\Service\DesignSettingsSyncService::DEFAULTS;
        }
        // 未知キー除去 + DEFAULTS 補完で後方互換（旧5キーの PluginData でも license_* が欠落しない）
        $allowed = array_flip(array_keys(\Plugin\AiChatAssistant42\Service\DesignSettingsSyncService::DEFAULTS));
        $filtered = array_intersect_key($data, $allowed);
        return array_merge(\Plugin\AiChatAssistant42\Service\DesignSettingsSyncService::DEFAULTS, $filtered);
    }
}
