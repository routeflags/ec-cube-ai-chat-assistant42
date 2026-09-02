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

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\AiChatAssistant42\Entity\ChatLog;

/**
 * AI チャットログリポジトリ。
 *
 * コントローラやサービスが直接 SQL を発行するのではなく、
 * 本リポジトリに集約して Doctrine 形式（ORM QueryBuilder / DBAL QueryBuilder）で
 * データアクセスを一元管理する。
 */
class ChatLogRepository extends AbstractRepository
{
    private EntityManagerInterface $entityManager;

    public function __construct(
        ManagerRegistry $registry,
        EntityManagerInterface $entityManager,
    ) {
        parent::__construct($registry, ChatLog::class);
        $this->entityManager = $entityManager;
    }

    // ================================================================
    //  集計・カウント
    // ================================================================

    /**
     * 指定セッションの直近ログ件数を数える（レート制限用）。
     */
    public function countRecentBySession(string $sessionId, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('log')
            ->select('COUNT(log.id)')
            ->where('log.session_id = :sid')
            ->andWhere('log.created_at > :since')
            ->setParameter('sid', $sessionId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * 指定 IP の直近ログ件数を数える（レート制限用）。
     */
    public function countRecentByIp(string $ip, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('log')
            ->select('COUNT(log.id)')
            ->where('log.client_ip = :ip')
            ->andWhere('log.created_at > :since')
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * 指定セッションのメール返信依頼件数を数える。
     */
    public function countEmailReplyBySession(string $sessionId, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('log')
            ->select('COUNT(log.id)')
            ->where('log.session_id = :sid')
            ->andWhere('log.created_at > :since')
            ->andWhere('log.email_reply_address IS NOT NULL')
            ->setParameter('sid', $sessionId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * 指定 IP のメール返信依頼件数を数える。
     */
    public function countEmailReplyByIp(string $ip, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('log')
            ->select('COUNT(log.id)')
            ->where('log.client_ip = :ip')
            ->andWhere('log.created_at > :since')
            ->andWhere('log.email_reply_address IS NOT NULL')
            ->setParameter('ip', $ip)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * 未対応のメール返信依頼件数を数える。
     */
    public function countPendingEmailReplies(): int
    {
        return (int) $this->createQueryBuilder('log')
            ->select('COUNT(log.id)')
            ->where('log.email_reply_address IS NOT NULL')
            ->andWhere('log.email_replied_at IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ================================================================
    //  更新
    // ================================================================

    /**
     * 最新1件のログにメール返信先を記録する。
     *
     * MySQL の LIMIT 付き UPDATE をエミュレートするため、
     * DBAL QueryBuilder でサブクエリを組み立てる。
     *
     * @return int 更新件数（0 の場合は対象ログなし）
     */
    public function updateEmailReplyAddress(string $sessionId, string $email): int
    {
        $conn = $this->entityManager->getConnection();

        // DBAL QueryBuilder で最新1件の ID を取得するサブクエリを構築
        $subQb = $conn->createQueryBuilder()
            ->select('sub.id')
            ->from('plg_ai_chat_assistant_log', 'sub')
            ->where('sub.session_id = :sid')
            ->andWhere('sub.email_reply_address IS NULL')
            ->orderBy('sub.created_at', 'DESC')
            ->setMaxResults(1)
            ->setParameter('sid', $sessionId);

        $subSql = $subQb->getSQL();
        $subParams = $subQb->getParameters();

        // MySQL では UPDATE 内で同じテーブルのサブクエリを直接参照できないため二重サブクエリ化
        $qb = $conn->createQueryBuilder()
            ->update('plg_ai_chat_assistant_log')
            ->set('email_reply_address', ':email')
            ->where('id IN (SELECT id FROM (' . $subSql . ') AS tmp)')
            ->setParameter('email', $email);

        // サブクエリのパラメータをマージ
        foreach ($subParams as $key => $value) {
            $qb->setParameter($key, $value);
        }

        return (int) $qb->executeStatement();
    }

    /**
     * 同一セッションの未解決ログを解決済みに更新する。
     *
     * @return int 更新件数
     */
    public function markResolvedBySession(string $sessionId): int
    {
        return (int) $this->createQueryBuilder('log')
            ->update()
            ->set('log.is_resolved', ':resolved')
            ->where('log.session_id = :sid')
            ->andWhere('log.is_resolved = 0')
            ->setParameter('resolved', 1)
            ->setParameter('sid', $sessionId)
            ->getQuery()
            ->execute();
    }

    // ================================================================
    //  時間帯分布
    // ================================================================

    /**
     * 時間帯別（0〜23時）のリクエスト分布を取得する。
     *
     * DQL では HOUR() が使えないため DBAL QueryBuilder + プラットフォーム分岐で
     * 時間式を組み立て、0件の時間帯は 0 で補完して 24件を返す。
     *
     * @return array<int, array{hour: int, count: int}>
     */
    public function fetchHourlyDistribution(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $conn = $this->entityManager->getConnection();
        $platform = strtolower($conn->getDatabasePlatform()->getName());

        if (str_contains($platform, 'sqlite')) {
            $hourExpr = "CAST(strftime('%H', created_at) AS INTEGER)";
        } elseif (str_contains($platform, 'pgsql') || str_contains($platform, 'postgres')) {
            $hourExpr = 'CAST(EXTRACT(HOUR FROM created_at) AS INTEGER)';
        } else {
            $hourExpr = 'HOUR(created_at)';
        }

        $qb = $conn->createQueryBuilder()
            ->select($hourExpr . ' AS hour', 'COUNT(*) AS count')
            ->from('plg_ai_chat_assistant_log')
            ->where('created_at >= :start')
            ->andWhere('created_at < :end')
            ->groupBy('hour')
            ->orderBy('hour', 'ASC')
            ->setParameter('start', $start->format('Y-m-d H:i:s'))
            ->setParameter('end', $end->format('Y-m-d H:i:s'));

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $map = array_fill(0, 24, 0);
        foreach ($rows as $row) {
            $h = (int) $row['hour'];
            if ($h >= 0 && $h < 24) {
                $map[$h] = (int) $row['count'];
            }
        }

        $out = [];
        foreach ($map as $hour => $count) {
            $out[] = ['hour' => $hour, 'count' => $count];
        }

        return $out;
    }

    // ================================================================
    //  会話履歴
    // ================================================================

    /**
     * 指定セッションの会話履歴を時系列で取得する。
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function fetchSessionHistory(string $sessionId, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('log')
            ->select('log.user_message', 'log.assistant_reply')
            ->where('log.session_id = :sid')
            ->andWhere('log.error_message IS NULL')
            ->orderBy('log.id', 'DESC')
            ->setMaxResults(max(0, $limit))
            ->setParameter('sid', $sessionId);

        $rows = $qb->getQuery()->getResult();

        $history = [];
        foreach (array_reverse($rows) as $row) {
            $history[] = ['role' => 'user', 'content' => $row['user_message']];
            $history[] = ['role' => 'assistant', 'content' => $row['assistant_reply']];
        }

        return $history;
    }
}
