import { mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawn, spawnSync } from "node:child_process";

const directory = mkdtempSync(join(tmpdir(), "consignment-ui-"));
const environment = {
    ...process.env,
    APP_ENV: "testing",
    APP_MAINTENANCE_DRIVER: "cache",
    APP_URL: "http://127.0.0.1:8011",
    APP_KEY: "base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
    DB_CONNECTION: "sqlite",
    DB_DATABASE: join(directory, "browser.sqlite"),
    DB_URL: "",
    SESSION_DRIVER: "database",
    CACHE_STORE: "array",
    QUEUE_CONNECTION: "sync",
    INERTIA_SSR_ENABLED: "false",
    XDEBUG_MODE: "off",
};

const prepared = spawnSync("php", ["tests/Browser/prepare.php"], {
    env: environment,
    stdio: "inherit",
    windowsHide: true,
});
if (prepared.status !== 0) process.exit(prepared.status ?? 1);

const server = spawn(
    "php",
    ["artisan", "serve", "--host=127.0.0.1", "--port=8011", "--no-reload"],
    {
        env: environment,
        stdio: "inherit",
        windowsHide: true,
    },
);

function shutdown() {
    if (process.platform === "win32" && server.pid) {
        spawnSync("taskkill", ["/PID", String(server.pid), "/T", "/F"], {
            windowsHide: true,
            stdio: "ignore",
        });
    } else server.kill("SIGTERM");
    process.exit(0);
}
process.once("SIGTERM", shutdown);
process.once("SIGINT", shutdown);
server.once("exit", (code) => process.exit(code ?? 0));
