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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Log\LoggerInterface;

/**
 * チャットログをリモートエンドポイントに同期するサービス。
 *
 * synced_at が NULL のログレコードをバッチ取得し、
 * 個人情報（IP アドレス・メールアドレス等）を匿名化した上で
 * リモートサーバーへ送信する。送信成功時に synced_at を記録する。
 *
 * 匿名化ポリシー:
 *   - ユーザーメッセージは文字列長のみ記録（本文は送信しない）
 *   - アシスタント返答も文字列長のみ記録
 *   - IP アドレス・メールアドレス・個人を特定する情報は一切送信しない
 */
class LogSyncService
{
    /** リモートエンドポイントの環境変数キー */
    private const REMOTE_ENDPOINT_ENV = 'AI_CHAT_SYNC_ENDPOINT';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * 未同期のチャットログをリモートへ同期する。
     *
     * @param int $batchSize 1回で取得する最大レコード数
     *
     * @return int 同期に成功したレコード数
     */
    public function sync(int $batchSize = 100): int
    {
        $endpoint = $this->getRemoteEndpoint();
        if ($endpoint === null) {
            $this->logger->warning('AI chat log sync endpoint is not configured.');
            return 0;
        }

        $unsyncedLogs = $this->fetchUnsyncedLogs($batchSize);
        if (empty($unsyncedLogs)) {
            return 0;
        }

        $syncedCount = 0;
        $now = new \DateTimeImmutable();

        foreach ($unsyncedLogs as $chatLog) {
            $payload = $this->anonymize($chatLog);

            try {
                $this->httpClient->post($endpoint, [
                    'json' => $payload,
                    'timeout' => 30,
                ]);

                $chatLog->setSyncedAt($now);
                $this->entityManager->flush();
                $syncedCount++;
            } catch (TransferException $e) {
                $this->logger->error('Failed to sync chat log #{id}: {message}', [
                    'id' => $chatLog->getId(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $syncedCount;
    }

    /**
     * 未同期のログレコードをバッチ取得する。
     *
     * @return ChatLog[]
     */
    private function fetchUnsyncedLogs(int $batchSize): array
    {
        $repository = $this->entityManager->getRepository(ChatLog::class);

        return $repository->createQueryBuilder('cl')
            ->where('cl.synced_at IS NULL')
            ->orderBy('cl.id', 'ASC')
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getResult();
    }

    /**
     * チャットログから匿名化された送信ペイロードを生成する。
     *
     * 個人を特定する情報は一切含まず、
     * 分析に必要な統計情報（文字列長・トークン数・レスポンス時間等）のみを抽出する。
     *
     * @return array<string, mixed>
     */
    private function anonymize(ChatLog $chatLog): array
    {
        return [
            'log_id' => $chatLog->getId(),
            'provider' => $chatLog->getProvider(),
            'model' => $chatLog->getModel(),
            'user_message_length' => mb_strlen($chatLog->getUserMessage()),
            'assistant_reply_length' => mb_strlen($chatLog->getAssistantReply()),
            'tools_used' => $chatLog->getToolsUsed(),
            'response_time_ms' => $chatLog->getResponseTimeMs(),
            'token_input' => $chatLog->getTokenInput(),
            'token_output' => $chatLog->getTokenOutput(),
            'error_message' => $chatLog->getErrorMessage(),
            'is_resolved' => $chatLog->getIsResolved(),
            'created_at' => $chatLog->getCreatedAt()?->format('Y-m-d\TH:i:sP'),
        ];
    }

    /**
     * 同期先のリモートエンドポイント URL を取得する。
     *
     * @return string|null 未設定の場合は null
     */
    private function getRemoteEndpoint(): ?string
    {
        $endpoint = getenv(self::REMOTE_ENDPOINT_ENV);

        return ($endpoint !== false && $endpoint !== '') ? $endpoint : null;
    }
}
