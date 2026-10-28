import { defineConfig } from "@playwright/test";
import { existsSync } from "node:fs";

const installedBrowser = [
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
    "C:/Program Files/Google/Chrome/Application/chrome.exe",
    "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
].find((path) => path && existsSync(path));

export default defineConfig({
    testDir: "./tests/Browser",
    testMatch: "**/*.spec.js",
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [["list"], ["json", { outputFile: "test-results/results.json" }]],
    use: {
        baseURL: "http://127.0.0.1:8011",
        viewport: { width: 1440, height: 1000 },
        launchOptions: installedBrowser
            ? { executablePath: installedBrowser }
            : {},
        screenshot: "only-on-failure",
        trace: "retain-on-failure",
    },
    webServer: {
        command: "node tests/Browser/start-server.mjs",
        url: "http://127.0.0.1:8011/login",
        reuseExistingServer: false,
        timeout: 60000,
    },
});
