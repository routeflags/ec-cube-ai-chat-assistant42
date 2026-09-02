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

namespace Plugin\AiChatAssistant42\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * ライセンス表示用の HTML サニタイズフィルタ。
 *
 * 目的: リンクのみ許可し、それ以外の HTML は無害化する。
 * JSON は配信者（PluginData）が正本だが、テンプレ側でも二重に防御する。
 *
 * - 許可タグ: <a> のみ（それ以外は strip_tags で除去）
 * - 許可 href: https://blog.routeflags.com/%e5%88%a9%e7%94%a8%e8%a6%8f%e7%b4%84/ の完全一致のみ
 * - href が不正な <a> はテキスト化（タグ除去）
 * - 属性は href のみ残し、target="_blank" rel="noopener" は付与し直す
 */
class LicenseHtmlExtension extends AbstractExtension
{
    private const ALLOWED_HREF = 'https://blog.routeflags.com/%e5%88%a9%e7%94%a8%e8%a6%8f%e7%b4%84/';

    public function getFilters(): array
    {
        return [
            new TwigFilter('license_html', [$this, 'sanitize'], ['is_safe' => ['html']]),
        ];
    }

    public function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // 1. <a> 以外を除去
        $stripped = strip_tags($html, '<a>');

        // 2. <a> を検証し、不正なものはタグ除去
        return (string) preg_replace_callback(
            '/<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
            function (array $m): string {
                $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = $m[3];

                // 許可 href のみ通す（完全一致）
                if ($href !== self::ALLOWED_HREF) {
                    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }

                // テキストはエスケープ、属性は固定値のみ
                $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $safeHref = htmlspecialchars(self::ALLOWED_HREF, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', $safeHref, $safeText);
            },
            $stripped
        );
    }
}
