const { defineConfig, devices } = require('@playwright/test');

// The sandbox ships Chromium at /opt/pw-browsers/chromium; use it instead of downloading.
const executablePath = process.env.PW_CHROMIUM || '/opt/pw-browsers/chromium';

module.exports = defineConfig({
  testDir: __dirname,
  timeout: 30000,
  fullyParallel: false,      // tests share the in-memory /event log on one server
  workers: 1,
  reporter: [['list']],
  use: {
    headless: true,
    launchOptions: require('fs').existsSync(executablePath) ? { executablePath } : {},
  },
  projects: [
    // Desktop + a cheap-Android viewport, so mobile breakpoints get exercised in CI.
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile-360', use: { viewport: { width: 360, height: 740 }, isMobile: true } },
  ],
});
