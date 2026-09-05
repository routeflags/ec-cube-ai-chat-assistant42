# Web MCP E2E (Playwright — true HTTP e2e)

`documents/plans/dev/20260905_web-mcp_design.md` §5.1 の 7ステップ curl ループを
Playwright の `request` API で再現する真の HTTP e2e。`McpHttpController` /
`McpHttpService` / `RateLimitService` を再実装せず HTTP 経由で検証する。

## 技術選定

- **候補1: Playwright (採用)** — Node.js で `http://eccube:80` (docker-compose.verify.yml) に対して
  `request.newContext().post()` を実行。EC-CUBE 本体が無いプラグイン単体リポジトリでも
  `docker-compose.verify.yml` で EC-CUBE 4.2/4.3 本体を立ち上げて e2e 可能。CI 安定。
- **候補2: CDP (chrome-devtools MCP)** — ブラウザ経由で `fetch`。CI の headless が
  不安定なため不採用。

配置: `e2e/mcp.spec.ts` + `playwright.config.ts`。EC-CUBE 4.2 制約
(`Resource/config/routes.yaml` 手書き) を壊さず、既存の Unit/Integration と seam を分ける。

## シナリオ対応 (8タスク / 10シナリオ → 33テスト)

| Spec | 設計 §5.1 | テスト | 検証 |
|------|-----------|--------|------|
| T-01 | `GET /.well-known/mcp.json` → 200 | `T-01 Discovery` 2 tests | `application/json` + `tools.length==7` + `transport.type==streamable-http` + `serverInfo.name==ec-mcp` + CORS `*` + `Cache-Control: public,max-age=300` + `Vary: Origin` + alias `/.well-known/mcp` |
| T-02 | `POST /mcp initialize` → 200 | `T-02 Connection` 2 tests | `result.serverInfo.name==ec-mcp` + `protocolVersion 2024-11-05` + `notifications/initialized → 204` |
| T-03 | `POST /mcp tools/list` → 7件 | `T-03 tools/list` 1 test | 7件 + wellKnown と一致 (diff) |
| T-04 | `POST /mcp tools/call x7` → 200 | `T-04 tools/call x7` 7 tests | `search_products/get_product_detail/get_stock/get_categories/get_category_products/get_tags/search_by_tag` → 200 + `content[0].type==text` + JSON valid |
| T-05 | エラー | `T-05 Error handling` 9 tests | invalid json -32700, method欠落 -32600, unknown -32601, unknown_tool isError:true, jsonrpc:"1.0" -32600, batch [] -32600, text/plain 415, GET /mcp 405 + Allow: POST, OPTIONS 204 + Allow-Methods/Headers + Max-Age |
| T-06 | CORS | `T-06 CORS` 3 tests | `Origin: https://evil.com` で `ACAO:*` + `Vary: Origin` が 3エンドポイントで返る |
| T-07 | RateLimit | `T-07 RateLimit` 1 test | `well_known 120` を別バケットで 121回目に 429 + `Retry-After:60` + `X-RateLimit-*` |
| T-08 | 7ページ非破壊 | `T-08 7ページ非破壊` 8 tests | `GET /, /products/list, /products/detail/{1,17}, /guide/articles, /guide/column/... , /news/category/cannabis, /news/1405` が 500 にしない (200 + html を期待、fixture 無しでは 404/302 も許容) |

> **Note:** `limit clamp / 空keyword / stock:null 曖昧化` は `ProductRepository` の Unit で担保。e2e では 7件全て 200 のみを検証。
> `get_stock 60` の 61回ループは時間costのため `RateLimitServiceTest` 8件で代替し、e2e では `well_known` のみ 429 を実証。

## 前提

- Node >=18, `npm install` 済み
- Docker: `docker compose` v2

## ローカル実行 (EC-CUBE 有り — graceful skip)

```bash
# 依存インストール
npm install
npx playwright install --with-deps chromium

# 通常は E2E_BASE_URL が無いと全テスト skip (graceful)
npx playwright test --config=playwright.config.ts --reporter=list
# → 33 skipped
```

## Docker での試験実施 (推奨)

`docker-compose.verify.yml` (EC-CUBE 4.2 本体 + プラグイン mount) を用い、
`ECCUBE_PORT=8080` (4.2) と `8081` (4.3) で試験。`verify-docker-install.yml` の 4.2/4.3 マトリクス同様、
e2e は 4.2 の 8080 のみで実行し 4.3 は手動で代替。

