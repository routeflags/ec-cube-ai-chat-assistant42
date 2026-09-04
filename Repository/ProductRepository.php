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
use Plugin\AiChatAssistant42\Service\ProductToolDefinition;
use Plugin\AiChatAssistant42\Service\ProductToolExecutor;
use Plugin\AiChatAssistant42\Service\ShopContextService;
use Plugin\AiChatAssistant42\Service\TwigPlainTextExtractor;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RequestContext;
use InvalidArgumentException;

/**
 * AI チャットアシスタント用の商品リポジトリ。
 *
 * EC-CUBE の商品テーブル群を検索し、AI ツールから呼び出されるための
 * フラットな配列データを返す。 Doctrine の QueryBuilder を使い、
 * 全パラメータはバインドパラメータで渡す（SQL インジェクション防止）。
 */
class ProductRepository extends AbstractRepository
{
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
                public function __construct()
                {
                }

                public function get($id = 1)
                {
                    // $id を検証して監査ログに残す（フォールバックでは id=1 のみが正規）
                    if ($id !== 1) {
                        error_log(sprintf('[AiChatAssistant42] Fallback BaseInfoRepository::get unexpected id=%s', (string) $id));
                    }

                    return new class {
                        public function getShopName()
                        {
                            return '';
                        }

                        public function getEmail01()
                        {
                            return '';
                        }

                        public function getEmail02()
                        {
                            return '';
                        }

                        public function getEmail03()
                        {
                            return '';
                        }
                    };
                }
            },
            new RequestStack(),
            new class implements \Symfony\Component\Routing\Generator\UrlGeneratorInterface {
                private RequestContext $storedContext;

                public function __construct()
                {
                    $this->storedContext = new RequestContext();
                }

                public function generate(
                    string $name,
                    array $parameters = [],
                    int $referenceType = self::ABSOLUTE_PATH
                ): string {
                    // パラメータを例外メッセージに含め、デバッグ時の原因特定を容易にする
                    $paramJson = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    error_log(sprintf(
                        '[AiChatAssistant42] Fallback UrlGenerator::generate route=%s params=%s referenceType=%d',
                        $name,
                        $paramJson !== false ? $paramJson : '[]',
                        $referenceType
                    ));

                    throw new RouteNotFoundException(sprintf(
                        'Route "%s" not found (fallback generator, params: %s).',
                        $name,
                        $paramJson !== false ? $paramJson : '[]'
                    ));
                }

                public function getContext(): RequestContext
                {
                    return $this->storedContext;
                }

                public function setContext(RequestContext $context): void
                {
                    $this->storedContext = $context;
                }
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
     * @return array<int, array{id: int, name: string, price: string|null, stock: string|null,
     *               stock_unlimited: bool, description_list: string|null, images: array<int, string>}>
     */
    public function search(string $keyword = '', ?int $categoryId = null, int $limit = 20, int $offset = 0): array
    {
        // DBAL QueryBuilder に統一。LIMIT/OFFSET は setMaxResults/setFirstResult に任せ、
        // LIKE はプレースホルダに % を含めて渡すことで DB 差異を吸収する。
        $qb = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.name', 'pc.price02 AS price', 'ps.stock', 'pc.stock_unlimited', 'p.description_list', 'p.update_date')
            ->from('dtb_product', 'p')
            ->innerJoin('p', 'dtb_product_class', 'pc', 'pc.product_id = p.id')
            ->leftJoin('p', 'dtb_product_stock', 'ps', 'ps.product_class_id = pc.id')
            ->leftJoin('p', 'dtb_product_category', 'pct', 'pct.product_id = p.id')
            ->where('p.product_status_id = 1')
            ->andWhere('pc.visible = 1')
            ->groupBy('p.id', 'pc.price02', 'ps.stock', 'pc.stock_unlimited', 'p.description_list', 'p.update_date')
            ->orderBy('p.update_date', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(min(max(0, $limit), 100))
            ->setFirstResult(max(0, $offset));

        if ($keyword !== '') {
            $likeKeyword = '%' . $keyword . '%';
            $qb->andWhere('(p.name LIKE :kw OR p.search_word LIKE :kw_sw OR pc.product_code LIKE :kw_code)')
                ->setParameter('kw', $likeKeyword)
                ->setParameter('kw_sw', $likeKeyword)
                ->setParameter('kw_code', $likeKeyword);
        }

        if ($categoryId !== null) {
            $qb->andWhere('pct.category_id = :category_id')
                ->setParameter('category_id', $categoryId);
        }

        $products = $qb->executeQuery()->fetchAllAssociative();

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
        $qb = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.name', 'p.description_list', 'p.create_date', 'p.update_date', 'p.product_status_id AS status_id')
            ->from('dtb_product', 'p')
            ->where('p.id = :product_id')
            ->andWhere('p.product_status_id = 1')
            ->setParameter('product_id', $productId);

        $product = $qb->executeQuery()->fetchAssociative();

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
     * @return array<int, array{class_id: int, code: string|null, stock: string|null,
     *               stock_unlimited: bool, price: string|null, class_category1: string|null,
     *               class_category2: string|null}>
     */
    public function getStock(int $productId): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'pc.id AS class_id',
                'pc.product_code AS code',
                'ps.stock',
                'pc.stock_unlimited',
                'pc.price02 AS price',
                'cc1.name AS class_category1',
                'cc2.name AS class_category2'
            )
            ->from('dtb_product_class', 'pc')
            ->leftJoin('pc', 'dtb_product_stock', 'ps', 'ps.product_class_id = pc.id')
            ->leftJoin('pc', 'dtb_class_category', 'cc1', 'cc1.id = pc.class_category_id1')
            ->leftJoin('pc', 'dtb_class_category', 'cc2', 'cc2.id = pc.class_category_id2')
            ->where('pc.product_id = :product_id')
            ->andWhere('pc.visible = 1')
            ->orderBy('pc.id', 'ASC')
            ->setParameter('product_id', $productId);

        $rows = $qb->executeQuery()->fetchAllAssociative();

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
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'c.id',
                'c.category_name AS name',
                'c.hierarchy',
                'c.parent_category_id AS parent_id',
                '(SELECT COUNT(*) FROM dtb_category sub WHERE sub.parent_category_id = c.id) AS children_count'
            )
            ->from('dtb_category', 'c')
            ->orderBy('c.sort_no', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        if ($parentId !== null) {
            $qb->where('c.parent_category_id = :parent_id')
                ->setParameter('parent_id', $parentId);
        }

        if ($parentId === null) {
            $qb->where('c.parent_category_id IS NULL');
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

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
     * @return array<int, array{id: int, name: string, price: string|null, stock: string|null,
     *               stock_unlimited: bool, description_list: string|null, images: array<int, string>}>
     */
    public function getCategoryProducts(int $categoryId, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.name', 'pc.price02 AS price', 'ps.stock', 'pc.stock_unlimited', 'p.description_list')
            ->from('dtb_product', 'p')
            ->innerJoin('p', 'dtb_product_class', 'pc', 'pc.product_id = p.id')
            ->leftJoin('p', 'dtb_product_stock', 'ps', 'ps.product_class_id = pc.id')
            ->innerJoin('p', 'dtb_product_category', 'pct', 'pct.product_id = p.id')
            ->where('p.product_status_id = 1')
            ->andWhere('pc.visible = 1')
            ->andWhere('pct.category_id = :category_id')
            ->setParameter('category_id', $categoryId)
            ->groupBy('p.id', 'pc.price02', 'ps.stock', 'pc.stock_unlimited', 'p.description_list')
            ->orderBy('p.update_date', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(min(max(0, $limit), 100))
            ->setFirstResult(max(0, $offset));

        $products = $qb->executeQuery()->fetchAllAssociative();

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
        $qb = $this->connection->createQueryBuilder()
            ->select('t.id', 't.name')
            ->from('dtb_tag', 't')
            ->orderBy('t.sort_no', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();

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
     * @return array<int, array{id: int, name: string, price: string|null, stock: string|null,
     *               stock_unlimited: bool, description_list: string|null, images: array<int, string>}>
     */
    public function searchByTag(int $tagId, int $limit = 20, int $offset = 0): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.name', 'pc.price02 AS price', 'ps.stock', 'pc.stock_unlimited', 'p.description_list')
            ->from('dtb_product', 'p')
            ->innerJoin('p', 'dtb_product_class', 'pc', 'pc.product_id = p.id')
            ->leftJoin('p', 'dtb_product_stock', 'ps', 'ps.product_class_id = pc.id')
            ->innerJoin('p', 'dtb_product_tag', 'pt', 'pt.product_id = p.id')
            ->where('p.product_status_id = 1')
            ->andWhere('pc.visible = 1')
            ->andWhere('pt.tag_id = :tag_id')
            ->setParameter('tag_id', $tagId)
            ->groupBy('p.id', 'pc.price02', 'ps.stock', 'pc.stock_unlimited', 'p.description_list')
            ->orderBy('p.update_date', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(min(max(0, $limit), 100))
            ->setFirstResult(max(0, $offset));

        $products = $qb->executeQuery()->fetchAllAssociative();

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
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function getToolDefinitions(): array
    {
        return ProductToolDefinition::all();
    }

    /**
     * AI ツール呼び出しを対応するメソッドにルーティングする。
     *
     * @deprecated ProductToolExecutor::execute() を使用すること。互換保持のための委譲ラッパ。
     *
     * @param string $name ツール名
     * @param array  $args ツール引数
     *
     * @return array ツール実行結果
     *
     * @throws InvalidArgumentException 未知のツール名の場合
     */
    public function executeTool(string $name, array $args): array
    {
        return (new ProductToolExecutor($this))->execute($name, $args);
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
        // パラメータを検証しつつ互換性を保つ（未使用扱いを避けるため意味あるガードを行う）
        $limit = max(1, min((int) $limit, 100));

        return [];
    }

    // getArticles は EasyArticle 依存のため完全削除済み（汎用プラグイン化）。
    // 旧 plg_ea_article テーブルが存在しても参照しない。

    /**
     * @deprecated Help は汎用化により廃止（データソースはナレッジと商品のみ）。
     */
    public function searchHelp(string $keyword = '', int $limit = 10): array
    {
        // 将来の復活時に備え、入力を検証して監査しやすくする
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $limit = max(1, min((int) $limit, 100));

        return [];
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

        $qb = $this->connection->createQueryBuilder()
            ->select('page_name', 'url', 'meta_robots', 'file_name')
            ->from('dtb_page')
            ->where('url = :url')
            ->setMaxResults(1)
            ->setParameter('url', $url);

        $row = $qb->executeQuery()->fetchAssociative();

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

        $qb = $this->connection->createQueryBuilder()
            ->select('product_id', 'file_name')
            ->from('dtb_product_image')
            ->where('product_id IN (:product_ids)')
            ->orderBy('sort_no', 'ASC')
            ->setParameter('product_ids', $productIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY);

        $rows = $qb->executeQuery()->fetchAllAssociative();

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
     * @return array<int, array{class_id: int, code: string|null, price: string|null,
     *               class_category1: string|null, class_category2: string|null}>
     */
    private function getProductClasses(int $productId): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select(
                'pc.id AS class_id',
                'pc.product_code AS code',
                'pc.price02 AS price',
                'cc1.name AS class_category1',
                'cc2.name AS class_category2'
            )
            ->from('dtb_product_class', 'pc')
            ->leftJoin('pc', 'dtb_class_category', 'cc1', 'cc1.id = pc.class_category_id1')
            ->leftJoin('pc', 'dtb_class_category', 'cc2', 'cc2.id = pc.class_category_id2')
            ->where('pc.product_id = :product_id')
            ->andWhere('pc.visible = 1')
            ->orderBy('pc.id', 'ASC')
            ->setParameter('product_id', $productId);

        $rows = $qb->executeQuery()->fetchAllAssociative();

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
        $qb = $this->connection->createQueryBuilder()
            ->select('c.id', 'c.category_name AS name', 'c.hierarchy')
            ->from('dtb_product_category', 'pct')
            ->innerJoin('pct', 'dtb_category', 'c', 'c.id = pct.category_id')
            ->where('pct.product_id = :product_id')
            ->orderBy('c.sort_no', 'ASC')
            ->setParameter('product_id', $productId);

        $rows = $qb->executeQuery()->fetchAllAssociative();

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
        $qb = $this->connection->createQueryBuilder()
            ->select('file_name')
            ->from('dtb_product_image')
            ->where('product_id = :product_id')
            ->orderBy('sort_no', 'ASC')
            ->setParameter('product_id', $productId);

        return array_column($qb->executeQuery()->fetchAllAssociative(), 'file_name');
    }

    /**
     * 商品に紐づくタグ情報を取得する。
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function getProductTags(int $productId): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('t.id', 't.name')
            ->from('dtb_product_tag', 'pt')
            ->innerJoin('pt', 'dtb_tag', 't', 't.id = pt.tag_id')
            ->where('pt.product_id = :product_id')
            ->orderBy('t.sort_no', 'ASC')
            ->setParameter('product_id', $productId);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return $rows;
    }
}
