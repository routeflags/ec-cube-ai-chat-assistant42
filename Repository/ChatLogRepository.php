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
     * 指定セッションのメール返信依頼件数を数える（hash/enc/plain いずれかで判定）。
     */
    public function countEmailReplyBySession(string $sessionId, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('log')
            ->select('COUNT(log.id)')
            ->where('log.session_id = :sid')
            ->andWhere('log.created_at > :since')
            ->andWhere('(log.email_reply_address_hash IS NOT NULL OR log.email_reply_address_enc IS NOT NULL OR log.email_reply_address IS NOT NULL)')
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
            ->andWhere('(log.email_reply_address_hash IS NOT NULL OR log.email_reply_address_enc IS NOT NULL OR log.email_reply_address IS NOT NULL)')
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
            ->where('(log.email_reply_address_hash IS NOT NULL OR log.email_reply_address_enc IS NOT NULL OR log.email_reply_address IS NOT NULL)')
            ->andWhere('log.email_replied_at IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    // ================================================================
    //  更新
    // ================================================================

    /**
     * 最新1件のログにメール返信先を記録する（平文・非推奨: 互換保持）。
     *
     * @deprecated hash+enc 版 updateEmailReplyAddressHashed を使用すること。
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
            ->andWhere('sub.email_reply_address_hash IS NULL')
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
     * 最新1件のログにメール返信先をハッシュ+暗号化して記録する（I-30）。
     *
     * 平文は保存しない。hash は HMAC-SHA256 64hex、enc は AES-256-GCM base64。
     *
     * @return int 更新件数
     */
    public function updateEmailReplyAddressHashed(string $sessionId, string $hash, string $enc): int
    {
        $conn = $this->entityManager->getConnection();

        $subQb = $conn->createQueryBuilder()
            ->select('sub.id')
            ->from('plg_ai_chat_assistant_log', 'sub')
            ->where('sub.session_id = :sid')
            ->andWhere('sub.email_reply_address_hash IS NULL')
            ->andWhere('sub.email_reply_address_enc IS NULL')
            ->orderBy('sub.created_at', 'DESC')
            ->setMaxResults(1)
            ->setParameter('sid', $sessionId);

        $subSql = $subQb->getSQL();
        $subParams = $subQb->getParameters();

        $qb = $conn->createQueryBuilder()
            ->update('plg_ai_chat_assistant_log')
            ->set('email_reply_address_hash', ':hash')
            ->set('email_reply_address_enc', ':enc')
            ->where('id IN (SELECT id FROM (' . $subSql . ') AS tmp)')
            ->setParameter('hash', $hash)
            ->setParameter('enc', $enc);

        foreach ($subParams as $key => $value) {
            $qb->setParameter($key, $value);
        }

        return (int) $qb->executeStatement();
    }

    /**
     * 最新1件のログからメール返信先の暗号文を取得する（送信時復号用）。
     *
     * hash/enc/plain の優先順位で返す。
     *
     * @return string|null 暗号文または平文（互換）、なければ null
     */
    public function fetchLatestEmailEnc(string $sessionId): ?string
    {
        $row = $this->createQueryBuilder('log')
            ->select('log.email_reply_address_enc', 'log.email_reply_address')
            ->where('log.session_id = :sid')
            ->andWhere('(log.email_reply_address_enc IS NOT NULL OR log.email_reply_address IS NOT NULL)')
            ->orderBy('log.created_at', 'DESC')
            ->setMaxResults(1)
            ->setParameter('sid', $sessionId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($row === null) {
            return null;
        }
        /** @var ChatLog $log */
        $log = $this->createQueryBuilder('log')
            ->where('log.session_id = :sid')
            ->andWhere('(log.email_reply_address_enc IS NOT NULL OR log.email_reply_address IS NOT NULL)')
            ->orderBy('log.created_at', 'DESC')
            ->setMaxResults(1)
            ->setParameter('sid', $sessionId)
            ->getQuery()
            ->getOneOrNullResult();

        if ($log === null) {
            return null;
        }

        return $log->getEmailReplyAddressEnc() ?? $log->getEmailReplyAddress();
    }

    /**
     * 30日経過の enc を NULL 化する（hash は保持）。
     *
     * @return int 更新件数
     */
    public function purgeExpiredEmailEnc(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('log')
            ->update()
            ->set('log.email_reply_address_enc', ':null')
            ->where('log.email_reply_address_enc IS NOT NULL')
            ->andWhere('log.created_at < :before')
            ->setParameter('null', null)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
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

    // ================================================================
    //  履歴一覧（管理画面 ChatHistoryController 用）
    // ================================================================

    /**
     * フィルタ条件に基づく QueryBuilder を構築する。
     *
     * @param array{date_from: string, date_to: string, provider: string, model: string, status: string} $filters
     */
    public function createFilteredQueryBuilder(array $filters): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('log')
            ->orderBy('log.created_at', 'DESC');

        $this->applyDateFilter($qb, $filters);
        $this->applyProviderFilter($qb, $filters);
        $this->applyModelFilter($qb, $filters);
        $this->applyStatusFilter($qb, $filters);

        return $qb;
    }

    /**
     * フィルタ後の集合からセッションごとの最新 ID を取得する。
     *
     * @param array $filters
     * @return int[]
     */
    public function fetchLatestIdPerSession(array $filters): array
    {
        $qb = $this->createFilteredQueryBuilder($filters)
            ->select('MAX(log.id) AS max_id')
            ->groupBy('log.session_id');
        $qb->resetDQLPart('orderBy');

        $rows = $qb->getQuery()->getScalarResult();

        return array_map('intval', array_column($rows, 'max_id'));
    }

    /**
     * 最新ID群から該当ログを created_at DESC でページング取得する。
     *
     * @param int[] $latestIds
     * @return ChatLog[]
     */
    public function fetchGroupedLogs(array $latestIds, int $page, int $limit = 20): array
    {
        if (empty($latestIds)) {
            return [];
        }

        $offset = ($page - 1) * $limit;

        return $this->createQueryBuilder('log')
            ->where('log.id IN (:maxIds)')
            ->setParameter('maxIds', $latestIds)
            ->orderBy('log.created_at', 'DESC')
            ->addOrderBy('log.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * セッションごとの件数マップを取得する。
     *
     * @param array $filters
     * @return array<string,int> ['session_id' => count]
     */
    public function fetchSessionCounts(array $filters): array
    {
        $qb = $this->createFilteredQueryBuilder($filters)
            ->select('log.session_id AS sid, COUNT(log.id) AS cnt')
            ->groupBy('log.session_id');
        $qb->resetDQLPart('orderBy');

        $rows = $qb->getQuery()->getScalarResult();
        $countsBySession = [];
        foreach ($rows as $row) {
            $countsBySession[$row['sid']] = (int) $row['cnt'];
        }

        return $countsBySession;
    }

    /**
     * 同一セッションの全リレーを時系列で取得する。
     *
     * @return ChatLog[]
     */
    public function fetchSessionRelayLogs(ChatLog $currentLog): array
    {
        $sessionId = $currentLog->getSessionId();
        if ($sessionId === '' || $sessionId === null) {
            return [$currentLog];
        }

        $result = $this->createQueryBuilder('l')
            ->where('l.session_id = :sid')
            ->setParameter('sid', $sessionId)
            ->orderBy('l.created_at', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return empty($result) ? [$currentLog] : $result;
    }

    /**
     * 全件数を数える。
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function applyDateFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (($filters['date_from'] ?? '') !== '') {
            $qb->andWhere('log.created_at >= :date_from')
                ->setParameter('date_from', new \DateTimeImmutable($filters['date_from']));
        }

        if (($filters['date_to'] ?? '') !== '') {
            $dateTo = (new \DateTimeImmutable($filters['date_to']))->modify('+1 day');
            $qb->andWhere('log.created_at < :date_to')
                ->setParameter('date_to', $dateTo);
        }
    }

    private function applyProviderFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (($filters['provider'] ?? '') !== '') {
            $qb->andWhere('log.provider = :provider')
                ->setParameter('provider', $filters['provider']);
        }
    }

    private function applyModelFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (($filters['model'] ?? '') !== '') {
            $qb->andWhere('log.model = :model')
                ->setParameter('model', $filters['model']);
        }
    }

    private function applyStatusFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (($filters['status'] ?? '') === '') {
            return;
        }

        $statusConditions = [
            'error' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb
                ->andWhere('log.error_message IS NOT NULL')
                ->andWhere("log.error_message != ''"),
            'resolved' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb->andWhere('log.is_resolved = 1'),
            'unresolved' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb->andWhere('log.is_resolved = 0'),
            'email_pending' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb
                ->andWhere('(log.email_reply_address IS NOT NULL OR log.email_reply_address_hash IS NOT NULL OR log.email_reply_address_enc IS NOT NULL)')
                ->andWhere('log.email_replied_at IS NULL'),
        ];

        $handler = $statusConditions[$filters['status']] ?? null;
        if ($handler !== null) {
            $handler($qb);
        }
    }

    // ================================================================
    //  ダッシュボード / レポート集計（KPI / Stats / Breakdown）
    // ================================================================

    /**
     * 指定期間の KPI を集計して返す。
     *
     * @return array{total: int, resolved: int, errors: int, avg_response_ms: float, resolution_rate: float, error_rate: float}
     */
    public function fetchKpi(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $qb = $this->createQueryBuilder('log');
        $qb->select('
            COUNT(log.id) AS total,
            SUM(CASE WHEN log.is_resolved = 1 THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN log.error_message IS NOT NULL AND log.error_message != \'\' THEN 1 ELSE 0 END) AS errors,
            AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms
        ')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        $row = $qb->getQuery()->getSingleResult();

        $total = (int) $row['total'];
        $resolved = (int) $row['resolved'];
        $errors = (int) $row['errors'];
        $avgResponseMs = (float) ($row['avg_response_ms'] ?? 0);

        return [
            'total' => $total,
            'resolved' => $resolved,
            'errors' => $errors,
            'avg_response_ms' => $avgResponseMs,
            'resolution_rate' => $total > 0 ? round($resolved / $total * 100, 1) : 0.0,
            'error_rate' => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * 直近のチャットログを取得する。
     *
     * @return ChatLog[]
     */
    public function fetchRecentLogs(int $limit): array
    {
        return $this->createQueryBuilder('log')
            ->orderBy('log.created_at', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * プロバイダ別の使用統計を取得する。
     *
     * Report 用は error_count を含むが、Dashboard でも余分なキーは無視されるため
     * 常に error_count を含めて返す（統一）。
     *
     * @return array<array{provider: string, count: int, avg_response_ms: float, error_count: int}>
     */
    public function fetchProviderStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('log')
            ->select('
                log.provider AS provider,
                COUNT(log.id) AS count,
                AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms,
                SUM(CASE WHEN log.error_message IS NOT NULL AND log.error_message != \'\' THEN 1 ELSE 0 END) AS error_count
            ')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('log.provider')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * モデル別のパフォーマンス統計を取得する。
     *
     * 常に token 平均と error_count を含む統一形で返す。
     *
     * @return array<array{model: string, provider: string, count: int, avg_response_ms: float, avg_token_input: float, avg_token_output: float, error_count: int}>
     */
    public function fetchModelStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('log')
            ->select('
                log.model AS model,
                log.provider AS provider,
                COUNT(log.id) AS count,
                AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms,
                AVG(CASE WHEN log.token_input IS NOT NULL THEN log.token_input ELSE 0 END) AS avg_token_input,
                AVG(CASE WHEN log.token_output IS NOT NULL THEN log.token_output ELSE 0 END) AS avg_token_output,
                SUM(CASE WHEN log.error_message IS NOT NULL AND log.error_message != \'\' THEN 1 ELSE 0 END) AS error_count
            ')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('log.model', 'log.provider')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * エラータイプ別の内訳を取得する。
     *
     * 常に latest_message を含む統一形で返す（Dashboard では未使用でも無害）。
     *
     * @return array<array{error_type: string, count: int, latest_message: string}>
     */
    public function fetchErrorBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('log')
            ->select('
                COALESCE(log.error_type, \'unknown\') AS error_type,
                COUNT(log.id) AS count,
                MAX(log.error_message) AS latest_message
            ')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->andWhere('log.error_message IS NOT NULL')
            ->andWhere('log.error_message != \'\'')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('error_type')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
