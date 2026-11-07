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

namespace Plugin\AiChatAssistant42\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Service\McpAccessPolicy;

/**
 * MCP 公開ポリシーの検証。
 *
 * トークン未設定時は従来動作（無認証・全オリジン許可）のままであることを含めて確認する。
 */
class McpAccessPolicyTest extends TestCase
{
    public function testRequiresTokenIsFalseWhenUnset(): void
    {
        $policy = new McpAccessPolicy(null, null, null);

        $this->assertFalse($policy->requiresToken());
        $this->assertTrue($policy->isAuthorized(null, null));
    }

    public function testRequiresTokenIsFalseWhenBlank(): void
    {
        $policy = new McpAccessPolicy(null, '   ', null);

        $this->assertFalse($policy->requiresToken());
    }

    public function testRequiresTokenIsTrueWhenConfigured(): void
    {
        $policy = new McpAccessPolicy(null, 'abcdefghijklmnop', null);

        $this->assertTrue($policy->requiresToken());
        $this->assertFalse($policy->isAuthorized(null, null));
    }

    public function testAuthorizesBearerToken(): void
    {
        $policy = new McpAccessPolicy(null, 'abcdefghijklmnop', null);

        $this->assertTrue($policy->isAuthorized('Bearer abcdefghijklmnop', null));
        $this->assertTrue($policy->isAuthorized('bearer abcdefghijklmnop', null));
        $this->assertFalse($policy->isAuthorized('Bearer wrong-token-value', null));
    }

    public function testAuthorizesCustomHeaderToken(): void
    {
        $policy = new McpAccessPolicy(null, 'abcdefghijklmnop', null);

        $this->assertTrue($policy->isAuthorized(null, 'abcdefghijklmnop'));
        $this->assertFalse($policy->isAuthorized(null, 'abcdefghijklmnoX'));
    }

    public function testRateLimitKeyUsesTokenWhenPresent(): void
    {
        $policy = new McpAccessPolicy(null, 'abcdefghijklmnop', null);

        $withToken = $policy->rateLimitKey('203.0.113.9', 'Bearer abcdefghijklmnop', null);
        $otherIp = $policy->rateLimitKey('198.51.100.7', 'Bearer abcdefghijklmnop', null);
        $otherToken = $policy->rateLimitKey('203.0.113.9', 'Bearer zzzzzzzzzzzzzzzz', null);

        $this->assertStringStartsWith('tok.', $withToken);
        $this->assertSame($withToken, $otherIp, 'same token should share one bucket regardless of IP');
        $this->assertNotSame($withToken, $otherToken);
    }

    public function testRateLimitKeyFallsBackToIp(): void
    {
        $policy = new McpAccessPolicy(null, 'abcdefghijklmnop', null);

        $this->assertSame('ip.203.0.113.9', $policy->rateLimitKey('203.0.113.9', null, null));
    }

    public function testAllowedOriginDefaultsToWildcard(): void
    {
        $policy = new McpAccessPolicy(null, null, null);

        $this->assertSame('*', $policy->allowedOrigin('https://anything.example'));
        $this->assertSame('*', $policy->allowedOrigin(null));
    }

    public function testAllowedOriginEchoesConfiguredMatch(): void
    {
        $policy = new McpAccessPolicy(null, null, 'https://shop.example.com,https://admin.example.com');

        $this->assertSame('https://shop.example.com', $policy->allowedOrigin('https://shop.example.com'));
        // CORS は要求された Origin をそのまま返す必要がある（正規化するとブラウザが拒否する）
        $this->assertSame('https://admin.example.com/', $policy->allowedOrigin('https://admin.example.com/'));
    }

    public function testAllowedOriginRejectsUnknownOrigin(): void
    {
        $policy = new McpAccessPolicy(null, null, 'https://shop.example.com');

        $this->assertNull($policy->allowedOrigin('https://evil.example'));
        $this->assertNull($policy->allowedOrigin(null));
    }

    public function testAllowedOriginSupportsWildcardEntry(): void
    {
        $policy = new McpAccessPolicy(null, null, 'https://shop.example.com,*');

        $this->assertSame('*', $policy->allowedOrigin('https://evil.example'));
    }
}
