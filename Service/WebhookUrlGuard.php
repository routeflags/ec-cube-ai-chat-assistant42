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
 * Webhook URL の SSRF ガード。
 *
 * 送信先 URL が https であり、プライベート IP / ローカルホスト /
 * link-local / 16 進表記による迂回でないことを検証する。
 * 送信処理（NotificationService）と検証ルールを分離するため切り出した。
 */
final class WebhookUrlGuard
{
    /**
     * Webhook URL の安全性を検証する。
     *
     * - https のみ許可
     * - プライベートIP / ローカルホスト / link-local を禁止
     * - ブラケット付き IPv6（例: [::1]）を正しく検出
     * - 10/16進表記の迂回を拒否（filter_var + 明示的な 16進チェック）
     */
    public function isSafe(string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }
        if (!$this->isHttpsUrl($parsed)) {
            return false;
        }
        $host = $this->normalizeWebhookHost((string) $parsed['host']);
        if ($this->isHexBypassHost($host)) {
            return false;
        }
        if ($this->isLocalHost($host)) {
            return false;
        }
        $ipLiteralResult = $this->checkIpLiteralHost($host);
        if ($ipLiteralResult !== null) {
            return $ipLiteralResult;
        }
        if ($this->isDnsResolvedPrivateHost($host)) {
            return false;
        }
        if ($this->hasDotHexSegment($host)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $parsed parse_url() の結果
     */
    private function isHttpsUrl(array $parsed): bool
    {
        return ($parsed['scheme'] ?? '') === 'https';
    }

    private function normalizeWebhookHost(string $rawHost): string
    {
        return strtolower(trim($rawHost, '[]'));
    }

    private function isHexBypassHost(string $host): bool
    {
        if (preg_match('/^0x[0-9a-f]+/i', $host) === 1 || preg_match('/^[0-9]+$/', $host) === 1) {
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                if (preg_match('/^0x/i', $host) === 1) {
                    return true;
                }
                if (ctype_digit($host)) {
                    return true;
                }
            }
        }
        if (strpos($host, '0x') !== false || strpos($host, '0X') !== false) {
            return true;
        }

        return false;
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', '::', '::ffff:127.0.0.1'], true);
    }

    /**
     * IP リテラルホストを検証する。
     *
     * @return bool|null ブロックなら false、許可なら true、IPでなければ null
     */
    private function checkIpLiteralHost(string $host): ?bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    private function isDnsResolvedPrivateHost(string $host): bool
    {
        $resolvedIp = gethostbyname($host);
        if ($resolvedIp === $host) {
            return false;
        }
        return filter_var(
            $resolvedIp,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function hasDotHexSegment(string $host): bool
    {
        return preg_match('/(?:^|\.)0x[0-9a-f]+/i', $host) === 1;
    }
}
