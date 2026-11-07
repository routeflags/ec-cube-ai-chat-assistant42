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
 * リダイレクト URI の検証。
 *
 * OAuth の認可コードは、事前に登録された URI と **完全一致** した場合にのみ返す。
 * 前方一致・ワイルドカード・フラグメント付きは、コードの横取り経路になるため拒否する。
 */
final class McpRedirectUri
{
    /**
     * 登録済み URI として受け入れ可能か（登録時の検証）。
     */
    public static function isValidRedirectUri(string $uri): bool
    {
        if ($uri === '' || str_contains($uri, '#')) {
            return false;
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }

        // ループバックは平文を許す（RFC 8252 のネイティブアプリ向け例外）
        return $scheme === 'http' && in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * リクエストの redirect_uri が登録値と完全一致するか。
     */
    public static function matches(string $requested, string $registered): bool
    {
        return $requested !== '' && hash_equals($registered, $requested);
    }
}
