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

namespace Plugin\AiChatAssistant42\Controller;

use Plugin\AiChatAssistant42\Service\McpOAuthException;
use Plugin\AiChatAssistant42\Service\McpOAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP OAuth のトークンエンドポイント。
 *
 * 認可画面（/authorize）は会員ログインとセッションが前提になるため別途。
 * ここはコード交換・リフレッシュ・失効のみを扱い、応答形式は RFC 6749 5.1/5.2 に合わせる。
 */
class McpOAuthController
{
    public function __construct(private McpOAuthService $oauthService)
    {
    }

    /**
     * POST /ai-chat-assistant/oauth/token
     */
    public function token(Request $request): JsonResponse
    {
        $grantType = (string) $request->request->get('grant_type', '');
        $clientId = (string) $request->request->get('client_id', '');

        try {
            if ($clientId === '') {
                throw new McpOAuthException('invalid_request', 'client_id is required');
            }

            $tokens = match ($grantType) {
                'authorization_code' => $this->oauthService->exchangeCode(
                    (string) $request->request->get('code', ''),
                    (string) $request->request->get('code_verifier', ''),
                    $clientId,
                    (string) $request->request->get('redirect_uri', ''),
                    $request->request->get('resource')
                ),
                'refresh_token' => $this->oauthService->refresh(
                    (string) $request->request->get('refresh_token', ''),
                    $clientId
                ),
                default => throw new McpOAuthException('unsupported_grant_type', 'Unsupported grant_type'),
            };
        } catch (McpOAuthException $e) {
            return $this->errorResponse($e);
        }

        return $this->jsonResponse($tokens, 200);
    }

    /**
     * POST /ai-chat-assistant/oauth/revoke
     *
     * トークンの種類は問わず 200 を返す（RFC 7009）。存在しないトークンも 200。
     */
    public function revoke(Request $request): Response
    {
        $this->oauthService->revoke((string) $request->request->get('token', ''));

        return new Response('', 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function errorResponse(McpOAuthException $e): JsonResponse
    {
        return $this->jsonResponse([
            'error' => $e->getError(),
            'error_description' => $e->getErrorDescription(),
        ], 400);
    }
}
