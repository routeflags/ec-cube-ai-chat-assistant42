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
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Plugin\AiChatAssistant42\Entity\Knowledge;
use Eccube\Repository\AbstractRepository;

/**
 * ナレッジベースリポジトリ。
 *
 * AI チャットが参照する FAQ・商品知識を管理する。
 */
class KnowledgeRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($managerRegistry, Knowledge::class);
    }

    /**
     * 全件を display_order → id の順で取得する。
     */

    /**
     * 有効なナレッジを表示順に取得する。
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.is_active = :isActive')
            ->setParameter('isActive', 1)
            ->orderBy('k.display_order', 'ASC')
            ->addOrderBy('k.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * カテゴリでフィルタして取得する。
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('k')
            ->where('k.category = :category')
            ->andWhere('k.is_active = :isActive')
            ->setParameter('category', $category)
            ->setParameter('isActive', 1)
            ->orderBy('k.display_order', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * キーワードでタイトル・本文を部分検索する。
     *
     * orX() でタイトル/本文の OR 条件を group 化し、
     * is_active フィルタが正しく適用されるようにする。
     */
    public function search(string $keyword): array
    {
        $qb = $this->createQueryBuilder('k');
        $orX = $qb->expr()->orX(
            $qb->expr()->like('k.title', ':keyword'),
            $qb->expr()->like('k.content', ':keyword')
        );

        return $qb
            ->andWhere($orX)
            ->andWhere('k.is_active = :isActive')
            ->setParameter('keyword', '%' . $keyword . '%')
            ->setParameter('isActive', 1)
            ->orderBy('k.display_order', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 管理画面用: 全件を QueryBuilder で返す（検索・ページネーション対応）。
     */
    public function getQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('k')
            ->orderBy('k.display_order', 'ASC')
            ->addOrderBy('k.id', 'DESC');
    }

    /**
     * ナレッジを永続化する。
     */
    public function save($entity)
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * ナレッジを削除する。
     */
    public function remove(Knowledge $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}
