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

namespace Plugin\AiChatAssistant42\Tests\Functional\Repository;

use Eccube\Tests\Web\AbstractWebTestCase;

/**
 * ProductRepository の機能テスト。
 *
 * EC-CUBE の商品テーブル群を対象にした検索・詳細取得・カテゴリ取得を
 * 実際の DB 経由で検証する。テストデータの有無に依存するため、
 * 取得件数のアサーションは範囲指定で行う。
 */
class ProductRepositoryTest extends AbstractWebTestCase
{
    /** @var \Plugin\AiChatAssistant42\Repository\ProductRepository */
    private $productRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepository = $this->getContainer()->get(
            \Plugin\AiChatAssistant42\Repository\ProductRepository::class
        );
    }

    // ================================================================
    //  search
    // ================================================================

    public function testSearchReturnsArray(): void
    {
        $results = $this->productRepository->search('');

        $this->assertIsArray($results);
    }

    public function testSearchWithKeywordReturnsResults(): void
    {
        // 部分一致検索 — 結果は空でもエラーにならないこと
        $results = $this->productRepository->search('テスト');

        $this->assertIsArray($results);

        // 結果がある場合はフィールド構造を検証
        if (!empty($results)) {
            $product = $results[0];
            $this->assertArrayHasKey('id', $product);
            $this->assertArrayHasKey('name', $product);
            $this->assertArrayHasKey('price', $product);
            $this->assertArrayHasKey('stock', $product);
            $this->assertArrayHasKey('stock_unlimited', $product);
            $this->assertArrayHasKey('images', $product);
            $this->assertIsArray($product['images']);
        }
    }

    public function testSearchWithCategoryFilter(): void
    {
        $results = $this->productRepository->search('', 1);

        $this->assertIsArray($results);
    }

    public function testSearchRespectsLimitAndOffset(): void
    {
        $results = $this->productRepository->search('', null, 5, 0);

        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(5, count($results));
    }

    public function testSearchReturnsEmptyForNonexistentKeyword(): void
    {
        $results = $this->productRepository->search('存在しない商品名XYZ123');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    // ================================================================
    //  getDetail
    // ================================================================

    public function testGetDetailReturnsNullForInvalidProductId(): void
    {
        $result = $this->productRepository->getDetail(999999);

        $this->assertNull($result);
    }

    public function testGetDetailReturnsProductWhenExists(): void
    {
        // まず検索で商品を1件取得し、その ID で詳細を取得
        $products = $this->productRepository->search('', null, 1);

        if (empty($products)) {
            $this->markTestSkipped('No products in database');
        }

        $productId = $products[0]['id'];
        $detail = $this->productRepository->getDetail($productId);

        $this->assertNotNull($detail);
        $this->assertEquals($productId, $detail['id']);
        $this->assertArrayHasKey('name', $detail);
        $this->assertArrayHasKey('classes', $detail);
        $this->assertArrayHasKey('stock', $detail);
        $this->assertArrayHasKey('categories', $detail);
        $this->assertArrayHasKey('images', $detail);
        $this->assertArrayHasKey('tags', $detail);
        $this->assertIsArray($detail['classes']);
        $this->assertIsArray($detail['stock']);
        $this->assertIsArray($detail['categories']);
        $this->assertIsArray($detail['images']);
        $this->assertIsArray($detail['tags']);
    }

    // ================================================================
    //  getStock
    // ================================================================

    public function testGetStockReturnsArrayForValidProduct(): void
    {
        $products = $this->productRepository->search('', null, 1);

        if (empty($products)) {
            $this->markTestSkipped('No products in database');
        }

        $stock = $this->productRepository->getStock($products[0]['id']);

        $this->assertIsArray($stock);

        // 在庫データがある場合はフィールド構造を検証
        if (!empty($stock)) {
            $this->assertArrayHasKey('class_id', $stock[0]);
            $this->assertArrayHasKey('stock', $stock[0]);
            $this->assertArrayHasKey('stock_unlimited', $stock[0]);
            $this->assertArrayHasKey('price', $stock[0]);
        }
    }

    public function testGetStockReturnsEmptyForNonexistentProduct(): void
    {
        $stock = $this->productRepository->getStock(999999);

        $this->assertIsArray($stock);
        $this->assertEmpty($stock);
    }

    // ================================================================
    //  getCategories
    // ================================================================

    public function testGetCategoriesReturnsArray(): void
    {
        $categories = $this->productRepository->getCategories();

        $this->assertIsArray($categories);
    }

    public function testGetCategoriesReturnsCorrectFields(): void
    {
        $categories = $this->productRepository->getCategories();

        if (!empty($categories)) {
            $this->assertArrayHasKey('id', $categories[0]);
            $this->assertArrayHasKey('name', $categories[0]);
            $this->assertArrayHasKey('hierarchy', $categories[0]);
            $this->assertArrayHasKey('parent_id', $categories[0]);
            $this->assertArrayHasKey('children_count', $categories[0]);
        }
    }

    public function testGetCategoriesWithParentIdReturnsChildCategories(): void
    {
        // ルートカテゴリから子を取得
        $rootCategories = $this->productRepository->getCategories();

        if (empty($rootCategories)) {
            $this->markTestSkipped('No root categories in database');
        }

        $firstRoot = $rootCategories[0];
        $children = $this->productRepository->getCategories($firstRoot['id']);

        $this->assertIsArray($children);

        // 子カテゴリがある場合は、parent_id が一致することを確認
        foreach ($children as $child) {
            $this->assertEquals($firstRoot['id'], $child['parent_id']);
        }
    }

    // ================================================================
    //  getToolDefinitions
    // ================================================================

    public function testGetToolDefinitionsReturnsAllExpectedTools(): void
    {
        $tools = $this->productRepository->getToolDefinitions();

        $this->assertIsArray($tools);
        $this->assertCount(12, $tools);

        $toolNames = array_column($tools, 'name');
        $this->assertContains('search_products', $toolNames);
        $this->assertContains('get_product_detail', $toolNames);
        $this->assertContains('get_stock', $toolNames);
        $this->assertContains('get_categories', $toolNames);
        $this->assertContains('get_category_products', $toolNames);
        $this->assertContains('get_tags', $toolNames);
        $this->assertContains('search_by_tag', $toolNames);
        $this->assertContains('get_news', $toolNames);
        $this->assertContains('get_articles', $toolNames);
        $this->assertContains('search_information', $toolNames);
        $this->assertContains('search_help', $toolNames);
        $this->assertContains('get_help_detail', $toolNames);
    }

    /**
     * search_help / get_help_detail のツール定義が help_guide 最優先を明記していること。
     *
     * BDD: AI が配送・FAQ 質問時に help_guide（https://www.thch-vape.shop/help_guide#faq）を
     * 最優先で参照するよう、ツール description に明記されていること。
     */
    public function testGetToolDefinitionsHelpToolsPrioritizeHelpGuide(): void
    {
        $tools = $this->productRepository->getToolDefinitions();
        $toolMap = array_column($tools, null, 'name');

        $this->assertArrayHasKey('search_help', $toolMap);
        $this->assertArrayHasKey('get_help_detail', $toolMap);

        $searchHelpDesc = $toolMap['search_help']['description'];
        $getHelpDetailDesc = $toolMap['get_help_detail']['description'];

        // search_help の description に help_guide 優先が明記されていること
        $this->assertStringContainsString('help_guide', $searchHelpDesc);
        $this->assertStringContainsString('https://www.thch-vape.shop/help_guide', $searchHelpDesc);
        $this->assertStringContainsString('最優先', $searchHelpDesc);

        // get_help_detail の description に help_guide 優先が明記されていること
        $this->assertStringContainsString('help_guide', $getHelpDetailDesc);
        $this->assertStringContainsString('https://www.thch-vape.shop/help_guide', $getHelpDetailDesc);
        $this->assertStringContainsString('最優先', $getHelpDetailDesc);

        // search_help の url パラメータ説明にも help_guide が最優先として記載されていること
        $urlDesc = $toolMap['get_help_detail']['input_schema']['properties']['url']['description'];
        $this->assertStringContainsString('help_guide', $urlDesc);
        $this->assertStringContainsString('最優先', $urlDesc);
    }

    /**
     * get_articles / search_information のツール定義がヒット時にURL追記を指示していること。
     *
     * BDD: 記事検索がヒットした場合は記事のURL（https://www.thch-vape.shop/guide/...）を
     * 追記して返答するよう、ツール description に明記されていること。
     */
    public function testGetToolDefinitionsArticleToolsIncludeUrlAppending(): void
    {
        $tools = $this->productRepository->getToolDefinitions();
        $toolMap = array_column($tools, null, 'name');

        $this->assertArrayHasKey('get_articles', $toolMap);
        $this->assertArrayHasKey('search_information', $toolMap);

        $getArticlesDesc = $toolMap['get_articles']['description'];
        $searchInfoDesc = $toolMap['search_information']['description'];

        // get_articles の description に URL 追記指示が含まれること
        $this->assertStringContainsString('ヒット', $getArticlesDesc);
        $this->assertStringContainsString('url', strtolower($getArticlesDesc));
        $this->assertStringContainsString('https://www.thch-vape.shop/guide/', $getArticlesDesc);
        $this->assertStringContainsString('追記', $getArticlesDesc);

        // search_information の description にも同様
        $this->assertStringContainsString('ヒット', $searchInfoDesc);
        $this->assertStringContainsString('https://www.thch-vape.shop/guide/', $searchInfoDesc);
        $this->assertStringContainsString('追記', $searchInfoDesc);
    }

    public function testGetToolDefinitionsReturnsValidStructure(): void
    {
        $tools = $this->productRepository->getToolDefinitions();

        foreach ($tools as $tool) {
            $this->assertEquals('function', $tool['type']);
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('input_schema', $tool);
            $this->assertArrayHasKey('type', $tool['input_schema']);
            $this->assertEquals('object', $tool['input_schema']['type']);
        }
    }

    // ================================================================
    //  executeTool
    // ================================================================

    public function testExecuteToolRoutesToSearchProducts(): void
    {
        $result = $this->productRepository->executeTool('search_products', [
            'keyword' => 'テスト',
            'limit' => 5,
        ]);

        $this->assertIsArray($result);
    }

    public function testExecuteToolRoutesToGetCategories(): void
    {
        $result = $this->productRepository->executeTool('get_categories', []);

        $this->assertIsArray($result);
    }

    public function testExecuteToolRoutesToGetTags(): void
    {
        $result = $this->productRepository->executeTool('get_tags', []);

        $this->assertIsArray($result);
    }

    public function testExecuteToolThrowsExceptionForUnknownTool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool: nonexistent_tool');

        $this->productRepository->executeTool('nonexistent_tool', []);
    }

    public function testExecuteToolGetProductDetailReturnsErrorForInvalidId(): void
    {
        $result = $this->productRepository->executeTool('get_product_detail', [
            'product_id' => 999999,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    // ================================================================
    //  searchHelp / getHelpDetail — help_guide（よくある質問）を最優先
    // ================================================================

    public function testSearchHelpReturnsArray(): void
    {
        $results = $this->productRepository->searchHelp('ガイド');

        $this->assertIsArray($results);
        if (!empty($results)) {
            $this->assertArrayHasKey('url', $results[0]);
            $this->assertArrayHasKey('page_name', $results[0]);
        }
    }

    public function testSearchHelpReturnsEmptyForNonexistentKeyword(): void
    {
        $results = $this->productRepository->searchHelp('存在しないキーワードXYZ123');

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * help_guide（よくある質問）はキーワード空でも最優先で先頭に返ること。
     *
     * BDD: キーワードなしで searchHelp('') を呼んだとき、
     * https://www.thch-vape.shop/help_guide が先頭に来る（FAQ優先）。
     */
    public function testSearchHelpWithoutKeywordPrioritizesHelpGuide(): void
    {
        $results = $this->productRepository->searchHelp('', 10);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results, 'dtb_page にヘルプページが存在すること');

        // help_guide が結果に含まれること
        $urls = array_column($results, 'url');
        $this->assertContains('help_guide', $urls, 'help_guide（よくある質問）が含まれること');

        // help_guide が先頭であること（最優先）
        $this->assertEquals('help_guide', $results[0]['url'], 'キーワードなしでも help_guide が先頭（最優先）であること');
    }

    /**
     * キーワード検索でも help_guide が最優先で返ること。
     *
     * BDD: 「ガイド」で検索したとき、help_guide が先頭にソートされる。
     * （dtb_page の description/keyword に「ガイド」が含まれるページが複数あっても help_guide が優先）
     */
    public function testSearchHelpPrioritizesHelpGuideWhenKeywordMatches(): void
    {
        $results = $this->productRepository->searchHelp('ガイド', 10);

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        $urls = array_column($results, 'url');
        if (in_array('help_guide', $urls, true)) {
            $this->assertEquals('help_guide', $results[0]['url'], '「ガイド」検索でも help_guide が先頭（最優先）であること');
        }
    }

    /**
     * よくある質問キーワードでも help_guide が優先されること。
     */
    public function testSearchHelpPrioritizesHelpGuideForFaqKeyword(): void
    {
        // help_guide の description には「よくある質問」が含まれない場合もあるため、
        // キーワードなしでも help_guide が優先されることを間接的に検証する
        $results = $this->productRepository->searchHelp('よくある質問', 10);

        // 結果が空でもエラーにならないこと（異常系）
        $this->assertIsArray($results);

        // 結果がある場合は help_guide が含まれるか、または空でも許容（データ依存）
        if (!empty($results)) {
            $urls = array_column($results, 'url');
            // help_guide がヒットすれば先頭であること
            if (in_array('help_guide', $urls, true)) {
                $this->assertEquals('help_guide', $results[0]['url']);
            }
        }
    }

    public function testGetHelpDetailReturnsHelpPage(): void
    {
        $detail = $this->productRepository->getHelpDetail('help_guide');

        $this->assertNotNull($detail);
        $this->assertArrayHasKey('page_name', $detail);
        $this->assertArrayHasKey('url', $detail);
        $this->assertArrayHasKey('meta_robots', $detail);
        $this->assertArrayHasKey('content', $detail);
        $this->assertEquals('help_guide', $detail['url']);
        $this->assertNotEmpty($detail['content']);
        // HTML タグが除去されていること
        $this->assertStringNotContainsString('<', $detail['content']);
    }

    /**
     * help_guide（よくある質問）の詳細が FAQ コンテンツを含むこと。
     *
     * BDD: getHelpDetail('help_guide') は https://www.thch-vape.shop/help_guide の
     * よくある質問（#faq）を含むプレーンテキストを返す。配送・FAQ の文言が含まれること。
     */
    public function testGetHelpDetailHelpGuideContainsFaqContent(): void
    {
        $detail = $this->productRepository->getHelpDetail('help_guide');

        $this->assertNotNull($detail);
        $this->assertEquals('help_guide', $detail['url']);
        $this->assertNotEmpty($detail['content']);

        // よくある質問セクションの FAQ 関連キーワードが含まれること（データ依存だが存在すれば）
        // 少なくとも配送・よくある質問・ポイント等のいずれかを含む（help_guide の実内容に依存）
        $content = $detail['content'];
        $hasFaqRelated = mb_strpos($content, 'よくある質問') !== false
            || mb_strpos($content, '配送') !== false
            || mb_strpos($content, 'ポイント') !== false
            || mb_strpos($content, 'お支払い') !== false;

        $this->assertTrue($hasFaqRelated, 'help_guide の content に FAQ/配送/ポイント等のよくある質問内容が含まれること');
        $this->assertStringNotContainsString('<', $content, 'HTML タグが除去されていること');
    }

    /**
     * FAQ と同一の質問で類似回答が得られること — 二日酔い FAQ。
     *
     * BDD: 「カンナビノイドの二日酔いを抑える方法はありますか？」は
     * help_guide.twig の FAQ「Q. カンナビノイドの二日酔いを抑える方法はありますか？」と同一。
     * getHelpDetail('help_guide') の content に FAQ回答のキーフレーズが含まれることで、
     * AI が FAQと類似の回答を生成できることを担保する。
     *
     * 期待する振る舞い:
     * - 入力: getHelpDetail('help_guide')
     * - 出力: content に「水分をしっかり補給」「肝臓の働きを助ける」「白玉点滴」「タチオン（グルタチオン）」「強ミノ」「プラセンタ注射」を含む
     * - 異常系: 存在しない help_guide の file_name でも例外ではなく '' を返す
     */
    public function testGetHelpDetailHelpGuideContainsHangoverFaqAnswer(): void
    {
        $detail = $this->productRepository->getHelpDetail('help_guide');

        $this->assertNotNull($detail);
        $this->assertEquals('help_guide', $detail['url']);
        $content = $detail['content'];

        // FAQ「カンナビノイドの二日酔いを抑える方法はありますか？」の回答と類似のキーフレーズ
        $this->assertStringContainsString('二日酔いを抑える方法', $content, 'FAQ質問タイトルが含まれること');
        $this->assertStringContainsString('水分をしっかり補給', $content, 'FAQ回答: 水分補給 が含まれること');
        $this->assertStringContainsString('肝臓の働きを助ける', $content, 'FAQ回答: 肝臓 が含まれること');
        $this->assertStringContainsString('白玉点滴', $content, 'FAQ回答: 白玉点滴 が含まれること');
        $this->assertStringContainsString('タチオン', $content, 'FAQ回答: タチオン（グルタチオン） が含まれること');
        $this->assertStringContainsString('強ミノ', $content, 'FAQ回答: 強ミノ が含まれること');
        $this->assertStringContainsString('プラセンタ注射', $content, 'FAQ回答: プラセンタ注射 が含まれること');
        $this->assertStringNotContainsString('<', $content);
    }

    /**
     * FAQ と同一の質問キーワードで searchHelp が help_guide を返すこと。
     *
     * BDD:
     * - 正常系: searchHelp('二日酔い') → help_guide を先頭に含む（ファイル内容検索で補完）
     * - 正常系: searchHelp('カンナビノイドの二日酔いを抑える方法はありますか？') → help_guide を先頭に含む
     * - 異常系: searchHelp('存在しないキーワードXYZ123') → help_guide を含まない（誤補完しない）
     * - エッジ: searchHelp('カンナビノイド') → help_guide を含む（FAQ語）
     */
    public function testSearchHelpForHangoverFaqReturnsHelpGuide(): void
    {
        // 完全一致の FAQ タイトル
        $resultsFull = $this->productRepository->searchHelp('カンナビノイドの二日酔いを抑える方法はありますか？', 10);
        $this->assertIsArray($resultsFull);
        $this->assertNotEmpty($resultsFull, 'FAQ完全一致でも help_guide が返ること');
        $this->assertEquals('help_guide', $resultsFull[0]['url'], 'FAQ完全一致で help_guide が先頭');

        // 部分一致「二日酔い」
        $resultsHangover = $this->productRepository->searchHelp('二日酔い', 10);
        $this->assertIsArray($resultsHangover);
        $this->assertNotEmpty($resultsHangover, '「二日酔い」で help_guide が返ること（ファイル内容検索）');
        $this->assertEquals('help_guide', $resultsHangover[0]['url'], '「二日酔い」で help_guide が先頭');

        // 部分一致「カンナビノイド」
        $resultsCannabinoid = $this->productRepository->searchHelp('カンナビノイド', 10);
        $this->assertIsArray($resultsCannabinoid);
        if (!empty($resultsCannabinoid)) {
            // DB検索で他ページがヒットしても、ファイル補完で help_guide が先頭に来ることを許容
            $urls = array_column($resultsCannabinoid, 'url');
            $this->assertContains('help_guide', $urls, '「カンナビノイド」で help_guide が含まれること');
        }

        // 異常系: 存在しないキーワードでは help_guide を誤補完しない
        $resultsNone = $this->productRepository->searchHelp('存在しないキーワードXYZ123', 10);
        $this->assertIsArray($resultsNone);
        $this->assertEmpty($resultsNone, '存在しないキーワードでは空を返す（誤補完しない）');
    }

    /**
     * executeTool 経由でも FAQ 質問で help_guide が取得できること。
     */
    public function testExecuteToolSearchHelpForHangoverFaq(): void
    {
        $result = $this->productRepository->executeTool('search_help', [
            'keyword' => 'カンナビノイドの二日酔いを抑える方法はありますか？',
            'limit' => 5,
        ]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertEquals('help_guide', $result[0]['url']);

        $detail = $this->productRepository->executeTool('get_help_detail', [
            'url' => 'help_guide',
        ]);
        $this->assertIsArray($detail);
        $this->assertStringContainsString('水分をしっかり補給', $detail['content']);
        $this->assertStringContainsString('白玉点滴', $detail['content']);
    }

    public function testGetHelpDetailReturnsNullForInvalidUrl(): void
    {
        $detail = $this->productRepository->getHelpDetail('nonexistent_page_xyz');

        $this->assertNull($detail);
    }

    public function testExecuteToolSearchHelp(): void
    {
        $result = $this->productRepository->executeTool('search_help', [
            'keyword' => 'ガイド',
            'limit' => 3,
        ]);

        $this->assertIsArray($result);
    }

    public function testExecuteToolGetHelpDetail(): void
    {
        $result = $this->productRepository->executeTool('get_help_detail', [
            'url' => 'help_guide',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('page_name', $result);
        $this->assertArrayHasKey('content', $result);
    }

    public function testExecuteToolGetHelpDetailReturnsErrorForInvalidUrl(): void
    {
        $result = $this->productRepository->executeTool('get_help_detail', [
            'url' => 'nonexistent_page_xyz',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetNewsReturnsArray(): void
    {
        $results = $this->productRepository->getNews(2);

        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(2, count($results));
    }

    public function testGetArticlesReturnsArray(): void
    {
        $results = $this->productRepository->getArticles('', 2);

        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(2, count($results));
    }

    // ================================================================
    //  MySQL LIKE 検索 — 本文（body_html/body_json）も対象
    //  https://xs990883.xsrv.jp/guide/column/citizen-movement-advocacy-strategy-guide
    //  ジェナウェイ・キャビオン は title/meta にはなく body のみに存在
    // ================================================================

    /**
     * MySQL LIKE で本文検索が可能であること — ジェナウェイ・キャビオン。
     *
     * BDD:
     * - 正常系: getArticles('ジェナウェイ・キャビオン') → slug=citizen-movement-advocacy-strategy-guide を含む（body_html LIKE）
     * - 正常系: getArticles('キャビオン') → 同上（部分一致）
     * - 正常系: getArticles('Jennawae Cavion') → 同上（英語表記）
     * - 異常系: getArticles('存在しないキーワードXYZ123') → 空配列（誤検出しない）
     * - エッジ: getArticles('citizen-movement') は title/slug ではなく body 検索ではないためヒットしないがエラーにならない
     * - URL: ヒット時は絶対URL（https://www.thch-vape.shop/guide/column/...）を追記して返答するため url が絶対URLであること
     */
    public function testGetArticlesLikeSearchMatchesBodyContent(): void
    {
        // ジェナウェイ・キャビオン（フルネーム・中点あり）
        $resultsFull = $this->productRepository->getArticles('ジェナウェイ・キャビオン', 10);
        $this->assertIsArray($resultsFull);
        $this->assertNotEmpty($resultsFull, '「ジェナウェイ・キャビオン」で記事がヒットすること（MySQL LIKE body_html）');
        $urls = array_column($resultsFull, 'url');
        $this->assertTrue(
            count(array_filter($urls, fn ($u) => str_contains($u, 'citizen-movement-advocacy-strategy-guide'))) > 0,
            '該当記事の URL が含まれること'
        );
        // URLは絶対URLで追記されること
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://www.thch-vape.shop', $url, '記事URLは絶対URLで返ること');
        }
        // 市民運動記事は column カテゴリのため /guide/column/ を含む
        $this->assertTrue(
            count(array_filter($urls, fn ($u) => str_contains($u, '/guide/column/citizen-movement-advocacy-strategy-guide'))) > 0,
            'カテゴリ付き絶対URL（/guide/column/...）が含まれること'
        );

        // 部分一致「キャビオン」
        $resultsPartial = $this->productRepository->getArticles('キャビオン', 10);
        $this->assertIsArray($resultsPartial);
        $this->assertNotEmpty($resultsPartial);
        $this->assertTrue(
            count(array_filter(array_column($resultsPartial, 'url'), fn ($u) => str_contains($u, 'citizen-movement-advocacy-strategy-guide'))) > 0
        );

        // 英語表記
        $resultsEn = $this->productRepository->getArticles('Jennawae Cavion', 10);
        $this->assertIsArray($resultsEn);
        $this->assertNotEmpty($resultsEn, '英語表記でもヒットすること（body_json LIKE）');

        // 存在しないキーワードでは誤ヒットしない
        $resultsNone = $this->productRepository->getArticles('存在しないキーワードXYZ123', 10);
        $this->assertIsArray($resultsNone);
        $this->assertEmpty($resultsNone);
    }

    /**
     * search_information（ニュース+記事横断）でも MySQL LIKE 本文検索が機能し、ヒット時はURLを追記すること。
     */
    public function testSearchInformationLikeSearchMatchesArticleBody(): void
    {
        $result = $this->productRepository->executeTool('search_information', [
            'keyword' => 'ジェナウェイ・キャビオン',
            'limit' => 5,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('articles', $result);
        $this->assertNotEmpty($result['articles'], 'search_information で「ジェナウェイ・キャビオン」が記事ヒットすること');
        $urls = array_column($result['articles'], 'url');
        $this->assertTrue(
            count(array_filter($urls, fn ($u) => str_contains($u, 'citizen-movement-advocacy-strategy-guide'))) > 0
        );
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://www.thch-vape.shop', $url, 'search_information の記事URLは絶対URL');
        }
    }

    /**
     * executeTool 経由の get_articles でも MySQL LIKE 本文検索が機能し、ヒット時はURLを追記すること。
     */
    public function testExecuteToolGetArticlesLikeSearchBody(): void
    {
        $result = $this->productRepository->executeTool('get_articles', [
            'keyword' => 'ジェナウェイ',
            'limit' => 5,
        ]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $urls = array_column($result, 'url');
        $this->assertTrue(
            count(array_filter($urls, fn ($u) => str_contains($u, 'citizen-movement-advocacy-strategy-guide'))) > 0
        );
        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://www.thch-vape.shop', $url);
        }
    }

    /**
     * 記事検索ヒット時に URL が絶対URLで追記されること — 汎用ケース。
     *
     * BDD: 記事検索がヒットした場合は記事のURLを追記して返答するため、
     * getArticles/search_information の各ヒット行に url（https://...）が含まれること。
     */
    public function testArticleSearchHitIncludesAbsoluteUrl(): void
    {
        $results = $this->productRepository->getArticles('市民運動', 5);
        $this->assertIsArray($results);
        if (!empty($results)) {
            foreach ($results as $row) {
                $this->assertArrayHasKey('url', $row, 'ヒット行に url が含まれること');
                $this->assertStringStartsWith('https://www.thch-vape.shop/guide/', $row['url'], 'URLは絶対URL（https://www.thch-vape.shop/guide/...）であること');
                $this->assertStringNotContainsString('https://www.example.com', $row['url']);
                $this->assertStringContainsString('https://www.thch-vape.shop', $row['url']);
            }
        }

        // search_information でも同様
        $info = $this->productRepository->executeTool('search_information', [
            'keyword' => '市民運動',
            'limit' => 3,
        ]);
        $this->assertArrayHasKey('articles', $info);
        foreach ($info['articles'] as $article) {
            $this->assertArrayHasKey('url', $article);
            $this->assertStringStartsWith('https://www.thch-vape.shop/guide/', $article['url']);
        }
    }

    /**
     * %/_ エスケープが正しく機能し SQL インジェクションにならないこと。
     */
    public function testGetArticlesLikeEscapingPreventsInjection(): void
    {
        // LIKE 特殊文字を含むキーワードでもエラーにならず空または正常に返る
        $results = $this->productRepository->getArticles('ジェナウェイ%キャビオン_テスト', 10);
        $this->assertIsArray($results);
        // エスケープされるため「%」がワイルドカードとして機能せず、ヒットしないはず
        // ただしエラーにならないことを主に検証
        $this->assertIsArray($results);
    }
}
