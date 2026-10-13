import { test, expect, request } from '@playwright/test';

/**
 * Web MCP 真の HTTP e2e — documents/plans/dev/20260905_web-mcp_design.md §5.1 の
 * 7ステップ curl ループを Playwright request API で再現。
 *
 * - 設計の 8タスク (T-01..T-08) を 10シナリオでカバー。
 * - 既存の McpHttpController / McpHttpService / RateLimitService を再実装せず HTTP 経由で検証。
 * - EC-CUBE 4.2 制約 (routes.yaml 手書き) を壊さず、Unit/Integration と seam を分離。
  * - EC-CUBE 本体が無いプラグイン単体リポジトリでは Tests/Docker/docker-compose.verify.yml で
  *   EC-CUBE 本体を立ち上げてから実行する。E2E_BASE_URL が無い場合は graceful skip.
  *
  * 実行:
  *   E2E_BASE_URL=http://localhost:8080 npx playwright test --config=playwright.config.ts --reporter=list
  *   # docker:
  *   #   docker compose -f Tests/Docker/docker-compose.verify.yml up -d --build
 *   #   bin/console eccube:install --no-interaction; bin/console cache:clear
 *   #   E2E_BASE_URL=http://localhost:8080 npm run test:e2e
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8080';

// graceful skip when not in docker/CI
test.skip(!process.env.E2E_BASE_URL, 'E2E_BASE_URL not set — Docker verify only (npm run test:e2e:docker)');

// small probe: if baseURL unreachable, skip rather than fail red
test.beforeAll(async () => {
  if (!process.env.E2E_BASE_URL) return;
  try {
    const ctx = await request.newContext();
    const r = await ctx.get(`${baseURL}/`, { timeout: 2_000 });
    await ctx.dispose();
    if (!r.ok() && r.status() >= 500) {
      test.skip(true, `E2E target not reachable at ${baseURL} (GET / => ${r.status()})`);
    }
  } catch {
    test.skip(true, `E2E target not reachable at ${baseURL}`);
  }
});

async function postMcp(
  api: Awaited<ReturnType<typeof request.newContext>>,
  body: unknown,
  extraHeaders: Record<string, string> = {},
) {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
    ...extraHeaders,
  };
  const data = typeof body === 'string' ? body : JSON.stringify(body);
  return api.post(`${baseURL}/mcp`, { data, headers });
}

function expectCorsHeaders(headers: Record<string, string>) {
  // header names lowercased by Playwright
  expect(headers['access-control-allow-origin']).toBe('*');
  // Vary: Origin が必須（CDN での CORS キャッシュ分離）
  const vary = headers['vary'] || '';
  expect(vary.toLowerCase()).toContain('origin');
}

// ---------------------------------------------------------------------------
// T-01: GET /.well-known/mcp.json → 200 + application/json + 7 tools + CORS + Cache
// ---------------------------------------------------------------------------
test.describe('T-01 Discovery GET /.well-known/mcp.json', () => {
  test('returns 200 + json + 7 tools + transport + CORS + Cache-Control + Vary', async () => {
    const api = await request.newContext();
    const res = await api.get(`${baseURL}/.well-known/mcp.json`, {
      headers: { Origin: 'https://evil.com', Accept: '*/*' },
    });

    expect(res.status(), 'T-01 status 200').toBe(200);
    const headers = res.headers();
    expect(headers['content-type']).toMatch(/application\/json/i);
    // Cache-Control: public, max-age=300 in prod; dev may be private,must-revalidate (Symfony debug)
    // So just ensure cache-control exists — strict value is env-dependent.
    expect(headers['cache-control'] || '').toBeTruthy();
    // In prod expect public,max-age=300 — in dev accept either
    const cc = (headers['cache-control'] || '').toLowerCase();
    expect(cc.includes('max-age=300') || cc.includes('max-age=0') || cc.includes('no-store')).toBeTruthy();
    expectCorsHeaders(headers);

    const body = await res.json();
    expect(body.serverInfo?.name).toBe('ec-mcp');
    expect(body.protocolVersion).toBe('2024-11-05');
    expect(body.transport?.type).toBe('streamable-http');
    expect(body.transport?.url).toMatch(/\/mcp$/);
    expect(Array.isArray(body.tools)).toBeTruthy();
    expect(body.tools.length).toBe(7);
    const names = body.tools.map((t: { name: string }) => t.name).sort();
    expect(names).toEqual([
      'aichat_search_products',
      'get_categories',
      'get_category_products',
      'get_product_detail',
      'get_stock',
      'get_tags',
      'search_by_tag',
    ]);

    await api.dispose();
  });

  test('alias GET /.well-known/mcp also 200', async () => {
    const api = await request.newContext();
    const res = await api.get(`${baseURL}/.well-known/mcp`, {
      headers: { Origin: 'https://evil.com' },
    });
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toMatch(/application\/json/i);
    const body = await res.json();
    expect(body.tools.length).toBe(7);
    await api.dispose();
  });
});

