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

namespace Plugin\AiChatAssistant42\Service;

/**
 * ai_models.json のリモート同期を担うサービス。
 *
 * - 取得元: https://routeflags.com/dist/ec_chat/ai_models.json (REMOTE_URL)
 * - 保存先: app/PluginData/AiChatAssistant42/ai_models.json (永続領域)
 * - 同期間隔: TTL 86400秒、排他や原子書き込みは基底に集約
 * - 検証: providers/cost_tier/boolean/文字数上限/重複id を厳格に検証
 * - 永続化: 全文置換 + 1世代バックアップ
 */
class AiModelSyncService extends AbstractPluginDataSyncService
{
    public const REMOTE_URL = 'https://routeflags.com/dist/ec_chat/ai_models.json';
    public const PLUGIN_DATA_PATH = '/app/PluginData/AiChatAssistant42/ai_models.json';
    public const META_PATH = '/app/PluginData/AiChatAssistant42/.ai_models.meta.json';
    public const LOCK_PATH = '/app/PluginData/AiChatAssistant42/.ai_models.sync.lock';

    private const ALLOWED_PROVIDERS = ['openai', 'anthropic', 'gemini'];
    private const ALLOWED_COST_TIERS = ['low', 'medium', 'high'];
    private const MAX_MODELS_PER_PROVIDER = 20;
    private const MAX_ID_LENGTH = 128;

    protected function getRemoteUrl(): string
    {
        return $_ENV['AI_MODELS_SYNC_URL'] ?? self::REMOTE_URL;
    }

    protected function getDataPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('AiModelSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::PLUGIN_DATA_PATH;
    }

    protected function getMetaPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('AiModelSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::META_PATH;
    }

    protected function getLockPath(): string
    {
        if ($this->projectDir === '') {
            throw new \LogicException('AiModelSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::LOCK_PATH;
    }

    protected function getSyncFailureLogMessage(): string
    {
        return 'AI model sync failed, keeping local';
    }

    protected function getNotModifiedLogMessage(): string
    {
        return 'AI model sync: 304 Not Modified';
    }

    /**
     * リモートデータを厳格にバリデーションする。
     *
     * - providers 必須・非空配列
     * - provider キーは ALLOWED_PROVIDERS のみ
     * - 各 provider は models 配列を持ち、上限 20件
     * - 各 model は id 必須 (非空・128文字以内・重複不可)
     * - cost_tier はホワイトリスト、supports_tools 等は boolean、文字列は 2000文字以内
     *
     * @return array<string,mixed>|null 不正なら null + warning
     */
    protected function validate(array $remoteData): ?array
    {
        if (!isset($remoteData['providers']) || !is_array($remoteData['providers']) || $remoteData['providers'] === []) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Missing or empty providers']);

            return null;
        }

        foreach ($remoteData['providers'] as $providerKey => $provider) {
            if (!in_array($providerKey, self::ALLOWED_PROVIDERS, true)) {
                $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Unknown provider: {$providerKey}"]);

                return null;
            }

            if (!is_array($provider) || !isset($provider['models']) || !is_array($provider['models'])) {
                $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid models for provider: {$providerKey}"]);

                return null;
            }

            if (count($provider['models']) > self::MAX_MODELS_PER_PROVIDER) {
                $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Too many models for {$providerKey}: " . count($provider['models'])]);

                return null;
            }

            // provider の name/api_base があれば文字列長を検証
            if (isset($provider['name']) && (!is_string($provider['name']) || mb_strlen($provider['name']) > self::MAX_STRING_LENGTH)) {
                $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid name for provider: {$providerKey}"]);

                return null;
            }
            if (isset($provider['api_base']) && (!is_string($provider['api_base']) || mb_strlen($provider['api_base']) > self::MAX_STRING_LENGTH)) {
                $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid api_base for provider: {$providerKey}"]);

                return null;
            }

            $seenIds = [];
            foreach ($provider['models'] as $model) {
                if (!is_array($model) || !isset($model['id']) || !is_string($model['id']) || $model['id'] === '' || mb_strlen($model['id']) > self::MAX_ID_LENGTH) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid id for provider: {$providerKey}"]);

                    return null;
                }

                if (isset($seenIds[$model['id']])) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Duplicate id for {$providerKey}: {$model['id']}"]);

                    return null;
                }
                $seenIds[$model['id']] = true;

                if (isset($model['cost_tier']) && !in_array($model['cost_tier'], self::ALLOWED_COST_TIERS, true)) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid cost_tier for {$providerKey}/{$model['id']}: {$model['cost_tier']}"]);

                    return null;
                }

                if (isset($model['supports_tools']) && !is_bool($model['supports_tools'])) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid supports_tools for {$providerKey}/{$model['id']}"]);

                    return null;
                }

                if (isset($model['supports_reasoning_with_tools']) && !is_bool($model['supports_reasoning_with_tools'])) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid supports_reasoning_with_tools for {$providerKey}/{$model['id']}"]);

                    return null;
                }

                if (isset($model['is_default']) && !is_bool($model['is_default'])) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid is_default for {$providerKey}/{$model['id']}"]);

                    return null;
                }

                if (isset($model['name']) && (!is_string($model['name']) || mb_strlen($model['name']) > self::MAX_STRING_LENGTH)) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Too long name for {$providerKey}/{$model['id']}"]);

                    return null;
                }

                if (isset($model['description']) && (!is_string($model['description']) || mb_strlen($model['description']) > self::MAX_STRING_LENGTH)) {
                    $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Too long description for {$providerKey}/{$model['id']}"]);

                    return null;
                }
            }
        }

        // version は任意だが、あれば文字列であること
        if (isset($remoteData['version']) && !is_string($remoteData['version'])) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Invalid version type']);

            return null;
        }

        return $remoteData;
    }

    /**
     * 検証済みデータを全文置換で永続化する。
     *
     * 1世代バックアップ (ai_models.json.bak) を取得し、atomicWrite で保存、
     * meta.json の last_synced_at/etag を更新する。
     *
     * @param array<string,mixed> $validated
     */
    protected function persist(array $validated): void
    {
        $path = $this->getDataPath();

        // 1世代バックアップ（失敗時の手動復旧用、エラーは握りつぶす）
        if (is_file($path)) {
            @copy($path, $path . '.bak');
        }

        $json = json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'json_encode failed: ' . json_last_error_msg()]);

            return;
        }

        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Payload too large after encode: ' . strlen($json)]);

            return;
        }

        $this->atomicWrite($path, $json);

        $meta = $this->loadMeta();
        $meta['last_synced_at'] = time();
        if (!empty($this->pendingRemoteMeta['etag'])) {
            $meta['etag'] = $this->pendingRemoteMeta['etag'];
        }
        if (!empty($this->pendingRemoteMeta['last_modified'])) {
            $meta['last_modified'] = $this->pendingRemoteMeta['last_modified'];
        }
        $this->saveMeta($meta);

        $this->logger->info('AI model synced from remote', [
            'version' => $validated['version'] ?? '',
            'providers' => array_keys($validated['providers']),
        ]);
    }
}
