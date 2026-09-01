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

namespace Plugin\AiChatAssistant42\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\AiChatAssistant42\Service\ShopContextService;
use Plugin\AiChatAssistant42\Service\TwigPlainTextExtractor;

/**
 * AI チャットアシスタント用の商品リポジトリ。
 *
 * EC-CUBE の商品テーブル群を検索し、AI ツールから呼び出されるための
 * フラットな配列データを返す。 Doctrine の QueryBuilder を使い、
 * 全パラメータはバインドパラメータで渡す（SQL インジェクション防止）。
 */
class ProductRepository extends AbstractRepository
{
    /**
     * AI ツール定数配列。CC ポイントを増やさずにツールスキーマを管理する。
     *
     * @var array<int, array{type: string, name: string, description: string, input_schema: array}>
     */
    private const TOOL_DEFINITIONS = [
        [
            'type' => 'function',
            'name' => 'search_products',
            'description' => '商品をキーワードとカテゴリで検索します。商品名・検索ワード・商品コードが対象です。返却される各商品の url はショップの商品詳細ページの絶対URLです。相対パスや https://www.example.com は使用しないでください。',
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
            'description' => '商品の詳細情報を取得します。規格・在庫・カテゴリ・画像・タグを含みます。返却される url はショップの商品詳細ページの絶対URLです。相対パスや https://www.example.com は使用しないでください。',
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

    /**
     * ツール名 => ハンドラメソッドのマップ。
     * match 文を置き換え、CC ポイントを削減する。
     *
     * @var array<string, string>
     */
    private const TOOL_HANDLERS = [
        'search_products' => 'executeSearchProducts',
        'get_product_detail' => 'executeGetProductDetail',
        'get_stock' => 'executeGetStock',
        'get_categories' => 'executeGetCategories',
        'get_category_products' => 'executeGetCategoryProducts',
        'get_tags' => 'executeGetTags',
        'search_by_tag' => 'executeSearchByTag',
    ];

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private TwigPlainTextExtractor $textExtractor;
    private ShopContextService $shopContextService;

    public function __construct(
        ManagerRegistry $registry,
        EntityManagerInterface $entityManager,
        ?TwigPlainTextExtractor $textExtractor = null,
        ?ShopContextService $shopContextService = null
    ) {
        parent::__construct($registry, \Eccube\Entity\Product::class);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->textExtractor = $textExtractor ?? new TwigPlainTextExtractor();
        // ShopContextService は EC-CUBE の DI から注入されるが、単体テスト時のフォールバックとして nullable とする
        $this->shopContextService = $shopContextService ?? $this->createFallbackShopContextService();
    }

    private function createFallbackShopContextService(): ShopContextService
    {
        // テスト環境や CLI で RequestStack が無い場合のフォールバック。BaseInfo 由来のショップ名/URL は使えないが、
        // 相対パス生成で最低限動作する。
        return new ShopContextService(
            new class extends \Eccube\Repository\BaseInfoRepository {
                public function __construct() {}
                public function get() { return new class { public function getShopName(){return '';} public function getEmail01(){return '';} public function getEmail02(){return '';} public function getEmail03(){return '';} }; }
            },
            new \Symfony\Component\HttpFoundation\RequestStack(),
            new class implements \Symfony\Component\Routing\Generator\UrlGeneratorInterface {
                public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string { throw new \Symfony\Component\Routing\Exception\RouteNotFoundException(); }
                public function getContext(): \Symfony\Component\Routing\RequestContext { return new \Symfony\Component\Routing\RequestContext(); }
                public function setContext(\Symfony\Component\Routing\RequestContext $context): void {}
            }
        );
    }

    // ================================================================
    //  ツール実装メソッド（AI からの呼び出し想定）
    // ================================================================

    /**
     * 商品をキーワード・カテゴリで検索する。
     *
     * @param string      $keyword  検索キーワード（name / search_word に LIKE 検索）
     * @param int|null    $categoryId カテゴリ ID（NULL なら全カテゴリ対象）
     * @param int         $limit    取得件数上限
     * @param int         $offset   オフセット
     *
     * @return array<int, array{id: int, name: string, price: string|null, stock: string|null, stock_unlimited: bool, description_list: string|null, images: array<int, string>}>
     */
    public function search(string $keyword = '', ?int $categoryId = null, int $limit = 20, int $offset = 0): array
    {
        $sql = <<<'SQL'
            SELECT
                p.id,
                p.name,
                pc.price02 AS price,
                ps.stock,
                pc.stock_unlimited,
                p.description_list,
                p.update_date
            FROM dtb_product p
            INNER JOIN dtb_product_class pc ON pc.product_id = p.id
            LEFT JOIN dtb_product_stock ps ON ps.product_class_id = pc.id
            LEFT JOIN dtb_product_category pct ON pct.product_id = p.id
            WHERE p.product_status_id = 1
              AND pc.visible = 1
        SQL;

        $params = [];
        $sql .= $this->buildKeywordCondition($keyword, $params);
        $sql .= $this->buildCategoryCondition($categoryId, $params);

        $sql .= ' GROUP BY p.id, pc.price02, ps.stock, pc.stock_unlimited, p.description_list, p.update_date';
        $sql .= ' ORDER BY p.update_date DESC, p.id DESC';
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;


        $stmt = $this->connection->executeQuery($sql, $params);
        $products = $stmt->fetchAllAssociative();

        // 画像情報を一括取得して付与
        $productIds = array_column($products, 'id');
        $imagesMap = $this->fetchImagesByProductIds($productIds);

        $this->castProductRows($products, $imagesMap, ['update_date']);

        return $products;
    }

    /**
     * 商品の詳細情報を返す。
     *
     * @param int $productId 商品 ID
     *
     * @return array|null 商品情報（存在しない場合は null）
     */
    public function getDetail(int $productId): ?array
    {
        $sql = <<<'SQL'
            SELECT
                p.id,
                p.name,
                p.description_list,
                p.create_date,
                p.update_date,
                p.product_status_id AS status_id
            FROM dtb_product p
            WHERE p.id = :product_id
              AND p.product_status_id = 1
        SQL;

        $stmt = $this->connection->executeQuery($sql, ['product_id' => $productId]);
        $product = $stmt->fetchAssociative();

        if ($product === false) {
            return null;
        }

        $product['id'] = (int) $product['id'];
        $product['url'] = $this->buildProductUrl((int) $product['id']);
        $product['classes'] = $this->getProductClasses($productId);
        $product['stock'] = $this->getStock($productId);
        $product['categories'] = $this->getProductCategories($productId);
        $product['images'] = $this->getImages($productId);
        $product['tags'] = $this->getProductTags($productId);

        return $product;
    }

    /**
     * 商品規格ごとの在庫情報を返す。
     *
     * @param int $productId 商品 ID
     *
     * @return array<int, array{class_id: int, code: string|null, stock: string|null, stock_unlimited: bool, price: string|null, class_category1: string|null, class_category2: string|null}>
     */
    public function getStock(int $productId): array
    {
        $sql = <<<'SQL'
            SELECT
                pc.id AS class_id,
                pc.product_code AS code,
                ps.stock,
                pc.stock_unlimited,
                pc.price02 AS price,
                cc1.name AS class_category1,
                cc2.name AS class_category2
            FROM dtb_product_class pc
            LEFT JOIN dtb_product_stock ps ON ps.product_class_id = pc.id
            LEFT JOIN dtb_class_category cc1 ON cc1.id = pc.class_category_id1
            LEFT JOIN dtb_class_category cc2 ON cc2.id = pc.class_category_id2
            WHERE pc.product_id = :product_id
              AND pc.visible = 1
            ORDER BY pc.id ASC
        SQL;

        $stmt = $this->connection->executeQuery($sql, ['product_id' => $productId]);
        $rows = $stmt->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['class_id'] = (int) $row['class_id'];
            $row['stock_unlimited'] = (bool) $row['stock_unlimited'];
        }
        unset($row);

        return $rows;
    }

    /**
     * カテゴリ階層を返す。
     * parentId を指定した場合はその子カテゴリのみ、NULL ならルートから全階層。
     *
     * @param int|null $parentId 親カテゴリ ID（NULL ならルート）
     *
     * @return array<int, array{id: int, name: string, hierarchy: int, parent_id: int|null, children_count: int}>
     */
    public function getCategories(?int $parentId = null): array
    {
        $sql = <<<'SQL'
            SELECT
                c.id,
                c.category_name AS name,
                c.hierarchy,
                c.parent_category_id AS parent_id,
                (SELECT COUNT(*) FROM dtb_category sub WHERE sub.parent_category_id = c.id) AS children_count
            FROM dtb_category c
        SQL;

        $params = [];

        if ($parentId !== null) {
            $sql .= ' WHERE c.parent_category_id = :parent_id';
            $params['parent_id'] = $parentId;
        } else {
            // ルート（親なし）のカテゴリのみ取得
            $sql .= ' WHERE c.parent_category_id IS NULL';
        }

        $sql .= ' ORDER BY c.sort_no ASC, c.id ASC';

        $stmt = $this->connection->executeQuery($sql, $params);
        $rows = $stmt->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['hierarchy'] = (int) $row['hierarchy'];
            $row['children_count'] = (int) $row['children_count'];
        }
        unset($row);

        return $rows;
    }