```bash
# 1. EC-CUBE 4.2 を clone 済み想定 (/tmp/eccube-verify-4.2) — verify-docker-install.sh が自動で行う
#    手動で行う場合:
#    git clone -b 4.2 https://github.com/EC-CUBE/ec-cube.git /tmp/eccube-verify

# 2. 起動 + インストール + enable
PLUGIN_DIR="$(pwd)"  # このプラグインの root
TMPDIR=/tmp/eccube-verify-4.2
cp docker-compose.verify.yml "$TMPDIR/docker-compose.verify.yml"
cp .env.verify "$TMPDIR/.env"
(
  cd "$TMPDIR"
  PHP_IMAGE=php:8.1-apache-bullseye PLUGIN_DIR="$PLUGIN_DIR" ECCUBE_PORT=8080 \
    docker compose -f docker-compose.verify.yml up -d --build
  # 起動待ち
  for i in $(seq 1 30); do docker compose -f docker-compose.verify.yml exec -T eccube php -v >/dev/null 2>&1 && break; sleep 2; done
  docker compose -f docker-compose.verify.yml exec -T eccube php bin/console eccube:install --no-interaction
  docker compose -f docker-compose.verify.yml exec -T eccube php bin/console eccube:plugin:install --code=AiChatAssistant42
  docker compose -f docker-compose.verify.yml exec -T eccube php bin/console eccube:plugin:enable  --code=AiChatAssistant42
  docker compose -f docker-compose.verify.yml exec -T eccube php bin/console cache:clear --no-warmup
  docker compose -f docker-compose.verify.yml exec -T eccube php bin/console cache:warmup
)

# 3. e2e 実行 (host から Playwright で 8080 を叩く)
E2E_BASE_URL=http://localhost:8080 npm run test:e2e
# または
E2E_BASE_URL=http://localhost:8080 npx playwright test --config=playwright.config.ts --reporter=list

# 4. 後片付け
(cd /tmp/eccube-verify-4.2 && docker compose -f docker-compose.verify.yml down -v)
```

### 代替: verify-docker-install.sh 経由

```bash
./bin/verify-docker-install.sh --version 4.2 --keep
E2E_BASE_URL=http://localhost:8080 npm run test:e2e
docker compose -f /tmp/eccube-verify-4.2/docker-compose.verify.yml down -v
```

## bin/verify-plugin.sh との分離

`bin/verify-plugin.sh` はローカルゲート (composer validate / php -l / hardcode / routes) のみ。
e2e は Docker 上でのみ実行する explicit な `npm run test:e2e` に分離する。

## トラブルシュート

| 症状 | 原因 | 対処 |
|------|------|------|
| `33 skipped` | `E2E_BASE_URL` 未設定 | `E2E_BASE_URL=http://localhost:8080` を付けて実行 |
| `E2E target not reachable` | `docker compose up` していない | `docker compose -f /tmp/eccube-verify-4.2/docker-compose.verify.yml ps` で確認 |
| `E2E target not reachable` on `8080` | host `8080` が code-server 等に占拠 | `ECCUBE_PORT=8085` で起動し `E2E_BASE_URL=http://localhost:8085` で実行（例: `ECCUBE_PORT=8085 docker compose -f docker-compose.verify.yml up -d && E2E_BASE_URL=http://localhost:8085 npx playwright test`） |
| `500` on `/mcp` | `cache:clear` 未実行 / `trusted_proxies` 未設定 | `bin/console cache:clear --no-warmup && cache:warmup` |
| `405` on `POST /mcp` | ルートが登録されていない | `bin/console debug:router | grep mcp` で 3ルート確認 |
| `GC: 429 not reached` | 前分の bucket がリセット (分跨ぎ) | `RateLimitService` は `YmdHi`（分）バケット `mcp.ratelimit.{ip}.well_known.{YmdHi}` のため、分を跨ぐとカウントがリセットされる。hotfix で上限 240（2分分）に延長済みのため通常は再現しない。依然発生する場合は分境界付近を避けて再実行 |

## 成果物

- `e2e/mcp.spec.ts` — 33 tests, 8タスク分 10シナリオ
- `playwright.config.ts` — `E2E_BASE_URL` 対応, `workers:1, fullyParallel:false`
- `package.json` — `playwright` devDependency + `test:e2e` スクリプト
- `e2e/README.md` — 本ファイル

## 補足: host 8080 が code-server に占拠される場合

ローカルで `code-server` が `8080` を占有している環境では、`ECCUBE_PORT` を `8085` 等にずらして起動する。e2e の `E2E_BASE_URL` も合わせる。

```bash
ECCUBE_PORT=8085 PLUGIN_DIR="$(pwd)" docker compose -f docker-compose.verify.yml up -d --build
E2E_BASE_URL=http://localhost:8085 npx playwright test --config=playwright.config.ts --reporter=list
# → 33 passed
```
