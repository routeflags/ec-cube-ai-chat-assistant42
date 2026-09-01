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

namespace Plugin\AiChatAssistant42\Controller\Admin;

use Eccube\Controller\AbstractController;
use Plugin\AiChatAssistant42\Entity\ChatLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI チャット履歴の一覧表示と詳細照会。
 *
 * 日付範囲・プロバイダ・モデル・ステータスでフィルタし、
 * ページネーション付きで履歴を一覧表示する。
 */
class ChatHistoryController extends AbstractController
{
    private const PAGE_LIMIT = 20;

    public function __construct(
    ) {
    }

    /**
     * チャット履歴一覧を表示する。
     *
     * 同一 session_id は最新1件のみを一覧に表示する。
     * フィルタ後の集合に対して GROUP BY session_id で MAX(id) を取得し、
     * その ID 群から created_at DESC でページングする。
     */
    public function index(Request $request): Response
    {
        $filters = $this->extractFilters($request);
        $page = max(1, (int) $request->query->get('page', 1));

        $latestIds = $this->fetchLatestIdPerSession($filters);
        if (empty($latestIds)) {
            return $this->renderGroupedHistory([], 0, $page, $filters, []);
        }

        $total = count($latestIds);
        $logs = $this->fetchGroupedLogs($latestIds, $page);
        $sessionCounts = $this->fetchSessionCounts($filters);
        $lastPage = max(1, (int) ceil($total / self::PAGE_LIMIT));

        return $this->renderGroupedHistory($logs, $total, $page, $filters, $sessionCounts, $lastPage);
    }

    /**
     * リクエストからフィルタ配列を抽出する。
     */
    private function extractFilters(Request $request): array
    {
        return [
            'date_from' => trim((string) $request->query->get('date_from', '')),
            'date_to' => trim((string) $request->query->get('date_to', '')),
            'provider' => trim((string) $request->query->get('provider', '')),
            'model' => trim((string) $request->query->get('model', '')),
            'status' => trim((string) $request->query->get('status', '')),
        ];
    }

    /**
     * フィルタ後の集合からセッションごとの最新 ID を取得する。
     *
     * @return int[]
     */
    private function fetchLatestIdPerSession(array $filters): array
    {
        $subQb = $this->buildFilteredQuery($filters)
            ->select('MAX(log.id) AS max_id')
            ->groupBy('log.session_id');
        $subQb->resetDQLPart('orderBy');

        $rows = $subQb->getQuery()->getScalarResult();

        return array_map('intval', array_column($rows, 'max_id'));
    }

