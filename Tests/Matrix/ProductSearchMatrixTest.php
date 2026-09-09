<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Tests\Matrix;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Repository\ProductRepository;

/**
 * 商品検索の DB マトリクステスト。
 *
 * 1つのテストコードを MySQL / PostgreSQL / SQLite の3種で実行する。
 * 各 DB への接続は環境変数で上書きできる。到達不能な DB は skip する。
 *
 * - MATRIX_MYSQL_URL  (default: mysql://root@127.0.0.1:3306/matrix_test)
 * - MATRIX_PGSQL_URL  (default: pgsql://<USER>@127.0.0.1:5432/matrix_test)
 * - MATRIX_SQLITE_PATH (default: /tmp/matrix_ai_chat_test.db)
 *
 * カオスモード: MATRIX_CHAOS=1 で到達可能な DB からランダムに1つだけ選ぶ。
 * 再現用に MATRIX_SEED を指定できる。選ばれた DB と seed は出力に残る。
 *
 * 実行: php vendor/bin/phpunit Tests/Matrix
 *       MATRIX_CHAOS=1 php vendor/bin/phpunit Tests/Matrix
 * 起動/停止は Makefile (make db-up / make db-down) を使う。
 */
class ProductSearchMatrixTest extends TestCase
{
    /** @var array<string, bool> DSN ごとの seed 済みフラグ */
    private static array $seeded = [];

    /** @var array<string, array<string, int>> DBラベルごとの name => id マップ */
    private static array $ids = [];