    /**
     * カテゴリに属する商品一覧を返す。
     *
     * @param int $categoryId カテゴリ ID
     * @param int $limit      取得件数上限
     * @param int $offset     オフセット
     *
     * @return array<int, array{id: int, name: string, price: string|null, stock: string|null, stock_unlimited: bool, description_list: string|null, images: array<int, string>}>
     */
    public function getCategoryProducts(int $categoryId, int $limit = 50, int $offset = 0): array
    {
        $sql = <<<'SQL'
            SELECT
                p.id,
                p.name,
                pc.price02 AS price,
                ps.stock,
                pc.stock_unlimited,
                p.description_list
            FROM dtb_product p
            INNER JOIN dtb_product_class pc ON pc.product_id = p.id
            LEFT JOIN dtb_product_stock ps ON ps.product_class_id = pc.id
            INNER JOIN dtb_product_category pct ON pct.product_id = p.id
            WHERE p.product_status_id = 1
              AND pc.visible = 1
              AND pct.category_id = :category_id
            GROUP BY p.id, pc.price02, ps.stock, pc.stock_unlimited, p.description_list
            ORDER BY p.update_date DESC, p.id DESC
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        SQL;

        $stmt = $this->connection->executeQuery($sql, [
            'category_id' => $categoryId,
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $products = $stmt->fetchAllAssociative();

        $productIds = array_column($products, 'id');
        $imagesMap = $this->fetchImagesByProductIds($productIds);

        $this->castProductRows($products, $imagesMap);

        return $products;
    }

    /**
     * 全タグ一覧を返す。
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getTags(): array
    {
        $sql = <<<'SQL'
            SELECT t.id, t.name
            FROM dtb_tag t
            ORDER BY t.sort_no ASC, t.id ASC
        SQL;

        $stmt = $this->connection->executeQuery($sql);
        $rows = $stmt->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return $rows;
    }

    /**
     * 特定タグに紐づく商品を返す。
     *
     * @param int $tagId   タグ ID
     * @param int $limit   取得件数上限
     * @param int $offset  オフセット
     *
     * @return array<int, array{id: int, name: string, price: string|null, stock: string|null, stock_unlimited: bool, description_list: string|null, images: array<int, string>}>
     */
    public function searchByTag(int $tagId, int $limit = 20, int $offset = 0): array
    {
        $sql = <<<'SQL'
            SELECT
                p.id,
                p.name,
                pc.price02 AS price,
                ps.stock,
                pc.stock_unlimited,
                p.description_list
            FROM dtb_product p
            INNER JOIN dtb_product_class pc ON pc.product_id = p.id
            LEFT JOIN dtb_product_stock ps ON ps.product_class_id = pc.id
            INNER JOIN dtb_product_tag pt ON pt.product_id = p.id
            WHERE p.product_status_id = 1
              AND pc.visible = 1
              AND pt.tag_id = :tag_id
            GROUP BY p.id, pc.price02, ps.stock, pc.stock_unlimited, p.description_list
            ORDER BY p.update_date DESC, p.id DESC
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        SQL;

        $stmt = $this->connection->executeQuery($sql, [
            'tag_id' => $tagId,
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $products = $stmt->fetchAllAssociative();

        $productIds = array_column($products, 'id');
        $imagesMap = $this->fetchImagesByProductIds($productIds);

        $this->castProductRows($products, $imagesMap);

        return $products;
    }

    // ================================================================
    //  AI ツール定義
    // ================================================================

    /**
     * Claude / OpenAI 互換のツール定義を返す。
     *
     * @return array<int, array{type: string, name: string, description: string, input_schema: array}>
     */
    public function getToolDefinitions(): array
    {
        return self::TOOL_DEFINITIONS;
    }

    /**
     * AI ツール呼び出しを対応するメソッドにルーティングする。
     *
     * ハンドラメソッドのディスパッチテーブルを使用し、
     * match 文の CC ポイントを削減している。
     *
     * @param string $name ツール名
     * @param array  $args ツール引数
     *
     * @return array ツール実行結果
     *
     * @throws \InvalidArgumentException 未知のツール名の場合
     */
    public function executeTool(string $name, array $args): array
    {
        $methodName = self::TOOL_HANDLERS[$name] ?? null;
        if ($methodName === null) {
            throw new \InvalidArgumentException(sprintf('Unknown tool: %s', $name));
        }

        return $this->$methodName($args);
    }

    // ================================================================
    //  ツールハンドラメソッド（executeTool からディスパッチ）
    // ================================================================

    /**
     * search_products ツールの引数を解析して実行する。
     */
    private function executeSearchProducts(array $args): array
    {
        return $this->search(
            $args['keyword'] ?? '',
            isset($args['category_id']) ? (int) $args['category_id'] : null,
            (int) ($args['limit'] ?? 20),
            (int) ($args['offset'] ?? 0)
        );
    }

    /**
     * get_product_detail ツールの引数を解析して実行する。
     */
    private function executeGetProductDetail(array $args): array
    {
        return $this->getDetail((int) $args['product_id']) ?? ['error' => 'Product not found'];
    }

    /**
     * get_stock ツールの引数を解析して実行する。
     */
    private function executeGetStock(array $args): array
    {
        return $this->getStock((int) $args['product_id']);
    }

    /**
     * get_categories ツールの引数を解析して実行する。
     */
    private function executeGetCategories(array $args): array
    {
        return $this->getCategories(
            isset($args['parent_id']) ? (int) $args['parent_id'] : null
        );
    }

    /**
     * get_category_products ツールの引数を解析して実行する。
     */
    private function executeGetCategoryProducts(array $args): array
    {
        return $this->getCategoryProducts(
            (int) $args['category_id'],
            (int) ($args['limit'] ?? 50),
            (int) ($args['offset'] ?? 0)
        );
    }

    /**
     * get_tags ツールを実行する（引数なし）。
     */
    private function executeGetTags(array $args): array
    {
        return $this->getTags();
    }

    /**
     * search_by_tag ツールの引数を解析して実行する。
     */
    private function executeSearchByTag(array $args): array
    {
        return $this->searchByTag(
            (int) $args['tag_id'],
            (int) ($args['limit'] ?? 20),
            (int) ($args['offset'] ?? 0)
        );
    }

    /**
     * @deprecated News は汎用化により廃止（データソースはナレッジと商品のみ）。
     */
    private function executeGetNews(array $args): array
    {
        return [];
    }

    /**
     * @deprecated Help は汎用化により廃止。
     */
    private function executeSearchHelp(array $args): array
    {
        return [];
    }

    /**
     * @deprecated Help は汎用化により廃止。
     */
    private function executeGetHelpDetail(array $args): array
    {
        return ['error' => 'Help page not found (deprecated)'];
    }

    // ================================================================
    //  静的ページ（ヘルプ）・ニュース・記事 取得ロジック
    // ================================================================

    /**
     * 最新のお知らせを取得する。
     *
     * @deprecated News は汎用化により廃止（データソースはナレッジと商品のみ）。
     */
    public function getNews(int $limit = 10): array
    {
        return [];
    }

    // getArticles は EasyArticle 依存のため完全削除済み（汎用プラグイン化）。
    // 旧 plg_ea_article テーブルが存在しても参照しない。

    /**
     * @deprecated Help は汎用化により廃止（データソースはナレッジと商品のみ）。
     */
    public function searchHelp(string $keyword = '', int $limit = 10): array
    {
        return [];
    }



    /**
     * 検索結果に help_guide が含まれているか判定する。
     */
    private function isHelpGuideInResults(array $results): bool
    {
        foreach ($results as $row) {
            if (($row['url'] ?? '') === 'help_guide') {
                return true;
            }
        }

        return false;
    }

    /**
     * help_guide（Help/guide.twig）のファイル内容がキーワードを含むか判定する。
     *
     * DBカラムに含まれない FAQ 本文（例: 二日酔い、カンナビノイド）を補完するための
     * ファイル内容検索。Twig/HTMLをプレーンテキスト化して部分一致で判定する。
     */
    private function doesHelpGuideContainKeyword(string $keyword): bool
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return false;
        }

        $text = $this->getHelpGuidePlainTextForSearch();
        if ($text === '') {
            return false;
        }

        // 完全一致（部分一致）の直接検索 — 「カンナビノイドの二日酔いを抑える方法はありますか？」等は
        // ファイル内に「Q. カンナビノイドの二日酔いを抑える方法はありますか？」として存在するため
        // 正規化せず直接 stripos でもヒットするが、記号差異に対応するため正規化も試す
        if (mb_stripos($text, $keyword) !== false) {
            return true;
        }

        // 正規化して再試行（全角/半角、記号除去、空白除去）
        $normalizedKeyword = $this->normalizeKeywordForFaqSearch($keyword);
        $normalizedText = $this->normalizeKeywordForFaqSearch($text);
        if ($normalizedKeyword !== '' && mb_strpos($normalizedText, $normalizedKeyword) !== false) {
            return true;
        }

        // トークン分割して FAQ 固有語が含まれているかチェック
        // 例: 「二日酔い」「カンナビノイド」「白玉点滴」「グルタチオン」等が help_guide FAQ に存在
        $tokens = preg_split('/[\s\p{P}]+/u', $keyword);
        if ($tokens === false) {
            return false;
        }

        foreach ($tokens as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 2) {
                continue;
            }
            // 2文字以上のトークンが FAQ 本文に含まれていれば help_guide 関連とみなす
            // ただし一般的すぎる語（「方法」「ありますか」等）は除外せず、FAQ候補として扱う
            if (mb_stripos($text, $token) !== false) {
                // 少なくとも1つのトークンがヒットし、かつそのトークンが FAQ らしい語であれば補完対象
                // ここでは「二日酔い」「カンナビノイド」「妊活」「梱包」等の FAQ 語が含まれていれば優先
                return true;
            }
        }

        return false;
    }

    /**
     * FAQ 検索用のキーワード正規化（小文字化、全角半角、記号除去）。
     */
    private function normalizeKeywordForFaqSearch(string $keyword): string
    {
        $keyword = mb_strtolower($keyword);
        // 記号・空白を除去（「Q.」「？」「。」「、」等）
        $keyword = preg_replace('/[\s\p{P}\p{S}]+/u', '', $keyword) ?? $keyword;

        return $keyword;
    }

    /**
     * help_guide のプレーンテキストを取得（キャッシュなし、毎回読み込み）。
     * 検索用に軽量に取得する。
     */
    private function getHelpGuidePlainTextForSearch(): string
    {
        // dtb_page から file_name を取得せず、既知のパスを直接参照して高速化
        $projectDir = dirname(__DIR__, 3);
        $candidates = [
            $projectDir . '/app/template/default/Help/guide.twig',
            $projectDir . '/src/Eccube/Resource/template/default/Help/guide.twig',
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

        return $this->htmlToPlainText($raw);
    }

    /**
     * help_guide の dtb_page 行を取得する。
     *
     * @return array{id: int, page_name: string|null, url: string, meta_robots: string|null}|null
     */
    private function fetchHelpGuideRow(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, page_name, url, meta_robots FROM dtb_page WHERE url = :url LIMIT 1',
            ['url' => 'help_guide']
        );

        if ($row === false) {
            return null;
        }

        return $row;
    }

    /**
     * 静的ページの詳細を url で取得する。
     *
     * @return array{page_name: string|null, url: string, meta_robots: string|null, content: string}|null
     */
    public function getHelpDetail(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT page_name, url, meta_robots, file_name FROM dtb_page WHERE url = :url LIMIT 1',
            ['url' => $url]
        );

        if ($row === false) {
            return null;
        }

        $textContent = $this->resolveHelpContentText($row['file_name'] ?? null);

        return [
            'page_name' => $row['page_name'],
            'url' => $row['url'],
            'meta_robots' => $row['meta_robots'],
            'content' => $textContent,
        ];
    }

