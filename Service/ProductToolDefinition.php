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

/**
 * AI ツール定義の値オブジェクト。
 *
 * ProductRepository からツールスキーマを分離し、
 * 重複定義を排除して単一の正本とする。
 * Claude / OpenAI 互換のツール定義を返す。
 */
final class ProductToolDefinition
{
    /**
     * @var array<int, array{type: string, name: string, description: string, input_schema: array}>
     */
    private const DEFINITIONS = [
        [
            'type' => 'function',
            'name' => 'search_products',
            'description' => '商品をキーワードとカテゴリで検索します。'
                . '商品名・検索ワード・商品コードが対象です。'
                . '返却される各商品の url はショップの商品詳細ページの絶対URLです。'
                . '相対パスや https://www.example.com は使用しないでください。',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'keyword' => ['type' => 'string', 'description' => '検索キーワード'],
                    'category_id' => ['type' => 'integer', 'description' => 'カテゴリ ID'],
                    'limit' => ['type' => 'integer', 'description' => '取得件数上限（デフォルト: 20）', 'default' => 20],
                    'offset' => ['type' => 'integer', 'description' => 'オフセット（デフォルト: 0）', 'default' => 0],
                ],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_product_detail',
            'description' => '商品の詳細情報を取得します。規格・在庫・カテゴリ・画像・タグを含みます。'
                . '返却される url はショップの商品詳細ページの絶対URLです。'
                . '相対パスや https://www.example.com は使用しないでください。',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => '商品 ID'],
                ],
                'required' => ['product_id'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_stock',
            'description' => '商品の規格ごとの在庫情報を取得します。返却される在庫情報と紐づく商品URLはショップの商品詳細ページの絶対URLで案内してください。相対パスや https://www.example.com は使用しないでください。',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => '商品 ID'],
                ],
                'required' => ['product_id'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_categories',
            'description' => 'カテゴリ階層を取得します。親カテゴリ ID を指定すると子カテゴリのみ返します。',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'parent_id' => ['type' => 'integer', 'description' => '親カテゴリ ID（省略時はルートカテゴリ）'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_category_products',
            'description' => '指定カテゴリに属する商品一覧を取得します。返却される各商品の url はショップの商品詳細ページの絶対URLです。相対パスや https://www.example.com は使用しないでください。',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'category_id' => ['type' => 'integer', 'description' => 'カテゴリ ID'],
                    'limit' => ['type' => 'integer', 'description' => '取得件数上限（デフォルト: 50）', 'default' => 50],
                    'offset' => ['type' => 'integer', 'description' => 'オフセット（デフォルト: 0）', 'default' => 0],
                ],
                'required' => ['category_id'],
            ],
        ],
        [
            'type' => 'function',
            'name' => 'get_tags',
            'description' => '全タグ一覧を取得します。',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ],
        [
            'type' => 'function',
            'name' => 'search_by_tag',
            'description' => '特定タグに紐づく商品を検索します。返却される各商品の url はショップの商品詳細ページの絶対URLです。相対パスや https://www.example.com は使用しないでください。',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'tag_id' => ['type' => 'integer', 'description' => 'タグ ID'],
                    'limit' => ['type' => 'integer', 'description' => '取得件数上限（デフォルト: 20）', 'default' => 20],
                    'offset' => ['type' => 'integer', 'description' => 'オフセット（デフォルト: 0）', 'default' => 0],
                ],
                'required' => ['tag_id'],
            ],
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @return array<int, array{type: string, name: string, description: string, input_schema: array}>
     */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }
}
