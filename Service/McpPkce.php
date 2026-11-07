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
 * PKCE（RFC 7636）の `S256` 検証。
 *
 * `plain` は受け付けない。認可コード横取りへの防御は S256 前提で成立する。
 */
final class McpPkce
{
    public const METHOD = 'S256';

    /**
     * code_verifier が code_challenge と対応するか。
     */
    public static function verify(string $codeVerifier, string $codeChallenge, ?string $method): bool
    {
        if (strtoupper((string) $method) !== self::METHOD) {
            return false;
        }

        // RFC 7636: verifier は 43〜128 文字の unreserved 文字のみ
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeVerifier) !== 1) {
            return false;
        }

        return hash_equals($codeChallenge, self::challenge($codeVerifier));
    }

    /**
     * verifier から S256 challenge を作る（テストと将来のクライアント実装用）。
     */
    public static function challenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
