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

namespace Plugin\AiChatAssistant42\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * MCP OAuth の認可コードとトークンの保存先。
 *
 * 平文は保存せず、SHA-256 ハッシュのみを持つ。
 * 行の特定はコードハッシュで行い、lastInsertId に依存しない
 * （PostgreSQL でのシーケンス名指定を避け、3 DB で同じコードが動くようにするため）。
 */
class McpOAuthRepository
{
    public const TABLE = 'plg_ai_chat_assistant_mcp_oauth';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<int, string> $scopes
     */
    public function createCode(
        string $clientId,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $resource,
        ?int $customerId,
        string $codeHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void {
        $this->connection->insert(self::TABLE, [
            'code_hash' => $codeHash,
            'code_expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'scopes' => implode(' ', $scopes),
            'customer_id' => $customerId,
            'resource' => $resource,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCode(string $codeHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE code_hash = ?',
            [$codeHash]
        );

        return $row === false ? null : $row;
    }

    public function markCodeUsed(string $codeHash, DateTimeImmutable $at): void
    {
        $this->connection->update(
            self::TABLE,
            ['code_used_at' => $at->format('Y-m-d H:i:s')],
            ['code_hash' => $codeHash]
        );
    }

    public function storeTokens(
        string $codeHash,
        string $accessHash,
        DateTimeImmutable $accessExpiresAt,
        string $refreshHash,
        DateTimeImmutable $refreshExpiresAt,
    ): void {
        $this->connection->update(
            self::TABLE,
            [
                'access_hash' => $accessHash,
                'access_expires_at' => $accessExpiresAt->format('Y-m-d H:i:s'),
                'refresh_hash' => $refreshHash,
                'refresh_expires_at' => $refreshExpiresAt->format('Y-m-d H:i:s'),
            ],
            ['code_hash' => $codeHash]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByAccessHash(string $accessHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE access_hash = ?',
            [$accessHash]
        );

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRefreshHash(string $refreshHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE refresh_hash = ?',
            [$refreshHash]
        );

        return $row === false ? null : $row;
    }

    public function revoke(string $codeHash, DateTimeImmutable $at): void
    {
        $this->connection->update(
            self::TABLE,
            ['revoked_at' => $at->format('Y-m-d H:i:s')],
            ['code_hash' => $codeHash]
        );
    }
}
