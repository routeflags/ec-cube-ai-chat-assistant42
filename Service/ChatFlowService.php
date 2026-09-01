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
    private const HELP_CONTEXT_MAX = 2000;
    private const GUIDE_NEWS_CONTEXT_MAX = 2000;
    private const KNOWLEDGE_CONTEXT_MAX = 4000;
    private const SNIPPET_MAX = 500;
    private const EXCERPT_MAX = 200;

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
     * ヘルプ（静的ページ）5件を要約してコンテキスト化する。
     *
     * dtb_page から help_guide, help_about, help_agreement, help_tradelaw, help_privacy
     * を取得し、各 content（twig）をプレーンテキスト化して 500文字に切り詰め、
     * 合計 2000文字で制限したコンテキストを返す。
     * help_guide（よくある質問: /help_guide）は
     * ユーザーの疑問解決に直結するため最優先で先頭に配置し、2000文字制限で
     * 切り詰められる場合でも確実に含まれるようにする。
     * DB例外時は空文字を返し、チャット全体を停止させない。
     */
    public function buildHelpContext(): string
    {
        try {
            $conn = $this->entityManager->getConnection();
            // help_guide（よくある質問）は最優先 — 2000文字制限で切り詰められても確実に含まれるよう先頭に配置
            $helpUrls = ['help_guide', 'help_about', 'help_agreement', 'help_tradelaw', 'help_privacy'];

            $placeholders = implode(',', array_fill(0, count($helpUrls), '?'));
            $rows = $conn->executeQuery(
                'SELECT url, page_name, file_name FROM dtb_page WHERE url IN (' . $placeholders . ')',
                $helpUrls
            )->fetchAllAssociative();

            if (empty($rows)) {
                return '';
            }

            $rowMap = [];
            foreach ($rows as $row) {
                $rowMap[$row['url']] = $row;
            }

            $context = "\n\n## ヘルプ（静的ページ）\n";
            $maxTotal = self::HELP_CONTEXT_MAX;
            $currentLength = 0;

            foreach ($helpUrls as $url) {
                if (!isset($rowMap[$url])) {
                    continue;
                }

                $text = $this->resolveHelpText($rowMap[$url]['file_name'] ?? null);
                if ($text === '') {
                    $text = $rowMap[$url]['page_name'] ?? $url;
                }

                // help_guide は FAQ（よくある質問）が後半にあり先頭500文字では欠落するため、
                // 「よくある質問」以降から抽出して FAQ が確実に含まれるようにする。
                // 例: 「カンナビノイドの二日酔いを抑える方法はありますか？」は 1777文字目付近にあるため
                // 先頭500では届かず、FAQ起点の500で初めて含まれる。
                if ($url === 'help_guide') {
                    $faqPos = mb_strpos($text, 'よくある質問');
                    if ($faqPos !== false) {
                        // FAQセクションから800文字を優先的に抽出し、SNIPPET_MAX内で確実に FAQ が入るようにする
                        $faqText = mb_substr($text, $faqPos, 800);
                        // FAQセクションが存在すれば優先（短くてもFAQ起点を優先し、ヘッダー大量重複よりFAQを露出）
                        if (mb_strlen(trim($faqText)) > 10) {
                            $text = $faqText;
                        }
                    }
                }

                $snippet = mb_substr($text, 0, self::SNIPPET_MAX);
                $entry = "- {$url}: {$snippet}\n";
                if ($currentLength + mb_strlen($entry) > $maxTotal) {
                    break;
                }
                $context .= $entry;
                $currentLength += mb_strlen($entry);
            }

            if ($currentLength === 0) {
                return '';
            }

            return $context;
        } catch (\Throwable $e) {
            $this->logger?->warning('buildHelpContext failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 直近のニュースを要約してコンテキスト化する。
     *
     * buildKnowledgeContext と同様に合計 2000文字で制限し、
     * ツール呼び出しなしでも概要を回答できるようにする。
     * EasyArticle 依存は完全削除済みのため dtb_news のみを参照する。DB例外時は空文字を返す。
     */
    public function buildGuideNewsContext(): string
    {
        try {
            $conn = $this->entityManager->getConnection();

            $newsList = $conn->fetchAllAssociative(
                'SELECT title, description FROM dtb_news ORDER BY publish_date DESC LIMIT 5'
            );

            if (empty($newsList)) {
                return '';
            }

            $context = "\n\n## ニュース\n";
            $maxTotal = self::GUIDE_NEWS_CONTEXT_MAX;
            $currentLength = 0;

            foreach ($newsList as $news) {
                $title = $news['title'] ?? '';
                $excerpt = $this->plainTextExcerpt($news['description'] ?? '', self::EXCERPT_MAX);
                $snippetSource = $excerpt !== '' ? "{$title}: {$excerpt}" : $title;
                $snippet = mb_substr($snippetSource, 0, self::SNIPPET_MAX);
                $entry = "- [ニュース] {$snippet}\n";
                if ($currentLength + mb_strlen($entry) > $maxTotal) {
                    break;
                }
                $context .= $entry;
                $currentLength += mb_strlen($entry);
            }

            if ($currentLength === 0) {
                return '';
            }

            return $context;
        } catch (\Throwable $e) {
            $this->logger?->warning('buildGuideNewsContext failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * HTML/テキストの抜粋をプレーンテキスト化して切り出す。
     * 共通ヘルパー TwigPlainTextExtractor に委譲する。
     */
    private function plainTextExcerpt(?string $html, int $limit): string
    {
        return $this->textExtractor->excerpt($html, $limit);
    }

    /**
     * ヘルプ twig ファイルからプレーンテキストを抽出する。
     */
    private function resolveHelpText(?string $fileName): string
    {
        if ($fileName === null || $fileName === '') {
            return '';
        }

        $projectDir = dirname(__DIR__, 4);
        $candidates = [
            $projectDir . '/app/template/default/' . $fileName . '.twig',
            $projectDir . '/src/Eccube/Resource/template/default/' . $fileName . '.twig',
        ];

        $filePath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $filePath = $candidate;
                break;
            }
        }

        if ($filePath === null) {
            return '';
        }

        $raw = (string) file_get_contents($filePath);

        return $this->textExtractor->extract($raw);
    }

    /**
     * Twig/HTML 文字列をプレーンテキスト化する。
     * 後方互換のため残置し、共通ヘルパーに委譲する。
     */
    private function twigToPlainText(string $html): string
    {
        return $this->textExtractor->extract($html);
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
        $helpGuideUrl = $this->shopContextService?->getHelpGuideUrl() ?? '/help_guide';
        $helpGuideFaqUrl = $this->shopContextService?->getHelpGuideFaqUrl() ?? '/help_guide#faq';
        $shopBaseLabel = $shopBaseUrl !== '' ? $shopBaseUrl : '当ショップ';
        // 外部サイト参照禁止ルール（管理者設定に関わらず常に適用）- 汎用プラグイン向けにドメイン固定を除去
        $basePrompt .= "\n\n## 重要なルール\n"
            . "- 外部サイト（ウェブサイト・URL）へのリンクや参照は一切含めないでください。\n"
            . "- 回答は提供される商品情報とナレッジベースのみを根拠にしてください。\n"
            . "- 外部の情報源を引用しないでください。\n"
            . "- 当ショップ（{$shopBaseLabel}）のページを案内する際は、必ず絶対URLで出力してください。相対パスや https://www.example.com は使用しないでください。\n"
            . "- 商品を推薦する際は、商品名を [商品名]({$shopBaseLabel}/products/detail/{id}) の形式でリンク化し、3-5件を箇条書きで提示してください。\n"
            . "- リンクはクリック可能な markdown 形式で出力し、ユーザーがスムーズに商品ページへ遷移できるようにしてください。\n"
            . "- よくある質問（{$helpGuideFaqUrl}）の内容を最優先で参照してください。配送・支払い・返品・ポイント・FAQ に関する質問は、まず {$helpGuideUrl} の記載を根拠に回答し、該当情報がない場合のみ他のヘルプページを参照してください。\n";

        $knowledgeContext = $this->buildKnowledgeContext();
        $helpContext = $this->buildHelpContext();
        $guideNewsContext = $this->buildGuideNewsContext();
        $responseMode = $config->getResponseMode();

        // knowledge + help + guideNews を結合して注入
        $combinedContext = $knowledgeContext . $helpContext . $guideNewsContext;

        if ($combinedContext !== '') {
            if ($responseMode === 'knowledge_only') {
                // 厳格モード: ナレッジ/ヘルプにない場合は回答しない
                $combinedContext .= "\n\n上記のナレッジベース・ヘルプ・ガイド/ニュースのみを根拠に回答してください。"
                    . "該当する情報がない場合は「申し訳ございません。該当する情報がございません。"
                    . "メールにてお問い合わせください。」と回答してください。";
            } else {
                // ハイブリッド: ナレッジを参照しつつ、一般的知識も許可
                $combinedContext .= "\n\n上記のナレッジベース・ヘルプ・ガイド/ニュースを参照して回答してください。"
                    . "該当する情報がない場合は、一般的な知識で回答してください。";
            }
        } elseif ($responseMode === 'knowledge_only') {
            // コンテキストが空でも knowledge_only モードなら制限を維持
            $combinedContext .= "\n\n現在ナレッジ・ヘルプが登録されていません。"
                . "申し訳ございません。該当する情報がございません。メールにてお問い合わせください。";
        }

        return $basePrompt . $combinedContext;
    }
}
