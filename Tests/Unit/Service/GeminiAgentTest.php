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

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Service\AiAgent\GeminiAgent;
use Psr\Log\LoggerInterface;

/**
 * GeminiAgent の単体テスト — 案C (thoughtSignature 対応) 検証。
 *
 * - thoughtSignature ありの model レスポンスを contents に保持すること
 * - 未知ツール名のバリデーション
 * - 既存の args/properties 正規化が壊れていないこと
 * - functionDeclaration 二重ラップ解消
 */
class GeminiAgentTest extends TestCase
{
    // ================================================================
    //  Helper: リフレクションで private メソッドを呼ぶ
    // ================================================================

    /**
     * @param array<int, array<string, mixed>> $tools
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    private function invokeConvertTools(GeminiAgent $agent, array $tools): array
    {
        $ref = new \ReflectionMethod($agent, 'convertToolsToGeminiFormat');
        $ref->setAccessible(true);
        return $ref->invoke($agent, $tools);
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<int, array{name: string, args: array}>
     */
    private function invokeExtractFunctionCalls(GeminiAgent $agent, array $parts): array
    {
        $ref = new \ReflectionMethod($agent, 'extractFunctionCalls');
        $ref->setAccessible(true);
        return $ref->invoke($agent, $parts);
    }

    /**
     * HTTP クライアントをモック差し替えし、リクエスト履歴を返す。
     *
     * @param array<int, Response> $responses
     * @return array{agent: GeminiAgent, history: array<int, array{request: \Psr\Http\Message\RequestInterface, response: \Psr\Http\Message\ResponseInterface}>}
     */
    private function createAgentWithMockResponses(array $responses, ?LoggerInterface $logger = null): array
    {
        $agent = new GeminiAgent('test-api-key', 'gemini-2.5-flash', 4096, '', 'https://generativelanguage.googleapis.com/v1beta', $logger);

        $mock = new MockHandler($responses);
        $history = [];
        $historyMiddleware = Middleware::history($history);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($historyMiddleware);

        $client = new Client(['handler' => $handlerStack, 'base_uri' => 'https://generativelanguage.googleapis.com/v1beta']);

        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        // history は参照渡しで更新されるため、オブジェクト参照を保持する必要がある。
        // クロージャで history を束縛して返すため、配列を参照で持つラッパーを用意する。
        return ['agent' => $agent, 'history' => &$history, 'mock' => $mock, 'stack' => $handlerStack, 'client' => $client];
    }

