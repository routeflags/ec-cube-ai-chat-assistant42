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
 * AI チャットアシスタントの設定を保持するエンティティ。
 *
 * プロバイダ・モデル・API キーなどのプラグイン設定を1行で管理する。
 *
 * @ORM\Entity
 * @ORM\Table(name="plg_ai_chat_assistant_config")
 */
class Config extends \Eccube\Entity\AbstractEntity
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=32, options={"default":"openai"})
     */
    private string $provider = 'openai';

    /**
     * @ORM\Column(type="string", length=128, options={"default":"gpt-4o"})
     */
    private string $model = 'gpt-4o';

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $api_key_openai = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $api_key_anthropic = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $api_key_gemini = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $system_prompt = null;

    /**
     * @ORM\Column(type="integer", options={"unsigned":true, "default":4096})
     */
    private int $max_tokens = 4096;

    /**
     * @ORM\Column(type="smallint", options={"default":0})
     */
    private int $is_enabled = 0;

    /**
     * 回答モード: 'hybrid' = ナレッジ+一般知識, 'knowledge_only' = ナレッジのみ
     *
     * @ORM\Column(type="string", length=32, options={"default":"hybrid"})
     */
    private string $response_mode = 'hybrid';

    /**
     * @ORM\Column(type="integer", options={"unsigned":true, "default":30})
     */
    private int $rate_limit_per_minute = 30;

    /**
     * 外部 AI 接続用のアクセストークン。未設定なら従来どおり無認証で公開する。
     *
     * @ORM\Column(name="mcp_access_token", type="string", length=128, nullable=true)
     */
    private ?string $mcp_access_token = null;

    /**
     * CORS を許可するオリジン（カンマ/空白/改行区切り）。未設定なら `*`。
     *
     * @ORM\Column(name="mcp_allowed_origins", type="string", length=512, nullable=true)
     */
    private ?string $mcp_allowed_origins = null;

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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getApiKeyOpenai(): ?string
    {
        return $this->api_key_openai;
    }

    public function setApiKeyOpenai(?string $apiKeyOpenai): self
    {
        $this->api_key_openai = $apiKeyOpenai;
        return $this;
    }

    public function getApiKeyAnthropic(): ?string
    {
        return $this->api_key_anthropic;
    }

    public function setApiKeyAnthropic(?string $apiKeyAnthropic): self
    {
        $this->api_key_anthropic = $apiKeyAnthropic;
        return $this;
    }

    public function getApiKeyGemini(): ?string
    {
        return $this->api_key_gemini;
    }

    public function setApiKeyGemini(?string $apiKeyGemini): self
    {
        $this->api_key_gemini = $apiKeyGemini;
        return $this;
    }

    /**
     * 指定プロバイダの API キーをマスクして返す。
     *
     * 管理画面表示やログ出力で API キーを露呈しないよう、
     * 先頭を asterisk で隠し末尾4文字だけを表示する。
     * キーが未設定の場合は空文字を返す。
     */
    public function getMaskedApiKey(string $provider): string
    {
        $key = match ($provider) {
            'openai' => $this->api_key_openai,
            'anthropic' => $this->api_key_anthropic,
            'gemini' => $this->api_key_gemini,
            default => null,
        };

        if (!$key) {
            return '';
        }

        $length = strlen($key);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($key, -4);
    }

    public function getSystemPrompt(): ?string
    {
        return $this->system_prompt;
    }

    public function setSystemPrompt(?string $systemPrompt): self
    {
        $this->system_prompt = $systemPrompt;
        return $this;
    }

    public function getMaxTokens(): int
    {
        return $this->max_tokens;
    }

    public function setMaxTokens(int $maxTokens): self
    {
        $this->max_tokens = $maxTokens;
        return $this;
    }

    public function getIsEnabled(): int
    {
        return $this->is_enabled;
    }

    public function setIsEnabled(int $isEnabled): self
    {
        $this->is_enabled = $isEnabled;
        return $this;
    }

    public function getResponseMode(): string
    {
        return $this->response_mode;
    }

    public function setResponseMode(string $responseMode): self
    {
        $this->response_mode = $responseMode;
        return $this;
    }

    public function getRateLimitPerMinute(): int
    {
        return $this->rate_limit_per_minute;
    }

    public function setRateLimitPerMinute(int $rateLimitPerMinute): self
    {
        $this->rate_limit_per_minute = $rateLimitPerMinute;
        return $this;
    }

    /**
     * 外部 AI 接続用のトークン。未設定なら null。
     */
    public function getMcpAccessToken(): ?string
    {
        return $this->mcp_access_token;
    }

    public function setMcpAccessToken(?string $mcpAccessToken): self
    {
        $this->mcp_access_token = ($mcpAccessToken === null || $mcpAccessToken === '') ? null : $mcpAccessToken;
        return $this;
    }

    /**
     * 管理画面表示用のマスク済みトークン。末尾 4 文字のみ残す。
     */
    public function getMaskedMcpAccessToken(): string
    {
        if ($this->mcp_access_token === null) {
            return '';
        }

        return '••••' . substr($this->mcp_access_token, -4);
    }

    /**
     * CORS 許可オリジンの生文字列（未設定なら null）。
     */
    public function getMcpAllowedOrigins(): ?string
    {
        return $this->mcp_allowed_origins;
    }

    public function setMcpAllowedOrigins(?string $mcpAllowedOrigins): self
    {
        $trimmed = $mcpAllowedOrigins === null ? '' : trim($mcpAllowedOrigins);
        $this->mcp_allowed_origins = ($trimmed === '') ? null : $trimmed;
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
