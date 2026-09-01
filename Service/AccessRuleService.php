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

use Plugin\AiChatAssistant42\Entity\Config;
use Symfony\Component\HttpFoundation\Request;

/**
 * チャット API のアクセス制御（rate limit）を判定する。
 *
 * 返却値で制限理由を明確化し、呼び出し側が 429 の原因を区別できるようにする。
 */
class AccessRuleService
{
    /**
     * @param array<string, mixed> $session
     *
     * @return array{allowed: bool, reason: null|'session'|'ip', wait_seconds: int}
     */
    public function evaluate(array $session, Config $config, ?Request $request = null): array
    {
        $rateLimit = max(1, $config->getRateLimitPerMinute());
        $now = time();
        $windowStart = $now - 60;

        $messages = $session['chat_messages'] ?? [];
        $messageCount = is_array($messages) ? count($messages) : 0;
        if ($messageCount >= 100) {
            return [
                'allowed' => false,
                'reason' => 'session',
                'wait_seconds' => 60,
            ];
        }

        $requestTimestamps = $session['chat_request_timestamps'] ?? [];
        if (!is_array($requestTimestamps)) {
            $requestTimestamps = [];
        }

        $requestTimestamps = array_values(array_filter(
            $requestTimestamps,
            static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $windowStart
        ));

        if (count($requestTimestamps) >= $rateLimit) {
            return [
                'allowed' => false,
                'reason' => 'session',
                'wait_seconds' => $this->calculateWaitSeconds($requestTimestamps, $windowStart),
            ];
        }

        $clientIp = $request?->getClientIp() ?? '';
        if ($clientIp !== '') {
            $ipRateLimits = $session['chat_ip_rate_limits'] ?? [];
            if (!is_array($ipRateLimits)) {
                $ipRateLimits = [];
            }

            $ipRateLimits[$clientIp] = array_values(array_filter(
                $ipRateLimits[$clientIp] ?? [],
                static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $windowStart
            ));

            if (count($ipRateLimits[$clientIp]) >= $rateLimit * 2) {
                return [
                    'allowed' => false,
                    'reason' => 'ip',
                    'wait_seconds' => $this->calculateWaitSeconds($ipRateLimits[$clientIp], $windowStart),
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'wait_seconds' => 0,
        ];
    }

    private function calculateWaitSeconds(array $timestamps, int $windowStart): int
    {
        $oldest = min($timestamps);
        $waitSeconds = 61 - ($oldest - $windowStart);

        return max(1, min(60, $waitSeconds));
    }
}
