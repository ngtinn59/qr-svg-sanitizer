import { defineConfig, devices } from '@playwright/test';

/**
 * Three engines, one spec.
 *
 * The claim under test is about HTML tokenisation, so it has to be checked on
 * every engine family rather than inferred from the specification alone:
 * Blink, Gecko and WebKit each ship their own tokeniser implementation.
 */
export default defineConfig({
  testDir: '.',
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
  forbidOnly: !!process.env.CI,
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
});
