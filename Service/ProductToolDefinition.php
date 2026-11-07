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
     * 全ツール共通の安全注釈。
     *
     * 本サーバーは店舗の商品カタログを読むだけで、状態を変えない。
     * 外部ネットワークも参照しない（自店舗 DB の閉じた範囲）。
     * ChatGPT / Claude の審査では、この注釈が実挙動と一致していることが確認される。
     */
    private const READ_ONLY_ANNOTATIONS = [
        'readOnlyHint' => true,
        'destructiveHint' => false,
        'openWorldHint' => false,
    ];

    /**
     * @var array<int, array{type: string, name: string, title: string, description: string, annotations: array{readOnlyHint: bool, destructiveHint: bool, openWorldHint: bool}, input_schema: array}>
     */
    private const DEFINITIONS = [
        [
            'type' => 'function',
            'name' => 'search_products',
            'title' => '商品を検索する',
            'description' => '商品名・検索ワード・商品コードを対象にキーワード検索し、カテゴリでも絞り込めます。'
                . '価格は税抜価格です（EC-CUBE の price02 は税抜保存）。'
                . '各商品の url は、ショップの商品詳細ページの絶対 URL です。',
            'annotations' => self::READ_ONLY_ANNOTATIONS,
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
            'title' => '商品の詳細を取得する',
            'description' => '1 商品の詳細を返します。規格・在庫・カテゴリ・画像・タグを含みます。'
                . '価格は税抜価格です（EC-CUBE の price02 は税抜保存）。'
                . 'url は、ショップの商品詳細ページの絶対 URL です。',
            'annotations' => self::READ_ONLY_ANNOTATIONS,
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
            'title' => '在庫を確認する',
            'description' => '商品 ID に対する規格（product_class）ごとの在庫数を返します。'
                . '価格は税抜価格です。',
            'annotations' => self::READ_ONLY_ANNOTATIONS,
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
            'title' => 'カテゴリ一覧を取得する',
            'description' => 'カテゴリ階層を返します。parent_id を指定すると、その子カテゴリのみを返します。',
            'annotations' => self::READ_ONLY_ANNOTATIONS,
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
            'title' => 'カテゴリの商品を取得する',
            'description' => '指定カテゴリに属する商品一覧を返します。'
                . '価格は税抜価格です。各商品の url は、ショップの商品詳細ページの絶対 URL です。',
            'annotations' => self::READ_ONLY_ANNOTATIONS,
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
            'title' => 'タグ一覧を取得する',
            'description' => '登録されている全タグを返します。',
            'annotations' => self::READ_ONLY_ANNOTATIONS,
            'input_schema' => ['type' => 'object', 'properties' => []],
        ],
        [
            'type' => 'function',
            'name' => 'search_by_tag',
            'title' => 'タグの商品を検索する',
            'description' => '指定タグに紐づく商品一覧を返します。'
                . '価格は税抜価格です。各商品の url は、ショップの商品詳細ページの絶対 URL です。',
            'annotations' => self::READ_ONLY_ANNOTATIONS,
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
     * @return array<int, array{type: string, name: string, title: string, description: string, annotations: array{readOnlyHint: bool, destructiveHint: bool, openWorldHint: bool}, input_schema: array}>
     */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }
}
