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
 * MCP ツール呼び出し監査ログ。
 *
 * tool名・結果区分・所要時間・エラーコードのみ記録し、
 * 引数・応答内容・個人情報は記録しない（WebMCP設計書 PREMISES-4）。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_mcp_audit")
 */
class McpAudit extends \Eccube\Entity\AbstractEntity
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
    private string $tool_name;

    /**
     * @ORM\Column(type="string", length=16)
     */
    private string $result;

    /**
     * @ORM\Column(type="string", length=32, nullable=true)
     */
    private ?string $error_code = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"unsigned":true})
     */
    private ?int $duration_ms = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    private ?string $client_ip_hash = null;

    /**
     * @ORM\Column(type="datetimetz")
     */
    private ?\DateTimeInterface $created_at = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToolName(): string
    {
        return $this->tool_name;
    }

    public function setToolName(string $toolName): self
    {
        $this->tool_name = $toolName;
        return $this;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function setResult(string $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function getErrorCode(): ?string
    {
        return $this->error_code;
    }

    public function setErrorCode(?string $errorCode): self
    {
        $this->error_code = $errorCode;
        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->duration_ms;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->duration_ms = $durationMs;
        return $this;
    }

    public function getClientIpHash(): ?string
    {
        return $this->client_ip_hash;
    }

    public function setClientIpHash(?string $clientIpHash): self
    {
        $this->client_ip_hash = $clientIpHash;
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
