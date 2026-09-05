import { defineConfig } from '@playwright/test';

/**
 * Playwright config for Web MCP E2E (true HTTP e2e).
 *
 * - Base URL is docker-compose.verify.yml の eccube:80 -> host 8080 (4.2) / 8081 (4.3).
 *   E2E_BASE_URL で上書き可能。未設定時は 8080 を既定とするが、CI では必ず 8080 を明示すること。
 *   ローカルで EC-CUBE が無い場合は graceful skip (mcp.spec.ts 側で test.skip)。
 * - webServer は持たない。docker compose up で EC-CUBE を起動してから test:e2e を叩く運用。
 * - 既存の bun 前提は bash/playwright に置換。
 */
const baseURL = process.env.E2E_BASE_URL || 'http://localhost:8080';

export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.spec.ts',
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
  // docker 上の EC-CUBE が起動している前提。CI では verify-docker-install.yml が up 済み。
  // ローカルで自動起動したい場合はコメントアウトを外す:
  // webServer: {
  //   command: 'docker compose -f docker-compose.verify.yml up -d --build',
  //   url: `${baseURL}/.well-known/mcp.json`,
  //   timeout: 120_000,
  //   reuseExistingServer: true,
  // },
});
