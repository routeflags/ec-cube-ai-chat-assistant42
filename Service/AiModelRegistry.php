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

use Psr\Log\LoggerInterface;

/**
 * AI モデルのレジストリ。
 *
 * ai_models.json を読み込み、プロバイダ別モデル情報の参照・検証を行う。
 * チャットアシスタントが利用可能なモデル一覧を単一ソースで管理する。
 *
 * フォールバック優先度: PluginData(ai_models.json) → Resource/config/ai_models.json。
 * Single Source of Truth として、分散していた探索ロジックを本クラスに集約する。
 */
class AiModelRegistry
{
    /** @var array 設定データ全体（JSON パース済み） */
    private array $config;

    /**
     * @param string               $configPath ai_models.json の第一候補パス（通常は PluginData）
     * @param string               $projectDir プロジェクトルート（%kernel.project_dir%）
     * @param LoggerInterface|null $logger     フォールバック時の info ログ用（任意）
     *
     * @throws \RuntimeException ファイルが読めない場合
     * @throws \InvalidArgumentException JSON の形式が不正な場合
     */
    public function __construct(string $configPath, string $projectDir = '', ?LoggerInterface $logger = null)
    {
        $resolved = $this->resolveConfigPath($configPath, $projectDir);

        // フォールバック時は任意でログを残す（デバッグ時の追跡用）
        if ($resolved !== $configPath && $logger !== null) {
            $logger->info('AI model config fallback', ['primary' => $configPath, 'resolved' => $resolved]);
        }

        $this->config = self::loadAndValidateConfig($resolved);
    }

    /**
     * 実在する設定ファイルパスを優先度順に探索する。
     *
     * 優先度:
     *  1. $primary（PluginData/ai_models.json — リモート同期の正本）
     *  2. $projectDir/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json（EC-CUBE 本体配置）
     *  3. プラグイン直下 Resource/config/ai_models.json（単体実行・テスト）
     *
     * 見つからなければ $primary を返し、呼び出し元の loadAndValidateConfig で
     * 既存どおり RuntimeException を投げる。
     */
    private function resolveConfigPath(string $primary, string $projectDir): string
    {
        // $primary 自体が存在すればそれを採用（テストで Resource 直指定された場合も含む）
        if (is_file($primary)) {
            return $primary;
        }

        // 汎用ガード: ai_models.json 以外のファイル名ではフォールバックしない
        // 従来の PluginData 文字列依存を str_ends_with に置換し、/tmp 等の一時パスでも
        // フォールバックが効くようにする（ただし本番の PluginData 優先は維持）
        if (!str_ends_with($primary, 'ai_models.json')) {
            return $primary;
        }

        // 既存テスト互換: 任意の /nonexistent/path/ai_models.json で projectDir が空の場合、
        // Resource へのフォールバックで誤って成功し RuntimeException が隠蔽されるのを防ぐ。
        // /tmp 配下・PluginData 配下・projectDir 配下のいずれでもない孤立パスはフォールバックしない
        $isPluginData = str_contains($primary, 'PluginData');
        $isTemp = str_contains($primary, sys_get_temp_dir());
        $isProjectDirRelated = $projectDir !== '' && str_starts_with($primary, $projectDir);
        if (!$isPluginData && !$isTemp && !$isProjectDirRelated) {
            return $primary;
        }

        $candidates = array_filter([
            $primary,
            $projectDir !== '' ? $projectDir . '/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json' : null,
            // EC-CUBE ルート直下の Resource（プラグイン単体リポジトリのレイアウト）
            $projectDir !== '' ? $projectDir . '/Resource/config/ai_models.json' : null,
            // プラグイン内部の相対パス（Service の兄弟ディレクトリ）
            dirname(__DIR__) . '/Resource/config/ai_models.json',
            dirname(__DIR__, 2) . '/Resource/config/ai_models.json',
            dirname(__DIR__, 3) . '/Resource/config/ai_models.json',
        ]);

        // 重複を除去しつつ順序を維持
        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $primary;
    }

