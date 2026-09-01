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
 * AI チャットのやり取りログを保存するエンティティ。
 *
 * ユーザーの質問・AI の返答・使用ツール・応答時間を記録し、
 * サポート品質の分析やモデル比較に活用する。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_log")
 */
class ChatLog extends \Eccube\Entity\AbstractEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="bigint", options={"unsigned":true})
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=64)
     */
    private string $session_id;

    /**
     * @ORM\Column(type="string", length=45, nullable=true)
     */
    private ?string $client_ip = null;

    /**
     * @ORM\Column(type="string", length=32)
     */
    private string $provider;

    /**
     * @ORM\Column(type="string", length=128)
     */
    private string $model;

    /**
     * @ORM\Column(type="text")
     */
    private string $user_message;

    /**
     * @ORM\Column(type="text")
     */
    private string $assistant_reply;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $tools_used = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"unsigned":true})
     */
    private ?int $response_time_ms = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"unsigned":true})
     */
    private ?int $token_input = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"unsigned":true})
     */
    private ?int $token_output = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $error_message = null;

    /**
     * @ORM\Column(type="smallint", nullable=true)
     */
    private ?int $satisfaction_rating = null;

    /**
     * @ORM\Column(type="smallint", options={"default":0})
     */
    private int $is_resolved = 0;

    /**
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    private ?string $error_type = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"unsigned":true})
     */
    private ?int $product_id = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"unsigned":true})
     */
    private ?int $order_id = null;

    /**
     * メール返信先アドレス（ユーザーが「解決できません」を選択した際に記録）。
     */
    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $email_reply_address = null;

    /**
     * メール返信の完了日時。
     */
    /**
     * @ORM\Column(type="datetimetz", nullable=true)
     */
    private ?\DateTimeInterface $email_replied_at = null;

    /**
     * @ORM\Column(type="datetimetz")
     */
    private ?\DateTimeInterface $created_at = null;

    /**
     * @ORM\Column(type="datetimetz", nullable=true)
     */
    private ?\DateTimeInterface $synced_at = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return string セッション ID */
    public function getSessionId(): string
    {
        return $this->session_id;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->session_id = $sessionId;
        return $this;
    }

    /** @return string|null クライアントIP */
    public function getClientIp(): ?string
    {
        return $this->client_ip;
    }

    public function setClientIp(?string $clientIp): self
    {
        $this->client_ip = $clientIp;
        return $this;
    }

    /** @return string AI プロバイダ識別子 (openai / anthropic / gemini) */
    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    /** @return string モデル名 */
    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /** @return string ユーザー入力メッセージ */
    public function getUserMessage(): string
    {
        return $this->user_message;
    }

    public function setUserMessage(string $userMessage): self
    {
        $this->user_message = $userMessage;
        return $this;
    }

    /** @return string AI 生成返答テキスト */
    public function getAssistantReply(): string
    {
        return $this->assistant_reply;
    }

    public function setAssistantReply(string $assistantReply): self
    {
        $this->assistant_reply = $assistantReply;
        return $this;
    }

    /** @return array|null 使用したツール名の配列 */
    public function getToolsUsed(): ?array
    {
        return $this->tools_used;
    }

    public function setToolsUsed(?array $toolsUsed): self
    {
        $this->tools_used = $toolsUsed;
        return $this;
    }

    /** @return int|null 応答時間（ミリ秒） */
    public function getResponseTimeMs(): ?int
    {
        return $this->response_time_ms;
    }

    public function setResponseTimeMs(?int $responseTimeMs): self
    {
        $this->response_time_ms = $responseTimeMs;
        return $this;
    }

    /** @return int|null 入力トークン数 */
    public function getTokenInput(): ?int
    {
        return $this->token_input;
    }

    public function setTokenInput(?int $tokenInput): self
    {
        $this->token_input = $tokenInput;
        return $this;
    }

    /** @return int|null 出力トークン数 */
    public function getTokenOutput(): ?int
    {
        return $this->token_output;
    }

    public function setTokenOutput(?int $tokenOutput): self
    {
        $this->token_output = $tokenOutput;
        return $this;
    }

    /** @return string|null エラーメッセージ */
    public function getErrorMessage(): ?string
    {
        return $this->error_message;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->error_message = $errorMessage;
        return $this;
    }

    /** @return int|null ユーザー満足度評価 (1-5) */
    public function getSatisfactionRating(): ?int
    {
        return $this->satisfaction_rating;
    }

    public function setSatisfactionRating(?int $satisfactionRating): self
    {
        $this->satisfaction_rating = $satisfactionRating;
        return $this;
    }

    /** @return int 解決済みフラグ (0=未解決, 1=解決済み) */
    public function getIsResolved(): int
    {
        return $this->is_resolved;
    }

    public function setIsResolved(int $isResolved): self
    {
        $this->is_resolved = $isResolved;
        return $this;
    }

    /** @return string|null エラー種別 (api_error / timeout / tool_error 等) */
    public function getErrorType(): ?string
    {
        return $this->error_type;
    }

    public function setErrorType(?string $errorType): self
    {
        $this->error_type = $errorType;
        return $this;
    }

    /** @return int|null 関連商品 ID */
    public function getProductId(): ?int
    {
        return $this->product_id;
    }

    public function setProductId(?int $productId): self
    {
        $this->product_id = $productId;
        return $this;
    }

    /** @return int|null 関連注文 ID */
    public function getOrderId(): ?int
    {
        return $this->order_id;
    }

    public function setOrderId(?int $orderId): self
    {
        $this->order_id = $orderId;
        return $this;
    }

    /** @return string|null メール返信先アドレス */
    public function getEmailReplyAddress(): ?string
    {
        return $this->email_reply_address;
    }

    public function setEmailReplyAddress(?string $emailReplyAddress): self
    {
        $this->email_reply_address = $emailReplyAddress;
        return $this;
    }

    /** @return \DateTimeInterface|null メール返信完了日時 */
    public function getEmailRepliedAt(): ?\DateTimeInterface
    {
        return $this->email_replied_at;
    }

    public function setEmailRepliedAt(?\DateTimeInterface $emailRepliedAt): self
    {
        $this->email_replied_at = $emailRepliedAt;
        return $this;
    }

    /** @return \DateTimeInterface|null レコード作成日時 */
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->created_at = $createdAt;
        return $this;
    }

    /** @return \DateTimeInterface|null 外部システム同期日時 */
    public function getSyncedAt(): ?\DateTimeInterface
    {
        return $this->synced_at;
    }

    public function setSyncedAt(?\DateTimeInterface $syncedAt): self
    {
        $this->synced_at = $syncedAt;
        return $this;
    }
}
