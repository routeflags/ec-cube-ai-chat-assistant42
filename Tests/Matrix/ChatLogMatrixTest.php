<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Tests\Matrix;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Repository\ChatLogRepository;

/**
 * チャットログ集計の DB マトリクステスト。
 *
 * プラットフォーム分岐 SQL（HOUR / strftime / EXTRACT）を持つ
 * fetchHourlyDistribution を MySQL / PostgreSQL / SQLite の3種で実行する。
 * 接続・カオス・DB作成の仕組みは ProductSearchMatrixTest と同一。
 *
 * 実行: php vendor/bin/phpunit Tests/Matrix
 *       MATRIX_CHAOS=1 php vendor/bin/phpunit Tests/Matrix
 */
class ChatLogMatrixTest extends TestCase
{
    /** @var array<string, bool> */
    private static array $seeded = [];

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

        foreach (['mysql' => 'ensureMysql', 'pgsql' => 'ensurePgsql'] as $label => $method) {
            try {
                $this->{$method}($candidates[$label]['url']);
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf("[matrix] %s setup failed: %s\n", $label, substr($e->getMessage(), 0, 120)));
            }
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

    private function ensureMysql(string $url): void
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

    private function ensurePgsql(string $url): void
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

    private function repositoryFor(string $label, array $params): ChatLogRepository
    {
        $this->seedDatabase($label, $params);
        $conn = DriverManager::getConnection($params);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $registry = $this->createMock(ManagerRegistry::class);

        return new ChatLogRepository($registry, $em);
    }

    private function seedDatabase(string $label, array $params): void
    {
        $key = $label . '|' . $params['url'];
        if (isset(self::$seeded[$key])) {
            return;
        }

        $conn = DriverManager::getConnection($params);
        $platform = strtolower($conn->getDatabasePlatform()->getName());
        $autoId = str_contains($platform, 'mysql') ? 'INT AUTO_INCREMENT PRIMARY KEY'
            : (str_contains($platform, 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'SERIAL PRIMARY KEY');

        $conn->executeStatement('DROP TABLE IF EXISTS plg_ai_chat_assistant_log');
        // 集計・更新対象のスキーマ（hourly 用 created_at + email 返信系カラム）
        $conn->executeStatement(<<<SQL
            CREATE TABLE plg_ai_chat_assistant_log (
                id {$autoId},
                created_at TIMESTAMP NULL,
                session_id VARCHAR(36) NULL,
                email_reply_address VARCHAR(255) NULL,
                email_reply_address_hash VARCHAR(64) NULL,
                email_reply_address_enc TEXT NULL
            )
            SQL);

        // 2026-09-08 の 0時×1、9時×2、23時×1。前日 9時×1 は範囲外で除外される
        foreach (['2026-09-08 00:15:00', '2026-09-08 09:00:00', '2026-09-08 09:59:59', '2026-09-08 23:30:00', '2026-09-07 09:00:00'] as $dt) {
            $conn->insert('plg_ai_chat_assistant_log', ['created_at' => $dt, 'session_id' => 'hourly']);
        }

        // email 返信系: s1/s3=未設定×2（新旧、テストごとに分離）、s2=hash設定済み×1
        // （hourly集計の範囲外日付にする）
        foreach (['s1', 's3'] as $sid) {
            $conn->insert('plg_ai_chat_assistant_log', ['created_at' => '2026-09-06 10:00:00', 'session_id' => $sid]);
            $conn->insert('plg_ai_chat_assistant_log', ['created_at' => '2026-09-06 11:00:00', 'session_id' => $sid]);
        }
        $conn->insert('plg_ai_chat_assistant_log', [
            'created_at' => '2026-09-06 12:00:00', 'session_id' => 's2',
            'email_reply_address_hash' => 'done', 'email_reply_address_enc' => 'enc-done',
        ]);

        self::$seeded[$key] = true;
    }

    /** @dataProvider provideDatabases */
    public function testHourlyDistribution(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $rows = $repo->fetchHourlyDistribution(
            new \DateTimeImmutable('2026-09-08 00:00:00'),
            new \DateTimeImmutable('2026-09-09 00:00:00')
        );

        $this->assertCount(24, $rows, "[$label] 24 slots");
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['hour']] = (int) $row['count'];
        }
        $this->assertSame(1, $map[0], "[$label] hour 0");
        $this->assertSame(2, $map[9], "[$label] hour 9");
        $this->assertSame(1, $map[23], "[$label] hour 23");
        $this->assertSame(0, $map[12], "[$label] empty hour is 0");
        $this->assertSame(4, array_sum($map), "[$label] out-of-range row excluded");
    }

    /** @dataProvider provideDatabases */
    public function testHourlyDistributionEmptyRange(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);
        $rows = $repo->fetchHourlyDistribution(
            new \DateTimeImmutable('2026-09-10 00:00:00'),
            new \DateTimeImmutable('2026-09-11 00:00:00')
        );

        $this->assertCount(24, $rows, "[$label]");
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row['count'], "[$label] all zero when no data");
        }
    }

    /** @dataProvider provideDatabases */
    public function testUpdateEmailReplyAddress(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);

        // 最新1件（11:00）のみに平文が入る。派生テーブル二重化が3DBで動くことの検証
        $this->assertSame(1, $repo->updateEmailReplyAddress('s1', 'a@example.com'), "[$label]");
        $conn = DriverManager::getConnection($params);
        $rows = $conn->fetchAllAssociative(
            "SELECT created_at, email_reply_address FROM plg_ai_chat_assistant_log WHERE session_id = 's1' ORDER BY created_at"
        );
        $this->assertNull($rows[0]['email_reply_address'], "[$label] older row untouched");
        $this->assertSame('a@example.com', $rows[1]['email_reply_address'], "[$label] newest row updated");

        // 2回目は「未設定の最新1件」＝古い方の行に入る。3回目で対象なし
        $this->assertSame(1, $repo->updateEmailReplyAddress('s1', 'b@example.com'), "[$label]");
        $this->assertSame(0, $repo->updateEmailReplyAddress('s1', 'c@example.com'), "[$label] exhausted");

        // hash設定済みセッションは対象外
        $this->assertSame(0, $repo->updateEmailReplyAddress('s2', 'c@example.com'), "[$label]");
    }

    /** @dataProvider provideDatabases */
    public function testUpdateEmailReplyAddressHashed(string $label, array $params): void
    {
        $repo = $this->repositoryFor($label, $params);

        $this->assertSame(1, $repo->updateEmailReplyAddressHashed('s3', 'h64', 'enc-body'), "[$label]");
        $conn = DriverManager::getConnection($params);
        $rows = $conn->fetchAllAssociative(
            "SELECT created_at, email_reply_address_hash, email_reply_address_enc FROM plg_ai_chat_assistant_log WHERE session_id = 's3' ORDER BY created_at"
        );
        $this->assertNull($rows[0]['email_reply_address_hash'], "[$label] older row untouched");
        $this->assertSame('h64', $rows[1]['email_reply_address_hash'], "[$label]");
        $this->assertSame('enc-body', $rows[1]['email_reply_address_enc'], "[$label]");

        // enc設定済みセッションは対象外
        $this->assertSame(0, $repo->updateEmailReplyAddressHashed('s2', 'hx', 'ex'), "[$label]");
    }
}