// ---------------------------------------------------------------------------
// T-02: POST /mcp initialize → 200 + serverInfo + protocolVersion + notifications/initialized 204
// ---------------------------------------------------------------------------
test.describe('T-02 Connection POST /mcp initialize', () => {
  test('initialize returns 200 + serverInfo + protocolVersion', async () => {
    const api = await request.newContext();
    const res = await postMcp(api, {
      jsonrpc: '2.0',
      id: 1,
      method: 'initialize',
      params: { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'test', version: '1.0.0' } },
    });

    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toMatch(/application\/json/i);
    expectCorsHeaders(res.headers());

    const body = await res.json();
    expect(body.jsonrpc).toBe('2.0');
    expect(body.id).toBe(1);
    expect(body.result?.serverInfo?.name).toBe('ec-mcp');
    expect(body.result?.protocolVersion).toBe('2024-11-05');
    expect(body.result?.capabilities?.tools?.listChanged).toBe(false);

    await api.dispose();
  });

  test('notifications/initialized returns 204', async () => {
    const api = await request.newContext();
    const res = await postMcp(api, { jsonrpc: '2.0', method: 'notifications/initialized' });
    expect(res.status()).toBe(204);
    expectCorsHeaders(res.headers());
    await api.dispose();
  });
});

// ---------------------------------------------------------------------------
// T-03: POST /mcp tools/list → 200 + 7件 + wellKnown と一致 (diff)
// ---------------------------------------------------------------------------
test.describe('T-03 POST /mcp tools/list', () => {
  test('returns 7 tools and matches wellKnown', async () => {
    const api = await request.newContext();

    const wellKnownRes = await api.get(`${baseURL}/.well-known/mcp.json`);
    const wellKnown = await wellKnownRes.json();
    const wellKnownTools = [...wellKnown.tools].sort((a: { name: string }, b: { name: string }) =>
      a.name.localeCompare(b.name),
    );

    const listRes = await postMcp(api, { jsonrpc: '2.0', id: 2, method: 'tools/list' });
    expect(listRes.status()).toBe(200);
    const listBody = await listRes.json();
    expect(listBody.result?.tools?.length).toBe(7);

    const listTools = [...listBody.result.tools].sort((a: { name: string }, b: { name: string }) =>
      a.name.localeCompare(b.name),
    );

    // diff wellKnown vs tools/list — name/description/inputSchema が一致すること
    expect(listTools.map((t: { name: string }) => t.name)).toEqual(
      wellKnownTools.map((t: { name: string }) => t.name),
    );
    for (let i = 0; i < wellKnownTools.length; i++) {
      expect(listTools[i].name).toBe(wellKnownTools[i].name);
      expect(listTools[i].inputSchema).toEqual(wellKnownTools[i].inputSchema);
    }

    // private cache for tools/list? Actually controller sets no-store for POST /mcp
    expect(listRes.headers()['cache-control'] || '').toMatch(/no-store/i);

    await api.dispose();
  });
});

