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
    ) {
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
        $conn = $this->entityManager->getConnection();
        $platform = strtolower($conn->getDatabasePlatform()->getName());
        if (str_contains($platform, 'sqlite')) {
            $hourExpr = "CAST(strftime('%H', log.created_at) AS INTEGER)";
        } elseif (str_contains($platform, 'pgsql') || str_contains($platform, 'postgres')) {
            $hourExpr = 'CAST(EXTRACT(HOUR FROM log.created_at) AS INTEGER)';
        } else {
            $hourExpr = 'HOUR(log.created_at)';
        }
        $sql = "
            SELECT {$hourExpr} AS hour, COUNT(log.id) AS count
            FROM plg_ai_chat_assistant_log log
            WHERE log.created_at >= :start AND log.created_at < :end
            GROUP BY hour
            ORDER BY hour ASC
        ";
        $raw = $conn->fetchAllAssociative($sql, [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ]);

        // 0〜23時すべてのキーを保証
        $distribution = [];
        $hourMap = [];
        foreach ($raw as $row) {
            $hourMap[(int) $row['hour']] = (int) $row['count'];
        }
        for ($h = 0; $h < 24; ++$h) {
            $distribution[] = [
                'hour' => $h,
                'count' => $hourMap[$h] ?? 0,
            ];
        }

        return $distribution;
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
