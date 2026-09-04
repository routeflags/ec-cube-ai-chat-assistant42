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
 * メールアドレスのハッシュ化・暗号化を担う薄いラッパー。
 *
 * - hash: HMAC-SHA256(normalized_email, APP_SECRET pepper) 64hex。不可逆、集計/レート制限用。
 * - enc: ApiKeyEncryptor(AES-256-GCM) で可逆暗号化。送信時に復号、30日で削除。
 *
 * 正規化: trim + strtolower（大文字小文字を同一視）。
 */
class EmailHashService
{
    public function __construct(
        private string $appSecret,
        private ApiKeyEncryptor $encryptor,
    ) {
    }

    public function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * 正規化済みメールから HMAC-SHA256 ハッシュを生成する。
     */
    public function hash(string $email): string
    {
        $normalized = $this->normalize($email);
        $pepper = hash('sha256', $this->appSecret, true);

        return hash_hmac('sha256', $normalized, $pepper);
    }

    /**
     * メールを暗号化する（送信時復号用）。
     */
    public function encrypt(string $email): string
    {
        return $this->encryptor->encrypt($this->normalize($email));
    }

    /**
     * 暗号文を復号する。
     */
    public function decrypt(string $enc): string
    {
        return $this->encryptor->decrypt($enc);
    }

    /**
     * ハッシュの検証（タイミング安全）。
     */
    public function verify(string $email, string $hash): bool
    {
        return hash_equals($this->hash($email), $hash);
    }

    /**
     * 表示用マスク（hash の先頭8 + ***@***）。
     */
    public function mask(string $hash): string
    {
        if ($hash === '') {
            return '***';
        }

        return substr($hash, 0, 8) . '***@***';
    }
}
