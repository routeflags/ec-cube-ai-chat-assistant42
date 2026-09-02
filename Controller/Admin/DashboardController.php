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

namespace Plugin\AiChatAssistant42\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Controller\AbstractController;
use Plugin\AiChatAssistant42\Entity\ChatLog;
use Plugin\AiChatAssistant42\Entity\Config;
use Plugin\AiChatAssistant42\Repository\ChatLogRepository;
use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use Plugin\AiChatAssistant42\Service\ApiKeyEncryptor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI チャットアシスタントの管理画面ダッシュボード。
 *
 * KPI（総会話数・解決率・エラー率・平均応答時間）、
 * 直近のチャットログ、プロバイダ／モデル統計、エラー内訳を
 * 過去30日分集計して表示する。
 *
 * max_tokens は DB(Config) で管理（管理画面で編集可能）。ai_models.json からは削除済み。
 *       ChatApiController::executeChatSession() は $config->getMaxTokens() を AiAgentFactory::create() に渡す。
 *       dashboard.twig:296 の表示も DB 値で統一。
 */
class DashboardController extends AbstractController
{
    /**
     * プロバイダのホワイトリスト（provider whitelist）。
     * 不正な provider 値の保存を防止する。
     */
    private const ALLOWED_PROVIDERS = ['openai', 'anthropic', 'gemini'];

    private const MODEL_MAX_LENGTH = 128;

    public function __construct(
        private ?AiModelRegistry $aiModelRegistry = null,
        private ?ApiKeyEncryptor $apiKeyEncryptor = null,
        private ?ChatLogRepository $chatLogRepository = null,
    ) {
    }

    /**
     * @return ChatLogRepository
     */
    private function getChatLogRepository()
    {
        if ($this->chatLogRepository !== null) {
            return $this->chatLogRepository;
        }
        // AbstractController 経由の EntityManager から取得（テスト時のフォールバック）
        /** @var ChatLogRepository $repo */
        $repo = $this->entityManager->getRepository(ChatLog::class);

        return $repo;
    }

    /**
     * ダッシュボードトップを表示する。
     */
    public function index(): Response
    {
        $now = new \DateTimeImmutable();
        $periodEnd = $now;
        $periodStart = $now->modify('-30 days');
        $prevPeriodEnd = $periodStart;
        $prevPeriodStart = $now->modify('-60 days');

        $kpiCurrent = $this->fetchKpi($periodStart, $periodEnd);
        $kpiPrevious = $this->fetchKpi($prevPeriodStart, $prevPeriodEnd);

        $recentLogs = $this->fetchRecentLogs(10);
        $providerStats = $this->fetchProviderStats($periodStart, $periodEnd);
        $modelStats = $this->fetchModelStats($periodStart, $periodEnd);
        $errorBreakdown = $this->fetchErrorBreakdown($periodStart, $periodEnd);
        $pendingEmailReplies = $this->fetchPendingEmailReplies();
        $config = $this->fetchConfig();
        $hourlyDistribution = $this->fetchHourlyDistribution($periodStart, $periodEnd);

        return $this->render('@AiChatAssistant42/admin/dashboard.twig', [
            'menus' => ['setting', 'ai_chat_assistant', 'ai_chat_assistant_dashboard'],
            'kpi_current' => $kpiCurrent,
            'kpi_previous' => $kpiPrevious,
            'recent_logs' => $recentLogs,
            'provider_stats' => $providerStats,
            'model_stats' => $modelStats,
            'hourly_distribution' => $hourlyDistribution,
            'error_breakdown' => $errorBreakdown,
            'pending_email_replies' => $pendingEmailReplies,
            'config' => $config,
        ]);
    }

