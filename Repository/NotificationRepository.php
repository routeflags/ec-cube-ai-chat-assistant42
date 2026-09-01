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
use Plugin\AiChatAssistant42\Entity\Notification;
use Eccube\Repository\AbstractRepository;

/**
 * 通知ルールリポジトリ。
 *
 * チャットイベントに紐づく通知ルールを管理する。
 */
class NotificationRepository extends AbstractRepository
{
    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($managerRegistry, Notification::class);
    }

    /**
     * 有効な通知ルールをすべて取得する。
     *
     * @return Notification[]
     */
    public function findAllActive(): array
    {
        return $this->entityManager->getRepository(Notification::class)
            ->findBy(['is_active' => 1], ['id' => 'ASC']);
    }

    /**
     * 指定イベントに紐づく通知ルールを取得する。
     *
     * @return Notification[]
     */
    public function findByEvent(string $event): array
    {
        return $this->entityManager->getRepository(Notification::class)
            ->findBy(['trigger_event' => $event, 'is_active' => 1], ['id' => 'ASC']);
    }

    /**
     * 指定 ID の通知ルールを取得する。
     */

    /**
     * 通知ルールを永続化する。
     */
    public function save($entity)
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * 通知ルールを削除する。
     */
    public function delete($entity)
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }
}
