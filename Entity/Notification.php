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

namespace Plugin\AiChatAssistant42\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 通知ルールを管理するエンティティ。
 *
 * エラー閾値や未解決件数など、トリガー条件に応じて
 * メール・Webhook・LINE など経由で管理者に通知を送信する。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_notification")
 */
class Notification extends \Eccube\Entity\AbstractEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private ?int $id = null;

    /**
     * 通知チャネル種別 (email / webhook / line)。
     *
     * @ORM\Column(type="string", length=32)
     */
    private string $notification_type;

    /**
     * トリガーとなるイベント識別子。
     *
     * @ORM\Column(type="string", length=64)
     */
    private string $trigger_event;

    /**
     * 通知設定の JSON (宛先 URL, ヘッダー, メッセージテンプレート等)。
     *
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $config_json = null;

    /**
     * 有効フラグ (1=有効, 0=無効)。
     *
     * @ORM\Column(type="smallint", options={"default":1})
     */
    private int $is_active = 1;

    /**
     * @ORM\Column(type="datetimetz")
     */
    private ?\DateTimeInterface $create_date = null;

    /**
     * @ORM\Column(type="datetimetz")
     */
    private ?\DateTimeInterface $update_date = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNotificationType(): string
    {
        return $this->notification_type;
    }

    public function setNotificationType(string $notificationType): self
    {
        $this->notification_type = $notificationType;
        return $this;
    }

    public function getTriggerEvent(): string
    {
        return $this->trigger_event;
    }

    public function setTriggerEvent(string $triggerEvent): self
    {
        $this->trigger_event = $triggerEvent;
        return $this;
    }

    public function getConfigJson(): ?array
    {
        return $this->config_json;
    }

    public function setConfigJson(?array $configJson): self
    {
        $this->config_json = $configJson;
        return $this;
    }

    public function getIsActive(): int
    {
        return $this->is_active;
    }

    public function setIsActive(int $isActive): self
    {
        $this->is_active = $isActive;
        return $this;
    }

    public function getCreateDate(): ?\DateTimeInterface
    {
        return $this->create_date;
    }

    public function setCreateDate(\DateTimeInterface $createDate): self
    {
        $this->create_date = $createDate;
        return $this;
    }

    public function getUpdateDate(): ?\DateTimeInterface
    {
        return $this->update_date;
    }

    public function setUpdateDate(\DateTimeInterface $updateDate): self
    {
        $this->update_date = $updateDate;
        return $this;
    }
}