    /**
     * @return iterable<string, array{string, array}>
     */
    public function provideDatabases(): iterable
    {
        $candidates = [
            'sqlite' => ['url' => 'sqlite:///' . (getenv('MATRIX_SQLITE_PATH') ?: '/tmp/matrix_ai_chat_test.db')],
            'mysql' => ['url' => getenv('MATRIX_MYSQL_URL') ?: 'mysql://root@127.0.0.1:3306/matrix_test'],
            'pgsql' => ['url' => getenv('MATRIX_PGSQL_URL') ?: sprintf('pgsql://%s@127.0.0.1:5432/matrix_test', getenv('USER') ?: 'postgres')],
        ];

        // DB が無いと到達確認自体が失敗するため、先に作成する
        try {
            $this->ensureMysqlDatabase($candidates['mysql']['url']);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("[matrix] mysql setup failed: %s\n", substr($e->getMessage(), 0, 120)));
        }
        try {
            $this->ensurePgsqlDatabase($candidates['pgsql']['url']);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("[matrix] pgsql setup failed: %s\n", substr($e->getMessage(), 0, 120)));
        }

        $reachable = [];
        foreach ($candidates as $label => $params) {
            try {
                $conn = DriverManager::getConnection($params);
                $conn->connect();
                $conn->close();
                $reachable[$label] = $params;
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf("[matrix] skip %s: %s\n", $label, substr($e->getMessage(), 0, 120)));
            }
        }

        if ($reachable === []) {
            $this->markTestSkipped('No reachable database. Start services with `make db-up`.');
        }

        if (getenv('MATRIX_CHAOS') === '1') {
            $seed = getenv('MATRIX_SEED') !== false ? (int) getenv('MATRIX_SEED') : random_int(0, PHP_INT_MAX);
            mt_srand($seed);
            $labels = array_keys($reachable);
            $picked = $labels[mt_rand(0, count($labels) - 1)];
            fwrite(STDERR, sprintf("[matrix][chaos] seed=%d picked=%s (of: %s)\n", $seed, $picked, implode(',', $labels)));
            yield $picked => [$picked, $reachable[$picked]];
            return;
        }

        foreach ($reachable as $label => $params) {
            yield $label => [$label, $params];
        }
    }

    private function repositoryFor(string $label, array $params): ProductRepository
    {
        $this->seedDatabase($label, $params);
        $conn = DriverManager::getConnection($params);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $registry = $this->createMock(ManagerRegistry::class);

        return new ProductRepository($registry, $em);
    }

    private function seedDatabase(string $label, array $params): void
    {
        $key = $label . '|' . $params['url'];
        if (isset(self::$seeded[$key])) {
            return;
        }

        if ($label === 'sqlite') {
            $path = preg_replace('#^sqlite:///#', '', $params['url']);
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
        if ($label === 'mysql') {
            $this->ensureMysqlDatabase($params['url']);
        }
        if ($label === 'pgsql') {
            $this->ensurePgsqlDatabase($params['url']);
        }

        $conn = DriverManager::getConnection($params);
        $platform = strtolower($conn->getDatabasePlatform()->getName());
        $autoId = str_contains($platform, 'mysql') ? 'INT AUTO_INCREMENT PRIMARY KEY'
            : (str_contains($platform, 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'SERIAL PRIMARY KEY');

        foreach (['dtb_product_image', 'dtb_product_tag', 'dtb_tag', 'dtb_product_category', 'dtb_category', 'dtb_product_stock', 'dtb_product_class', 'dtb_class_category', 'dtb_product'] as $table) {
            $conn->executeStatement("DROP TABLE IF EXISTS {$table}");
        }

        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_product (
                id {$autoId},
                name VARCHAR(255) NOT NULL,
                search_word TEXT NULL,
                description_list TEXT NULL,
                create_date TIMESTAMP NULL,
                update_date TIMESTAMP NULL,
                product_status_id INT NOT NULL DEFAULT 1
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_product_class (
                id {$autoId},
                product_id INT NOT NULL,
                product_code VARCHAR(255) NULL,
                price02 DECIMAL(12,2) NULL,
                visible BOOLEAN NOT NULL DEFAULT TRUE,
                stock_unlimited BOOLEAN NOT NULL DEFAULT FALSE,
                class_category_id1 INT NULL,
                class_category_id2 INT NULL
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_product_stock (
                product_class_id INT NOT NULL PRIMARY KEY,
                stock INT NULL
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_product_category (
                id {$autoId},
                product_id INT NOT NULL,
                category_id INT NOT NULL
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_product_tag (
                id {$autoId},
                product_id INT NOT NULL,
                tag_id INT NOT NULL
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_product_image (
                id {$autoId},
                product_id INT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                sort_no INT NOT NULL DEFAULT 0
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_class_category (
                id {$autoId},
                name VARCHAR(255) NOT NULL
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_category (
                id {$autoId},
                category_name VARCHAR(255) NOT NULL,
                hierarchy INT NOT NULL DEFAULT 1,
                parent_category_id INT NULL,
                sort_no INT NOT NULL DEFAULT 0
            )
            SQL);
        $conn->executeStatement(<<<SQL
            CREATE TABLE dtb_tag (
                id {$autoId},
                name VARCHAR(255) NOT NULL,
                sort_no INT NOT NULL DEFAULT 0
            )
            SQL);

        // マスタ: 規格分類・カテゴリ階層・タグ（sort_no 順を検証するため意図的に逆順登録）
        $conn->insert('dtb_class_category', ['name' => 'S']);
        $conn->insert('dtb_class_category', ['name' => 'M']);
        $conn->insert('dtb_category', ['id' => 10, 'category_name' => '食品', 'hierarchy' => 1, 'parent_category_id' => null, 'sort_no' => 1]);
        $conn->insert('dtb_category', ['id' => 11, 'category_name' => '菓子', 'hierarchy' => 2, 'parent_category_id' => 10, 'sort_no' => 2]);
        $conn->insert('dtb_category', ['id' => 20, 'category_name' => '飲料', 'hierarchy' => 1, 'parent_category_id' => null, 'sort_no' => 3]);
        $conn->insert('dtb_tag', ['id' => 100, 'name' => '人気', 'sort_no' => 2]);
        $conn->insert('dtb_tag', ['id' => 101, 'name' => '新商品', 'sort_no' => 1]);

        // フィクスチャ: [name, search_word, code, price, visible, unlimited, stock, status, category, tag, date]
        $fixtures = [
            ['チェリーアイスサンド', 'アイス デザート', 'ICE-001', '1200', true, false, 5, 1, 10, 100, '2026-09-01 10:00:00'],
            ['100%ジュース', 'ジュース 果汁', 'JUICE-100', '800', true, false, 10, 1, 10, null, '2026-09-02 10:00:00'],
            ['100Xジュース', 'ジュース', 'JUICE-X', '800', true, false, 10, 1, 10, null, '2026-09-03 10:00:00'],
            ['a_bナッツ', 'ナッツ', 'NUTS-U', '500', true, false, 3, 1, 10, null, '2026-09-04 10:00:00'],
            ['aXbナッツ', 'ナッツ', 'NUTS-X', '500', true, false, 3, 1, 10, null, '2026-09-05 10:00:00'],
            ['非表示の飴', '飴', 'CANDY-H', '300', false, false, 7, 1, 10, null, '2026-09-06 10:00:00'],
            ['別カテゴリの茶', 'お茶', 'TEA-020', '600', true, false, 8, 1, 20, null, '2026-09-07 10:00:00'],
            ['数え切れない豆', '豆', 'BEANS-INF', '400', true, true, 99, 1, 10, null, '2026-09-08 10:00:00'],
            ['下書きのパン', 'パン', 'BREAD-D', '200', true, false, 1, 2, 10, null, '2026-09-09 10:00:00'],
        ];

        $ids = [];
        foreach ($fixtures as $f) {
            [$name, $sw, $code, $price, $visible, $unlimited, $stock, $status, $cat, $tag, $date] = $f;
            $conn->insert('dtb_product', [
                'name' => $name, 'search_word' => $sw, 'description_list' => $name . 'の説明',
                'create_date' => $date, 'update_date' => $date, 'product_status_id' => $status,
            ]);
            $pid = (int) $conn->lastInsertId();
            $ids[$name] = $pid;
            $conn->insert('dtb_product_class', [
                'product_id' => $pid, 'product_code' => $code, 'price02' => $price,
                'visible' => $visible, 'stock_unlimited' => $unlimited,
                'class_category_id1' => $name === 'チェリーアイスサンド' ? 1 : null,
                'class_category_id2' => null,
            ], ['visible' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'stock_unlimited' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
            $cid = (int) $conn->lastInsertId();
            $conn->insert('dtb_product_stock', ['product_class_id' => $cid, 'stock' => $stock]);
            $conn->insert('dtb_product_category', ['product_id' => $pid, 'category_id' => $cat]);
            if ($tag !== null) {
                $conn->insert('dtb_product_tag', ['product_id' => $pid, 'tag_id' => $tag]);
            }
            if ($name === 'チェリーアイスサンド') {
                $conn->insert('dtb_product_image', ['product_id' => $pid, 'file_name' => 'ice.jpg', 'sort_no' => 0]);
            }
        }

        self::$ids[$label] = $ids;
        self::$seeded[$key] = true;
    }

    private function ensureMysqlDatabase(string $url): void
    {
        if (!preg_match('#^mysql://([^@]*)@([^/]+)/([^?]+)#', $url, $m)) {
            return;
        }
        [$user, $pass] = array_pad(explode(':', $m[1], 2), 2, '');
        $admin = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => explode(':', $m[2])[0],
            'user' => $user ?: 'root', 'password' => $pass ?: '',
        ]);
        $admin->executeStatement(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4',
            str_replace('`', '', $m[3])
        ));
        $admin->close();
    }

    private function ensurePgsqlDatabase(string $url): void
    {
        if (!preg_match('#^pgsql://([^@]*)@([^/]+)/([^?]+)#', $url, $m)) {
            return;
        }
        [$user, $pass] = array_pad(explode(':', $m[1], 2), 2, '');
        [$host, $port] = array_pad(explode(':', $m[2], 2), 2, '5432');
        $dbname = explode('?', $m[3])[0];
        $admin = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'host' => $host, 'port' => (int) $port,
            'dbname' => 'postgres', 'user' => $user ?: 'postgres', 'password' => $pass ?: '',
        ]);
        $exists = $admin->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$dbname]);
        if (!$exists) {
            $admin->executeStatement(sprintf('CREATE DATABASE "%s"', str_replace('"', '', $dbname)));
        }
        $admin->close();
    }

    /** @dataProvider provideDatabases */
    public function testSearchFindsByKeyword(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $names = array_column($repo->search('アイス'), 'name');
        $this->assertContains('チェリーアイスサンド', $names, "[$label] reported failure case");
    }

    /** @dataProvider provideDatabases */
    public function testSearchEscapesPercent(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $names = array_column($repo->search('100%'), 'name');
        $this->assertContains('100%ジュース', $names, "[$label] literal % must match");
        $this->assertNotContains('100Xジュース', $names, "[$label] % must not act as wildcard");
    }

    /** @dataProvider provideDatabases */
    public function testSearchEscapesUnderscore(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $names = array_column($repo->search('a_b'), 'name');
        $this->assertContains('a_bナッツ', $names, "[$label] literal _ must match");
        $this->assertNotContains('aXbナッツ', $names, "[$label] _ must not act as wildcard");
    }

    /** @dataProvider provideDatabases */
    public function testSearchExcludesInvisibleAndNonPublic(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $names = array_column($repo->search('飴'), 'name');
        $this->assertNotContains('非表示の飴', $names, "[$label] visible=false must be excluded (BOOLEAN binding)");
        $names = array_column($repo->search('パン'), 'name');
        $this->assertNotContains('下書きのパン', $names, "[$label] non-public status must be excluded");
    }

    /** @dataProvider provideDatabases */
    public function testSearchUnlimitedStockReturnsNull(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $rows = $repo->search('豆');
        $this->assertNotEmpty($rows, "[$label]");
        $this->assertSame('数え切れない豆', $rows[0]['name']);
        $this->assertNull($rows[0]['stock'], "[$label] stock_unlimited=true must hide stock");
    }

    /** @dataProvider provideDatabases */
    public function testCategoryProducts(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $names10 = array_column($repo->getCategoryProducts(10), 'name');
        $this->assertContains('チェリーアイスサンド', $names10, "[$label]");
        $this->assertNotContains('別カテゴリの茶', $names10, "[$label]");
        $names20 = array_column($repo->getCategoryProducts(20), 'name');
        $this->assertContains('別カテゴリの茶', $names20, "[$label]");
    }

    /** @dataProvider provideDatabases */
    public function testSearchByTag(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $names = array_column($repo->searchByTag(100), 'name');
        $this->assertSame(['チェリーアイスサンド'], $names, "[$label]");
    }

    /** @dataProvider provideDatabases */
    public function testGetDetail(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $ids = self::$ids[$label];
        $detail = $repo->getDetail($ids['チェリーアイスサンド']);
        $this->assertNotNull($detail, "[$label]");
        $this->assertSame('チェリーアイスサンド', $detail['name']);
        $this->assertStringContainsString('/products/detail/', $detail['url']);
        $this->assertSame('ICE-001', $detail['classes'][0]['code'], "[$label] class_category join");
        $this->assertSame('S', $detail['classes'][0]['class_category1'], "[$label]");
        $this->assertSame(5, (int) $detail['stock'][0]['stock'], "[$label] stock join");
        $this->assertSame(['食品'], array_column($detail['categories'], 'name'), "[$label] category join");
        $this->assertSame(['ice.jpg'], $detail['images'], "[$label] images");
        $this->assertSame(['人気'], array_column($detail['tags'], 'name'), "[$label] tags");

        $this->assertNull($repo->getDetail(999999), "[$label] missing product returns null");
    }

    /** @dataProvider provideDatabases */
    public function testGetStock(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $ids = self::$ids[$label];
        $rows = $repo->getStock($ids['チェリーアイスサンド']);
        $this->assertNotEmpty($rows, "[$label]");
        $this->assertSame('ICE-001', $rows[0]['code']);

        $hidden = $repo->getStock($ids['非表示の飴']);
        $this->assertSame([], $hidden, "[$label] invisible class must be excluded (BOOLEAN binding)");
    }

    /** @dataProvider provideDatabases */
    public function testGetCategories(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $roots = $repo->getCategories(null);
        $byName = array_column($roots, null, 'name');
        $this->assertArrayHasKey('食品', $byName, "[$label]");
        $this->assertArrayHasKey('飲料', $byName, "[$label]");
        $this->assertSame(1, $byName['食品']['children_count'], "[$label] correlated subselect");
        $this->assertSame(0, $byName['飲料']['children_count'], "[$label]");

        $children = $repo->getCategories(10);
        $this->assertSame(['菓子'], array_column($children, 'name'), "[$label] parent filter");
    }

    /** @dataProvider provideDatabases */
    public function testGetTags(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $names = array_column($repo->getTags(), 'name');
        $this->assertSame(['新商品', '人気'], $names, "[$label] sort_no ordering");
    }
}
