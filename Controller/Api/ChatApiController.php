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

namespace Plugin\AiChatAssistant42\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Controller\AbstractController;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Plugin\AiChatAssistant42\Entity\Feedback;
use Plugin\AiChatAssistant42\Repository\ChatLogRepository;
use Plugin\AiChatAssistant42\Repository\ConfigRepository;
use Plugin\AiChatAssistant42\Repository\ProductRepository;
use Plugin\AiChatAssistant42\Service\AiAgentFactory;
use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use Plugin\AiChatAssistant42\Service\ChatFlowService;
use Plugin\AiChatAssistant42\Service\ApiKeyEncryptor;
use Plugin\AiChatAssistant42\Service\ChatLogger;
use Plugin\AiChatAssistant42\Service\EmailReplyService;
use Plugin\AiChatAssistant42\Service\NotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * AI チャットアシスタントのチャット API。
 *
 * ユーザーからのメッセージを受け取り、選択された AI プロバイダに
 * ツール呼び出しループを含めて委譲し、応答を返す。
 */
class ChatApiController extends AbstractController
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const MAX_EMAIL_REPLY_PER_HOUR = 10;

    public function __construct(
        private AiAgentFactory $aiAgentFactory,
        private ProductRepository $productRepository,
        private ConfigRepository $configRepository,
        private ChatLogger $chatLogger,
        private AiModelRegistry $aiModelRegistry,
        EntityManagerInterface $entityManager,
        private ChatFlowService $chatFlowService,
        private EmailReplyService $emailReplyService,
        private NotificationService $notificationService,
        private ApiKeyEncryptor $apiKeyEncryptor,
        private LoggerInterface $logger,
        private ?ChatLogRepository $chatLogRepository = null,
    ) {
        $this->entityManager = $entityManager;
    }

    private function getChatLogRepository(): ChatLogRepository
    {
        if ($this->chatLogRepository !== null) {
            return $this->chatLogRepository;
        }
        /** @var ChatLogRepository $repo */
        $repo = $this->entityManager->getRepository(\Plugin\AiChatAssistant42\Entity\ChatLog::class);

        return $repo;
    }

    /**
     * チャットメッセージを処理し、AI 応答を返す。
     *
     * リクエストボディ (JSON):
     *   - message: string (必須) — ユーザー入力メッセージ
     *   - session_id: string (オプション) — クライアント生成のセッション ID
     *
     * @return JsonResponse
     */
    public function chat(Request $request): JsonResponse
    {
        // 1. リクエストの検証とパース
        $parsed = $this->parseChatRequest($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }

        $userMessage = $parsed['message'];
        $sessionId = $parsed['session_id'];

        // 2. 設定を取得し、有効かチェック
        $config = $this->configRepository->get();
        if ($config === null || !$config->getIsEnabled()) {
            return $this->json([
                'success' => false,
                'error' => 'AI チャットアシスタントが無効です。',
            ], 403);
        }

        // 3. セッション単位のレート制限をチェック（Requestから正しくIP取得するため $request を渡す）
        $rateLimitResponse = $this->enforceRateLimit($request, $sessionId, $config->getRateLimitPerMinute());
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        // 4. アクセス制限チェック（ユーザー入力も渡して block_keyword 判定）
        $accessCheck = $this->chatFlowService->checkAccessRules($sessionId, $userMessage);
        if (!$accessCheck['allowed']) {
            return $this->json([
                'success' => false,
                'error' => $accessCheck['reason'] ?? 'アクセスが制限されています。',
            ], 403);
        }

        // 5. シナリオマッチング
        $scenarioMatch = $this->chatFlowService->matchScenario($userMessage);
        if ($scenarioMatch['matched']) {
            $this->chatLogger->log([
                'session_id' => $sessionId,
                'provider' => $config->getProvider(),
                'model' => $config->getModel(),
                'user_message' => $userMessage,
                'assistant_reply' => $scenarioMatch['response'],
                'tools_used' => [],
                'response_time_ms' => 0,
            ]);

            return $this->json([
                'success' => true,
                'reply' => $scenarioMatch['response'],
                'tools_used' => [],
            ]);
        }

        // 6. プロバイダに応じた API キーを選択
        $apiKey = $this->resolveApiKey($config);
        if ($apiKey === null) {
            return $this->json([
                'success' => false,
                'error' => sprintf('%s の API キーが設定されていません。', $config->getProvider()),
            ], 400);
        }

        // 6.5. モデルの有効性を検証
        if (!$this->aiModelRegistry->isValidModel($config->getProvider(), $config->getModel())) {
            return $this->json([
                'success' => false,
                'error' => sprintf(
                    '指定されたモデル「%s」はプロバイダ「%s」で利用できません。利用可能なモデルを確認してください。',
                    $config->getModel(),
                    $config->getProvider()
                ),
            ], 400);
        }

        // 7. チャットを実行して結果を返す（ナレッジをシステムプロンプトに統合）
        return $this->executeChatSession($userMessage, $sessionId, $config, $apiKey);
    }

    /**
     * チャットリクエストを解析し、バリデーションを行う。
     *
     * @return array{message: string, session_id: string}|JsonResponse
     *         成功時は配列、バリデーション失敗時は JsonResponse
     */
    private function parseChatRequest(Request $request): array|JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['message'])) {
            return $this->json([
                'success' => false,
                'error' => 'message フィールドは必須です。',
            ], 400);
        }

        $message = (string) $data['message'];
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return $this->json([
                'success' => false,
                'error' => sprintf('message は %d 文字以内で入力してください。', self::MAX_MESSAGE_LENGTH),
            ], 400);
        }

        return [
            'message' => $message,
            'session_id' => $this->normalizeSessionId($data['session_id'] ?? null),
        ];
    }

    /**
     * session_id を正規化する（レート制限回避の防止）。
     *
     * クライアント生成の session_id は UUID v4 形式（36文字、ハイフン含む）のみ許容する。
     * 空または不正な形式の場合は 400 を返さず、サーバー側で新規 UUID を自動生成して返す。
     * これにより Math.random() 等で毎回異なる ID を送る攻撃を抑止しつつ、フロントの互換性を保つ。
     */
    private function normalizeSessionId(mixed $raw): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return $this->generateSessionId();
        }

        $candidate = trim($raw);

        // UUID v4 形式（例: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx）を許容
        // 厳密な v4 バリデーション: 8-4-4-4-12 かつ version=4, variant=8/9/a/b
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $candidate) === 1) {
            return strtolower($candidate);
        }

        // 後方互換: ハイフンなし 32 hex も UUID として扱い、ハイフン付きに正規化して許容
        if (preg_match('/^[0-9a-f]{32}$/i', $candidate) === 1) {
            $hex = strtolower($candidate);
            return sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12)
            );
        }

        // 汎用 UUID 形式（36文字、hex とハイフンのみ）も許容 — loose check for legacy
        if (preg_match('/^[0-9a-f\-]{36}$/i', $candidate) === 1 && substr_count($candidate, '-') === 4) {
            // 位置が正しいか再チェック（8-4-4-4-12）
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        // 不正形式 → 警告を残しつつ新規 UUID を発行（Medium: 所有権検証はログのみ）
        $this->logger->info('Invalid session_id format, generated new UUID', [
            'provided' => substr($candidate, 0, 32),
        ]);

        return $this->generateSessionId();
    }

    /**
     * レート制限をチェックする（セッション単位 + IP 単位を分離）。
     * IP取得は Request::getClientIp()（trusted_proxies考慮）を使用する。
     *
     * @return JsonResponse|null 制限超過時は JsonResponse、それ以外は null
     */
    private function enforceRateLimit(Request $request, string $sessionId, int $rateLimit): ?JsonResponse
    {
        if ($rateLimit <= 0) {
            return null;
        }

        $since = new \DateTimeImmutable('-1 minute');

        // セッション単位の制限: session:{sessionId} — Doctrine QueryBuilder 経由で集計
        $recentCount = $this->getChatLogRepository()->countRecentBySession($sessionId, $since);

        if ($recentCount >= $rateLimit) {
            return $this->json([
                'success' => false,
                'error' => 'リクエスト数が多すぎます。しばらくお待ちください。（session）',
            ], 429);
        }

        // IP 単位の制限: ip:{clientIp}（trusted_proxies考慮、client_ip カラムが存在しない環境ではフォールバック）
        $clientIp = $request->getClientIp() ?? '';
        if ($clientIp !== '') {
            try {
                $ipCount = $this->getChatLogRepository()->countRecentByIp($clientIp, $since);
                $ipLimit = $rateLimit * 2;
                if ($ipCount >= $ipLimit) {
                    return $this->json([
                        'success' => false,
                        'error' => 'リクエスト数が多すぎます。しばらくお待ちください。（ip）',
                    ], 429);
                }
            } catch (\Throwable $e) {
                // カラム未作成の旧環境では IP制限をスキップ（セッション制限のみ有効）
                $this->logger->warning('IP rate limit skipped: ' . $this->redactedMessage($e->getMessage()));
            }
        }

        return null;
    }

    /**
     * メール返信依頼のレート制限（session + IP、1時間あたり上限）。
     *
     * IP カラム欠落時は warning を残し session 制限のみで継続する。
     * メール爆弾防止のため chat とは独立した 1時間窓でカウントする。
     */
    private function enforceEmailReplyRateLimit(Request $request, string $sessionId): ?JsonResponse
    {
        $limit = self::MAX_EMAIL_REPLY_PER_HOUR;
        $since = new \DateTimeImmutable('-1 hour');

        // session 単位: 同一 session からの email 依頼回数（email_reply_address が設定された行を数える）
        try {
            $sessionCount = $this->getChatLogRepository()->countEmailReplyBySession($sessionId, $since);
            if ($sessionCount >= $limit) {
                return $this->json([
                    'success' => false,
                    'error' => 'リクエスト数が多すぎます。しばらくお待ちください。',
                ], 429);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Email session rate limit check failed: ' . $this->redactedMessage($e->getMessage()));
        }

        // IP 単位
        $clientIp = $request->getClientIp() ?? '';
        if ($clientIp !== '') {
            try {
                $ipCount = $this->getChatLogRepository()->countEmailReplyByIp($clientIp, $since);
                if ($ipCount >= $limit) {
                    return $this->json([
                        'success' => false,
                        'error' => 'リクエスト数が多すぎます。しばらくお待ちください。',
                    ], 429);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Email IP rate limit skipped: ' . $this->redactedMessage($e->getMessage()));
            }
        } else {
            $this->logger->info('Email rate limit: client_ip empty, IP check skipped', ['session_id' => $sessionId]);
        }

        return null;
    }

    /**
     * チャットセッションを実行し、結果をログ記録して返す。
     */
    private function executeChatSession(
        string $userMessage,
        string $sessionId,
        \Plugin\AiChatAssistant42\Entity\Config $config,
        string $apiKey
    ): JsonResponse {
        $startTime = microtime(true);

        try {
            // buildSystemPrompt 内の DB例外も捕捉するため try 内で生成する
            $systemPrompt = $this->chatFlowService->buildSystemPrompt($config);
            $agent = $this->aiAgentFactory->create(
                $config->getProvider(),
                $apiKey,
                $config->getModel(),
                $config->getMaxTokens(),
                $systemPrompt,
            );

            // 同じセッションの過去の会話を取得し、AI に渡す
            $history = $this->chatLogger->fetchSessionHistory($sessionId);

            $tools = $this->productRepository->getToolDefinitions();
            $toolExecutor = fn (string $name, array $args): array => $this->productRepository->executeTool($name, $args);

            $result = $agent->chat($userMessage, $tools, $toolExecutor, $history);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logChatSuccess($sessionId, $config, $userMessage, $result, $responseTimeMs);

            return $this->json([
                'success' => true,
                'reply' => $result['reply'],
                'tools_used' => $result['tools_used'],
            ]);
        } catch (\Throwable $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $safeMessage = $this->redactedMessage($e->getMessage());
            $this->logger->warning('Chat execution failed', ['error' => $safeMessage, 'session_id' => $sessionId]);
            $this->logChatError($sessionId, $config, $userMessage, $safeMessage, $responseTimeMs);

            return $this->json([
                'success' => false,
                'error' => 'AI 応答の生成中にエラーが発生しました。',
            ], 500);
        }
    }

    /**
     * チャット成功時のログを記録する。
     */
    private function logChatSuccess(
        string $sessionId,
        \Plugin\AiChatAssistant42\Entity\Config $config,
        string $userMessage,
        array $result,
        int $responseTimeMs
    ): void {
        $this->chatLogger->log([
            'session_id' => $sessionId,
            'provider' => $config->getProvider(),
            'model' => $config->getModel(),
            'user_message' => $userMessage,
            'assistant_reply' => $result['reply'],
            'tools_used' => $result['tools_used'],
            'response_time_ms' => $responseTimeMs,
            'token_input' => $result['token_input'] ?? null,
            'token_output' => $result['token_output'] ?? null,
        ]);
    }

    /**
     * チャットエラー時のログを記録する。
     */
    private function logChatError(
        string $sessionId,
        \Plugin\AiChatAssistant42\Entity\Config $config,
        string $userMessage,
        string $errorMessage,
        int $responseTimeMs
    ): void {
        $safeErrorMessage = $this->redactedMessage($errorMessage);
        $this->chatLogger->log([
            'session_id' => $sessionId,
            'provider' => $config->getProvider(),
            'model' => $config->getModel(),
            'user_message' => $userMessage,
            'assistant_reply' => '',
            'response_time_ms' => $responseTimeMs,
            'error_message' => $safeErrorMessage,
        ]);
    }

    /**
     * エラーメッセージから機微情報（APIキー等）を除去する。
     *
     * URL クエリの key / api_key やヘッダー値が例外メッセージに含まれる場合、
     * DB（ChatLog.error_message）やログへの漏洩を防ぐため [REDACTED] に置換する。
     */
    private function redactedMessage(string $message): string
    {
        $redacted = preg_replace('/((?:api[_-]?key|key)\s*=\s*)[^&\s"\']+/i', '$1[REDACTED]', $message);
        if ($redacted !== null) {
            $message = $redacted;
        }
        $redacted = preg_replace('/(x-goog-api-key\s*[:=]\s*)[^\s"\']+/i', '$1[REDACTED]', $message);
        if ($redacted !== null) {
            $message = $redacted;
        }
        // Bearer トークン等のヘッダー値も念のため除去
        $redacted = preg_replace('/(Bearer\s+)[^\s"\']+/i', '$1[REDACTED]', $message);
        return $redacted !== null ? $redacted : $message;
    }

    /**
     * メール返信依頼を処理する。
     *
     * ユーザーがチャットで問題を解決できなかった場合、メールアドレスを
     * 入力して後からの連絡を依頼できる。アドレスを DB に保存し、
     * 管理者向け通知を発行する。
     *
     * リクエストボディ (JSON):
     *   - session_id: string (必須) — チャットセッション ID
     *   - email: string (必須) — 返信先メールアドレス
     */
    public function emailReplyRequest(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $rawSessionId = $data['session_id'] ?? '';
        $email = $data['email'] ?? '';

        if (empty($rawSessionId) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['success' => false, 'error' => 'リクエストが不正です。'], 400);
        }

        // M1: session_id 形式検証 — 不正形式はログを残しつつ正規化（client が Math.random 等で生成した不正値の無害化）
        $sessionId = $this->normalizeSessionId($rawSessionId);
        if ($sessionId !== (string) $rawSessionId) {
            $this->logger->info('emailReplyRequest: session_id normalized', [
                'provided' => substr((string) $rawSessionId, 0, 32),
                'normalized' => $sessionId,
            ]);
        }

        // メール爆弾防止: IP + session でレート制限（1時間10回）
        $rateLimitResponse = $this->enforceEmailReplyRateLimit($request, $sessionId);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
        }

        // セッションの最新ログにメールアドレスを記録 — リポジトリに委譲（QueryBuilder）
        $affected = $this->getChatLogRepository()->updateEmailReplyAddress($sessionId, $email);

        if ($affected === 0) {
            return new JsonResponse([
                'success' => false,
                'error' => '対象のチャットログが見つかりませんでした。',
            ], 404);
        }

        // メール送信は EmailReplyService に一本化（NotificationService の email チャネルは廃止し二重送信を防止）
        // 将来 webhook/line は NotificationService、email_reply_request は EmailReplyService と責務を分離
        // 運用メモ: 既存 plg_ai_chat_assistant_notification WHERE event='email_reply_request' AND notification_type='email' は手動 DELETE でクリーンアップ
        try {
            $this->emailReplyService->sendBoth($sessionId, $email);
        } catch (TransportExceptionInterface|\InvalidArgumentException $e) {
            // MAILER_DSN=null://null（現行開発）でも 500 にしないため warning に留める
            // catch(\Throwable) でプログラミングエラーを握りつぶさない
            $this->logger->warning('メール返信依頼の送信に失敗しました', [
                'session_id' => $sessionId,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        // LINE / Webhook は NotificationService 経由で通知（email は EmailReplyService に一本化済みのため除外）
        try {
            $this->notificationService->checkAndSend('email_reply_request', [
                'session_id' => $sessionId,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('LINE/Webhook 通知の送信に失敗しました', [
                'session_id' => $sessionId,
                'event' => 'email_reply_request',
                'error' => $e->getMessage(),
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'メールアドレスを記録しました。後ほどご連絡いたします。',
        ]);
    }

    /**
     * ポジティブ / ネガティブ フィードバックを記録する。
     *
     * リクエストボディ (JSON):
     *   - session_id: string (必須)
     *   - feedback: string (必須) — "positive" | "negative"
     */
    public function feedback(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'error' => 'リクエストが不正です。'], 400);
        }

        $rawSessionId = trim((string) ($data['session_id'] ?? ''));
        $feedbackValue = trim((string) ($data['feedback'] ?? ''));

        if ($rawSessionId === '' || $feedbackValue === '') {
            return new JsonResponse(['success' => false, 'error' => 'リクエストが不正です。'], 400);
        }

        // M1: session_id 形式検証
        $sessionId = $this->normalizeSessionId($rawSessionId);
        if ($sessionId !== $rawSessionId) {
            $this->logger->info('feedback: session_id normalized', [
                'provided' => substr($rawSessionId, 0, 32),
                'normalized' => $sessionId,
            ]);
        }

        if (!in_array($feedbackValue, ['positive', 'negative'], true)) {
            return new JsonResponse(['success' => false, 'error' => 'feedback は positive または negative を指定してください。'], 400);
        }

        // 同一セッションの重複投稿は 409 で拒否
        $existing = $this->entityManager->getRepository(Feedback::class)->findOneBy(['session_id' => $sessionId]);
        if ($existing !== null) {
            return new JsonResponse(['success' => false, 'error' => '既にフィードバック済みです。'], 409);
        }

        $feedback = new Feedback();
        $feedback->setSessionId($sessionId);
        $feedback->setFeedback($feedbackValue);
        $feedback->setCreatedAt(new \DateTimeImmutable());

        try {
            $this->entityManager->wrapInTransaction(function () use ($feedback, $feedbackValue, $sessionId): void {
                $this->entityManager->persist($feedback);
                $this->entityManager->flush();

                // ポジティブフィードバック時は同一セッションの ChatLog を解決済みに更新する
                // negative は更新しない。同一セッションの複数行を一括更新し既に解決済みは冪等にスキップ
                // UPDATE 失敗は warning に留め feedback 保存は維持（同一トランザクション内で catch し rethrow しない）
                if ($feedbackValue === 'positive') {
                    try {
                        $this->getChatLogRepository()->markResolvedBySession($sessionId);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to mark chat log as resolved on positive feedback', [
                            'session_id' => $sessionId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            return new JsonResponse(['success' => false, 'error' => '既にフィードバック済みです。'], 409);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => 'フィードバックの保存中にエラーが発生しました。'], 500);
        }

        // 低評価（negative）はメール依頼の有無に関わらず、全チャネル（email/webhook/line）で通知
        if ($feedbackValue === 'negative') {
            try {
                $this->notificationService->checkAndSend('low_satisfaction', [
                    'session_id' => $sessionId,
                    'feedback' => $feedbackValue,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('低満足度通知の送信に失敗しました', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'フィードバックありがとうございます。',
        ]);
    }

    /**
     * 設定とプロバイダに応じた API キーを復号して返す。
     *
     * 復号失敗（APP_SECRET 変更等）を検出した場合は警告ログを残し、
     * 暗号文をそのままプロバイダへ送らないよう null を返す。
     */
    private function resolveApiKey(\Plugin\AiChatAssistant42\Entity\Config $config): ?string
    {
        $encrypted = match ($config->getProvider()) {
            'openai' => $config->getApiKeyOpenai(),
            'anthropic' => $config->getApiKeyAnthropic(),
            'gemini' => $config->getApiKeyGemini(),
            default => null,
        };

        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        $plain = $this->apiKeyEncryptor->decrypt($encrypted);

        // M2: 復号失敗の検出 — isEncrypted で暗号文と判定されるのに decrypt 結果が変わらない場合
        if ($this->apiKeyEncryptor->isEncrypted($encrypted) && $plain === $encrypted) {
            $this->logger->warning('APIキー復号に失敗しました。APP_SECRET 変更の可能性があります。再登録してください。', [
                'provider' => $config->getProvider(),
            ]);
            // 暗号文をキーとして送信しない
            return null;
        }

        return $plain !== '' ? $plain : null;
    }

    /**
     * チャットセッション ID を生成する（UUID v4）。
     *
     * フロントエンドからのリクエストごとに一意のセッション ID を付与し、
     * 同じ会話のログをグループ化できるようにする。
     * random_bytes から version/variant ビットを立てて UUID v4 を生成する。
     */
    private function generateSessionId(): string
    {
        $bytes = random_bytes(16);
        // version 4
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // variant 10xx
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
