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
 * AI チャットアシスタントのフィードバックを保存するエンティティ。
 *
 * ユーザーが「解決できました / 解決できません」でフィードバックした結果を
 * session_id 単位で記録する。同一 session_id で重複投稿は 409 で拒否する。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_feedback",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uniq_session_feedback", columns={"session_id"})
 *     },
 *     indexes={
 *         @ORM\Index(name="idx_feedback", columns={"feedback"}),
 *         @ORM\Index(name="idx_feedback_created_at", columns={"created_at"})
 *     }
 * )
 */
class Feedback extends \Eccube\Entity\AbstractEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=64)
     */
    private string $session_id;

    /**
     * フィードバック種別: positive / negative
     *
     * @ORM\Column(type="string", length=16)
     */
    private string $feedback;

    /**
     * @ORM\Column(type="datetimetz")
     */
    private ?\DateTimeInterface $created_at = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->session_id;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->session_id = $sessionId;
        return $this;
    }

    public function getFeedback(): string
    {
        return $this->feedback;
    }

    public function setFeedback(string $feedback): self
    {
        $this->feedback = $feedback;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->created_at = $createdAt;
        return $this;
    }
}
