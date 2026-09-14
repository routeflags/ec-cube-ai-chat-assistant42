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

use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Service\PageContextService;

/**
 * PageContextService の単体テスト。
 *
 * BDD: 初回送信時の page_context を検証・正規化し、
 * システムプロンプト用の閲覧ページブロックを組み立てること。
 */
class PageContextServiceTest extends TestCase
{
    private PageContextService $service;

    protected function setUp(): void
    {
        $this->service = new PageContextService();
    }

    // ================================================================
    //  parse — 正常系
    // ================================================================

    public function testParseValidContext(): void
    {
        $result = $this->service->parse([
            'url' => '/products/detail/123',
            'title' => 'テスト商品 | ショップ',
            'product_id' => 123,
        ]);

        $this->assertSame('/products/detail/123', $result['url']);
        $this->assertSame('テスト商品 | ショップ', $result['title']);
        $this->assertSame(123, $result['product_id']);
    }

    public function testParseAcceptsStringProductId(): void
    {
        $result = $this->service->parse(['product_id' => '42']);

        $this->assertSame(42, $result['product_id']);
    }

    public function testParsePartialContext(): void
    {
        $result = $this->service->parse(['url' => '/cart']);

        $this->assertSame(['url' => '/cart'], $result);
    }

    // ================================================================
    //  parse — 異常系（null を返し、チャットを止めない）
    // ================================================================

    public function testParseNonArrayReturnsNull(): void
    {
        $this->assertNull($this->service->parse(null));
        $this->assertNull($this->service->parse('string'));
        $this->assertNull($this->service->parse(123));
    }

    public function testParseEmptyArrayReturnsNull(): void
    {
        $this->assertNull($this->service->parse([]));
    }

    public function testParseIgnoresInvalidFields(): void
    {
        $result = $this->service->parse([
            'url' => '',
            'title' => 12345,
            'product_id' => 0,
        ]);

        $this->assertNull($result);
    }

    public function testParseRejectsNonNumericProductId(): void
    {
        $result = $this->service->parse([
            'url' => '/products/detail/abc',
            'product_id' => 'abc',
        ]);

        $this->assertSame(['url' => '/products/detail/abc'], $result);
        $this->assertArrayNotHasKey('product_id', $result);
    }

    // ================================================================
    //  parse — プライバシーと上限
    // ================================================================

    public function testParseStripsUrlHash(): void
    {
        // hash 断片はセッショントークン等を含みうるため除去する
        $result = $this->service->parse(['url' => '/products/detail/1#token=secret']);

        $this->assertSame('/products/detail/1', $result['url']);
    }

    public function testParseTruncatesLongValues(): void
    {
        $result = $this->service->parse([
            'url' => '/' . str_repeat('a', 600),
            'title' => str_repeat('あ', 300),
        ]);

        $this->assertSame(PageContextService::MAX_URL_LENGTH, mb_strlen($result['url']));
        $this->assertSame(PageContextService::MAX_TITLE_LENGTH, mb_strlen($result['title']));
    }

    // ================================================================
    //  buildBlock — 商品あり
    // ================================================================

    public function testBuildBlockWithProduct(): void
    {
        $block = $this->service->buildBlock(
            ['url' => '/products/detail/123', 'title' => 'テスト商品', 'product_id' => 123],
            [
                'id' => 123,
                'name' => 'テスト商品',
                'description_list' => '美味しいテスト商品です。',
                'classes' => [
                    ['price' => '1100'],
                    ['price' => '1650'],
                ],
                'stock' => [
                    ['stock_unlimited' => false, 'stock' => 5],
                ],
            ]
        );

        $this->assertStringContainsString('/products/detail/123', $block);
        $this->assertStringContainsString('テスト商品 (ID: 123)', $block);
        $this->assertStringContainsString('1,100円（税抜）〜1,650円（税抜）', $block);
        $this->assertStringContainsString('在庫: あり', $block);
        $this->assertStringContainsString('美味しいテスト商品です。', $block);
        $this->assertStringContainsString('指示語', $block);
    }

    public function testBuildBlockDoesNotExposeStockCount(): void
    {
        // 正確な在庫数は出力しない（あり/なしのみ）
        $block = $this->service->buildBlock(
            ['product_id' => 7],
            [
                'id' => 7,
                'name' => '在庫商品',
                'classes' => [],
                'stock' => [['stock_unlimited' => false, 'stock' => 42]],
            ]
        );

        $this->assertStringNotContainsString('42', $block);
        $this->assertStringContainsString('在庫: あり', $block);
    }

    public function testBuildBlockOutOfStock(): void
    {
        $block = $this->service->buildBlock(
            ['product_id' => 8],
            [
                'id' => 8,
                'name' => '売り切れ商品',
                'classes' => [['price' => '500']],
                'stock' => [['stock_unlimited' => false, 'stock' => 0]],
            ]
        );

        $this->assertStringContainsString('500円（税抜）', $block);
        $this->assertStringContainsString('在庫: なし', $block);
    }

    public function testBuildBlockTruncatesLongDescription(): void
    {
        $block = $this->service->buildBlock(
            ['product_id' => 9],
            [
                'id' => 9,
                'name' => '長い説明の商品',
                'classes' => [],
                'stock' => [],
                'description_list' => str_repeat('あ', 500),
            ]
        );

        // 説明は MAX_DESCRIPTION_LENGTH で切り詰められる
        $this->assertStringContainsString(str_repeat('あ', PageContextService::MAX_DESCRIPTION_LENGTH), $block);
        $this->assertStringNotContainsString(str_repeat('あ', PageContextService::MAX_DESCRIPTION_LENGTH + 1), $block);
    }

    // ================================================================
    //  buildBlock — 商品なし
    // ================================================================

    public function testBuildBlockWithoutProduct(): void
    {
        $block = $this->service->buildBlock(['url' => '/cart', 'title' => 'カート'], null);

        $this->assertStringContainsString('/cart', $block);
        $this->assertStringContainsString('カート', $block);
        $this->assertStringNotContainsString('閲覧中の商品:', $block);
    }

    public function testBuildBlockProductNotFound(): void
    {
        // product_id はあるが商品取得不可の場合、その旨を明示する
        $block = $this->service->buildBlock(['product_id' => 999], null);

        $this->assertStringContainsString('999', $block);
        $this->assertStringContainsString('取得できませんでした', $block);
    }
}