    /**
     * 設定ファイルを読み込み、バリデーションする。
     *
     * コンストラクタの責務分離により、読み込みロジックの CC を
     * このスタティックメソッドに集約する。
     *
     * @throws \RuntimeException ファイルが読めない場合
     * @throws \InvalidArgumentException JSON の形式が不正な場合
     */
    private static function loadAndValidateConfig(string $configPath): array
    {
        if (!file_exists($configPath)) {
            throw new \RuntimeException(sprintf('AI model config not found: %s', $configPath));
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Failed to read AI model config: %s', $configPath));
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid JSON in AI model config: %s (error: %s)',
                $configPath,
                json_last_error_msg()
            ));
        }

        // 構造バリデーション: providers キーが必須
        if (!isset($decoded['providers']) || !is_array($decoded['providers'])) {
            throw new \InvalidArgumentException(sprintf(
                'AI model config must contain a "providers" key: %s',
                $configPath
            ));
        }

        return $decoded;
    }

    /**
     * 指定プロバイダのモデル一覧を返す。
     *
     * @param string $provider プロバイダキー（openai / anthropic / gemini）
     *
     * @return array<int, array{id: string, name: string, description: string, supports_tools: bool, supports_reasoning_with_tools?: bool, cost_tier: string, is_default: bool}>
     */
    public function getModels(string $provider): array
    {
        return $this->config['providers'][$provider]['models'] ?? [];
    }

    /**
     * 指定プロバイダの単一モデル定義を返す。
     *
     * 見つからない場合は null を返す。
     *
     * @return array{id: string, name: string, description: string, supports_tools: bool, supports_reasoning_with_tools?: bool, cost_tier: string, is_default: bool}|null
     */
    public function getModel(string $provider, string $modelId): ?array
    {
        foreach ($this->getModels($provider) as $model) {
            if ($model['id'] === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * 指定モデルがツール併用時の reasoning に対応しているか判定する。
     *
     * ai_models.json の supports_reasoning_with_tools を参照する。
     * キーが存在しないモデルは後方互換のため true として扱う（従来モデルは制限なし）。
     */
    public function supportsReasoningWithTools(string $provider, string $modelId): bool
    {
        $model = $this->getModel($provider, $modelId);
        if ($model === null) {
            // 未知のモデルは制限をかけない（デグレ防止）
            return true;
        }

        // 明示的に false が設定されている場合のみ false、未設定は true
        if (!array_key_exists('supports_reasoning_with_tools', $model)) {
            return true;
        }

        return (bool) $model['supports_reasoning_with_tools'];
    }

    /**
     * 指定モデルがツール呼び出しをサポートしているか判定する。
     */
    public function supportsTools(string $provider, string $modelId): bool
    {
        $model = $this->getModel($provider, $modelId);
        if ($model === null) {
            return false;
        }

        return (bool) ($model['supports_tools'] ?? false);
    }

    /**
     * 指定プロバイダのデフォルトモデル ID を返す。
     * デフォルトが未設定の場合は、最初のモデル ID を返す。
     *
     * @return string|null モデル ID（プロバイダが存在しない場合は null）
     */
    public function getDefaultModel(string $provider): ?string
    {
        $models = $this->getModels($provider);
        if (empty($models)) {
            return null;
        }

        return $this->findDefaultModelId($models);
    }

    /**
     * 全プロバイダの一覧を返す。
     *
     * @return array<int, array{key: string, name: string, api_base: string, model_count: int}>
     */
    public function getProviders(): array
    {
        $providers = [];
        foreach ($this->config['providers'] ?? [] as $key => $provider) {
            $providers[] = $this->buildProviderSummary($key, $provider);
        }

        return $providers;
    }

    /**
     * 指定プロバイダのモデルが有効か検証する。
     *
     * @param string $provider プロバイダキー
     * @param string $modelId  モデル ID
     */
    public function isValidModel(string $provider, string $modelId): bool
    {
        foreach ($this->getModels($provider) as $model) {
            if ($model['id'] === $modelId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 設定ファイルのバージョン文字列を返す。
     */
    public function getVersion(): string
    {
        return $this->config['version'] ?? '';
    }

    /**
     * 設定ファイルの全データを返す。
     *
     * @return array{version: string, providers: array}
     */
    public function getAll(): array
    {
        return $this->config;
    }

    /**
     * 指定プロバイダの API ベース URL を返す。
     *
     * @return string|null 存在しない場合は null
     */
    public function getApiBase(string $provider): ?string
    {
        return $this->config['providers'][$provider]['api_base'] ?? null;
    }

    /**
     * 指定プロバイダの表示名を返す。
     *
     * @return string|null 存在しない場合は null
     */
    public function getProviderName(string $provider): ?string
    {
        return $this->config['providers'][$provider]['name'] ?? null;
    }

    /**
     * 全プロバイダの全モデルを平坦化して返す。
     *
     * @return array<int, array{provider: string, id: string, name: string, description: string, supports_tools: bool, cost_tier: string, is_default: bool}>
     */
    public function getAllModels(): array
    {
        $all = [];
        foreach ($this->config['providers'] ?? [] as $providerKey => $provider) {
            foreach ($provider['models'] ?? [] as $model) {
                $model['provider'] = $providerKey;
                $all[] = $model;
            }
        }

        return $all;
    }

    // ================================================================
    //  内部ヘルパーメソッド
    // ================================================================

    /**
     * モデル配列からデフォルトモデル ID を検索する。
     *
     * is_default フラグが true のモデルがあればそれを返し、
     * なければ先頭モデル ID を返す。
     *
     * @param array<int, array{id: string, is_default: bool}> $models
     */
    private function findDefaultModelId(array $models): string
    {
        foreach ($models as $model) {
            if (!empty($model['is_default'])) {
                return $model['id'];
            }
        }

        // デフォルト指定がなければ先頭モデル ID を返す（空配列の場合は呼び出し元でガード済み）
        return $models[0]['id'] ?? '';
    }

    /**
     * プロバイダ設定からサマリー配列を構築する。
     *
     * @param string $key      プロバイダキー
     * @param array  $provider プロバイダ設定データ
     *
     * @return array{key: string, name: string, api_base: string, model_count: int}
     */
    private function buildProviderSummary(string $key, array $provider): array
    {
        return [
            'key' => $key,
            'name' => $provider['name'] ?? $key,
            'api_base' => $provider['api_base'] ?? '',
            'model_count' => count($provider['models'] ?? []),
        ];
    }
}
