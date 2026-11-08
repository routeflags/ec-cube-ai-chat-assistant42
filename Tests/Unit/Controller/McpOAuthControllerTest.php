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

namespace Plugin\AiChatAssistant42\Tests\Unit\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Controller\McpOAuthController;
use Plugin\AiChatAssistant42\Repository\McpOAuthRepository;
use Plugin\AiChatAssistant42\Service\McpOAuthService;
use Plugin\AiChatAssistant42\Service\McpPkce;
use Symfony\Component\HttpFoundation\Request;

/**
 * トークンエンドポイントの HTTP 応答を検証する。
 *
 * 認可画面は会員ログインが前提のため対象外。ここではコード交換とリフレッシュの
 * 応答形式（RFC 6749 5.1/5.2）と no-store を確認する。
 */
class McpOAuthControllerTest extends TestCase
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

    private McpOAuthService $service;
    private McpOAuthController $controller;

    private const CLIENT_ID = 'https://chatgpt.com/oauth/client.json';
    private const REDIRECT_URI = 'https://chatgpt.com/connector_platform_oauth_redirect';
    private const RESOURCE = 'https://shop.example.com/mcp';

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
        $connection->executeStatement(self::DDL);

        $now = new DateTimeImmutable('2026-09-10 10:00:00');
        $this->service = new McpOAuthService(new McpOAuthRepository($connection), fn (): DateTimeImmutable => $now);
        $this->controller = new McpOAuthController($this->service);
    }

    private function verifier(): string
    {
        return str_repeat('a', 43);
    }

    private function issueCode(): string
    {
        return $this->service->issueCode(
            self::CLIENT_ID,
            self::REDIRECT_URI,
            'orders.read',
            McpPkce::challenge($this->verifier()),
            McpPkce::METHOD,
            self::RESOURCE,
            42
        );
    }

    private function tokenRequest(array $params): Request
    {
        return Request::create('/ai-chat-assistant/oauth/token', 'POST', $params);
    }

    public function testAuthorizationCodeGrantReturnsTokens(): void
    {
        $response = $this->controller->token($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $this->issueCode(),
            'code_verifier' => $this->verifier(),
            'redirect_uri' => self::REDIRECT_URI,
            'resource' => self::RESOURCE,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertNotEmpty($body['access_token']);
        $this->assertNotEmpty($body['refresh_token']);
        $this->assertSame('orders.read', $body['scope']);
    }

    public function testReusedCodeReturnsInvalidGrant(): void
    {
        $code = $this->issueCode();
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'code_verifier' => $this->verifier(),
            'redirect_uri' => self::REDIRECT_URI,
        ];

        $this->assertSame(200, $this->controller->token($this->tokenRequest($params))->getStatusCode());

        $second = $this->controller->token($this->tokenRequest($params));
        $this->assertSame(400, $second->getStatusCode());
        $this->assertSame('invalid_grant', json_decode((string) $second->getContent(), true)['error']);
    }

    public function testUnsupportedGrantType(): void
    {
        $response = $this->controller->token($this->tokenRequest([
            'grant_type' => 'client_credentials',
            'client_id' => self::CLIENT_ID,
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('unsupported_grant_type', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testMissingClientIdIsRejected(): void
    {
        $response = $this->controller->token($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => 'x',
            'code_verifier' => $this->verifier(),
        ]));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_request', json_decode((string) $response->getContent(), true)['error']);
    }

    public function testRefreshGrantReturnsNewTokens(): void
    {
        $tokens = json_decode((string) $this->controller->token($this->tokenRequest([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $this->issueCode(),
            'code_verifier' => $this->verifier(),
            'redirect_uri' => self::REDIRECT_URI,
        ]))->getContent(), true);

        $response = $this->controller->token($this->tokenRequest([
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $rotated = json_decode((string) $response->getContent(), true);
        $this->assertNotSame($tokens['access_token'], $rotated['access_token']);
    }

    public function testRevokeReturns200RegardlessOfToken(): void
    {
        $response = $this->controller->revoke(Request::create('/ai-chat-assistant/oauth/revoke', 'POST', [
            'token' => 'does-not-exist',
        ]));

        $this->assertSame(200, $response->getStatusCode());
    }
}