    /**
     * モックレスポンス用の JSON ボディを生成する。
     */
    private function makeGeminiResponse(array $parts, string $finishReason = 'STOP', array $usage = []): Response
    {
        $body = json_encode([
            'candidates' => [
                [
                    'content' => ['parts' => $parts],
                    'finishReason' => $finishReason,
                ],
            ],
            'usageMetadata' => $usage,
        ], JSON_UNESCAPED_UNICODE);

        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    // ================================================================
    //  1. convertToolsToGeminiFormat — 空 properties の正規化
    // ================================================================

    public function testConvertToolsNormalizesEmptyPropertiesToStdClass(): void
    {
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');

        $tools = [
            [
                'name' => 'get_tags',
                'description' => '全タグ一覧を取得',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ];

        $converted = $this->invokeConvertTools($agent, $tools);

        self::assertCount(1, $converted);
        self::assertSame('get_tags', $converted[0]['name']);
        // properties が [] ではなく stdClass ({}) に変換されていること
        self::assertInstanceOf(\stdClass::class, $converted[0]['parameters']['properties']);
        // JSON で {} になること
        $json = json_encode($converted[0]['parameters'], JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('"properties":{}', $json);
    }

    public function testConvertToolsKeepsNonEmptyProperties(): void
    {
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');

        $tools = [
            [
                'name' => 'search_products',
                'description' => '検索',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        $converted = $this->invokeConvertTools($agent, $tools);

        self::assertIsArray($converted[0]['parameters']['properties']);
        self::assertArrayHasKey('keyword', $converted[0]['parameters']['properties']);
    }

    // ================================================================
    //  2. convertToolsToGeminiFormat — functionDeclaration 二重ラップ解消
    // ================================================================

    public function testConvertToolsDissolvesFunctionDeclarationDoubleWrap(): void
    {
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');

        // ケース A: ツール自体が functionDeclaration ラップされている
        $toolsA = [
            [
                'functionDeclaration' => [
                    'name' => 'search_products',
                    'description' => '検索A',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['keyword' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $convertedA = $this->invokeConvertTools($agent, $toolsA);
        self::assertSame('search_products', $convertedA[0]['name']);
        self::assertSame('検索A', $convertedA[0]['description']);
        self::assertArrayHasKey('keyword', $convertedA[0]['parameters']['properties']);

        // ケース B: inputSchema が functionDeclaration を含む
        $toolsB = [
            [
                'name' => 'get_tags',
                'description' => 'タグ取得B',
                'inputSchema' => [
                    'functionDeclaration' => [
                        'name' => 'get_tags',
                        'description' => 'タグ取得B',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                ],
            ],
        ];

        $convertedB = $this->invokeConvertTools($agent, $toolsB);
        self::assertSame('get_tags', $convertedB[0]['name']);
        // 内側の properties: [] が stdClass に正規化されていること
        self::assertInstanceOf(\stdClass::class, $convertedB[0]['parameters']['properties']);
    }

    // ================================================================
    //  3. extractFunctionCalls — args 正規化
    // ================================================================

    public function testExtractFunctionCallsNormalizesArgs(): void
    {
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');

        // 空配列 args は [] のまま（chat() の round-trip で {} になる）
        $partsEmpty = [
            ['functionCall' => ['name' => 'get_tags', 'args' => []]],
        ];
        $callsEmpty = $this->invokeExtractFunctionCalls($agent, $partsEmpty);
        self::assertSame('get_tags', $callsEmpty[0]['name']);
        self::assertSame([], $callsEmpty[0]['args']);

        // 通常の args はそのまま
        $partsWithArgs = [
            ['functionCall' => ['name' => 'search_products', 'args' => ['keyword' => 'test', 'limit' => 10]]],
        ];
        $callsWithArgs = $this->invokeExtractFunctionCalls($agent, $partsWithArgs);
        self::assertSame(['keyword' => 'test', 'limit' => 10], $callsWithArgs[0]['args']);

        // 非配列 args は [] にフォールバック
        $partsInvalid = [
            ['functionCall' => ['name' => 'search_products', 'args' => 'invalid']],
        ];
        $callsInvalid = $this->invokeExtractFunctionCalls($agent, $partsInvalid);
        self::assertSame([], $callsInvalid[0]['args']);

        // args なしは [] にフォールバック
        $partsNoArgs = [
            ['functionCall' => ['name' => 'get_tags']],
        ];
        $callsNoArgs = $this->invokeExtractFunctionCalls($agent, $partsNoArgs);
        self::assertSame([], $callsNoArgs[0]['args']);
    }

    // ================================================================
    //  4. chat() — thoughtSignature 保持（Google spec: 検証用に透過保持）
    // ================================================================

    public function testChatPreservesThoughtSignatureInContents(): void
    {
        // 1ターン目: thought + thoughtSignature を含む functionCall レスポンス
        $firstParts = [
            [
                'thought' => true,
                'thoughtSignature' => 'sig-thought-abc123',
                'text' => '内部思考: ツールを呼ぶべきか検討する',
            ],
            [
                'functionCall' => [
                    'name' => 'search_products',
                    'args' => ['keyword' => 'test'],
                ],
                'thoughtSignature' => 'sig-func-xyz789',
            ],
        ];

        $firstResponse = $this->makeGeminiResponse($firstParts, 'FUNCTION_CALL', ['promptTokenCount' => 10, 'candidatesTokenCount' => 20]);
        $secondResponse = $this->makeGeminiResponse([['text' => '検索結果をお届けします']], 'STOP', ['promptTokenCount' => 5, 'candidatesTokenCount' => 10]);

        // history を捕捉するためのコンテナ
        $mock = new MockHandler([$firstResponse, $secondResponse]);
        $history = [];
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack, 'base_uri' => 'https://generativelanguage.googleapis.com/v1beta']);

        $agent = new GeminiAgent('test-key', 'gemini-2.5-flash');
        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        $tools = [
            [
                'name' => 'search_products',
                'description' => '商品を検索',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['keyword' => ['type' => 'string']],
                ],
            ],
        ];

        $toolExecutor = function (string $name, array $args): array {
            return [['id' => 1, 'name' => 'テスト商品']];
        };

        $result = $agent->chat('テストメッセージ', $tools, $toolExecutor);

        self::assertSame('検索結果をお届けします', $result['reply']);
        self::assertSame(['search_products'], $result['tools_used']);

        // 2回目のリクエストで contents に 1回目の model parts が thoughtSignature 付きで round-trip されていること
        self::assertCount(2, $history, '2回の API 呼び出しが記録されているべき');

        $secondRequest = $history[1]['request'];
        $secondBody = (string) $secondRequest->getBody();
        $secondPayload = json_decode($secondBody, true);

        self::assertIsArray($secondPayload);
        $contents = $secondPayload['contents'] ?? [];
        // contents は [user(初回), model(thought+functionCall), user(functionResponse)] の3件
        // ただし 2回目のリクエスト時点では初回 user + model + functionResponse が含まれている
        self::assertGreaterThanOrEqual(3, count($contents), '2回目の contents は少なくとも3件');

        // model レスポンス（1回目の内容）が保持されているか
        $modelEntry = null;
        foreach ($contents as $entry) {
            if (($entry['role'] ?? '') === 'model') {
                $modelEntry = $entry;
                break;
            }
        }
        self::assertNotNull($modelEntry, 'model ロールの entry が存在するべき');
        $parts = $modelEntry['parts'] ?? [];
        self::assertCount(2, $parts, 'model parts は2件（thought + functionCall）');

        // 1つ目の thought part が保持されている
        $thoughtPart = $parts[0];
        self::assertTrue($thoughtPart['thought'] ?? false);
        self::assertSame('sig-thought-abc123', $thoughtPart['thoughtSignature'] ?? null);
        self::assertSame('内部思考: ツールを呼ぶべきか検討する', $thoughtPart['text'] ?? null);

        // 2つ目の functionCall part が thoughtSignature を保持しつつ args も含む
        $functionCallPart = $parts[1];
        self::assertSame('sig-func-xyz789', $functionCallPart['thoughtSignature'] ?? null);
        self::assertSame('search_products', $functionCallPart['functionCall']['name'] ?? null);
        self::assertSame(['keyword' => 'test'], $functionCallPart['functionCall']['args'] ?? null);
    }

    public function testChatPreservesThoughtSignatureWithEmptyArgsNormalization(): void
    {
        // args が [] の functionCall + thoughtSignature の組み合わせ
        $firstParts = [
            [
                'functionCall' => ['name' => 'get_tags', 'args' => []],
                'thoughtSignature' => 'sig-empty-args-001',
            ],
        ];

        $firstResponse = $this->makeGeminiResponse($firstParts, 'FUNCTION_CALL');
        $secondResponse = $this->makeGeminiResponse([['text' => 'タグ一覧です']], 'STOP');

        $mock = new MockHandler([$firstResponse, $secondResponse]);
        $history = [];
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');
        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        $tools = [
            ['name' => 'get_tags', 'description' => 'タグ取得', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ];

        $agent->chat('タグを教えて', $tools, fn (string $n, array $a): array => []);

        $rawBody = (string) $history[1]['request']->getBody();
        $secondPayload = json_decode($rawBody, true);
        $modelParts = null;
        foreach ($secondPayload['contents'] as $entry) {
            if (($entry['role'] ?? '') === 'model') {
                $modelParts = $entry['parts'];
                break;
            }
        }
        self::assertNotNull($modelParts);
        // args が {} に正規化されつつ thoughtSignature が保持されている
        self::assertSame('sig-empty-args-001', $modelParts[0]['thoughtSignature'] ?? null);
        // JSON で {} になること（stdClass）— decode では区別がつかないため生 JSON で検証
        self::assertStringContainsString('"args":{}', $rawBody, '空 args は {} にエンコードされるべき');
    }

    // ================================================================
    //  5. chat() — 未知ツール名のバリデーション（日本語で簡潔に）
    // ================================================================

    public function testChatThrowsOnUnknownToolWithJapaneseMessage(): void
    {
        $firstParts = [
            ['functionCall' => ['name' => 'unknown_tool_xyz', 'args' => ['foo' => 'bar']]],
        ];
        $firstResponse = $this->makeGeminiResponse($firstParts, 'FUNCTION_CALL');

        $mock = new MockHandler([$firstResponse]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');
        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        $tools = [
            ['name' => 'search_products', 'description' => '検索', 'inputSchema' => ['type' => 'object', 'properties' => ['keyword' => ['type' => 'string']]]],
            ['name' => 'get_tags', 'description' => 'タグ', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/未知のツールが呼び出されました/');

        $agent->chat('何かして', $tools, fn (string $n, array $a): array => []);
    }

    public function testChatThrowsOnUnknownToolAndLogsJapaneseWarning(): void
    {
        $firstParts = [
            ['functionCall' => ['name' => 'delete_all_products', 'args' => []]],
        ];
        $firstResponse = $this->makeGeminiResponse($firstParts, 'FUNCTION_CALL');

        $mock = new MockHandler([$firstResponse]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('未知のツールが呼び出されました'),
                $this->arrayHasKey('tool_name')
            );

        $agent = new GeminiAgent('k', 'gemini-2.5-flash', 4096, '', 'https://generativelanguage.googleapis.com/v1beta', $logger);
        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        $tools = [
            ['name' => 'search_products', 'description' => '検索', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ];

        try {
            $agent->chat('test', $tools, fn (string $n, array $a): array => []);
            self::fail('例外が投げられるべき');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('delete_all_products', $e->getMessage());
            self::assertStringContainsString('未知のツール', $e->getMessage());
        }
    }

    public function testChatAllowsKnownTool(): void
    {
        $firstParts = [
            ['functionCall' => ['name' => 'search_products', 'args' => ['keyword' => 'a']]],
        ];
        $firstResponse = $this->makeGeminiResponse($firstParts, 'FUNCTION_CALL');
        $secondResponse = $this->makeGeminiResponse([['text' => 'ok']], 'STOP');

        $mock = new MockHandler([$firstResponse, $secondResponse]);
        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');
        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        $tools = [
            ['name' => 'search_products', 'description' => '検索', 'inputSchema' => ['type' => 'object', 'properties' => ['keyword' => ['type' => 'string']]]],
        ];

        $result = $agent->chat('hello', $tools, fn (string $n, array $a): array => [['id' => 1]]);

        self::assertSame('ok', $result['reply']);
        self::assertSame(['search_products'], $result['tools_used']);
    }

    // ================================================================
    //  6. 既存の正規化が壊れていないこと（統合）
    // ================================================================

    public function testRoundTripDoesNotBreakWhenNoThoughtSignature(): void
    {
        // thoughtSignature なしの通常レスポンスでも正常に round-trip すること
        $firstParts = [
            ['functionCall' => ['name' => 'get_tags', 'args' => []]],
        ];
        $firstResponse = $this->makeGeminiResponse($firstParts, 'FUNCTION_CALL');
        $secondResponse = $this->makeGeminiResponse([['text' => '結果']], 'STOP');

        $mock = new MockHandler([$firstResponse, $secondResponse]);
        $history = [];
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');
        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        $tools = [
            ['name' => 'get_tags', 'description' => 'タグ', 'inputSchema' => ['type' => 'object', 'properties' => []]],
        ];

        $result = $agent->chat('hello', $tools, fn (string $n, array $a): array => []);
        self::assertSame('結果', $result['reply']);

        // model parts の args が {} に正規化されている — 生 JSON で検証
        $rawBody = (string) $history[1]['request']->getBody();
        $secondPayload = json_decode($rawBody, true);
        $modelParts = null;
        foreach ($secondPayload['contents'] as $entry) {
            if (($entry['role'] ?? '') === 'model') {
                $modelParts = $entry['parts'];
                break;
            }
        }
        self::assertNotNull($modelParts);
        self::assertStringContainsString('"args":{}', $rawBody);
    }

    public function testChatHandlesTextThoughtAndFunctionCallTogether(): void
    {
        // テキスト + thought + functionCall が混在するケース
        $firstParts = [
            ['text' => '少し考えます', 'thought' => true, 'thoughtSignature' => 'sig-text-1'],
            ['functionCall' => ['name' => 'search_products', 'args' => ['keyword' => 'v']]],
        ];
        $firstResponse = $this->makeGeminiResponse($firstParts, 'FUNCTION_CALL');
        $secondResponse = $this->makeGeminiResponse([['text' => 'done']], 'STOP');

        $mock = new MockHandler([$firstResponse, $secondResponse]);
        $history = [];
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));
        $client = new Client(['handler' => $handlerStack]);
        $agent = new GeminiAgent('k', 'gemini-2.5-flash');
        $prop = new \ReflectionProperty($agent, 'httpClient');
        $prop->setAccessible(true);
        $prop->setValue($agent, $client);

        $tools = [
            ['name' => 'search_products', 'description' => '検索', 'inputSchema' => ['type' => 'object', 'properties' => ['keyword' => ['type' => 'string']]]],
        ];

        $result = $agent->chat('hi', $tools, fn (string $n, array $a): array => []);
        self::assertSame('done', $result['reply']);

        $secondPayload = json_decode((string) $history[1]['request']->getBody(), true);
        $modelParts = null;
        foreach ($secondPayload['contents'] as $entry) {
            if (($entry['role'] ?? '') === 'model') {
                $modelParts = $entry['parts'];
                break;
            }
        }
        self::assertCount(2, $modelParts);
        self::assertSame('sig-text-1', $modelParts[0]['thoughtSignature']);
    }
}
