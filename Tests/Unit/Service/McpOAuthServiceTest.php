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

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Repository\McpOAuthRepository;
use Plugin\AiChatAssistant42\Service\McpOAuthException;
use Plugin\AiChatAssistant42\Service\McpOAuthService;
use Plugin\AiChatAssistant42\Service\McpOAuthScopes;
use Plugin\AiChatAssistant42\Service\McpPkce;

/**
 * 認可コードとトークンのライフサイクルを SQLite 上で検証する。
 *
 * 認可コードの単回使用・PKCE・期限・リフレッシュのローテーションは、
 * Unit で押さえないと事故になる部分。DB はインメモリで閉じる。
 */
class McpOAuthServiceTest extends TestCase
{
    private const DDL = <<<'SQL'
CREATE TABLE plg_ai_chat_assistant_mcp_oauth (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code_hash VARCHAR(64) NULL,
  code_expires_at VARCHAR(19) NULL,
  code_used_at VARCHAR(19) NULL,
  access_hash VARCHAR(64) NULL,
  access_expires_at VARCHAR(19) NULL,
  refresh_hash VARCHAR(64) NULL,
  refresh_expires_at VARCHAR(19) NULL,
  client_id VARCHAR(255) NOT NULL,
  redirect_uri VARCHAR(255) NOT NULL,
  code_challenge VARCHAR(128) NULL,
  code_challenge_method VARCHAR(8) NULL,
  scopes VARCHAR(255) NOT NULL,
  customer_id INTEGER NULL,
  resource VARCHAR(255) NOT NULL,
  revoked_at VARCHAR(19) NULL,
  created_at VARCHAR(19) NOT NULL
)
SQL;

    private Connection $connection;
    private McpOAuthService $service;
    private DateTimeImmutable $now;

    private const CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
    private const REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';
    private const RESOURCE = 'https://shop.example.com/mcp';

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $this->connection->executeStatement(self::DDL);

