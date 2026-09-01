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
use Plugin\AiChatAssistant42\Repository\ConfigRepository;
use Plugin\AiChatAssistant42\Repository\ProductRepository;
use Plugin\AiChatAssistant42\Service\AiAgentFactory;
use Plugin\AiChatAssistant42\Service\AiModelRegistry;
use Plugin\AiChatAssistant42\Service\ChatFlowService;
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
        private LoggerInterface $logger,
    ) {
        $this->entityManager = $entityManager;
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

        return [
            'message' => (string) $data['message'],
            'session_id' => !empty($data['session_id']) ? (string) $data['session_id'] : $this->generateSessionId(),
        ];
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

        $conn = $this->entityManager->getConnection();
        $since = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');

        // セッション単位の制限: session:{sessionId}（MySQL/SQLite両対応のためバインドパラメータで日時を渡す）
        $recentCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM plg_ai_chat_assistant_log WHERE session_id = :sid AND created_at > :since',
            ['sid' => $sessionId, 'since' => $since]
        );

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
                $ipCount = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM plg_ai_chat_assistant_log WHERE client_ip = :ip AND created_at > :since',
                    ['ip' => $clientIp, 'since' => $since]
                );
                $ipLimit = $rateLimit * 2;
                if ($ipCount >= $ipLimit) {
                    return $this->json([
                        'success' => false,
                        'error' => 'リクエスト数が多すぎます。しばらくお待ちください。（ip）',
                    ], 429);
                }
            } catch (\Throwable $e) {
                // カラム未作成の旧環境では IP制限をスキップ（セッション制限のみ有効）
                $this->logger->warning('IP rate limit skipped: ' . $e->getMessage());
            }
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
            $this->logChatError($sessionId, $config, $userMessage, $e->getMessage(), $responseTimeMs);

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
        $this->chatLogger->log([
            'session_id' => $sessionId,
            'provider' => $config->getProvider(),
            'model' => $config->getModel(),
            'user_message' => $userMessage,
            'assistant_reply' => '',
            'response_time_ms' => $responseTimeMs,
            'error_message' => $errorMessage,
        ]);
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
        $sessionId = $data['session_id'] ?? '';
        $email = $data['email'] ?? '';

        if (empty($sessionId) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['success' => false, 'error' => 'リクエストが不正です。'], 400);
        }

        // セッションの最新ログにメールアドレスを記録
        $conn = $this->entityManager->getConnection();
        $affected = $conn->executeStatement(
            'UPDATE plg_ai_chat_assistant_log
             SET email_reply_address = :email
             WHERE id = (
                 SELECT id FROM (
                     SELECT id FROM plg_ai_chat_assistant_log
                     WHERE session_id = :sid
                       AND email_reply_address IS NULL
                     ORDER BY created_at DESC
                     LIMIT 1
                 ) AS tmp
             )',
            ['email' => $email, 'sid' => $sessionId]
        );

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

        $sessionId = trim((string) ($data['session_id'] ?? ''));
        $feedbackValue = trim((string) ($data['feedback'] ?? ''));

        if ($sessionId === '' || $feedbackValue === '') {
            return new JsonResponse(['success' => false, 'error' => 'リクエストが不正です。'], 400);
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
                        $this->entityManager->getConnection()->executeStatement(
                            'UPDATE plg_ai_chat_assistant_log SET is_resolved = 1 WHERE session_id = :sid AND is_resolved = 0',
                            ['sid' => $sessionId]
                        );
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

        return new JsonResponse([
            'success' => true,
            'message' => 'フィードバックありがとうございます。',
        ]);
    }

    /**
     * 設定とプロバイダに応じた API キーを返す。
     */
    private function resolveApiKey(\Plugin\AiChatAssistant42\Entity\Config $config): ?string
    {
        return match ($config->getProvider()) {
            'openai' => $config->getApiKeyOpenai(),
            'anthropic' => $config->getApiKeyAnthropic(),
            'gemini' => $config->getApiKeyGemini(),
            default => null,
        };
    }

    /**
     * チャットセッション ID を生成する。
     *
     * フロントエンドからのリクエストごとに一意のセッション ID を付与し、
     * 同じ会話のログをグループ化できるようにする。
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
