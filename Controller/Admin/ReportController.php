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

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Controller\AbstractController;
use Plugin\AiChatAssistant42\Entity\ChatLog;
use Plugin\AiChatAssistant42\Repository\ChatLogRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI チャットアシスタントのレポート画面。
 *
 * プロバイダ使用量・モデルパフォーマンス・時間帯別分布・
 * エラー内訳を集計し、CSV エクスポートも可能にする。
 */
class ReportController extends AbstractController
{
    public function __construct(
        private ?ChatLogRepository $chatLogRepository = null,
    ) {
    }

    /**
     * @return ChatLogRepository
     */
    private function getChatLogRepository()
    {
        if ($this->chatLogRepository !== null) {
            return $this->chatLogRepository;
        }
        /** @var ChatLogRepository $repo */
        $repo = $this->entityManager->getRepository(ChatLog::class);

        return $repo;
    }

    /**
     * レポート画面を表示する。
     */
    public function index(): Response
    {
        $periodEnd = new \DateTimeImmutable();
        $periodStart = $periodEnd->modify('-30 days');

        $providerStats = $this->fetchProviderStats($periodStart, $periodEnd);
        $modelStats = $this->fetchModelStats($periodStart, $periodEnd);
        $hourlyDistribution = $this->fetchHourlyDistribution($periodStart, $periodEnd);
        $errorBreakdown = $this->fetchErrorBreakdown($periodStart, $periodEnd);

        return $this->render('@AiChatAssistant42/admin/report.twig', [
            'menus' => ['setting', 'ai_chat_assistant', 'ai_chat_assistant_report'],
            'provider_stats' => $providerStats,
            'model_stats' => $modelStats,
            'hourly_distribution' => $hourlyDistribution,
            'error_breakdown' => $errorBreakdown,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    /**
     * プロバイダ別の使用統計を取得する。
     *
     * @return array<array{provider: string, count: int, avg_response_ms: float, error_count: int}>
     */
    private function fetchProviderStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('
                log.provider AS provider,
                COUNT(log.id) AS count,
                AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms,
                SUM(CASE WHEN log.error_message IS NOT NULL AND log.error_message != \'\' THEN 1 ELSE 0 END) AS error_count
            ')
            ->from(ChatLog::class, 'log')
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
     * @return array<array{model: string, provider: string, count: int, avg_response_ms: float, avg_token_input: float, avg_token_output: float, error_count: int}>
     */
    private function fetchModelStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('
                log.model AS model,
                log.provider AS provider,
                COUNT(log.id) AS count,
                AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms,
                AVG(CASE WHEN log.token_input IS NOT NULL THEN log.token_input ELSE 0 END) AS avg_token_input,
                AVG(CASE WHEN log.token_output IS NOT NULL THEN log.token_output ELSE 0 END) AS avg_token_output,
                SUM(CASE WHEN log.error_message IS NOT NULL AND log.error_message != \'\' THEN 1 ELSE 0 END) AS error_count
            ')
            ->from(ChatLog::class, 'log')
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
     * 時間帯別（0〜23時）のリクエスト分布を取得する。
     *
     * @return array<array{hour: int, count: int}>
     */
    private function fetchHourlyDistribution(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->getChatLogRepository()->fetchHourlyDistribution($start, $end);
    }

    /**
     * エラータイプ別の内訳を取得する。
     *
     * @return array<array{error_type: string, count: int, latest_message: string}>
     */
    private function fetchErrorBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('
                COALESCE(log.error_type, \'unknown\') AS error_type,
                COUNT(log.id) AS count,
                MAX(log.error_message) AS latest_message
            ')
            ->from(ChatLog::class, 'log')
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
