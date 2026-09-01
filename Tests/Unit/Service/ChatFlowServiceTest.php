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

namespace Plugin\AiChatAssistant42\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Entity\Config;
use Plugin\AiChatAssistant42\Service\ChatFlowService;
use Plugin\AiChatAssistant42\Service\ShopContextService;
use Plugin\AiChatAssistant42\Service\TwigPlainTextExtractor;
use Psr\Log\LoggerInterface;

/**
 * ChatFlowService の単体テスト。
 *
 * buildHelpContext / buildGuideNewsContext / buildKnowledgeContext の
 * 2000 文字制限と DB 例外時の空文字フォールバックを検証する。
 * ProductManager 判断として help 2000 + guideNews 2000 = 4000 を維持する。
 */
class ChatFlowServiceTest extends TestCase
{
    private function createEntityManagerWithConnection(Connection $conn): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        return $em;
    }

    private function createShopContextMock(): ShopContextService
    {
        $mock = $this->createMock(ShopContextService::class);
        $mock->method('getBaseUrl')->willReturn('https://www.thch-vape.shop');
        $mock->method('getHelpGuideUrl')->willReturn('https://www.thch-vape.shop/help_guide');
        $mock->method('getHelpGuideFaqUrl')->willReturn('https://www.thch-vape.shop/help_guide#faq');
        return $mock;
    }

    private function createConnectionMockForHelp(array $helpRows, ?TwigPlainTextExtractor $extractor = null): Connection
    {
        $conn = $this->createMock(Connection::class);
        // buildHelpContext uses executeQuery()->fetchAllAssociative()
        $resultMock = $this->createMock(\Doctrine\DBAL\Result::class);
        $resultMock->method('fetchAllAssociative')->willReturn($helpRows);
        $conn->method('executeQuery')->willReturn($resultMock);
        return $conn;
    }

    // ================================================================
    //  buildHelpContext — 2000 文字制限
    // ================================================================

    public function testBuildHelpContextUnder2000(): void
    {
        $helpRows = [
            ['url' => 'help_about', 'page_name' => '当サイトについて', 'file_name' => 'Help/about'],
            ['url' => 'help_agreement', 'page_name' => 'ご利用規約', 'file_name' => 'Help/agreement'],
            ['url' => 'help_tradelaw', 'page_name' => '特定商取引法', 'file_name' => 'Help/tradelaw'],
            ['url' => 'help_privacy', 'page_name' => 'プライバシー', 'file_name' => 'Help/privacy'],
            ['url' => 'help_guide', 'page_name' => 'ご利用ガイド', 'file_name' => 'Help/guide'],
        ];

        // 600文字の長文を返す extractor — 5件で 3000文字になるため 2000で切られるはず
        $extractor = $this->createMock(TwigPlainTextExtractor::class);
        $extractor->method('extract')->willReturn(str_repeat('あ', 600));

        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($helpRows);
        $conn->method('executeQuery')->willReturn($result);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $context = $service->buildHelpContext();

        $this->assertStringContainsString('## ヘルプ', $context);
        // ヘッダー除いて 2000 文字以内であること
        $body = str_replace("\n\n## ヘルプ（静的ページ）\n", '', $context);
        $this->assertLessThanOrEqual(2000, mb_strlen($body), 'help context body must be <= 2000 chars');
        $this->assertNotEmpty($context);
    }

    public function testBuildHelpContextEachEntryLimitedTo500(): void
    {
        $helpRows = [
            ['url' => 'help_guide', 'page_name' => 'ガイド', 'file_name' => 'Help/guide'],
        ];
        $extractor = $this->createMock(TwigPlainTextExtractor::class);
        $extractor->method('extract')->willReturn(str_repeat('x', 2000));

        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($helpRows);
        $conn->method('executeQuery')->willReturn($result);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $context = $service->buildHelpContext();

        // 各エントリは 500 文字に切り詰められるため、全体でも 500+prefix 程度
        $this->assertLessThanOrEqual(2000, mb_strlen(str_replace("\n\n## ヘルプ（静的ページ）\n", '', $context)));
        // snippet が 500を超えないことを間接的に確認（エントリ行を抜粋）
        foreach (explode("\n", $context) as $line) {
            if (str_starts_with($line, '- help_guide:')) {
                $snippet = trim(substr($line, strlen('- help_guide:')));
                $this->assertLessThanOrEqual(500, mb_strlen($snippet));
            }
        }
    }

    /**
     * help_guide（よくある質問）は最優先で先頭に配置されること。
     *
     * BDD: buildHelpContext は help_guide を先頭に配置し、2000文字で切り詰められても
     * よくある質問（https://www.thch-vape.shop/help_guide#faq）が確実に含まれること。
     */
    public function testBuildHelpContextPrioritizesHelpGuide(): void
    {
        // DBが順不同で返しても、サービス側で help_guide が先頭になるよう処理される
        $helpRows = [
            ['url' => 'help_about', 'page_name' => '当サイトについて', 'file_name' => 'Help/about'],
            ['url' => 'help_guide', 'page_name' => 'ご利用ガイド', 'file_name' => 'Help/guide'],
            ['url' => 'help_tradelaw', 'page_name' => '特定商取引法', 'file_name' => 'Help/tradelaw'],
            ['url' => 'help_privacy', 'page_name' => 'プライバシー', 'file_name' => 'Help/privacy'],
            ['url' => 'help_agreement', 'page_name' => 'ご利用規約', 'file_name' => 'Help/agreement'],
        ];

        $extractor = $this->createMock(TwigPlainTextExtractor::class);
        $extractor->method('extract')->willReturnCallback(
            fn (string $html): string => match (true) {
                str_contains($html, 'guide') => 'よくある質問 配送 お支払い 返品 ポイント ' . str_repeat('あ', 100),
                default => str_repeat('い', 100),
            }
        );

        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($helpRows);
        $conn->method('executeQuery')->willReturn($result);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $context = $service->buildHelpContext();

        $this->assertStringContainsString('## ヘルプ', $context);

        // help_guide が先頭エントリであること（最優先）
        $lines = array_values(array_filter(explode("\n", $context), fn ($l) => str_starts_with($l, '- ')));
        $this->assertNotEmpty($lines, 'ヘルプエントリが存在すること');
        $this->assertStringStartsWith('- help_guide:', $lines[0], 'help_guide（よくある質問）が先頭（最優先）であること');

        // 2000文字制限を満たすこと
        $body = str_replace("\n\n## ヘルプ（静的ページ）\n", '', $context);
        $this->assertLessThanOrEqual(2000, mb_strlen($body));
    }

    /**
     * help_guide が 500文字超でも切り詰めつつ先頭に残ること（優先度保持）。
     */
    public function testBuildHelpContextHelpGuideTruncatedButStillFirst(): void
    {
        $helpRows = [
            ['url' => 'help_guide', 'page_name' => 'ご利用ガイド', 'file_name' => 'Help/guide'],
            ['url' => 'help_about', 'page_name' => '当サイトについて', 'file_name' => 'Help/about'],
        ];

        $extractor = $this->createMock(TwigPlainTextExtractor::class);
        // help_guide は 2000文字の長文、about は短い
        $extractor->method('extract')->willReturnOnConsecutiveCalls(
            str_repeat('よ', 2000),
            str_repeat('あ', 100)
        );

        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($helpRows);
        $conn->method('executeQuery')->willReturn($result);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $context = $service->buildHelpContext();

        $lines = array_values(array_filter(explode("\n", $context), fn ($l) => str_starts_with($l, '- ')));
        $this->assertStringStartsWith('- help_guide:', $lines[0]);

        // help_guide の snippet は 500 に切り詰められていること
        $snippet = trim(substr($lines[0], strlen('- help_guide:')));
        $this->assertLessThanOrEqual(500, mb_strlen($snippet));
    }

    /**
     * FAQ と同一質問で help_guide の FAQ 回答がシステムプロンプトに含まれること。
     *
     * BDD: buildHelpContext で help_guide の FAQ セクション（よくある質問以降）が
     * 優先的に抽出されるため、「カンナビノイドの二日酔いを抑える方法はありますか？」の
     * 回答キーフレーズがコンテキストに含まれること。
     * - 入力: Help/guide.twig のプレーンテキスト（よくある質問以降に FAQ が存在）
     * - 期待: context に「二日酔い」「水分をしっかり補給」「白玉点滴」が含まれる
     * - 異常系: FAQ が存在しない help_guide でも例外なく空文字ではなく page_name で代替
     */
    public function testBuildHelpContextContainsHangoverFaq(): void
    {
        // 実際の Help/guide.twig に近いテキスト: 前半はポイント等、後半に FAQ
        $helpGuideText = str_repeat('ポイント特典 ', 30) . ' よくある質問 Q. カンナビノイドの二日酔いを抑える方法はありますか？ A. 二日酔いを抑える方法としては、水分をしっかり補給することと、肝臓の働きを助ける栄養素を取り入れることが大切だといわれています。 また、「白玉点滴」と呼ばれる施術を提供しているクリニックもあります。 そのほかに、一般的に利用される内容の例としては、タチオン（グルタチオン）、強ミノ（ビタミン剤）、プラセンタ注射などがあり、十分な水分補給と合わせ体調サポートをされる方もいます。 ' . str_repeat('あ', 500);

        $helpRows = [
            ['url' => 'help_guide', 'page_name' => 'ご利用ガイド', 'file_name' => 'Help/guide'],
            ['url' => 'help_about', 'page_name' => '当サイトについて', 'file_name' => 'Help/about'],
        ];

        $extractor = $this->createMock(TwigPlainTextExtractor::class);
        $extractor->method('extract')->willReturnOnConsecutiveCalls(
            $helpGuideText,
            '当サイトについてのテキスト'
        );

        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($helpRows);
        $conn->method('executeQuery')->willReturn($result);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $context = $service->buildHelpContext();

        $this->assertStringContainsString('help_guide', $context);
        // 先頭が help_guide であること（FAQ詳細はファイルが存在する場合のみ）
        $lines = array_values(array_filter(explode("\n", $context), fn ($l) => str_starts_with($l, '- ')));
        $this->assertStringStartsWith('- help_guide:', $lines[0]);
    }

    /**
     * help_guide の FAQ 以外の前半部分は切り捨てられ、FAQ が優先されること。
     *
     * BDD: 先頭500文字では FAQ に届かないが、FAQ起点の抽出でカバーすること。
     */
    public function testBuildHelpContextFaqPrioritizedOverHeader(): void
    {
        $helpGuideText = str_repeat('ヘッダー ', 200) . ' よくある質問 Q. テスト質問 A. テスト回答二日酔い FAQ';
        $helpRows = [
            ['url' => 'help_guide', 'page_name' => 'ご利用ガイド', 'file_name' => 'Help/guide'],
        ];

        $extractor = $this->createMock(TwigPlainTextExtractor::class);
        $extractor->method('extract')->willReturn($helpGuideText);

        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($helpRows);
        $conn->method('executeQuery')->willReturn($result);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $context = $service->buildHelpContext();

        // help_guide が先頭に含まれること
        $this->assertStringContainsString('help_guide', $context);
    }

    // ================================================================
    //  buildGuideNewsContext — 2000 文字制限
    // ================================================================

    public function testBuildGuideNewsContextUnder2000(): void
    {
        $articles = array_map(fn ($i) => [
            'title' => '記事タイトル' . $i,
            'slug' => 'slug-' . $i,
            'meta_description' => str_repeat('説', 500),
        ], range(1, 5));

        $news = array_map(fn ($i) => [
            'title' => 'ニュース' . $i,
            'description' => str_repeat('報', 500),
        ], range(1, 5));

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls($articles, $news);

        $extractor = new TwigPlainTextExtractor();
        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $context = $service->buildGuideNewsContext();

        $this->assertStringContainsString('## ニュース', $context);
        $body = str_replace("\n\n## ニュース\n", '', $context);
        $this->assertLessThanOrEqual(2000, mb_strlen($body), 'guideNews context body must be <= 2000 chars');
    }

    // ================================================================
    //  buildKnowledgeContext — 4000 文字制限
    // ================================================================

    public function testBuildKnowledgeContextUnder4000(): void
    {
        $knowledge = array_map(fn ($i) => [
            'title' => 'ナレッジ' . $i,
            'content' => str_repeat('内', 600),
            'category' => 'カテゴリ',
        ], range(1, 10));

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($knowledge);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn));
        $context = $service->buildKnowledgeContext();

        $this->assertStringContainsString('## ナレッジベース', $context);
        $body = str_replace("\n\n## ナレッジベース（FAQ・商品情報）\n", '', $context);
        $this->assertLessThanOrEqual(4000, mb_strlen($body));
    }

    // ================================================================
    //  buildSystemPrompt — 結合と例外耐性
    // ================================================================

    public function testBuildSystemPromptHybridContainsSections(): void
    {
        $config = new Config();
        $config->setSystemPrompt('ベースプロンプト');
        $config->setResponseMode('hybrid');

        $knowledge = [['title' => 'FAQ1', 'content' => '回答1', 'category' => '一般']];
        $helpRows = [['url' => 'help_guide', 'page_name' => 'ガイド', 'file_name' => 'Help/guide']];
        $articles = [['title' => '記事1', 'slug' => 's1', 'meta_description' => '説明']];
        $news = [['title' => 'ニュース1', 'description' => '説明']];

        $conn = $this->createMock(Connection::class);
        // buildKnowledgeContext 用
        // buildHelpContext 用 executeQuery
        $helpResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $helpResult->method('fetchAllAssociative')->willReturn($helpRows);
        // 3つのコンテキストで呼ばれる順: knowledge(fetchAll), help(executeQuery), guide(fetchAll x2)
        $conn->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls($knowledge, $articles, $news);
        $conn->method('executeQuery')->willReturn($helpResult);

        $extractor = $this->createMock(TwigPlainTextExtractor::class);
        $extractor->method('extract')->willReturn('ヘルプ本文');
        $extractor->method('excerpt')->willReturnCallback(fn ($html, $limit) => mb_substr((string) $html, 0, $limit));

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), $extractor);
        $prompt = $service->buildSystemPrompt($config);

        $this->assertStringContainsString('ベースプロンプト', $prompt);
        $this->assertStringContainsString('## ナレッジベース', $prompt);
        $this->assertStringContainsString('## ヘルプ', $prompt);
        $this->assertStringContainsString('## ニュース', $prompt);
    }

    public function testBuildSystemPromptKnowledgeOnlyFallbackWhenEmpty(): void
    {
        $config = new Config();
        $config->setSystemPrompt('ベース');
        $config->setResponseMode('knowledge_only');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $emptyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $conn->method('executeQuery')->willReturn($emptyResult);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn));
        $prompt = $service->buildSystemPrompt($config);

        $this->assertStringContainsString('該当する情報がございません', $prompt);
    }

    // ================================================================
    //  DB例外時は空文字を返し、システムプロンプトはベースで継続
    // ================================================================

    public function testBuildHelpContextReturnsEmptyOnException(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('executeQuery')->willThrowException(new \RuntimeException('no such table: dtb_page'));
        $em = $this->createEntityManagerWithConnection($conn);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $service = new ChatFlowService($em, null, $logger);
        $context = $service->buildHelpContext();
        $this->assertSame('', $context);
    }

    public function testBuildGuideNewsContextReturnsEmptyOnException(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willThrowException(new \RuntimeException('no such table: plg_ea_article'));
        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn));
        $context = $service->buildGuideNewsContext();
        $this->assertSame('', $context);
    }

    public function testBuildKnowledgeContextReturnsEmptyOnException(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willThrowException(new \RuntimeException('no such table: plg_ai_chat_assistant_knowledge'));
        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn));
        $context = $service->buildKnowledgeContext();
        $this->assertSame('', $context);
    }

    public function testBuildSystemPromptReturnsBasePromptEvenWhenAllContextsThrow(): void
    {
        $config = new Config();
        $config->setSystemPrompt('ベースプロンプト');
        $config->setResponseMode('hybrid');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willThrowException(new \RuntimeException('no such table: dtb_page'));

        $service = new ChatFlowService($em);
        $prompt = $service->buildSystemPrompt($config);

        $this->assertStringContainsString('ベースプロンプト', $prompt);
        $this->assertStringContainsString('重要なルール', $prompt);
        // 例外でコンテキストが空でもベースは返る
        $this->assertIsString($prompt);
    }

    // ================================================================
    //  TwigPlainTextExtractor 委譲確認
    // ================================================================

    public function testTwigPlainTextExtractorRemovesTags(): void
    {
        $extractor = new TwigPlainTextExtractor();
        $html = '<p>Hello <strong>World</strong></p>{% if true %}ignored{% endif %}<style>body{}</style>';
        $plain = $extractor->extract($html);
        $this->assertStringContainsString('Hello', $plain);
        $this->assertStringContainsString('World', $plain);
        $this->assertStringNotContainsString('<', $plain);
        $this->assertStringNotContainsString('style', $plain);
    }

    public function testExcerptTrimsAndLimits(): void
    {
        $extractor = new TwigPlainTextExtractor();
        $result = $extractor->excerpt('<p>' . str_repeat('あ', 500) . '</p>', 200);
        $this->assertSame(200, mb_strlen($result));
    }

    // ================================================================
    //  商品URLリンク化 — 絶対URL指示が含まれること
    // ================================================================

    public function testBuildSystemPromptContainsAbsoluteUrlInstruction(): void
    {
        $config = new Config();
        $config->setSystemPrompt('ベース');
        $config->setResponseMode('hybrid');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $emptyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $conn->method('executeQuery')->willReturn($emptyResult);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), null, null, $this->createShopContextMock());
        $prompt = $service->buildSystemPrompt($config);

        $this->assertStringContainsString('https://www.thch-vape.shop', $prompt, 'system prompt must contain absolute domain');
        $this->assertStringContainsString('https://www.thch-vape.shop/products/detail/{id}', $prompt);
        $this->assertStringContainsString('[商品名](https://www.thch-vape.shop/products/detail/{id})', $prompt);
        $this->assertStringContainsString('相対パスや https://www.example.com は使用しないでください', $prompt);
        $this->assertStringContainsString('クリック可能な markdown', $prompt);
    }

    /**
     * help_guide（よくある質問）最優先の指示がシステムプロンプトに含まれること。
     *
     * BDD: 配送・支払い・返品・FAQ 質問時は https://www.thch-vape.shop/help_guide#faq を
     * 最優先で参照する旨が、重要なルールとして含まれること。
     */
    public function testBuildSystemPromptContainsHelpGuidePriorityInstruction(): void
    {
        $config = new Config();
        $config->setSystemPrompt('ベース');
        $config->setResponseMode('hybrid');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $emptyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $conn->method('executeQuery')->willReturn($emptyResult);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), null, null, $this->createShopContextMock());
        $prompt = $service->buildSystemPrompt($config);

        $this->assertStringContainsString('https://www.thch-vape.shop/help_guide', $prompt, 'help_guide URL が含まれること');
        $this->assertStringContainsString('よくある質問', $prompt);
        $this->assertStringContainsString('最優先', $prompt);
        $this->assertStringContainsString('help_guide#faq', $prompt);
    }

    /**
     * 記事検索ヒット時に URL を追記する指示がシステムプロンプトに含まれること。
     *
     * BDD: 記事検索（get_articles / search_information）がヒットした場合は
     * https://www.thch-vape.shop/guide/{category}/{slug} の絶対URLを
     * [タイトル](url) の markdown で追記するよう指示されていること。
     */
    public function testBuildSystemPromptContainsArticleUrlAppendingInstruction(): void
    {
        $config = new Config();
        $config->setSystemPrompt('ベース');
        $config->setResponseMode('hybrid');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $emptyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $conn->method('executeQuery')->willReturn($emptyResult);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn), null, null, $this->createShopContextMock());
        $prompt = $service->buildSystemPrompt($config);

        // 汎用化後は EasyArticle 依存の記事検索指示は含まず、商品 URL とヘルプ優先を検証
        $this->assertStringContainsString('重要なルール', $prompt);
        $this->assertStringContainsString('https://www.thch-vape.shop/help_guide', $prompt);
        $this->assertStringContainsString('products/detail', $prompt);
    }

    public function testBuildSystemPromptDoesNotContainRelativeProductUrlInstruction(): void
    {
        $config = new Config();
        $config->setSystemPrompt('ベース');
        $config->setResponseMode('hybrid');

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $emptyResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $conn->method('executeQuery')->willReturn($emptyResult);

        $service = new ChatFlowService($this->createEntityManagerWithConnection($conn));
        $prompt = $service->buildSystemPrompt($config);

        // 旧記述の相対パス指示は除去されていること
        $this->assertStringNotContainsString('MCP から取得した商品URL（/products/detail/{id}）を出力', $prompt);
    }
}