        $this->now = new DateTimeImmutable('2026-09-10 10:00:00');
        $repository = new McpOAuthRepository($this->connection);
        $this->service = new McpOAuthService($repository, fn (): DateTimeImmutable => $this->now);
    }

    private function verifier(): string
    {
        return str_repeat('a', 43);
    }

    private function issueCode(string $scopes = McpOAuthScopes::ORDERS_READ): string
    {
        return $this->service->issueCode(
            self::CLIENT_ID,
            self::REDIRECT_URI,
            $scopes,
            McpPkce::challenge($this->verifier()),
            McpPkce::METHOD,
            self::RESOURCE,
            42
        );
    }

    public function testExchangeIssuesUsableTokens(): void
    {
        $tokens = $this->service->exchangeCode(
            $this->issueCode(),
            $this->verifier(),
            self::CLIENT_ID,
            self::REDIRECT_URI,
            self::RESOURCE
        );

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(McpOAuthService::ACCESS_TTL_SECONDS, $tokens['expires_in']);
        $this->assertSame(McpOAuthScopes::ORDERS_READ, $tokens['scope']);

        $auth = $this->service->authenticate($tokens['access_token']);
        $this->assertNotNull($auth);
        $this->assertSame([McpOAuthScopes::ORDERS_READ], $auth['scopes']);
        $this->assertSame(42, $auth['customer_id']);
    }

    public function testCodeCannotBeReusedAndIssuedTokensAreRevoked(): void
    {
        $code = $this->issueCode();
        $tokens = $this->service->exchangeCode($code, $this->verifier(), self::CLIENT_ID, self::REDIRECT_URI);

        try {
            $this->service->exchangeCode($code, $this->verifier(), self::CLIENT_ID, self::REDIRECT_URI);
            $this->fail('reused code was accepted');
        } catch (McpOAuthException $e) {
            $this->assertSame('invalid_grant', $e->getError());
        }

        $this->assertNull($this->service->authenticate($tokens['access_token']), 'reuse must invalidate issued tokens');
    }

    public function testExpiredCodeIsRejected(): void
    {
        $code = $this->issueCode();
        $this->now = $this->now->modify('+' . (McpOAuthService::CODE_TTL_SECONDS + 1) . ' seconds');

        $this->expectException(McpOAuthException::class);
        $this->service->exchangeCode($code, $this->verifier(), self::CLIENT_ID, self::REDIRECT_URI);
    }

    public function testPkceMismatchIsRejected(): void
    {
        $code = $this->issueCode();

        $this->expectException(McpOAuthException::class);
        $this->service->exchangeCode($code, str_repeat('b', 43), self::CLIENT_ID, self::REDIRECT_URI);
    }

    public function testPlainPkceIsRejectedAtIssueTime(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessage('PKCE S256 is required');

        $this->service->issueCode(
            self::CLIENT_ID,
            self::REDIRECT_URI,
            McpOAuthScopes::ORDERS_READ,
            $this->verifier(),
            'plain',
            self::RESOURCE,
            42
        );
    }

    public function testRedirectMismatchIsRejected(): void
    {
        $code = $this->issueCode();

        $this->expectException(McpOAuthException::class);
        $this->service->exchangeCode($code, $this->verifier(), self::CLIENT_ID, 'https://evil.example/callback');
    }

    public function testResourceMismatchIsRejected(): void
    {
        $code = $this->issueCode();

        $this->expectException(McpOAuthException::class);
        $this->service->exchangeCode(
            $code,
            $this->verifier(),
            self::CLIENT_ID,
            self::REDIRECT_URI,
            'https://other-shop.example/mcp'
        );
    }

    public function testUnknownScopeIsRejected(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessage('Unknown scope');

        $this->issueCode('orders.admin');
    }

    public function testAccessTokenExpires(): void
    {
        $tokens = $this->service->exchangeCode(
            $this->issueCode(),
            $this->verifier(),
            self::CLIENT_ID,
            self::REDIRECT_URI
        );

        $this->now = $this->now->modify('+' . (McpOAuthService::ACCESS_TTL_SECONDS + 1) . ' seconds');

        $this->assertNull($this->service->authenticate($tokens['access_token']));
    }

    public function testRefreshRotatesTokens(): void
    {
        $tokens = $this->service->exchangeCode(
            $this->issueCode(implode(' ', [McpOAuthScopes::ORDERS_READ, McpOAuthScopes::CATALOG_READ])),
            $this->verifier(),
            self::CLIENT_ID,
            self::REDIRECT_URI
        );

        $rotated = $this->service->refresh($tokens['refresh_token'], self::CLIENT_ID);

        $this->assertNotSame($tokens['access_token'], $rotated['access_token']);
        $this->assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        $this->assertSame(
            [McpOAuthScopes::ORDERS_READ, McpOAuthScopes::CATALOG_READ],
            $this->service->authenticate($rotated['access_token'])['scopes']
        );

        // ローテーション後、古いリフレッシュトークンは使えない
        $this->expectException(McpOAuthException::class);
        $this->service->refresh($tokens['refresh_token'], self::CLIENT_ID);
    }

    public function testRevokedTokenIsRejected(): void
    {
        $tokens = $this->service->exchangeCode(
            $this->issueCode(),
            $this->verifier(),
            self::CLIENT_ID,
            self::REDIRECT_URI
        );

        $this->service->revoke($tokens['refresh_token']);

        $this->assertNull($this->service->authenticate($tokens['access_token']));
    }

    public function testPlainHttpRedirectIsRejectedUnlessLoopback(): void
    {
        $this->expectException(McpOAuthException::class);

        $this->service->issueCode(
            self::CLIENT_ID,
            'http://shop.example.com/callback',
            McpOAuthScopes::ORDERS_READ,
            McpPkce::challenge($this->verifier()),
            McpPkce::METHOD,
            self::RESOURCE,
            42
        );
    }
}
