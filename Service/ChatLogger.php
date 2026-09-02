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

use Doctrine\ORM\EntityManagerInterface;
use Plugin\AiChatAssistant42\Entity\ChatLog;
use Plugin\AiChatAssistant42\Repository\ChatLogRepository;

/**
 * AI チャットのやり取りを DB に記録するサービス。
 *
 * コントローラから呼び出され、plg_ai_chat_assistant_log テーブルに
 * セッション単位のログを1行挿入する。レポジトリに直接依存せず、
 * EntityManager で直接挿入することで軽量に保つ。
 */
class ChatLogger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?\Symfony\Component\HttpFoundation\RequestStack $requestStack = null,
        private ?ChatLogRepository $chatLogRepository = null,
    ) {
    }

    private function getChatLogRepository(): ChatLogRepository
    {
        if ($this->chatLogRepository !== null) {
            return $this->chatLogRepository;
        }
        /** @var ChatLogRepository $repo */
        $repo = $this->entityManager->getRepository(ChatLog::class);

        return $repo;
    }

    /**
     * チャットログを1行記録する。
     *
     * @param array{
     *     session_id: string,
     *     provider: string,
     *     model: string,
     *     user_message: string,
     *     assistant_reply: string,
     *     tools_used?: string[]|null,
     *     response_time_ms?: int|null,
     *     token_input?: int|null,
     *     token_output?: int|null,
     *     error_message?: string|null,
     *     product_id?: int|null,
     *     order_id?: int|null,
     *     client_ip?: string|null,
     * } $data
     */
    public function log(array $data): void
    {
        $chatLog = new ChatLog();
        $chatLog->setSessionId($data['session_id']);
        $clientIp = $data['client_ip'] ?? $this->resolveClientIp();
        $chatLog->setClientIp($clientIp);
        $chatLog->setProvider($data['provider']);
        $chatLog->setModel($data['model']);
        $chatLog->setUserMessage($data['user_message']);
        $chatLog->setAssistantReply($data['assistant_reply']);
        $chatLog->setToolsUsed($data['tools_used'] ?? null);
        $chatLog->setResponseTimeMs($data['response_time_ms'] ?? null);
        $chatLog->setTokenInput($data['token_input'] ?? null);
        $chatLog->setTokenOutput($data['token_output'] ?? null);
        $chatLog->setErrorMessage($data['error_message'] ?? null);
        $chatLog->setProductId($data['product_id'] ?? null);
        $chatLog->setOrderId($data['order_id'] ?? null);
        $chatLog->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($chatLog);
        $this->entityManager->flush();
    }

    private function resolveClientIp(): ?string
    {
        if ($this->requestStack === null) {
            return null;
        }
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }
        return $request->getClientIp();
    }

    /**
     * 指定セッションの過去のやり取りを時系列で取得する。
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function fetchSessionHistory(string $sessionId, int $limit = 20): array
    {
        return $this->getChatLogRepository()->fetchSessionHistory($sessionId, $limit);
    }
}
