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
}
