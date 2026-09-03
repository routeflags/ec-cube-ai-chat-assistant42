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

use LogicException;

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

    /**
     * @SuppressWarnings("PHPMD.Superglobals")
     */
    protected function getRemoteUrl(): string
    {
        // $_ENV は Symfony Dotenv の正規取得口であり、getenv() と挙動差があるため Superglobals を許容する
        $envUrl = $_ENV['AI_MODELS_SYNC_URL'] ?? null;
        if ($envUrl !== null && $envUrl !== '') {
            $parsed = parse_url($envUrl);
            $scheme = $parsed['scheme'] ?? null;
            if ($scheme !== 'https') {
                $this->logger->warning('AI_MODELS_SYNC_URL must be https, fallback to default', ['url' => $envUrl]);

                return self::REMOTE_URL;
            }

            return $envUrl;
        }

        return self::REMOTE_URL;
    }

    protected function getDataPath(): string
    {
        if ($this->projectDir === '') {
            throw new LogicException('AiModelSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::PLUGIN_DATA_PATH;
    }

    protected function getMetaPath(): string
    {
        if ($this->projectDir === '') {
            throw new LogicException('AiModelSyncService: projectDir is not configured.');
        }

        return $this->projectDir . self::META_PATH;
    }

    protected function getLockPath(): string
    {
        if ($this->projectDir === '') {
            throw new LogicException('AiModelSyncService: projectDir is not configured.');
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
        if (!$this->hasValidProvidersStructure($remoteData)) {
            return null;
        }

        foreach ($remoteData['providers'] as $providerKey => $provider) {
            if (!$this->validateProvider((string) $providerKey, $provider)) {
                return null;
            }
        }

        return $this->sanitizeVersion($remoteData);
    }

    private function hasValidProvidersStructure(array $remoteData): bool
    {
        if (!isset($remoteData['providers']) || !is_array($remoteData['providers']) || $remoteData['providers'] === []) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Missing or empty providers']);

            return false;
        }

        return true;
    }

    /**
     * provider 単位の検証を行う。
     */
    private function validateProvider(string $providerKey, mixed $provider): bool
    {
        if (!in_array($providerKey, self::ALLOWED_PROVIDERS, true)) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Unknown provider: {$providerKey}"]);

            return false;
        }

        if (!is_array($provider) || !isset($provider['models']) || !is_array($provider['models'])) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid models for provider: {$providerKey}"]);

            return false;
        }

        if (count($provider['models']) > self::MAX_MODELS_PER_PROVIDER) {
            $this->logger->warning(
                $this->getSyncFailureLogMessage(),
                ['error' => "Too many models for {$providerKey}: " . count($provider['models'])]
            );

            return false;
        }

        if (!$this->validateProviderStringField($providerKey, $provider, 'name')) {
            return false;
        }

        if (!$this->validateProviderStringField($providerKey, $provider, 'api_base')) {
            return false;
        }

        return $this->validateModels($providerKey, $provider['models']);
    }

    private function validateProviderStringField(string $providerKey, array $provider, string $field): bool
    {
        if (!isset($provider[$field])) {
            return true;
        }

        if (!is_string($provider[$field]) || mb_strlen($provider[$field]) > self::MAX_STRING_LENGTH) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Invalid {$field} for provider: {$providerKey}"]);

            return false;
        }

        return true;
    }

    /**
     * models 配列を一件ずつ検証する。
     *
     * @param array<int, mixed> $models
     */
    private function validateModels(string $providerKey, array $models): bool
    {
        $seenIds = [];

        foreach ($models as $model) {
            if (!$this->validateModel($providerKey, $model, $seenIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 単一 model の検証を行う。
     *
     * @param array<string, bool> $seenIds
     */
    private function validateModel(string $providerKey, mixed $model, array &$seenIds): bool
    {
        if (!$this->hasValidModelId($providerKey, $model)) {
            return false;
        }

        /** @var array{id: string} $model */
        $modelId = $model['id'];

        if (isset($seenIds[$modelId])) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => "Duplicate id for {$providerKey}: {$modelId}"]);

            return false;
        }

        $seenIds[$modelId] = true;

        return $this->validateModelFields($providerKey, $model);
    }

    private function hasValidModelId(string $providerKey, mixed $model): bool
    {
        if (
            !is_array($model)
            || !isset($model['id'])
            || !is_string($model['id'])
            || $model['id'] === ''
            || mb_strlen($model['id']) > self::MAX_ID_LENGTH
        ) {
            $this->logger->warning(
                $this->getSyncFailureLogMessage(),
                ['error' => "Invalid id for provider: {$providerKey}"]
            );

            return false;
        }

        return true;
    }

    /**
     * model の属性を検証する。
     *
     * @param array<string,mixed> $model
     */
    private function validateModelFields(string $providerKey, array $model): bool
    {
        if (!$this->validateCostTier($providerKey, $model)) {
            return false;
        }

        if (!$this->validateBooleanField($providerKey, $model, 'supports_tools')) {
            return false;
        }

        if (!$this->validateBooleanField($providerKey, $model, 'supports_reasoning_with_tools')) {
            return false;
        }

        if (!$this->validateBooleanField($providerKey, $model, 'is_default')) {
            return false;
        }

        if (!$this->validateModelStringField($providerKey, $model, 'name')) {
            return false;
        }

        if (!$this->validateModelStringField($providerKey, $model, 'description')) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $model
     */
    private function validateCostTier(string $providerKey, array $model): bool
    {
        if (!isset($model['cost_tier'])) {
            return true;
        }

        if (!in_array($model['cost_tier'], self::ALLOWED_COST_TIERS, true)) {
            $this->logger->warning(
                $this->getSyncFailureLogMessage(),
                ['error' => "Invalid cost_tier for {$providerKey}/{$model['id']}: {$model['cost_tier']}"]
            );

            return false;
        }

        return true;
    }

    /**
     * boolean フィールドを検証する。
     *
     * @param array<string,mixed> $model
     */
    private function validateBooleanField(string $providerKey, array $model, string $field): bool
    {
        if (!isset($model[$field])) {
            return true;
        }

        if (!is_bool($model[$field])) {
            $this->logger->warning(
                $this->getSyncFailureLogMessage(),
                ['error' => "Invalid {$field} for {$providerKey}/{$model['id']}"]
            );

            return false;
        }

        return true;
    }

    /**
     * 文字列長を検証する。
     *
     * @param array<string,mixed> $model
     */
    private function validateModelStringField(string $providerKey, array $model, string $field): bool
    {
        if (!isset($model[$field])) {
            return true;
        }

        if (!is_string($model[$field]) || mb_strlen($model[$field]) > self::MAX_STRING_LENGTH) {
            $message = $field === 'name' ? "Too long name for {$providerKey}/{$model['id']}" : "Too long description for {$providerKey}/{$model['id']}";
            $this->logger->warning(
                $this->getSyncFailureLogMessage(),
                ['error' => $message]
            );

            return false;
        }

        return true;
    }

    /**
     * version は任意。型不正でも providers が正しければ許容する。
     *
     * @param array<string,mixed> $remoteData
     * @return array<string,mixed>
     */
    private function sanitizeVersion(array $remoteData): array
    {
        if (isset($remoteData['version']) && !is_string($remoteData['version'])) {
            $this->logger->warning($this->getSyncFailureLogMessage(), ['error' => 'Invalid version type, ignored']);
            unset($remoteData['version']);
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
            try {
                if (!copy($path, $path . '.bak')) {
                    $this->logger->debug('AI model backup copy failed', ['path' => $path]);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('AI model backup copy failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
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
