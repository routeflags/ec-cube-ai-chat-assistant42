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
        'well_known' => 120,
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
     * 注意: CacheItemPoolInterface (cache.app / FilesystemAdapter) は PSR-6 準拠のため
     * キーに {}()/\@: を含められない。":" は reserved character なので "." に置換する。
     * IP に含まれる ":" "." "/" も "_" にサニタイズし、最終的に preg_replace で PSR-6 合法化する。
     *
     * 注意 (trusted_proxies): getClientIp() は framework.trusted_proxies / trusted_headers が
     * 正しく設定されている前提で X-Forwarded-For を信頼する。Cloudflare 等のプロキシ環境では
     * config/packages/framework.yaml で trusted_proxies: ['127.0.0.1', 'REMOTE_ADDR'] と
     * trusted_headers: ['x-forwarded-for','x-forwarded-proto'] を設定すること。
     * 未設定時は全ユーザが同一バケット (127.0.0.1) を共有し誤爆 DoS になる。
     *
     * 注意 (非アトミック): 本実装は read-modify-write のため並行リクエストで競合し
     * 制限を超過し得る（本来 15 になるべきところ 6 のまま等）。Phase2 で
     * symfony/rate-limiter (RateLimiterFactory) への移行を検討する。短期は
     * 閾値超過を許容する旨をコメントし、必要に応じて limit * 0.9 等の緩和を検討する。
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
        // PSR-6 キーは ":" を含められないため "." に置換。IP の ":" "." "/" は "_" にサニタイズ。
        $sanitizedIp = str_replace([':', '.', '/'], '_', $ip);
        $raw = sprintf('mcp.ratelimit.%s.%s.%s', $sanitizedIp, $normalizedTool, $minute);
        $key = preg_replace('/[^A-Za-z0-9_.]/', '_', $raw);

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