    /**
     * 指定期間の KPI を集計して返す。
     *
     * @return array{total: int, resolved: int, errors: int, avg_response_ms: float, resolution_rate: float, error_rate: float}
     */
    private function fetchKpi(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('
            COUNT(log.id) AS total,
            SUM(CASE WHEN log.is_resolved = 1 THEN 1 ELSE 0 END) AS resolved,
            SUM(CASE WHEN log.error_message IS NOT NULL AND log.error_message != \'\' THEN 1 ELSE 0 END) AS errors,
            AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms
        ')
            ->from(ChatLog::class, 'log')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        $row = $qb->getQuery()->getSingleResult();

        $total = (int) $row['total'];
        $resolved = (int) $row['resolved'];
        $errors = (int) $row['errors'];
        $avgResponseMs = (float) ($row['avg_response_ms'] ?? 0);

        return [
            'total' => $total,
            'resolved' => $resolved,
            'errors' => $errors,
            'avg_response_ms' => $avgResponseMs,
            'resolution_rate' => $total > 0 ? round($resolved / $total * 100, 1) : 0.0,
            'error_rate' => $total > 0 ? round($errors / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * 直近のチャットログを取得する。
     *
     * @return ChatLog[]
     */
    private function fetchRecentLogs(int $limit): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('log')
            ->from(ChatLog::class, 'log')
            ->orderBy('log.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * プロバイダ別の使用統計を取得する。
     *
     * @return array<array{provider: string, count: int, avg_response_ms: float}>
     */
    private function fetchProviderStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('
                log.provider AS provider,
                COUNT(log.id) AS count,
                AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms
            ')
            ->from(ChatLog::class, 'log')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('log.provider')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * モデル別の使用統計を取得する。
     *
     * @return array<array{model: string, count: int, avg_response_ms: float}>
     */
    private function fetchModelStats(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('
                log.model AS model,
                COUNT(log.id) AS count,
                AVG(CASE WHEN log.response_time_ms IS NOT NULL THEN log.response_time_ms ELSE 0 END) AS avg_response_ms
            ')
            ->from(ChatLog::class, 'log')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('log.model')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * エラータイプ別の内訳を取得する。
     *
     * @return array<array{error_type: string, count: int}>
     */
    private function fetchErrorBreakdown(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('
                COALESCE(log.error_type, \'unknown\') AS error_type,
                COUNT(log.id) AS count
            ')
            ->from(ChatLog::class, 'log')
            ->where('log.created_at >= :start')
            ->andWhere('log.created_at < :end')
            ->andWhere('log.error_message IS NOT NULL')
            ->andWhere('log.error_message != \'\'')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('error_type')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * 未対応のメール返信依頼件数を取得する。
     */
    private function fetchPendingEmailReplies(): int
    {
        return $this->getChatLogRepository()->countPendingEmailReplies();
    }

    /**
     * 時間帯別分布（0-23時）を取得する。
     *
     * DB プラットフォーム分岐を含む集計ロジックは
     * ChatLogRepository::fetchHourlyDistribution に委譲し、
     * コントローラは表示責務に専念する。
     *
     * @return array<int, array{hour: int, count: int}>
     */
    private function fetchHourlyDistribution(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->getChatLogRepository()->fetchHourlyDistribution($start, $end);
    }

    /**
     * プラグイン設定を取得する。
     */
    private function fetchConfig(): ?Config
    {
        return $this->entityManager->getRepository(Config::class)
            ->findOneBy([], ['id' => 'ASC']);
    }

    /**
     * プラグイン設定ページを表示する。
     *
     * GET 時は ai_models.json を読み込み、プロバイダ別のモデル選択肢を
     * Twig に渡す。POST 時は model をバリデーションしつつ保存する
     * （max_tokens は JSON で管理するため保存しない）。
     */
    public function settings(Request $request): Response
    {
        $config = $this->ensureConfigExists();
        $modelsByProvider = $this->loadAiModels();
        $allModelIds = $this->extractAllModelIds($modelsByProvider);

        if ($request->isMethod('POST')) {
            $redirect = $this->handleSettingsPost($request, $config, $allModelIds, $modelsByProvider);
            if ($redirect instanceof Response) {
                return $redirect;
            }
        }

        // APIキーのマスク表示は復号後の平文をマスクする（暗号化対応 + 平文後方互換）
        $maskedKeys = [
            'openai' => $this->getMaskedApiKey($config->getApiKeyOpenai()),
            'anthropic' => $this->getMaskedApiKey($config->getApiKeyAnthropic()),
            'gemini' => $this->getMaskedApiKey($config->getApiKeyGemini()),
        ];

        return $this->render('@AiChatAssistant42/admin/settings.twig', [
            'menus' => ['setting', 'ai_chat_assistant', 'ai_chat_assistant_settings'],
            'config' => $config,
            'modelsByProvider' => $modelsByProvider,
            'allModelIds' => $allModelIds,
            'maskedKeys' => $maskedKeys,
        ]);
    }

    /**
     * 設定が存在しなければデフォルト値で作成して返す。
     */
    private function ensureConfigExists(): Config
    {
        $config = $this->fetchConfig();
        if ($config !== null) {
            return $config;
        }

        $config = $this->createDefaultConfig();
        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $config;
    }

    /**
     * デフォルト設定の Config エンティティを生成する。
     */
    private function createDefaultConfig(): Config
    {
        $config = new Config();
        $config->setProvider('openai');
        $config->setModel('gpt-4o');
        $config->setMaxTokens(4096);
        $config->setIsEnabled(0);

        return $config;
    }

    /**
     * 設定フォームの POST リクエストを処理する。
     *
     * CSRF と provider/model/max_tokens を検証し、不正なら保存せずリダイレクトする。
     */
    private function handleSettingsPost(Request $request, Config $config, array $allModelIds, array $modelsByProvider): ?Response
    {
        if ($response = $this->validateCsrfOrRedirect()) {
            return $response;
        }
        $provider = $this->resolveProviderValue($request);
        if ($response = $this->validateProviderOrRedirect($provider)) {
            return $response;
        }
        $model = $this->resolveModelValue($request);
        if ($response = $this->validateModelOrRedirect($model, $provider, $config, $allModelIds, $modelsByProvider)) {
            return $response;
        }
        if ($response = $this->validateMaxTokensOrRedirect($request)) {
            return $response;
        }

        return $this->persistSettingsAndRedirect($request, $config, $provider, $model);
    }

    /**
     * 検証済みの設定を保存し、成功リダイレクトを返す。
     */
    private function persistSettingsAndRedirect(Request $request, Config $config, string $provider, string $model): Response
    {
        $this->applySettingsFromRequest($request, $config, $provider, $model);
        $this->entityManager->flush();
        $this->addSuccess('設定を保存しました。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_settings');
    }

    /**
     * CSRF トークンを検証し、失敗時はリダイレクトを返す。
     */
    private function validateCsrfOrRedirect(): ?Response
    {
        try {
            $this->isTokenValid();
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->addError('CSRFトークンが無効です。', 'admin');

            return $this->redirectToRoute('admin_ai_chat_assistant_settings');
        }

        return null;
    }

    /**
     * プロバイダを検証し、不正ならリダイレクトを返す。
     */
    private function validateProviderOrRedirect(string $provider): ?Response
    {
        if ($this->isValidProvider($provider)) {
            return null;
        }

        $this->addError('不正なプロバイダです。', 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_settings');
    }

    /**
     * モデルを検証し、不正ならリダイレクトを返す。
     */
    private function validateModelOrRedirect(string $model, string $provider, Config $config, array $allModelIds, array $modelsByProvider): ?Response
    {
        $error = $this->validateModelValue($model, $provider, $config, $allModelIds, $modelsByProvider);
        if ($error === null) {
            return null;
        }

        $this->addError($error, 'admin');

        return $this->redirectToRoute('admin_ai_chat_assistant_settings');
    }

    /**
     * max_tokens を検証し、不正ならリダイレクトを返す。
     */
    private function validateMaxTokensOrRedirect(Request $request): ?Response
    {
        $raw = $request->request->get('max_tokens', null);
        // 未送信や空文字は既存値を維持するため検証不要
        if ($raw === null || $raw === '') {
            return null;
        }
        $maxTokens = (int) $raw;
        if ($maxTokens < 256 || $maxTokens > 128000) {
            $this->addError('最大トークン数は256〜128000の範囲で入力してください。', 'admin');
            return $this->redirectToRoute('admin_ai_chat_assistant_settings');
        }

        return null;
    }

    /**
     * リクエストから provider 値を解決する（trim）。
     */
    private function resolveProviderValue(Request $request): string
    {
        return trim((string) $request->request->get('provider', 'openai'));
    }

    /**
     * プロバイダが許可リストに含まれるか検証する。
     * provider whitelist による検証。
     */
    private function isValidProvider(string $provider): bool
    {
        return in_array($provider, self::ALLOWED_PROVIDERS, true);
    }

    /**
     * リクエストからモデル値を解決する（trim）。
     *
     * allModelIds はバリデーションで使用するため、ここでは trim のみ行う。
     */
    private function resolveModelValue(Request $request): string
    {
        $model = trim((string) $request->request->get('model', 'gpt-4o'));
        if ($model === '') {
            return 'gpt-4o';
        }

        return $model;
    }

    /**
     * モデル値を検証する。
     *
     * - 文字数 128 以下（Entity length 128）
     * - allModelIds に存在するか、ただし現在 DB の config.getModel() と同一の旧モデルは許容
     * - provider/model の組み合わせが有効か（isValidModel 相当）
     *
     * @return string|null エラー時はメッセージ、正常時は null
     */
    private function validateModelValue(string $model, string $provider, Config $config, array $allModelIds, array $modelsByProvider): ?string
    {
        if (mb_strlen($model) > self::MODEL_MAX_LENGTH) {
            return 'モデル名が長すぎます。';
        }

        if ($this->isUnknownNewModel($model, $config, $allModelIds)) {
            return sprintf('モデル「%s」は利用できません。', $model);
        }

        if (!$this->isValidModel($provider, $model, $config, $modelsByProvider)) {
            return sprintf('モデル「%s」はプロバイダ「%s」で利用できません。', $model, $provider);
        }

        return null;
    }

    /**
     * 新規に存在しないモデルかを判定する。
     *
     * 現在の config.getModel() と同一なら旧モデルとして許容する。
     */
    private function isUnknownNewModel(string $model, Config $config, array $allModelIds): bool
    {
        if ($model === $config->getModel()) {
            return false;
        }

        return !in_array($model, $allModelIds, true);
    }

    /**
     * provider と model の組み合わせが有効か検証する。
     *
     * modelsByProvider[provider] の中に model が含まれるかで判定。
     * 現在の config と同一の旧モデルは後方互換として許容する。
     */
    private function isValidModel(string $provider, string $model, Config $config, array $modelsByProvider): bool
    {
        if ($model === $config->getModel()) {
            return true;
        }

        $providerModels = $modelsByProvider[$provider]['models'] ?? [];
        $providerModelIds = array_column($providerModels, 'id');

        return in_array($model, $providerModelIds, true);
    }

    /**
     * リクエストから基本設定を Config に反映する。
     *
     * max_tokens は DB(Config) で管理し、管理画面から編集可能。
     * provider/model は事前にバリデーション済みの値を用いる。
     */
    private function applySettingsFromRequest(Request $request, Config $config, string $provider, string $model): void
    {
        $config->setIsEnabled((int) $request->request->get('is_enabled', '0'));
        $config->setProvider($provider);
        $config->setModel($model);
        $rawMaxTokens = $request->request->get('max_tokens', null);
        if ($rawMaxTokens !== null && $rawMaxTokens !== '') {
            $config->setMaxTokens((int) $rawMaxTokens);
        }
        $config->setResponseMode((string) $request->request->get('response_mode', 'hybrid'));
        $this->applyApiKeysFromRequest($request, $config);
        $this->applySystemPromptFromRequest($request, $config);
    }

    /**
     * API キーをリクエストから反映する（空文字は上書きしない、保存時に暗号化）。
     */
    private function applyApiKeysFromRequest(Request $request, Config $config): void
    {
        $openai = (string) $request->request->get('api_key_openai', '');
        if ($openai !== '') {
            $config->setApiKeyOpenai($this->encryptApiKey($openai));
        }

        $anthropic = (string) $request->request->get('api_key_anthropic', '');
        if ($anthropic !== '') {
            $config->setApiKeyAnthropic($this->encryptApiKey($anthropic));
        }

        $gemini = (string) $request->request->get('api_key_gemini', '');
        if ($gemini !== '') {
            $config->setApiKeyGemini($this->encryptApiKey($gemini));
        }
    }

    private function encryptApiKey(string $plain): string
    {
        if ($this->apiKeyEncryptor === null) {
            return $plain;
        }

        return $this->apiKeyEncryptor->encrypt($plain);
    }

    /**
     * システムプロンプトをリクエストから反映する（空文字は上書きしない）。
     */
    private function applySystemPromptFromRequest(Request $request, Config $config): void
    {
        $prompt = (string) $request->request->get('system_prompt', '');
        if ($prompt !== '') {
            $config->setSystemPrompt($prompt);
        }
    }

    /**
     * ai_models.json を読み込み、プロバイダ別のモデル定義を返す。
     *
     * AiModelRegistry を優先して再利用し、二重実装を解消。
     * フォールバックとして従来の file_get_contents 経由も維持（Registry 未注入やテスト時の後方互換）。
     *
     * @return array<string, array{name: string, api_base: string, models: array<int, array{id: string, name: string, description: string, supports_tools: bool, cost_tier: string, is_default: bool}>}>
     */
    private function loadAiModels(): array
    {
        // 優先: AiModelRegistry 経由（単一ソース、バリデーション済み）
        if ($this->aiModelRegistry !== null) {
            try {
                $all = $this->aiModelRegistry->getAll();
                if (isset($all['providers']) && is_array($all['providers'])) {
                    return $all['providers'];
                }
            } catch (\Throwable $e) {
                // フォールバックへ
            }
        }

        $path = $this->resolveAiModelsPath();
        if ($path === null) {
            return [];
        }

        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        return $this->readProvidersFromJson($path);
    }

    /**
     * ai_models.json の絶対パスを解決する。
     *
     * getParameter はテスト時にモックされるため失敗時は null を返す。
     */
    private function resolveAiModelsPath(): ?string
    {
        try {
            $projectDir = $this->getParameter('kernel.project_dir');
        } catch (\Throwable $e) {
            return null;
        }

        $candidates = [
            $projectDir . '/app/Plugin/AiChatAssistant42/Resource/config/ai_models.json',
            $projectDir . '/Resource/config/ai_models.json',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * JSON ファイルから providers 配列を読み込む。
     */
    private function readProvidersFromJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded['providers'] ?? [];
    }

    /**
     * モデル定義から全モデル ID の平坦配列を抽出する。
     *
     * @param array<string, array{models?: array}> $modelsByProvider
     *
     * @return string[]
     */
    private function extractAllModelIds(array $modelsByProvider): array
    {
        $ids = [];
        foreach ($modelsByProvider as $provider) {
            foreach ($provider['models'] ?? [] as $model) {
                if (isset($model['id'])) {
                    $ids[] = $model['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * 暗号化された API キーを復号してマスク表示用に整形する.
     */
    private function getMaskedApiKey(?string $encrypted): string
    {
        if ($encrypted === null || $encrypted === '') {
            return '';
        }

        $plain = $this->apiKeyEncryptor?->decrypt($encrypted) ?? $encrypted;
        if ($plain === '') {
            $plain = $encrypted;
        }

        $len = strlen($plain);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($plain, 0, 7) . '...' . substr($plain, -4);
    }
}
