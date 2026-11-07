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

use DateTimeImmutable;
use Plugin\AiChatAssistant42\Repository\McpOAuthRepository;

/**
 * MCP の認可コード発行・交換・トークン検証。
 *
 * セキュリティ上の要点（設計書 5 章）:
 * - PKCE は S256 のみ。`plain` は拒否する
 * - 認可コードは一回限り。再利用を検知したら発行済みトークンも失効させる
 * - redirect_uri と resource は「完全一致」で検証する
 * - 平文のトークンは返却時のみ。DB にはハッシュだけを置く
 */
final class McpOAuthService
{
    public const CODE_TTL_SECONDS = 60;
    public const ACCESS_TTL_SECONDS = 3600;
    public const REFRESH_TTL_SECONDS = 2592000;

    /** @var callable(): DateTimeImmutable */
    private $clock;

    /**
     * @param callable(): DateTimeImmutable|null $clock テスト用の時刻源
     */
    public function __construct(
        private McpOAuthRepository $repository,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * 認可コードを発行する。
     *
     * @return string クライアントに返す平文コード（DB にはハッシュのみ）
     */
    public function issueCode(
        string $clientId,
        string $redirectUri,
        string $scopesRaw,
        string $codeChallenge,
        ?string $codeChallengeMethod,
        string $resource,
        ?int $customerId,
    ): string {
        if (!McpRedirectUri::isValidRedirectUri($redirectUri)) {
            throw new McpOAuthException('invalid_request', 'Invalid redirect_uri');
        }

        if (strtoupper((string) $codeChallengeMethod) !== McpPkce::METHOD || $codeChallenge === '') {
            throw new McpOAuthException('invalid_request', 'PKCE S256 is required');
        }

        $scopes = McpOAuthScopes::parse($scopesRaw);

        $code = $this->randomToken();
        $now = $this->now();
        $this->repository->createCode(
            $clientId,
            $redirectUri,
            $scopes,
            $codeChallenge,
            McpPkce::METHOD,
            $resource,
            $customerId,
            $this->hash($code),
            $now->modify('+' . self::CODE_TTL_SECONDS . ' seconds'),
            $now
        );

        return $code;
    }

    /**
     * 認可コードをトークンに交換する。
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function exchangeCode(
        string $code,
        string $codeVerifier,
        string $clientId,
        string $redirectUri,
        ?string $resource = null,
    ): array {
        $codeHash = $this->hash($code);
        $row = $this->repository->findCode($codeHash);
        if ($row === null) {
            throw new McpOAuthException('invalid_grant', 'Unknown code');
        }

        if ($row['code_used_at'] !== null) {
            // 再利用はコード漏洩の兆候。発行済みトークンごと失効させる
            $this->repository->revoke($codeHash, $this->now());
            throw new McpOAuthException('invalid_grant', 'Code already used');
        }

        if ($this->isExpired((string) $row['code_expires_at'])) {
            throw new McpOAuthException('invalid_grant', 'Code expired');
        }

        if (!hash_equals((string) $row['client_id'], $clientId)) {
            throw new McpOAuthException('invalid_grant', 'Client mismatch');
        }

        if (!McpRedirectUri::matches($redirectUri, (string) $row['redirect_uri'])) {
            throw new McpOAuthException('invalid_grant', 'redirect_uri mismatch');
        }

        if (!McpPkce::verify($codeVerifier, (string) $row['code_challenge'], (string) $row['code_challenge_method'])) {
            throw new McpOAuthException('invalid_grant', 'PKCE verification failed');
        }

        if ($resource !== null && $resource !== '' && !hash_equals((string) $row['resource'], $resource)) {
            throw new McpOAuthException('invalid_grant', 'resource mismatch');
        }

        return $this->issueTokens($codeHash, (string) $row['scopes']);
    }

    /**
     * リフレッシュトークンで新しいトークンを発行する（ローテーション）。
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    public function refresh(string $refreshToken, string $clientId): array
    {
        $row = $this->repository->findByRefreshHash($this->hash($refreshToken));
        if ($row === null || $row['revoked_at'] !== null) {
            throw new McpOAuthException('invalid_grant', 'Unknown refresh token');
        }

        if ($this->isExpired((string) $row['refresh_expires_at'])) {
            throw new McpOAuthException('invalid_grant', 'Refresh token expired');
        }

        if (!hash_equals((string) $row['client_id'], $clientId)) {
            throw new McpOAuthException('invalid_grant', 'Client mismatch');
        }

        return $this->issueTokens((string) $row['code_hash'], (string) $row['scopes']);
    }

    /**
     * Bearer トークンを検証し、付与された scope と会員 ID を返す。
     *
     * @return array{scopes: array<int, string>, customer_id: int|null}|null
     */
    public function authenticate(string $bearerToken): ?array
    {
        $row = $this->repository->findByAccessHash($this->hash($bearerToken));
        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }

        if ($this->isExpired((string) $row['access_expires_at'])) {
            return null;
        }

        $scopes = preg_split('/\s+/', trim((string) $row['scopes'])) ?: [];
        $customerId = $row['customer_id'] === null ? null : (int) $row['customer_id'];

        return ['scopes' => array_values(array_filter($scopes)), 'customer_id' => $customerId];
    }

    /**
     * リフレッシュトークンを失効させる。
     */
    public function revoke(string $refreshToken): void
    {
        $row = $this->repository->findByRefreshHash($this->hash($refreshToken));
        if ($row !== null) {
            $this->repository->revoke((string) $row['code_hash'], $this->now());
        }
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string}
     */
    private function issueTokens(string $codeHash, string $scopes): array
    {
        $now = $this->now();
        $access = $this->randomToken();
        $refresh = $this->randomToken();

        $this->repository->markCodeUsed($codeHash, $now);
        $this->repository->storeTokens(
            $codeHash,
            $this->hash($access),
            $now->modify('+' . self::ACCESS_TTL_SECONDS . ' seconds'),
            $this->hash($refresh),
            $now->modify('+' . self::REFRESH_TTL_SECONDS . ' seconds')
        );

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL_SECONDS,
            'scope' => $scopes,
        ];
    }

    private function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)();
    }

    private function isExpired(string $expiresAt): bool
    {
        if ($expiresAt === '') {
            return true;
        }

        return new DateTimeImmutable($expiresAt) <= $this->now();
    }
}
