<?php

declare(strict_types=1);

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

namespace Plugin\AiChatAssistant42\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Service\RateLimitExceededException;
use Plugin\AiChatAssistant42\Service\RateLimitService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * RateLimitService の回帰テスト。
 *
 * QA-02: FilesystemAdapter (PSR-6) はキーに {}()/\@: を含められない。
 * 旧実装 mcp:ratelimit:{ip}:{tool}:{minute} が INVALID になることを検知し、
 * 現行 mcp.ratelimit.{ip}.{tool}.{minute} (+ IP サニタイズ) が valid になることを担保する。
 */
class RateLimitServiceTest extends TestCase
{
    public function testEnforceWithArrayAdapterDoesNotThrow(): void
    {
        $cache = new ArrayAdapter();
        $service = new RateLimitService($cache);

        // IPv6 形式の IP でも PSR-6 合法キーで通ること
        $service->enforce('2001:db8::1', 'search_products');
        $service->enforce('192.168.1.1', 'get_stock');
        $service->enforce('unknown', 'default');

        self::assertTrue(true);
    }

    public function testEnforceWithFilesystemAdapterDoesNotThrowForIpv6(): void
    {
        $cache = new FilesystemAdapter('', 0, sys_get_temp_dir() . '/mcp_ratelimit_test_' . uniqid());
        $service = new RateLimitService($cache);

        // FilesystemAdapter で InvalidArgumentException が出ないこと（回帰: ":" を含むと 500）
        $service->enforce('2001:db8::1', 'search_products');
        $service->enforce('192.168.0.1/24', 'get_stock');
        $service->enforce('unknown', 'well_known');

        self::assertTrue(true);

        $cache->clear();
    }

    public function testOldColonKeyIsInvalidOnFilesystemAdapter(): void
    {
        $cache = new FilesystemAdapter('', 0, sys_get_temp_dir() . '/mcp_ratelimit_old_' . uniqid());

        $this->expectException(\Psr\Cache\InvalidArgumentException::class);
        // 旧キー形式は ":" を含むため PSR-6 違反で例外
        $cache->getItem('mcp:ratelimit:127.0.0.1:default:202501011200');

        $cache->clear();
    }

    public function testNewDotKeyIsValidOnFilesystemAdapter(): void
    {
        $cache = new FilesystemAdapter('', 0, sys_get_temp_dir() . '/mcp_ratelimit_new_' . uniqid());

        // 新キー形式は ":" を含まず valid
        $item = $cache->getItem('mcp.ratelimit.127_0_0_1.default.202501011200');
        self::assertFalse($item->isHit());

        $cache->clear();
    }

    public function testEnforceThrowsAfterLimitExceeded(): void
    {
        $cache = new ArrayAdapter();
        $service = new RateLimitService($cache);

        // default limit 120 を超える
        for ($i = 0; $i < 120; $i++) {
            $service->enforce('10.0.0.1', 'default');
        }

        $this->expectException(RateLimitExceededException::class);
        $service->enforce('10.0.0.1', 'default');
    }

    public function testGetStockLimitIs60(): void
    {
        $cache = new ArrayAdapter();
        $service = new RateLimitService($cache);

        for ($i = 0; $i < 60; $i++) {
            $service->enforce('10.0.0.2', 'get_stock');
        }

        $this->expectException(RateLimitExceededException::class);
        $service->enforce('10.0.0.2', 'get_stock');
    }

    public function testWellKnownLimitIsDefined(): void
    {
        self::assertArrayHasKey('well_known', RateLimitService::LIMITS);
        self::assertSame(120, RateLimitService::LIMITS['well_known']);
    }

    public function testWellKnownEnforceCountsSeparately(): void
    {
        $cache = new ArrayAdapter();
        $service = new RateLimitService($cache);

        // well_known と default は別バケット
        for ($i = 0; $i < 120; $i++) {
            $service->enforce('10.0.0.3', 'default');
        }

        // well_known はまだ通る
        $service->enforce('10.0.0.3', 'well_known');
        self::assertTrue(true);
    }
}
