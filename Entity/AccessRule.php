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
 * アクセスルールを管理するエンティティ。
 *
 * IP アドレス・利用時間帯・禁止キーワードなど、
 * ユーザーの入力をフィルタリングしてチャットへのアクセスを制御する。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_access_rule")
 */
class AccessRule extends \Eccube\Entity\AbstractEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private ?int $id = null;

    /**
     * ルール種別 (ip / time / block_keyword)。
     *
     * @ORM\Column(type="string", length=32)
     */
    private string $rule_type;

    /**
     * ルール値 (IP アドレス, 時間帯範囲, 禁止キーワード等)。
     *
     * @ORM\Column(type="string", length=255)
     */
    private string $rule_value;

    /**
     * 適用アクション (deny / throttle / allow)。
     *
     * @ORM\Column(type="string", length=32, options={"default":"deny"})
     */
    private string $action = 'deny';

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

    public function getRuleType(): string
    {
        return $this->rule_type;
    }

    public function setRuleType(string $ruleType): self
    {
        $this->rule_type = $ruleType;
        return $this;
    }

    public function getRuleValue(): string
    {
        return $this->rule_value;
    }

    public function setRuleValue(string $ruleValue): self
    {
        $this->rule_value = $ruleValue;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
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
