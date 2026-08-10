import { defineConfig, devices } from '@playwright/test';

/**
 * Family Castel E2E — runs against the docker dev stack by default
 * (http://localhost:8090, demo family installed). Override with FC_BASE_URL.
 */
export default defineConfig({
  testDir: './specs',
  fullyParallel: false,          // suites share one demo database
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
  use: {
    baseURL: process.env.FC_BASE_URL ?? 'http://localhost:8090',
    locale: 'de-CH',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
    },
    {
      name: 'mobile',
      // Chromium-only install: emulate the phone on Chromium (device default is WebKit).
      use: { ...devices['iPhone 14'], browserName: 'chromium', viewport: { width: 390, height: 844 } },
    },
  ],
});
