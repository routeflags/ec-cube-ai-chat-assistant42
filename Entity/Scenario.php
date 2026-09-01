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
 * AI チャットアシスタントの自動応答シナリオ。
 *
 * ユーザー入力にキーワードがマッチした場合、
 * AI 呼び出しなしで定型応答を返す。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_scenario")
 */
class Scenario extends \Eccube\Entity\AbstractEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=128)
     */
    private string $trigger_keyword;

    /**
     * exact: 完全一致, contains: 部分一致, regex: 正規表現
     *
     * @ORM\Column(type="string", length=32, options={"default":"exact"})
     */
    private string $trigger_type = 'exact';

    /**
     * @ORM\Column(type="text")
     */
    private string $response_text;

    /**
     * text: テキスト, product_list: 商品一覧, url: URL リダイレクト
     *
     * @ORM\Column(type="string", length=32, options={"default":"text"})
     */
    private string $response_type = 'text';

    /**
     * 数値が大きいほど優先的にマッチする。
     *
     * @ORM\Column(type="integer", options={"default":0})
     */
    private int $priority = 0;

    /**
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

    public function getTriggerKeyword(): string
    {
        return $this->trigger_keyword;
    }

    public function setTriggerKeyword(string $triggerKeyword): self
    {
        $this->trigger_keyword = $triggerKeyword;
        return $this;
    }

    public function getTriggerType(): string
    {
        return $this->trigger_type;
    }

    public function setTriggerType(string $triggerType): self
    {
        $this->trigger_type = $triggerType;
        return $this;
    }

    public function getResponseText(): string
    {
        return $this->response_text;
    }

    public function setResponseText(string $responseText): self
    {
        $this->response_text = $responseText;
        return $this;
    }

    public function getResponseType(): string
    {
        return $this->response_type;
    }

    public function setResponseType(string $responseType): self
    {
        $this->response_type = $responseType;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
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
