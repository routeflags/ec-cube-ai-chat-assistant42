<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * services.yaml と Controller の __construct の配線を静的に検証する.
 *
 * DesignController で起きた「services.yaml 3引数 / Controller 2引数」不一致（TypeError）を
 * コンテナを boot せずに検知するためのテスト。Unit テストがモックで setConstructorArgs を
 * 直書きするため配線を素通りしていた問題を補完する。
 *
 * - 明示定義がある Controller は、services.yaml の arguments キーとコンストラクタ引数名・型・必須数が一致することを検証
 * - 明示定義がない Controller は、resource による autowire で解決可能か（型ヒントが存在し、デフォルト値または型で解決可能か）を検証
 * - 全 Controller を漏れなく列挙（Controller/ 以下の全 *.php を収集）し、不足があればテスト自体が落ちる
 */
class ServiceWiringTest extends TestCase
{
    private const SERVICES_YAML = __DIR__ . '/../../Resource/config/services.yaml';

    /** @var string[] */
    private array $allControllers;

    protected function setUp(): void
    {
        $this->allControllers = $this->collectAllControllers();
    }

    public function testAllControllersAreCovered(): void
    {
        // 11件を期待 — 新規 Controller 追加時にテストが落ち、漏れを検知できる
        $expected = [
            'Plugin\AiChatAssistant42\Controller\Admin\AccessRuleController',
            'Plugin\AiChatAssistant42\Controller\Admin\ChatHistoryController',
            'Plugin\AiChatAssistant42\Controller\Admin\DashboardController',
            'Plugin\AiChatAssistant42\Controller\Admin\DesignController',
            'Plugin\AiChatAssistant42\Controller\Admin\KnowledgeController',
            'Plugin\AiChatAssistant42\Controller\Admin\NotificationController',
            'Plugin\AiChatAssistant42\Controller\Admin\ReportController',
            'Plugin\AiChatAssistant42\Controller\Admin\ScenarioController',
            'Plugin\AiChatAssistant42\Controller\Api\ChatApiController',
            'Plugin\AiChatAssistant42\Controller\Api\ModelApiController',
            'Plugin\AiChatAssistant42\Controller\McpHttpController',
        ];

        sort($expected);
        $actual = $this->allControllers;
        sort($actual);

        self::assertSame($expected, $actual, 'Controller の増減を検知 — 期待リストを更新し、配線テストの dataProvider も更新してください');

        // 各 Controller が wiring 検証でカバーされることを保証（dataProvider と同期）
        foreach ($expected as $fqcn) {
            self::assertTrue(class_exists($fqcn), "Class not found: {$fqcn}");
        }
    }

    /**
     * @dataProvider provideAllControllers
     */
    public function testServiceWiringMatchesConstructor(string $fqcn): void
    {
        $services = $this->loadServicesYaml();
        $explicit = $services[$fqcn] ?? null;

        $ref = new \ReflectionClass($fqcn);
        $ctor = $ref->getConstructor();
        $params = $ctor ? $ctor->getParameters() : [];

        if ($explicit !== null) {
            $this->assertExplicitWiringMatches($fqcn, $params, $explicit);
        } else {
            $this->assertAutowireIsResolvable($fqcn, $params);
        }
    }

    public function provideAllControllers(): array
    {
        // setUp 前でも動くよう再収集
        $controllers = (new self('testAllControllersAreCovered'))->collectAllControllers();
        $cases = [];
        foreach ($controllers as $fqcn) {
            $cases[$fqcn] = [$fqcn];
        }

        return $cases;
    }

