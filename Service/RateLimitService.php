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

namespace Plugin\AiChatAssistant42\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * MCP レート制限サービス。
 *
 * CacheItemPoolInterface (cache.app) に mcp:ratelimit:{ip}:{tool}:{minute} キーでカウントを保存する。
 * get_stock は 60/min、他は 120/min。
 */
class RateLimitService
{
    /** ツール別レート制限（req/min） */
    public const LIMITS = [
        'get_stock' => 60,
        'default' => 120,
    ];

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * レート制限を強制する。
     *
     * 制限超過時は RateLimitExceededException を throw する。
     *
     * @param string $ip       クライアント IP（null の場合は 'unknown' を渡すこと）
     * @param string $toolName ツール名（get_stock で 60、他は 120）
     *
     * @throws RateLimitExceededException
     */
    public function enforce(string $ip, string $toolName): void
    {
        $limit = self::LIMITS[$toolName] ?? self::LIMITS['default'];
        $minute = date('YmdHi');
        // ツール名はレート制限キーで分離（get_stock は厳格に別カウント）
        $normalizedTool = isset(self::LIMITS[$toolName]) ? $toolName : 'default';
        $key = sprintf('mcp:ratelimit:%s:%s:%s', $ip, $normalizedTool, $minute);

        $item = $this->cache->getItem($key);
        $count = $item->isHit() ? (int) $item->get() : 0;
        $count++;

        $item->set($count);
        // 分単位のキーなので 60 秒で失効
        $item->expiresAfter(60);
        $this->cache->save($item);

        if ($count > $limit) {
            throw new RateLimitExceededException($limit, 60);
        }
    }
}