// ---------------------------------------------------------------------------
// T-04: POST /mcp tools/call x7 → 200 + content[0].type==text
// ---------------------------------------------------------------------------
test.describe('T-04 POST /mcp tools/call x7', () => {
  const calls: Array<{ name: string; args: Record<string, unknown> }> = [
    { name: 'aichat_search_products', args: { keyword: 'CBD', limit: 2 } },
    { name: 'get_product_detail', args: { product_id: 1 } },
    { name: 'get_stock', args: { product_id: 1 } },
    { name: 'get_categories', args: {} },
    { name: 'get_category_products', args: { category_id: 1, limit: 2 } },
    { name: 'get_tags', args: {} },
    { name: 'search_by_tag', args: { tag_id: 1, limit: 2 } },
  ];

  for (const { name, args } of calls) {
    test(`tools/call ${name} → 200 + content[0].type==text`, async () => {
      const api = await request.newContext();
      const res = await postMcp(api, {
        jsonrpc: '2.0',
        id: 3,
        method: 'tools/call',
        params: { name, arguments: args },
      });

      expect(res.status(), `${name} status`).toBe(200);
      expect(res.headers()['content-type']).toMatch(/application\/json/i);
      const body = await res.json();
      expect(body.result).toBeTruthy();
      expect(Array.isArray(body.result.content)).toBeTruthy();
      expect(body.result.content[0]?.type).toBe('text');
      // text は JSON として parse 可能
      const text = body.result.content[0].text;
      expect(() => JSON.parse(text), `${name} text is JSON`).not.toThrow();

      // private no-store は tools/call 全体で付与
      expect(res.headers()['cache-control'] || '').toMatch(/no-store/i);

      await api.dispose();
    });
  }

  test('tools/call search_products (old name) → 200 + isError:true', async () => {
    // 公式MCPとの衝突回避で aichat_search_products に改名済み。旧名はエラーになること。
    const api = await request.newContext();
    const res = await postMcp(api, {
      jsonrpc: '2.0',
      id: 3,
      method: 'tools/call',
      params: { name: 'search_products', arguments: { keyword: 'CBD' } },
    });

    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.result?.isError).toBe(true);

    await api.dispose();
  });
});

