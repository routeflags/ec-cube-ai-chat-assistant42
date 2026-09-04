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

use InvalidArgumentException;
use Plugin\AiChatAssistant42\Repository\ProductRepository;

/**
 * AI ツール実行の Facade。
 *
 * ProductRepository を委譲保持し、ツール名から対応する
 * リポジトリメソッドへディスパッチする責務のみを持つ。
 * DBAL QueryBuilder の詳細はリポジトリ側に留め、
 * サービス層へ漏らさない。
 */
class ProductToolExecutor
{
    public function __construct(
        private ProductRepository $productRepository
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array<mixed>
     */
    public function execute(string $name, array $args): array
    {
        return match ($name) {
            'search_products' => $this->executeSearchProducts($args),
            'get_product_detail' => $this->executeGetProductDetail($args),
            'get_stock' => $this->executeGetStock($args),
            'get_categories' => $this->executeGetCategories($args),
            'get_category_products' => $this->executeGetCategoryProducts($args),
            'get_tags' => $this->executeGetTags(),
            'search_by_tag' => $this->executeSearchByTag($args),
            default => throw new InvalidArgumentException(sprintf('Unknown tool: %s', $name)),
        };
    }

    private function executeSearchProducts(array $args): array
    {
        return $this->productRepository->search(
            $args['keyword'] ?? '',
            isset($args['category_id']) ? (int) $args['category_id'] : null,
            (int) ($args['limit'] ?? 20),
            (int) ($args['offset'] ?? 0)
        );
    }

    private function executeGetProductDetail(array $args): array
    {
        return $this->productRepository->getDetail((int) $args['product_id']) ?? ['error' => 'Product not found'];
    }

    private function executeGetStock(array $args): array
    {
        return $this->productRepository->getStock((int) $args['product_id']);
    }

    private function executeGetCategories(array $args): array
    {
        return $this->productRepository->getCategories(
            isset($args['parent_id']) ? (int) $args['parent_id'] : null
        );
    }

    private function executeGetCategoryProducts(array $args): array
    {
        return $this->productRepository->getCategoryProducts(
            (int) $args['category_id'],
            (int) ($args['limit'] ?? 50),
            (int) ($args['offset'] ?? 0)
        );
    }

    private function executeGetTags(): array
    {
        return $this->productRepository->getTags();
    }

    private function executeSearchByTag(array $args): array
    {
        return $this->productRepository->searchByTag(
            (int) $args['tag_id'],
            (int) ($args['limit'] ?? 20),
            (int) ($args['offset'] ?? 0)
        );
    }
}