    /**
     * @return string[] FQCN list
     */
    private function collectAllControllers(): array
    {
        $baseDir = __DIR__ . '/../../Controller';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baseDir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($baseDir) + 1);
            // Controller/Admin/FooController.php -> Plugin\AiChatAssistant42\Controller\Admin\FooController
            $fqcn = 'Plugin\\AiChatAssistant42\\Controller\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
            $files[] = $fqcn;
        }
        sort($files);

        return $files;
    }

    private function loadServicesYaml(): array
    {
        $raw = Yaml::parseFile(self::SERVICES_YAML);
        $services = $raw['services'] ?? [];

        // 正規化: services.yaml のキーが Controller FQCN と一致するものだけ抽出
        $result = [];
        foreach ($services as $key => $def) {
            if (!is_string($key) || !str_contains($key, 'Controller\\')) {
                continue;
            }
            if ($def === null) {
                $def = [];
            }
            $result[$key] = $def;
        }

        return $result;
    }

    /**
     * @param \ReflectionParameter[] $params
     */
    private function assertExplicitWiringMatches(string $fqcn, array $params, $serviceDef): void
    {
        $arguments = $serviceDef['arguments'] ?? [];

        // services.yaml の arguments は 2形式あり得る:
        // - 連番: ['@Foo', '%kernel.project_dir%']（順序依存）
        // - 連想: ['$projectDir' => '%kernel.project_dir%']（名前依存）
        $isAssoc = $this->isAssoc($arguments);

        if ($isAssoc) {
            // 名前ベース — services.yaml のキーがコンストラクタ引数名と一致するか
            foreach ($arguments as $name => $value) {
                $paramName = ltrim($name, '$');
                $found = null;
                foreach ($params as $p) {
                    if ($p->getName() === $paramName) {
                        $found = $p;
                        break;
                    }
                }
                self::assertNotNull($found, "services.yaml の argument '{$name}' が {$fqcn}::__construct に存在しません — services.yaml か Controller のどちらかが古いままです");

                // 型チェック: string なら %kernel.project_dir% / クラスなら @Service
                $type = $found->getType();
                if ($type !== null) {
                    $typeName = $type->getName();
                    if ($typeName === 'string') {
                        self::assertStringContainsString('%kernel.project_dir%', (string) $value, "string 型 {$paramName} には %kernel.project_dir% を期待");
                    }
                }
            }

            // 必須引数が services.yaml で漏れていないか
            foreach ($params as $p) {
                if (!$p->isOptional() && !$p->allowsNull() && $p->getType() !== null && !$p->isVariadic()) {
                    // 必須かつデフォルトなし → services.yaml に存在すべき
                    $has = array_key_exists('$' . $p->getName(), $arguments);
                    // autowire で解決可能な型（Repository 等）は明示がなくてもよいが、string は明示が必須
                    $typeName = $p->getType() ? $p->getType()->getName() : null;
                    if ($typeName === 'string' && !$has) {
                        self::fail("必須 string 引数 \${$p->getName()} が services.yaml に未定義です — {$fqcn}");
                    }
                }
            }

            // 引数の数がコンストラクタの総数を超えていないか（今回のバグの直接検知）
            // 必須引数が 0 でも optional な引数へ明示で注入するのは正当（例: DashboardController の $syncService）
            $totalCount = count($params);
            $requiredCount = count(array_filter($params, fn ($p) => !$p->isOptional()));
            self::assertLessThanOrEqual(
                $totalCount,
                count($arguments),
                "services.yaml の arguments 数 (" . count($arguments) . ") が {$fqcn} の総引数数 ({$totalCount}) を超えています — 余分な引数（例: 旧 ConfigRepository）が残留していませんか"
            );
            self::assertGreaterThanOrEqual(
                $requiredCount,
                count($arguments),
                "services.yaml の arguments 数 (" . count($arguments) . ") が {$fqcn} の必須引数数 ({$requiredCount}) より少ないです — 必須引数が未定義です"
            );
        } else {
            // 連番 — 順序で比較。DesignController の旧バグ（3件 vs 2件）をここで検知
            $requiredCount = count(array_filter($params, fn ($p) => !$p->isOptional()));
            $optionalCount = count($params) - $requiredCount;
            $min = $requiredCount;
            $max = $requiredCount + $optionalCount;

            self::assertGreaterThanOrEqual($min, count($arguments), "services.yaml の arguments が少なすぎます — {$fqcn} は最低 {$min} 件必要");
            self::assertLessThanOrEqual($max, count($arguments), "services.yaml の arguments が多すぎます — {$fqcn} は最大 {$max} 件まで。DesignController で起きた旧バグ（3件渡して2件しか受けない TypeError）の再発です");
        }
    }

    /**
     * @param \ReflectionParameter[] $params
     */
    private function assertAutowireIsResolvable(string $fqcn, array $params): void
    {
        // パラメータなし（例: ChatHistoryController が __construct を持たない）でも wiring は成功とみなす
        self::assertTrue(true, "autowire check for {$fqcn} — no required params, wiring should succeed via resource autowire");

        foreach ($params as $param) {
            if ($param->isOptional() || $param->allowsNull()) {
                continue;
            }
            $type = $param->getType();
            self::assertNotNull($type, "autowire 対象 {$fqcn}::\${$param->getName()} は型ヒントを持たせてください — 型なしは autowire できず、手動定義が必要になります");

            // 型がクラスなら、そのクラスが存在することを確認（typo 検知）
            $typeName = $type->getName();
            if (!$type->isBuiltin()) {
                self::assertTrue(
                    class_exists($typeName) || interface_exists($typeName),
                    "autowire 対象 {$fqcn}::\${$param->getName()} の型 {$typeName} が存在しません"
                );
            }
        }
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