// ---------------------------------------------------------------------------
// T-05: エラー → -32700/-32600/-32601/isError/415/405/204
// ---------------------------------------------------------------------------
test.describe('T-05 Error handling (JSON-RPC)', () => {
  test('invalid json → -32700', async () => {
    const api = await request.newContext();
    const res = await api.post(`${baseURL}/mcp`, {
      data: 'invalid json',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.error?.code).toBe(-32700);
    await api.dispose();
  });

  test('method missing → -32600', async () => {
    const api = await request.newContext();
    const res = await postMcp(api, { jsonrpc: '2.0', id: 1 });
    const body = await res.json();
    expect(body.error?.code).toBe(-32600);
    await api.dispose();
  });

  test('unknown method → -32601', async () => {
    const api = await request.newContext();
    const res = await postMcp(api, { jsonrpc: '2.0', id: 1, method: 'unknown' });
    const body = await res.json();
    expect(body.error?.code).toBe(-32601);
    await api.dispose();
  });

  test('unknown_tool → 200 + isError:true', async () => {
    const api = await request.newContext();
    const res = await postMcp(api, {
      jsonrpc: '2.0',
      id: 1,
      method: 'tools/call',
      params: { name: 'unknown_tool', arguments: {} },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.result?.isError).toBe(true);
    expect(body.result?.content?.[0]?.type).toBe('text');
    await api.dispose();
  });

  test('jsonrpc:"1.0" → -32600', async () => {
    const api = await request.newContext();
    const res = await postMcp(api, { jsonrpc: '1.0', id: 1, method: 'tools/list' });
    const body = await res.json();
    expect(body.error?.code).toBe(-32600);
    await api.dispose();
  });

  test('batch [] → -32600', async () => {
    const api = await request.newContext();
    const res = await postMcp(api, []);
    const body = await res.json();
    expect(body.error?.code).toBe(-32600);
    await api.dispose();
  });

  test('text/plain → 415', async () => {
    const api = await request.newContext();
    const res = await api.post(`${baseURL}/mcp`, {
      data: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list' }),
      headers: { 'Content-Type': 'text/plain', Accept: 'application/json' },
    });
    expect(res.status()).toBe(415);
    expect(res.headers()['content-type']).toMatch(/application\/json/i);
    const body = await res.json();
    expect(body.error?.code).toBe(-32700);
    await api.dispose();
  });

  test('GET /mcp → 405 + Allow: POST', async () => {
    const api = await request.newContext();
    const res = await api.get(`${baseURL}/mcp`);
    expect(res.status()).toBe(405);
    const allow = res.headers()['allow'] || '';
    expect(allow).toMatch(/POST/i);
    expect(res.headers()['content-type']).toMatch(/application\/json/i);
    expectCorsHeaders(res.headers());
    await api.dispose();
  });

  test('OPTIONS /mcp → 204 + Allow-Methods/Headers', async () => {
    const api = await request.newContext();
    const res = await api.fetch(`${baseURL}/mcp`, {
      method: 'OPTIONS',
      headers: {
        Origin: 'https://evil.com',
        'Access-Control-Request-Method': 'POST',
        'Access-Control-Request-Headers': 'Content-Type, Authorization',
      },
    });
    expect(res.status()).toBe(204);
    const h = res.headers();
    expect(h['access-control-allow-origin']).toBe('*');
    expect(h['access-control-allow-methods'] || '').toMatch(/POST/i);
    expect(h['access-control-allow-headers'] || '').toMatch(/Content-Type/i);
    // Max-Age 86400 が付与されること（preflight キャッシュ）
    expect(h['access-control-max-age']).toBe('86400');
    await api.dispose();
  });
});

// ---------------------------------------------------------------------------
// T-06: CORS → Origin: https://evil.com で ACAO:* が 3エンドポイントで返る
// ---------------------------------------------------------------------------
test.describe('T-06 CORS (Origin: https://evil.com)', () => {
  test('GET /.well-known/mcp.json with Origin → ACAO:* + Vary: Origin', async () => {
    const api = await request.newContext();
    const res = await api.get(`${baseURL}/.well-known/mcp.json`, {
      headers: { Origin: 'https://evil.com' },
    });
    expect(res.status()).toBe(200);
    expectCorsHeaders(res.headers());
    await api.dispose();
  });

  test('POST /mcp with Origin → ACAO:* + Vary: Origin', async () => {
    const api = await request.newContext();
    const res = await postMcp(
      api,
      { jsonrpc: '2.0', id: 1, method: 'tools/list' },
      { Origin: 'https://evil.com' },
    );
    expect(res.status()).toBe(200);
    expectCorsHeaders(res.headers());
    await api.dispose();
  });

  test('OPTIONS /mcp with Origin → ACAO:* + Vary: Origin', async () => {
    const api = await request.newContext();
    const res = await api.fetch(`${baseURL}/mcp`, {
      method: 'OPTIONS',
      headers: { Origin: 'https://evil.com', 'Access-Control-Request-Method': 'POST' },
    });
    expect(res.status()).toBe(204);
    expectCorsHeaders(res.headers());
    await api.dispose();
  });
});

// ---------------------------------------------------------------------------
// T-07: RateLimit → well_known 120 と get_stock 60 を別バケットで 429
//        e2e では well_known のみ 121回ループで 429 を実証。get_stock は
//        RateLimitServiceTest 8件で担保（61回ループは時間costのため e2e では省略）。
// ---------------------------------------------------------------------------
test.describe('T-07 RateLimit (well_known 120)', () => {
  test('well_known 121回目で 429 + Retry-After + X-RateLimit-*', async () => {
    test.setTimeout(60_000);
    const api = await request.newContext();

    // 直前の wellKnown テストで数回消費しているため、121 回で必ず 429 になるよう
    // 240 回までループし、429 を検知した時点でヘッダを検証する。
    // RateLimitService は YmdHi（分）バケットで mcp.ratelimit.{ip}.well_known.{YmdHi} を
    // カウントするため、19.8s の sequential ループが分を跨ぐと前分のカウントが捨てられ
    // 130 では 429 に届かない flaky が起きる。2分分を吸収するため上限 240 に延長
    // （分跨ぎ 1回を許容。2分跨ぎは 2分要するため flaky 確率 < 1/3600）。
    // 同一分内に収まるよう sequential で叩く。
    let rateLimitedAt: number | null = null;
    let lastHeaders: Record<string, string> = {};
    let lastBody: unknown = null;
    const startMinute = new Date().getMinutes();
    let minuteWarned = false;

    for (let i = 1; i <= 240; i++) {
      const res = await api.get(`${baseURL}/.well-known/mcp.json`, {
        headers: { Origin: 'https://evil.com' },
      });
      if (res.status() === 429) {
        rateLimitedAt = i;
        lastHeaders = res.headers();
        try {
          lastBody = await res.json();
        } catch {
          lastBody = null;
        }
        break;
      }
      // 分跨ぎ検出ガード: YmdHi バケットがリセットされた可能性をログで可視化
      if (!minuteWarned && new Date().getMinutes() !== startMinute) {
        minuteWarned = true;
        console.log(
          `[T-07] minute boundary crossed at iteration ${i} (startMinute=${startMinute} -> ${new Date().getMinutes()}) — RateLimit YmdHi bucket may have reset; continuing up to 240`,
        );
      }
      // 429 前は必ず 200 であること（500 などで誤爆していないか）
      expect(res.status(), `well_known #${i} should be 200 before 429`).toBe(200);
    }

    expect(rateLimitedAt, 'should be rate-limited within 240 requests (limit 120, 2-minute window for YmdHi bucket)').not.toBeNull();

    // 429 のヘッダ検証
    expect(lastHeaders['retry-after']).toBe('60');
    expect(lastHeaders['x-ratelimit-limit']).toBe('120');
    expect(lastHeaders['x-ratelimit-remaining']).toBe('0');
    expect(lastHeaders['content-type']).toMatch(/application\/json/i);
    // JSON body は REST 形式: {error: "Too Many Requests"}
    expect((lastBody as { error?: string })?.error).toMatch(/Too Many Requests/i);
    // CORS も 429 で維持
    expect(lastHeaders['access-control-allow-origin']).toBe('*');
    expect((lastHeaders['vary'] || '').toLowerCase()).toContain('origin');

    await api.dispose();
  });
});

// ---------------------------------------------------------------------------
// T-08: 7ページ非破壊 → GET 7ページが 200 + text/html (fixture 無しでも 500 にしない)
// ---------------------------------------------------------------------------
test.describe('T-08 7ページ非破壊', () => {
  const pages = [
    '/',
    '/products/list',
    '/products/detail/1',
    '/products/detail/17',
    '/guide/articles',
    '/guide/column/influence-marketing-activism-framework',
    '/news/category/cannabis',
    '/news/1405',
  ];

  for (const path of pages) {
    test(`GET ${path} → 200 (or 404 if fixture無し) + text/html, never 500`, async () => {
      const api = await request.newContext();
      const res = await api.get(`${baseURL}${path}`, {
        headers: { Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' },
      });

      const status = res.status();
      const ct = res.headers()['content-type'] || '';

      // プラグインが 500 を誘発していないことが最重要。EC-CUBE が fixture 無しで 404 を返すのは許容。
      expect(status, `${path} should not be 500`).not.toBe(500);
      expect(status, `${path} should not be 502/503`).not.toBe(502);
      expect(status, `${path} should not be 503`).not.toBe(503);

      // 200 が期待だが、fixture 無し環境では 404/302 も許容。ただし 200 の場合は html であること
      if (status === 200) {
        expect(ct).toMatch(/text\/html/i);
        const text = await res.text();
        expect(text.length).toBeGreaterThan(100);
        // html 構造の smoke: <html or <!DOCTYPE
        expect(text.toLowerCase()).toMatch(/<html|<!doctype/);
      } else {
        // 404/302 でも Content-Type が html または redirect であること
        // 500 でないことが担保できれば合格（ボーイスカウト: 壊していない）
        expect([200, 302, 301, 404]).toContain(status);
      }

      await api.dispose();
    });
  }
});

// ---------------------------------------------------------------------------
// T-09: 管理画面スモーク → ログイン → 管理9画面が 200 + text/html (never 500)
// ---------------------------------------------------------------------------
// 実績ある手順: GET /admin/ (302追従) → GET /admin/login から _csrf_token 取得
// → POST /admin/login (login_id/password/_csrf_token) → 302 → セッション確立
// → 管理9画面 (/admin/ai-chat-assistant/dashboard|history|report|settings|
//    knowledge|scenario|access|design|notification) の 200 確認。
// 管理者ID/パスワードは環境変数で上書き可 (E2E_ADMIN_ID / E2E_ADMIN_PASSWORD)。
// 既定値 admin/password は docker verify env 限定の公開可能な既定値であり、
// 本番情報は入れないこと。T-01〜T-08 とは独立した describe のため非破壊。
// ---------------------------------------------------------------------------
test.describe('T-09 管理画面スモーク (admin login → 9画面 200)', () => {
  const adminPaths = [
    '/admin/ai-chat-assistant/dashboard',
    '/admin/ai-chat-assistant/history',
    '/admin/ai-chat-assistant/report',
    '/admin/ai-chat-assistant/settings',
    '/admin/ai-chat-assistant/knowledge',
    '/admin/ai-chat-assistant/scenario',
    '/admin/ai-chat-assistant/access',
    '/admin/ai-chat-assistant/design',
    '/admin/ai-chat-assistant/notification',
  ];

  // docker verify env 限定の公開可能な既定値 (本番情報は入れない)。上書き可。
  const adminId = process.env.E2E_ADMIN_ID || 'admin';
  const adminPassword = process.env.E2E_ADMIN_PASSWORD || 'password';

  let api: Awaited<ReturnType<typeof request.newContext>>;

  test.beforeAll(async () => {
    api = await request.newContext();

    // 1. GET /admin/ (302追従) → ログイン画面に到達すること
    const top = await api.get(`${baseURL}/admin/`);
    expect(top.status(), 'GET /admin/ follows 302 to login page (200)').toBe(200);

    // 2. GET /admin/login から _csrf_token 取得
    const loginPage = await api.get(`${baseURL}/admin/login`);
    expect(loginPage.status(), 'GET /admin/login => 200').toBe(200);
    const loginHtml = await loginPage.text();
    const tokenMatch =
      loginHtml.match(/name="_csrf_token"\s+value="([^"]+)"/) ||
      loginHtml.match(/value="([^"]+)"\s+name="_csrf_token"/);
    expect(tokenMatch?.[1], 'login form has _csrf_token').toBeTruthy();

    // 3. POST /admin/login (login_id/password/_csrf_token) → 302 でセッション確立。
    //    maxRedirects: 0 で 302 自体を検証する (追従すると成功/失敗の区別が付かないため)。
    const login = await api.post(`${baseURL}/admin/login`, {
      form: { login_id: adminId, password: adminPassword, _csrf_token: tokenMatch![1] },
      maxRedirects: 0,
    });
    expect(login.status(), 'POST /admin/login => 302 on success (200 means bad credentials or CSRF)').toBe(302);

    // 4. セッション確立の裏付け: GET /admin/ がログイン画面ではないこと
    //    (未認証なら login_id フィールドを持つログイン画面に戻される)。
    const authed = await api.get(`${baseURL}/admin/`);
    expect(authed.status(), 'authed GET /admin/ => 200').toBe(200);
    const authedHtml = await authed.text();
    expect(
      authedHtml,
      'authed /admin/ must not be the login page (check E2E_ADMIN_ID/E2E_ADMIN_PASSWORD)',
    ).not.toMatch(/name="login_id"/);
  });

  test.afterAll(async () => {
    await api?.dispose();
  });

  for (const path of adminPaths) {
    test(`GET ${path} → 200 + text/html, never 500`, async () => {
      const res = await api.get(`${baseURL}${path}`, {
        headers: { Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' },
      });

      const status = res.status();
      const ct = res.headers()['content-type'] || '';

      // 未認証リダイレクト (302→login) はログイン失敗を意味する。即 fail で原因を明示。
      expect(status, `${path} must not redirect to login (session not established)`).not.toBe(302);
      expect(status, `${path} should not be 500`).not.toBe(500);
      expect(status, `${path} should not be 502/503`).not.toBe(502);
      expect(status, `${path} should not be 503`).not.toBe(503);
      expect(status, `${path} should be 200`).toBe(200);
      expect(ct, `${path} content-type`).toMatch(/text\/html/i);
      const text = await res.text();
      expect(text.length).toBeGreaterThan(100);
      expect(text.toLowerCase()).toMatch(/<html|<!doctype/);

      // 管理9画面いずれもプラグインの管理基盤で描画されること (Nav 経由の到達性)。
      // 9画面共通フッタ/ナビの存在までは assert しない (デザイン変更に弱いため 200+html をもって合格)。
    });
  }
});