    /**
     * 最新ID群から該当ログを created_at DESC でページング取得する。
     *
     * @param int[] $latestIds
     *
     * @return ChatLog[]
     */
    private function fetchGroupedLogs(array $latestIds, int $page): array
    {
        $offset = ($page - 1) * self::PAGE_LIMIT;

        return $this->entityManager->createQueryBuilder()
            ->select('log')
            ->from(ChatLog::class, 'log')
            ->where('log.id IN (:maxIds)')
            ->setParameter('maxIds', $latestIds)
            ->orderBy('log.created_at', 'DESC')
            ->addOrderBy('log.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults(self::PAGE_LIMIT)
            ->getQuery()
            ->getResult();
    }

    /**
     * セッションごとの件数マップを取得する。
     *
     * @return array<string,int> ['session_id' => count]
     */
    private function fetchSessionCounts(array $filters): array
    {
        $qb = $this->buildFilteredQuery($filters)
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
     * グループ化された履歴一覧を描画する。
     */
    private function renderGroupedHistory(array $logs, int $total, int $page, array $filters, array $sessionCounts, int $lastPage = 1): Response
    {
        return $this->render('@AiChatAssistant42/admin/chat_history.twig', [
            'menus' => ['setting', 'ai_chat_assistant', 'ai_chat_assistant_history'],
            'filters' => $filters,
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'limit' => self::PAGE_LIMIT,
            'sessionCounts' => $sessionCounts,
        ]);
    }

    /**
     * チャット履歴の詳細を表示する。
     *
     * 同一 session_id の全リレーを時系列（created_at ASC, id ASC）で取得し、
     * 現在位置（0始まり）と総件数をテンプレートへ渡すことで、
     * 会話の流れ（リレー）を可視化する。
     */
    public function show(int $id): Response
    {
        $log = $this->entityManager->find(ChatLog::class, $id);
        if ($log === null) {
            throw $this->createNotFoundException('チャットログが見つかりません。');
        }

        $relatedLogs = $this->fetchSessionRelayLogs($log);
        $currentIndex = $this->resolveCurrentRelayIndex($relatedLogs, $log);

        return $this->render('@AiChatAssistant42/admin/chat_history_show.twig', [
            'menus' => ['setting', 'ai_chat_assistant', 'ai_chat_assistant_history'],
            'log' => $log,
            'relatedLogs' => $relatedLogs,
            'currentIndex' => $currentIndex,
            'totalInSession' => count($relatedLogs),
        ]);
    }

    /**
     * 同一セッションの全リレーを時系列で取得する。
     *
     * session_id が空の場合はフォールバックとして現在の1件のみを返す。
     * DQL 上のフィールド名は Entity プロパティ名（session_id / created_at）に合わせる。
     *
     * @return ChatLog[]
     */
    private function fetchSessionRelayLogs(ChatLog $currentLog): array
    {
        $sessionId = $currentLog->getSessionId();
        if ($sessionId === '' || $sessionId === null) {
            return [$currentLog];
        }
        $relatedLogs = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(ChatLog::class, 'l')
            ->where('l.session_id = :sid')
            ->setParameter('sid', $sessionId)
            ->orderBy('l.created_at', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return empty($relatedLogs) ? [$currentLog] : $relatedLogs;
    }

    /**
     * 現在表示中のログが relatedLogs の何番目かを解決する（0始まり）。
     */
    private function resolveCurrentRelayIndex(array $relatedLogs, ChatLog $currentLog): int
    {
        foreach ($relatedLogs as $index => $relayLog) {
            if ($relayLog->getId() === $currentLog->getId()) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * チャット履歴を物理削除する。
     *
     * CSRF トークンは `admin_ai_chat_assistant_history_{id}` で検証する。
     * 失敗時はエラーフラッシュを積んで一覧へリダイレクトし、成功時は
     * 該当ログを物理削除して成功フラッシュを積む。削除後は `page` を保持し、
     * 末尾ページが消失した場合は `min(currentPage, lastPage)` に丸める。
     */
    public function delete(Request $request, int $id): Response
    {
        $currentPage = max(1, (int) ($request->query->get('page', $request->request->get('page', 1))));

        if (!$this->isCsrfTokenValid('admin_ai_chat_assistant_history_' . $id, (string) $request->request->get('_token'))) {
            $this->addError('不正なリクエストです。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_history', ['page' => $currentPage]);
        }

        $log = $this->entityManager->find(ChatLog::class, $id);
        if ($log === null) {
            throw $this->createNotFoundException('チャットログが見つかりません。');
        }

        $this->entityManager->remove($log);
        $this->entityManager->flush();

        $this->addSuccess('削除しました。', 'admin');

        // 削除後の総件数から最終ページを再計算し、現在ページが溢れたら丸める
        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(ChatLog::class, 'l')
            ->getQuery()
            ->getSingleScalarResult();
        $lastPage = max(1, (int) ceil($total / self::PAGE_LIMIT));
        $redirectPage = min($currentPage, $lastPage);

        return $this->redirectToRoute('admin_ai_chat_assistant_history', ['page' => $redirectPage]);
    }

    /**
     * フィルタ条件に基づく QueryBuilder を構築する。
     */
    private function buildFilteredQuery(array $filters): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('log')
            ->from(ChatLog::class, 'log')
            ->orderBy('log.created_at', 'DESC');

        $this->applyDateFilter($qb, $filters);
        $this->applyProviderFilter($qb, $filters);
        $this->applyModelFilter($qb, $filters);
        $this->applyStatusFilter($qb, $filters);

        return $qb;
    }

    private function applyDateFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if ($filters['date_from'] !== '') {
            $qb->andWhere('log.created_at >= :date_from')
                ->setParameter('date_from', new \DateTimeImmutable($filters['date_from']));
        }

        if ($filters['date_to'] !== '') {
            $dateTo = (new \DateTimeImmutable($filters['date_to']))->modify('+1 day');
            $qb->andWhere('log.created_at < :date_to')
                ->setParameter('date_to', $dateTo);
        }
    }

    private function applyProviderFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if ($filters['provider'] !== '') {
            $qb->andWhere('log.provider = :provider')
                ->setParameter('provider', $filters['provider']);
        }
    }

    private function applyModelFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if ($filters['model'] !== '') {
            $qb->andWhere('log.model = :model')
                ->setParameter('model', $filters['model']);
        }
    }

    private function applyStatusFilter(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if ($filters['status'] === '') {
            return;
        }

        $statusConditions = [
            'error' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb
                ->andWhere('log.error_message IS NOT NULL')
                ->andWhere("log.error_message != ''"),
            'resolved' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb->andWhere('log.is_resolved = 1'),
            'unresolved' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb->andWhere('log.is_resolved = 0'),
            'email_pending' => fn(\Doctrine\ORM\QueryBuilder $qb) => $qb
                ->andWhere('log.email_reply_address IS NOT NULL')
                ->andWhere('log.email_replied_at IS NULL'),
        ];

        $handler = $statusConditions[$filters['status']] ?? null;
        if ($handler !== null) {
            $handler($qb);
        }
    }
}