    /**
     * 記事行の slug を url に変換する共通ヘルパー。
     *
     * URL はヒット時に必ず追記して返答するため、絶対URL（/guide/{category}/{slug}）で返す。
     * カテゴリが取得できない場合は /guide/{slug} にフォールバックする。
     *
     * @param array<int, array<string, mixed>> $rows
     *
    // formatArticleRows / buildArticleUrl は EasyArticle 依存のため完全削除済み。

    /**
     * ヘルプページの twig ファイルからテキストを抽出する。
     */
    private function resolveHelpContentText(?string $fileName): string
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

        return $this->htmlToPlainText($raw);
    }

    /**
     * HTML/Twig 文字列からプレーンテキストを抽出する。
     * 共通ヘルパー TwigPlainTextExtractor に委譲する。
     */
    private function htmlToPlainText(string $html): string
    {
        return $this->textExtractor->extract($html);
    }

    /**
     * LIKE 検索用のキーワードをエスケープし、前後に % を付与する。
     */
    private function escapeLikeKeyword(string $keyword): string
    {
        return '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
    }

    // ================================================================
    //  SQL ビルダー（search() の条件分岐を分離して CC を削減）
    // ================================================================

    /**
     * キーワード検索条件の SQL フラグメントとバインドパラメータを構築する。
     *
     * @param string   $keyword  検索キーワード
     * @param array    $params   バインドパラメータ（参照渡しで追加）
     *
     * @return string SQL フラグメント（空文字列なら条件なし）
     */
    private function buildKeywordCondition(string $keyword, array &$params): string
    {
        if ($keyword === '') {
            return '';
        }

        $escapedKeyword = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';

        $params['keyword'] = $escapedKeyword;
        $params['keyword_sw'] = $escapedKeyword;
        $params['keyword_code'] = $escapedKeyword;

        // MySQL と SQLite で ESCAPE の扱いが異なるため、DB によって分岐
        // MySQL: ESCAPE '\\'（2文字）、SQLite: ESCAPE '\'（1文字）
        $platform = strtolower($this->connection->getDatabasePlatform()->getName());
        $isSqlite = str_contains($platform, 'sqlite');
        $escape = $isSqlite ? "'\\'" : "'\\\\'";

        return " AND (p.name LIKE :keyword ESCAPE {$escape} OR p.search_word LIKE :keyword_sw ESCAPE {$escape} OR pc.product_code LIKE :keyword_code ESCAPE {$escape})";
    }

    /**
     * カテゴリフィルターの SQL フラグメントとバインドパラメータを構築する。
     *
     * @param int|null $categoryId カテゴリ ID
     * @param array    $params     バインドパラメータ（参照渡しで追加）
     *
     * @return string SQL フラグメント（空文字列なら条件なし）
     */
    private function buildCategoryCondition(?int $categoryId, array &$params): string
    {
        if ($categoryId === null) {
            return '';
        }

        $params['category_id'] = $categoryId;

        return ' AND pct.category_id = :category_id';
    }

    // ================================================================
    //  行キャスト共通ヘルパー（重複 foreach を排除して CC を削減）
    // ================================================================

    /**
     * 商品行配列の型変換と画像付与を行う。
     *
     * search / getCategoryProducts / searchByTag の3メソッドで共通する
     * foreach ループを1箇所に集約し、クラス全体の CC を削減する。
     *
     * @param array<int, array<string, mixed>> $products  商品行（参照渡しで変更）
     * @param array<int, array<int, string>>   $imagesMap 商品 ID => 画像ファイル名
     * @param array<int, string>               $removeKeys 除去するキー（例: update_date）
     */
    private function castProductRows(array &$products, array $imagesMap, array $removeKeys = []): void
    {
        foreach ($products as &$product) {
            $productId = (int) $product['id'];
            $product['id'] = $productId;
            $product['url'] = $this->buildProductUrl($productId);
            $product['price'] = $product['price'] ?? null;
            $product['stock'] = $product['stock'] ?? null;
            $product['stock_unlimited'] = (bool) $product['stock_unlimited'];
            $product['description_list'] = $product['description_list'] ?? null;
            $product['images'] = $imagesMap[$productId] ?? [];

            foreach ($removeKeys as $key) {
                unset($product[$key]);
            }
        }
        unset($product);
    }

    /**
     * 商品IDから絶対URLを生成する。汎用化のため ShopContextService に委譲する。
     */
    private function buildProductUrl(int $productId): string
    {
        return $this->shopContextService->getProductDetailUrl($productId);
    }

    // ================================================================
    //  内部ヘルパーメソッド
    // ================================================================

    /**
     * 複数商品 ID の画像ファイル名を一括取得する。
     *
     * @param array<int, int> $productIds
     *
     * @return array<int, array<int, string>> 商品 ID => 画像ファイル名の配列
     */
    private function fetchImagesByProductIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $sql = sprintf(
            'SELECT product_id, file_name FROM dtb_product_image WHERE product_id IN (%s) ORDER BY sort_no ASC',
            $placeholders
        );

        $stmt = $this->connection->executeQuery($sql, $productIds);
        $rows = $stmt->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $map[$pid][] = $row['file_name'];
        }

        return $map;
    }

    /**
     * 商品の規格クラス情報を取得する。
     *
     * @return array<int, array{class_id: int, code: string|null, price: string|null, class_category1: string|null, class_category2: string|null}>
     */
    private function getProductClasses(int $productId): array
    {
        $sql = <<<'SQL'
            SELECT
                pc.id AS class_id,
                pc.product_code AS code,
                pc.price02 AS price,
                cc1.name AS class_category1,
                cc2.name AS class_category2
            FROM dtb_product_class pc
            LEFT JOIN dtb_class_category cc1 ON cc1.id = pc.class_category_id1
            LEFT JOIN dtb_class_category cc2 ON cc2.id = pc.class_category_id2
            WHERE pc.product_id = :product_id
              AND pc.visible = 1
            ORDER BY pc.id ASC
        SQL;

        $stmt = $this->connection->executeQuery($sql, ['product_id' => $productId]);
        $rows = $stmt->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['class_id'] = (int) $row['class_id'];
        }
        unset($row);

        return $rows;
    }

    /**
     * 商品に紐づくカテゴリ情報を取得する。
     *
     * @return array<int, array{id: int, name: string, hierarchy: int}>
     */
    private function getProductCategories(int $productId): array
    {
        $sql = <<<'SQL'
            SELECT
                c.id,
                c.category_name AS name,
                c.hierarchy
            FROM dtb_product_category pct
            INNER JOIN dtb_category c ON c.id = pct.category_id
            WHERE pct.product_id = :product_id
            ORDER BY c.sort_no ASC
        SQL;

        $stmt = $this->connection->executeQuery($sql, ['product_id' => $productId]);
        $rows = $stmt->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['hierarchy'] = (int) $row['hierarchy'];
        }
        unset($row);

        return $rows;
    }

    /**
     * 商品に紐づく画像ファイル名を取得する。
     *
     * @return array<int, string>
     */
    private function getImages(int $productId): array
    {
        $sql = <<<'SQL'
            SELECT file_name
            FROM dtb_product_image
            WHERE product_id = :product_id
            ORDER BY sort_no ASC
        SQL;

        $stmt = $this->connection->executeQuery($sql, ['product_id' => $productId]);

        return array_column($stmt->fetchAllAssociative(), 'file_name');
    }

    /**
     * 商品に紐づくタグ情報を取得する。
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function getProductTags(int $productId): array
    {
        $sql = <<<'SQL'
            SELECT t.id, t.name
            FROM dtb_product_tag pt
            INNER JOIN dtb_tag t ON t.id = pt.tag_id
            WHERE pt.product_id = :product_id
            ORDER BY t.sort_no ASC
        SQL;

        $stmt = $this->connection->executeQuery($sql, ['product_id' => $productId]);
        $rows = $stmt->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return $rows;
    }
}
