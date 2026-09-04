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

namespace Plugin\AiChatAssistant42\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 有効化成功時にキャッシュクリアを促す警告フラッシュを追加する.
 *
 * PluginController::enable は CacheUtil::clearCache() を enable 前にのみ呼び、
 * forceClearCache は --no-warmup のため NavCompilerPass による eccube_nav 再構築が
 * 次回 warmup まで遅延する。そのため GUI 成功直後にナビが増えず、暫定で警告を表示する。
 *
 * 条件:
 * - kernel.response で低優先度 (-10) で発火
 * - _route === admin_store_plugin_enable
 * - 302 リダイレクト
 * - FlashBag に eccube.admin.success が存在（成功時のみ）
 *
 * 失敗 (400 / addError) や disable では警告を出さない。
 */
class PluginEnableFlashSubscriber implements EventSubscriberInterface
{
    private const TARGET_ROUTE = 'admin_store_plugin_enable';
    private const FLASH_SUCCESS = 'eccube.admin.success';
    private const FLASH_WARNING = 'eccube.admin.warning';
    private const TRANSLATION_KEY = 'admin.store.plugin.enable.cache_warmup_required';
    private const DUPLICATE_KEYWORD = 'キャッシュのクリア';
    private const DEFAULT_PLUGIN_NAME = 'AI チャットアシスタント';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->shouldHandle($event)) {
            return;
        }

        $flashBag = $this->resolveFlashBag($event);
        if ($flashBag === null) {
            return;
        }

        if (!$this->hasSuccessFlash($flashBag)) {
            return;
        }

        if ($this->hasDuplicateWarning($flashBag)) {
            return;
        }

        $pluginName = $this->resolvePluginName($flashBag->peek(self::FLASH_SUCCESS));
        $warningMessage = $this->buildWarningMessage($pluginName);

        $flashBag->add(self::FLASH_WARNING, $warningMessage);
    }

    private function shouldHandle(ResponseEvent $event): bool
    {
        if (!$this->isMainRequest($event)) {
            return false;
        }

        $request = $event->getRequest();

        if ($request->attributes->get('_route') !== self::TARGET_ROUTE) {
            return false;
        }

        return $event->getResponse()->getStatusCode() === 302;
    }

    private function resolveFlashBag(ResponseEvent $event): ?FlashBagInterface
    {
        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();
        if ($session === null) {
            return null;
        }

        // @phpstan-ignore-next-line SessionInterface には getFlashBag があるが phpstan が検出できない場合がある
        return $session->getFlashBag();
    }

    private function hasSuccessFlash(FlashBagInterface $flashBag): bool
    {
        if (!$flashBag->has(self::FLASH_SUCCESS)) {
            return false;
        }

        return !empty($flashBag->peek(self::FLASH_SUCCESS));
    }

    private function hasDuplicateWarning(FlashBagInterface $flashBag): bool
    {
        foreach ($flashBag->peek(self::FLASH_WARNING) as $existing) {
            if (str_contains((string) $existing, self::DUPLICATE_KEYWORD)) {
                return true;
            }
        }

        return false;
    }

    private function buildWarningMessage(string $pluginName): string
    {
        // XSS 対策: プラグイン名はエスケープしてから trans に渡し、テンプレートでは |raw を使わない
        $escapedPluginName = htmlspecialchars($pluginName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $warningMessage = $this->translator->trans(
            self::TRANSLATION_KEY,
            ['%plugin_name%' => $escapedPluginName]
        );

        // 翻訳ファイルがまだ読み込まれていない場合（キャッシュ未 warmup）はキーがそのまま返るためフォールバック
        if ($warningMessage === self::TRANSLATION_KEY) {
            return sprintf(
                '「%s」のナビゲーションを反映するには、コンテンツ管理 > キャッシュ管理 からキャッシュクリアを実行してください。',
                $escapedPluginName
            );
        }

        return $warningMessage;
    }

    /**
     * Flash の success メッセージからプラグイン名を推測する.
     *
     * 例: "「AI チャットアシスタント」を有効にしました。" から中身を抜く。
     * 取得できなければデフォルト名を返す。
     */
    private function resolvePluginName(array $successMessages): string
    {
        foreach ($successMessages as $message) {
            $messageString = (string) $message;
            if (preg_match('/「(.+?)」を有効/u', $messageString, $matches) === 1) {
                $candidate = trim($matches[1]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return self::DEFAULT_PLUGIN_NAME;
    }

    /**
     * Symfony 5.4 互換: isMainRequest / isMasterRequest の両対応.
     */
    private function isMainRequest(ResponseEvent $event): bool
    {
        if (method_exists($event, 'isMainRequest')) {
            return $event->isMainRequest();
        }

        // @phpstan-ignore-next-line Symfony 5.3 以前のフォールバック
        return $event->isMasterRequest();
    }
}
