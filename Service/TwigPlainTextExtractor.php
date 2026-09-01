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

namespace Plugin\AiChatAssistant42\Service;

/**
 * Twig/HTML からプレーンテキストを抽出する共通ヘルパー。
 *
 * ProductRepository::htmlToPlainText と ChatFlowService::twigToPlainText /
 * plainTextExcerpt の重複を解消するための単一責務クラス。
 * Twig タグ除去・HTML デコード・タグ除去・空白正規化を一箇所に集約する。
 */
class TwigPlainTextExtractor
{
    /**
     * Twig/HTML 文字列をプレーンテキスト化する。
     *
     * agreement.twig のように {{ '...HTML...'|raw }} 形式で
     * コンテンツが文字列リテラルに含まれる場合は中身を保持する。
     */
    public function extract(string $html): string
    {
        $withoutTwig = preg_replace('/\{#.*?#\}/s', '', $html) ?? $html;
        $withoutTwig = preg_replace('/\{%.*?%\}/s', '', $withoutTwig) ?? $withoutTwig;
        $withoutTwig = preg_replace("/\{\{\s*'(.*?)'\s*(?:\|[^}]*)?\}\}/s", '$1', $withoutTwig) ?? $withoutTwig;
        $withoutTwig = preg_replace('/\{\{\s*"(.*?)"\s*(?:\|[^}]*)?\}\}/s', '$1', $withoutTwig) ?? $withoutTwig;
        $withoutTwig = preg_replace('/\{\{.*?\}\}/s', '', $withoutTwig) ?? $withoutTwig;
        $withoutTwig = preg_replace('/<style.*?<\/style>/is', '', $withoutTwig) ?? $withoutTwig;
        $withoutTwig = preg_replace('/<script.*?<\/script>/is', '', $withoutTwig) ?? $withoutTwig;

        $decoded = html_entity_decode($withoutTwig, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);
        $normalized = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;

        return trim($normalized);
    }

    /**
     * HTML/テキストの抜粋をプレーンテキスト化して切り出す。
     */
    public function excerpt(?string $html, int $limit): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = strip_tags($decoded);
        $normalized = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
        $trimmed = trim($normalized);

        return mb_substr($trimmed, 0, $limit);
    }
}
