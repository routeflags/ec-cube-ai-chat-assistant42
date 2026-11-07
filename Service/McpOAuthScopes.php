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
 * OAuth のスコープ集合。
 *
 * カタログは公開のまま、注文の読み取りと書き込みを分けて発行できるようにする。
 */
final class McpOAuthScopes
{
    public const CATALOG_READ = 'catalog.read';
    public const ORDERS_READ = 'orders.read';
    public const ORDERS_WRITE = 'orders.write';

    public const ALL = [self::CATALOG_READ, self::ORDERS_READ, self::ORDERS_WRITE];

    /**
     * 空白区切りの scope 文字列を配列にし、未知の scope を拒否する。
     *
     * @return array<int, string>
     */
    public static function parse(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: [];
        $scopes = [];
        foreach ($parts as $scope) {
            if ($scope === '') {
                continue;
            }
            if (!in_array($scope, self::ALL, true)) {
                throw new McpOAuthException('invalid_scope', 'Unknown scope: ' . $scope);
            }
            $scopes[$scope] = true;
        }

        if ($scopes === []) {
            throw new McpOAuthException('invalid_scope', 'At least one scope is required');
        }

        return array_keys($scopes);
    }

    /**
     * 要求された scope と発行可能な scope の積を返す。
     *
     * @param array<int, string> $requested
     * @param array<int, string> $allowed
     *
     * @return array<int, string>
     */
    public static function intersect(array $requested, array $allowed): array
    {
        return array_values(array_intersect($requested, $allowed));
    }

    /**
     * scope が要求された権限を満たすか。
     *
     * @param array<int, string> $granted
     */
    public static function covers(array $granted, string $required): bool
    {
        return in_array($required, $granted, true);
    }
}
