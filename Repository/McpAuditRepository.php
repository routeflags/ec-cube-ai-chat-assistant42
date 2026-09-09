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
use Plugin\AiChatAssistant42\Entity\McpAudit;
use DateTimeImmutable;

/**
 * MCP 監査ログリポジトリ。
 *
 * 引数・応答内容は記録しない。tool名・結果区分・所要時間・
 * エラーコード・IPハッシュのみ。
 */
class McpAuditRepository extends AbstractRepository
{
    private EntityManagerInterface $entityManager;

    public function __construct(
        ManagerRegistry $registry,
        EntityManagerInterface $entityManager,
    ) {
        parent::__construct($registry, McpAudit::class);
        $this->entityManager = $entityManager;
    }

    /**
     * 監査1行を記録する。記録失敗時は例外を投げず false を返す
     * （本処理の応答に影響させない）。
     */
    public function record(string $toolName, string $result, ?string $errorCode, ?int $durationMs, ?string $clientIpHash): bool
    {
        try {
            $audit = (new McpAudit())
                ->setToolName(substr($toolName, 0, 64))
                ->setResult($result === 'ok' ? 'ok' : 'error')
                ->setErrorCode($errorCode !== null ? substr($errorCode, 0, 32) : null)
                ->setDurationMs($durationMs)
                ->setClientIpHash($clientIpHash !== null ? substr($clientIpHash, 0, 64) : null)
                ->setCreatedAt(new DateTimeImmutable());
            $this->entityManager->persist($audit);
            $this->entityManager->flush();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 日別・ツール別の集計（管理レポート用）。
     *
     * @return array<int, array{day: string, tool_name: string, total: int, errors: int, avg_ms: float|null}>
     */
    public function aggregateByDay(int $days = 30): array
    {
        $since = (new DateTimeImmutable())->modify('-' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT DATE(created_at) AS day, tool_name, COUNT(*) AS total,'
            . " SUM(CASE WHEN result = 'error' THEN 1 ELSE 0 END) AS errors,"
            . ' AVG(duration_ms) AS avg_ms'
            . ' FROM plg_ai_chat_assistant_mcp_audit WHERE created_at >= :since'
            . ' GROUP BY DATE(created_at), tool_name ORDER BY day DESC, total DESC';

        try {
            return $conn->fetchAllAssociative($sql, ['since' => $since]);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
