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
 * AI チャットアシスタントのナレッジベースエントリ。
 *
 * 管理画面で登録された FAQ や商品知識を保存し、
 * チャット時の応答生成に利用する。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_knowledge")
 */
class Knowledge extends \Eccube\Entity\AbstractEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private string $title;

    /**
     * @ORM\Column(type="text")
     */
    private string $content;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    private ?string $category = null;

    /**
     * @ORM\Column(type="smallint", options={"default":1})
     */
    private int $is_active = 1;

    /**
     * @ORM\Column(type="integer", options={"default":0})
     */
    private int $display_order = 0;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
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

    public function getDisplayOrder(): int
    {
        return $this->display_order;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        $this->display_order = $displayOrder;
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
