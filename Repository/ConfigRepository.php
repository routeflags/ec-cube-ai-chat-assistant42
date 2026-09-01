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
use Plugin\AiChatAssistant42\Entity\Config;
use Eccube\Repository\AbstractRepository;

/**
 * AI チャットアシスタント設定リポジトリ。
 *
 * 設定レコードは原則1行。get() で取得する。
 */
class ConfigRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($managerRegistry, Config::class);
    }

    /**
     * 指定 ID の設定を取得する。
     */

    /**
     * 設定レコードを取得する（原則1行）。
     */
    public function get(): ?Config
    {
        return $this->entityManager->getRepository(Config::class)
            ->findOneBy([], ['id' => 'ASC']);
    }

    /**
     * 設定を永続化する。
     */
    public function save($entity)
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }
}
