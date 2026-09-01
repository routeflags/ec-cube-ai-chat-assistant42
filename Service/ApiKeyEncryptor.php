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
 * API キーの暗号化・復号を担当するサービス.
 *
 * APP_SECRET を鍵として AES-256-GCM で暗号化する.
 * 保存形式: base64(nonce(12) + ciphertext + tag(16))
 */
class ApiKeyEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(string $appSecret)
    {
        // APP_SECRET から 32byte の鍵を導出（SHA-256）
        $this->key = hash('sha256', $appSecret, true);
    }

    /**
     * 平文を暗号化する.
     */
    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('APIキーの暗号化に失敗しました');
        }

        return base64_encode($nonce . $ciphertext . $tag);
    }

    /**
     * 暗号文を復号する.
     *
     * 平文で保存された既存データの場合はそのまま返す（後方互換）.
     */
    public function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        if (!$this->isEncrypted($encrypted)) {
            // 平文の既存データ
            return $encrypted;
        }

        $decoded = base64_decode($encrypted, true);
        if ($decoded === false || strlen($decoded) < self::NONCE_LENGTH + self::TAG_LENGTH + 1) {
            // 復号失敗時は平文として扱う（後方互換）
            return $encrypted;
        }

        $nonce = substr($decoded, 0, self::NONCE_LENGTH);
        $tag = substr($decoded, -self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::NONCE_LENGTH, -self::TAG_LENGTH);

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plain === false) {
            // APP_SECRET 変更等で復号失敗した場合は平文として扱う（ログは呼び出し側で）
            return $encrypted;
        }

        return $plain;
    }

    /**
     * 暗号化された形式かどうかを判定する.
     */
    public function isEncrypted(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        // base64 かつ 12+1+16 以上の長さ
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        if (strlen($decoded) < self::NONCE_LENGTH + self::TAG_LENGTH + 1) {
            return false;
        }

        // 平文の API キーは通常 sk- や AQ. 等で始まり、base64 ではないか、長さが短い
        // 暗号化されたものは base64 でランダムなバイト列を含むため、平文と区別可能
        // 簡易判定: base64 デコード後に再エンコードして一致するか
        return base64_encode($decoded) === $value;
    }
}
