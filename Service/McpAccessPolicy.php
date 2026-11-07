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

use Plugin\AiChatAssistant42\Repository\ConfigRepository;

/**
 * MCP エンドポイントの公開ポリシー。
 *
 * 管理画面で設定したアクセストークンと CORS 許可オリジンを評価する。
 * どちらも未設定なら従来どおり（無認証・全オリジン許可）で、既存の接続を壊さない。
 *
 * トークンは `Authorization: Bearer <token>` または `X-MCP-Token: <token>` で受理する。
 * 比較は hash_equals で行い、先頭一致などのタイミング差を作らない。
 */
final class McpAccessPolicy
{
    public function __construct(
        private ?ConfigRepository $configRepository = null,
        private ?string $accessToken = null,
        private ?string $allowedOrigins = null,
    ) {
    }

    /**
     * トークンを要求するか。
     */
    public function requiresToken(): bool
    {
        return $this->resolveToken() !== null;
    }

    /**
     * リクエストが認証を満たしているか。
     *
     * トークン未設定のサーバーは常に true（後方互換）。
     */
    public function isAuthorized(?string $authorizationHeader, ?string $tokenHeader): bool
    {
        $expected = $this->resolveToken();
        if ($expected === null) {
            return true;
        }

        $presented = $this->extractPresentedToken($authorizationHeader, $tokenHeader);

        return $presented !== null && hash_equals($expected, $presented);
    }

    /**
     * レート制限に使うクライアントキー。
     *
     * ChatGPT は共有 IP から来るため、IP 単位の制限は全利用者で共有されてしまう。
     * トークンがあればトークン単位（SHA-256 の先頭 16 文字）に切り替える。
     */
    public function rateLimitKey(string $clientIp, ?string $authorizationHeader, ?string $tokenHeader): string
    {
        $presented = $this->extractPresentedToken($authorizationHeader, $tokenHeader);
        if ($presented !== null) {
            return 'tok.' . substr(hash('sha256', $presented), 0, 16);
        }

        return 'ip.' . $clientIp;
    }

    /**
     * CORS の `Access-Control-Allow-Origin` に返す値。
     *
     * 未設定なら従来どおり `*`。設定済みなら一致した Origin のみ、不一致は null（ヘッダなし）。
     */
    public function allowedOrigin(?string $requestOrigin): ?string
    {
        $raw = $this->resolveAllowedOrigins();
        if ($raw === null) {
            return '*';
        }

        if ($requestOrigin === null || trim($requestOrigin) === '') {
            return null;
        }

        $origin = strtolower(rtrim(trim($requestOrigin), '/'));
        foreach ($this->parseOrigins($raw) as $allowed) {
            if ($allowed === '*') {
                return '*';
            }
            if (strtolower(rtrim($allowed, '/')) === $origin) {
                return $requestOrigin;
            }
        }

        return null;
    }

    /**
     * 設定済みトークン（管理画面の値）。
     */
    private function resolveToken(): ?string
    {
        $token = $this->accessToken ?? $this->readConfig()?->getMcpAccessToken();
        $token = $token === null ? '' : trim($token);

        return $token === '' ? null : $token;
    }

    /**
     * 設定済み許可オリジン（管理画面の値）。
     */
    private function resolveAllowedOrigins(): ?string
    {
        $raw = $this->allowedOrigins ?? $this->readConfig()?->getMcpAllowedOrigins();
        $raw = $raw === null ? '' : trim($raw);

        return $raw === '' ? null : $raw;
    }

    /**
     * DB から設定を読む。未注入・DB 不可時は null（従来動作にフォールバック）。
     *
     * DB 障害時は公開範囲を絞らずに従来動作を続ける。
     * 店舗自体が停止している状況でエンドポイントだけ 500 を返し続けるより、
     * 商品データの読み取りを維持する方が被害が小さいと判断した。
     */
    private function readConfig(): ?\Plugin\AiChatAssistant42\Entity\Config
    {
        if ($this->configRepository === null) {
            return null;
        }

        try {
            return $this->configRepository->get();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Authorization ヘッダと X-MCP-Token ヘッダのどちらかから提示値を取り出す。
     */
    private function extractPresentedToken(?string $authorizationHeader, ?string $tokenHeader): ?string
    {
        if ($authorizationHeader !== null && preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $m) === 1) {
            $bearer = trim($m[1]);

            return $bearer === '' ? null : $bearer;
        }

        if ($tokenHeader !== null && trim($tokenHeader) !== '') {
            return trim($tokenHeader);
        }

        return null;
    }

    /**
     * "a.com, b.com" / 改行 / 空白区切りの許可オリジンを配列に分解する。
     *
     * @return array<int, string>
     */
    private function parseOrigins(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $v): bool => $v !== ''));
    }
}
