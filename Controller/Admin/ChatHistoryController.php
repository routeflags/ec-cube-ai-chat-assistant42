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
use Plugin\AiChatAssistant42\Repository\ChatLogRepository;
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
        return $this->getChatLogRepository()->fetchLatestIdPerSession($filters);
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
        return $this->getChatLogRepository()->fetchGroupedLogs($latestIds, $page, self::PAGE_LIMIT);
    }

    /**
     * セッションごとの件数マップを取得する。
     *
     * @return array<string,int> ['session_id' => count]
     */
    private function fetchSessionCounts(array $filters): array
    {
        return $this->getChatLogRepository()->fetchSessionCounts($filters);
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
     * @return ChatLog[]
     */
    private function fetchSessionRelayLogs(ChatLog $currentLog): array
    {
        return $this->getChatLogRepository()->fetchSessionRelayLogs($currentLog);
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
        $total = $this->getChatLogRepository()->countAll();
        $lastPage = max(1, (int) ceil($total / self::PAGE_LIMIT));
        $redirectPage = min($currentPage, $lastPage);

        return $this->redirectToRoute('admin_ai_chat_assistant_history', ['page' => $redirectPage]);
    }
}
