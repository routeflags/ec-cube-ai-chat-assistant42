<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Service;

use Doctrine\ORM\EntityManagerInterface;
use Plugin\AiChatAssistant42\Entity\Config;
use Psr\Log\LoggerInterface;

/**
 * チャットフローをオーケストレーションするサービス。
 *
 * アクセス制限チェック → シナリオマッチ → ナレッジ注入 → AI 呼び出し を一連で行う。
 */
class ChatFlowService
{
    private const KNOWLEDGE_CONTEXT_MAX = 4000;
    private const SNIPPET_MAX = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?TwigPlainTextExtractor $textExtractor = null,
        private ?LoggerInterface $logger = null,
        private ?ShopContextService $shopContextService = null,
        private ?\Symfony\Component\HttpFoundation\RequestStack $requestStack = null,
    ) {
        $this->textExtractor ??= new TwigPlainTextExtractor();
    }

    /**
     * アクセス制限をチェックし、許可されているか判定する。
     *
     * @return array{allowed: bool, reason?: string}
     */
    public function checkAccessRules(string $sessionId, string $userMessage = ''): array
    {
        $conn = $this->entityManager->getConnection();

        // アクティブなルールを取得
        $rules = $conn->fetchAllAssociative(
            'SELECT rule_type, rule_value FROM plg_ai_chat_assistant_access_rule WHERE is_active = 1'
        );

        if (empty($rules)) {
            return ['allowed' => true];
        }

        $clientIp = $this->requestStack?->getCurrentRequest()?->getClientIp() ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        $lowerMessage = mb_strtolower($userMessage);

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule['rule_type'], $rule['rule_value'], $clientIp, $lowerMessage);
            if ($result !== null) {
                return $result;
            }
        }

        return ['allowed' => true];
    }

    /**
     * 単一のルールを評価し、ブロック判定なら配列を返す。
     * 許可なら null を返す。
     */
    private function evaluateRule(string $type, string $value, string $clientIp, string $lowerMessage): ?array
    {
        return match ($type) {
            'ip' => $this->evaluateIpRule($value, $clientIp),
            'time' => $this->evaluateTimeRule($value),
            'block_keyword' => $this->evaluateKeywordRule($value, $lowerMessage),
            default => null,
        };
    }

    private function evaluateIpRule(string $pattern, string $clientIp): ?array
    {
        if ($clientIp === '') {
            return null;
        }

        $escaped = preg_quote($pattern, '/');
        $regex = '/^' . str_replace('\\*', '.*', $escaped) . '$/';

        if (preg_match($regex, $clientIp)) {
            return ['allowed' => false, 'reason' => 'IP アドレスがブロックされています。'];
        }

        return null;
    }

    private function evaluateTimeRule(string $value): ?array
    {
        if ($this->isTimeBlocked($value)) {
            return ['allowed' => false, 'reason' => 'サービス提供時間外です。'];
        }

        return null;
    }

    private function evaluateKeywordRule(string $value, string $lowerMessage): ?array
    {
        if ($lowerMessage === '') {
            return null;
        }

        $keywords = array_map('trim', explode(',', $value));
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && mb_strpos($lowerMessage, mb_strtolower($keyword)) !== false) {
                return ['allowed' => false, 'reason' => '入力された内容に使用できないキーワードが含まれています。'];
            }
        }

        return null;
    }

    /**
     * 時間帯ブロック判定（日跨ぎ対応）。
     *
     * @param string $ruleValue "HH:MM-HH:MM" 形式（例: "22:00-06:00"）
     */
    private function isTimeBlocked(string $ruleValue): bool
    {
        if (!str_contains($ruleValue, '-')) {
            return false;
        }

        [$startStr, $endStr] = explode('-', $ruleValue, 2);
        $startMinutes = $this->timeToMinutes($startStr);
        $endMinutes = $this->timeToMinutes($endStr);
        $now = (int) date('Gi');
        $currentMinutes = (int) floor($now / 100) * 60 + ($now % 100);

        if ($startMinutes <= $endMinutes) {
            // 同日内 (例: 09:00-18:00)
            return $currentMinutes >= $startMinutes && $currentMinutes <= $endMinutes;
        }

        // 日をまたぐ (例: 22:00-06:00)
        return $currentMinutes >= $startMinutes || $currentMinutes <= $endMinutes;
    }

    /**
     * "HH:MM" 形式を分単位に変換する。
     */
    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', trim($time)));

        return $hour * 60 + $minute;
    }

    /**
     * シナリオをマッチングし、一致したものがあれば返す。
     *
     * @return array{matched: bool, response?: string, response_type?: string}
     */
    public function matchScenario(string $userMessage): array
    {
        $conn = $this->entityManager->getConnection();

        $scenarios = $conn->fetchAllAssociative(
            'SELECT trigger_keyword, trigger_type, response_text, response_type, priority'
            . ' FROM plg_ai_chat_assistant_scenario'
            . ' WHERE is_active = 1'
            . ' ORDER BY priority DESC'
        );

        foreach ($scenarios as $scenario) {
            $matched = false;

            switch ($scenario['trigger_type']) {
                case 'exact':
                    $matched = mb_strtolower($userMessage) === mb_strtolower($scenario['trigger_keyword']);
                    break;
                case 'contains':
                    $matched = mb_strpos(mb_strtolower($userMessage), mb_strtolower($scenario['trigger_keyword'])) !== false;
                    break;
                case 'regex':
                    $matched = @preg_match($scenario['trigger_keyword'], $userMessage) === 1;
                    break;
            }

            if ($matched) {
                return [
                    'matched' => true,
                    'response' => $scenario['response_text'],
                    'response_type' => $scenario['response_type'],
                ];
            }
        }

        return ['matched' => false];
    }

    /**
     * 有効なナレッジを取得し、システムプロンプトに追加する文脈を返す。
     * DB例外時は空文字を返し、チャット全体を停止させない。
     */
    public function buildKnowledgeContext(): string
    {
        try {
            $conn = $this->entityManager->getConnection();

            $knowledge = $conn->fetchAllAssociative(
                'SELECT title, content, category FROM plg_ai_chat_assistant_knowledge'
                . ' WHERE is_active = 1'
                . ' ORDER BY display_order ASC, id ASC'
                . ' LIMIT 50'
            );

            if (empty($knowledge)) {
                return '';
            }

            $context = "\n\n## ナレッジベース（FAQ・商品情報）\n";
            $maxLength = self::KNOWLEDGE_CONTEXT_MAX;
            $currentLength = 0;

            foreach ($knowledge as $item) {
                $category = $item['category'] ? "【{$item['category']}】" : '';
                $content = mb_substr($item['content'], 0, self::SNIPPET_MAX);
                $entry = "- {$category}{$item['title']}: {$content}\n";

                if ($currentLength + mb_strlen($entry) > $maxLength) {
                    break;
                }
                $context .= $entry;
                $currentLength += mb_strlen($entry);
            }

            return $context;
        } catch (\Throwable $e) {
            $this->logger?->warning('buildKnowledgeContext failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 後方互換: ヘルプは汎用化により廃止。DB のナレッジのみを利用する。
     * カスタムヘルプ（app/template/default/Help/*.twig）は参照しない。
     *
     * @deprecated
     */
    public function buildHelpContext(): string
    {
        return '';
    }

    /**
     * 後方互換: ニュース/ガイドは汎用化により廃止。DB のナレッジのみを利用する。
     * dtb_news への依存を除去し、常に空文字を返す。
     *
     * @deprecated
     */
    public function buildGuideNewsContext(): string
    {
        return '';
    }

    /**
     * システムプロンプトにナレッジを統合する。
     *
     * response_mode が 'knowledge_only' の場合、ナレッジにない質問には
     * 「該当情報がございません」と返し、管理者への連絡を促す。
     * ヘルプ・ガイド・ニュースのコンテキストも常時付与する。
     */
    public function buildSystemPrompt(Config $config): string
    {
        $basePrompt = $config->getSystemPrompt() ?? '';
        if (empty($basePrompt)) {
            $basePrompt = 'あなたは親切なアシスタントです。ユーザーの質問に丁寧に回答してください。';
        }

        // 汎用化: ショップのベースURLを ShopContextService から取得（特定ドメイン固定を避ける）
        $shopBaseUrl = $this->shopContextService?->getBaseUrl() ?? '';
        $shopBaseLabel = $shopBaseUrl !== '' ? $shopBaseUrl : '当ショップ';
        // 外部サイト参照禁止ルール（管理者設定に関わらず常に適用）- 汎用プラグイン向けにドメイン固定を除去
        // データソースは DB のナレッジ（plg_ai_chat_assistant_knowledge）と商品DB（ツール経由）のみ。ヘルプ/ニュース/記事は参照しない
        $basePrompt .= "\n\n## 重要なルール\n"
            . "- 外部サイト（ウェブサイト・URL）へのリンクや参照は一切含めないでください。\n"
            . "- 回答は提供される商品情報とナレッジベースのみを根拠にしてください。\n"
            . "- 外部の情報源を引用しないでください。\n"
            . "- 当ショップ（{$shopBaseLabel}）のページを案内する際は、必ず絶対URLで出力してください。相対パスや https://www.example.com は使用しないでください。\n"
            . "- 商品を推薦する際は、商品名を [商品名]({$shopBaseLabel}/products/detail/{id}) の形式でリンク化し、3-5件を箇条書きで提示してください。\n"
            . "- リンクはクリック可能な markdown 形式で出力し、ユーザーがスムーズに商品ページへ遷移できるようにしてください。\n";

        $knowledgeContext = $this->buildKnowledgeContext();
        $responseMode = $config->getResponseMode();

        // 汎用化: ナレッジのみを注入（ヘルプ/ニュース/記事は参照しない）
        $combinedContext = $knowledgeContext;

        if ($combinedContext !== '') {
            if ($responseMode === 'knowledge_only') {
                // 厳格モード: ナレッジにない場合は回答しない
                $combinedContext .= "\n\n上記のナレッジベースのみを根拠に回答してください。"
                    . "該当する情報がない場合は「申し訳ございません。該当する情報がございません。"
                    . "メールにてお問い合わせください。」と回答してください。";
            } else {
                // ハイブリッド: ナレッジを参照しつつ、一般的知識も許可
                $combinedContext .= "\n\n上記のナレッジベースを参照して回答してください。"
                    . "該当する情報がない場合は、一般的な知識で回答してください。";
            }
        } elseif ($responseMode === 'knowledge_only') {
            // コンテキストが空でも knowledge_only モードなら制限を維持
            $combinedContext .= "\n\n現在ナレッジが登録されていません。"
                . "申し訳ございません。該当する情報がございません。メールにてお問い合わせください。";
        }

        return $basePrompt . $combinedContext;
    }
}
