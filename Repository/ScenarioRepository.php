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

namespace Plugin\AiChatAssistant44\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Plugin\AiChatAssistant44\Entity\Scenario;
use Eccube\Entity\AbstractEntity;
use Eccube\Repository\AbstractRepository;

/**
 * 自動応答シナリオリポジトリ。
 *
 * ユーザー入力に対する定型応答を管理する。
 *
 * @extends AbstractRepository<Scenario>
 */
class ScenarioRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($managerRegistry, Scenario::class);
    }

    /**
     * 有効なシナリオを優先度順に取得する。
     *
     * @return Scenario[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.is_active = :isActive')
            ->setParameter('isActive', 1)
            ->orderBy('s.priority', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * トリガーキーワードで検索する。
     *
     * @return Scenario[]
     */
    public function findByTrigger(string $keyword): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.trigger_keyword = :keyword')
            ->andWhere('s.is_active = :isActive')
            ->setParameter('keyword', $keyword)
            ->setParameter('isActive', 1)
            ->orderBy('s.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * ユーザー入力にマッチするシナリオを返す。
     *
     * exact — 完全一致
     * contains — 部分一致（キーワードが入力に含まれる）
     * regex — 正規表現マッチ
     *
     * マッチ順は priority DESC。
     *
     * @return Scenario[]
     */
    public function findMatching(string $input): array
    {
        $allActive = $this->findAllActive();
        $matched = [];

        foreach ($allActive as $scenario) {
            $pattern = $scenario->getTriggerKeyword();
            $isMatch = match ($scenario->getTriggerType()) {
                'exact' => mb_strtolower($input) === mb_strtolower($pattern),
                'contains' => mb_strpos(mb_strtolower($input), mb_strtolower($pattern)) !== false,
                'regex' => $this->matchesRegex($pattern, $input),
                default => false,
            };

            if ($isMatch) {
                $matched[] = $scenario;
            }
        }

        return $matched;
    }

    /**
     * 管理画面用: 全件を QueryBuilder で返す（検索・ページネーション対応）。
     */
    public function getQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.priority', 'DESC')
            ->addOrderBy('s.id', 'DESC');
    }

    /**
     * シナリオを永続化する。
     */
    public function save(AbstractEntity $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * シナリオを削除する。
     */
    public function remove(Scenario $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    private function matchesRegex(string $pattern, string $input): bool
    {
        // 不正な正規表現は false 扱い（@ を使わず try/catch で握りつぶし意図を明示）
        try {
            $result = preg_match($pattern, $input);
        } catch (\Throwable $e) {
            return false;
        }

        return $result === 1;
    }
}
