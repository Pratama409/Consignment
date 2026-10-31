import { test, expect } from "@playwright/test";

async function login(page, email) {
    await page.goto("/login");
    await page.getByLabel("Email", { exact: false }).fill(email);
    await page.getByLabel(/^Kata sandi/).fill("Browser-testing-password-123");
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(page).toHaveURL(/master-data\/barang$/);
}

test("dashboard analytics renders for admin and the theme choice survives a reload", async ({ page }) => {
    await login(page, "admin@browser.test");
    await page.goto("/");
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Dashboard Analytics");
    await expect(page.getByText("Operational Health")).toBeVisible();
    await expect(page.getByRole("navigation", { name: "Pintasan" }).getByRole("link", { name: "Stock Opname" })).toBeVisible();

    await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
    await page.getByRole("button", { name: "Ganti ke tema gelap" }).click();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
    await page.reload();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
    await expect(page.getByRole("button", { name: "Ganti ke tema terang" })).toHaveAttribute("aria-pressed", "true");
});

test("dashboard has no horizontal page scroll on a phone and the sidebar groups expand", async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await login(page, "admin@browser.test");
    await page.goto("/");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

    await page.getByRole("button", { name: "Buka navigasi" }).click();
    const drawer = page.getByRole("dialog");
    const group = drawer.getByRole("button", { name: /Pengadaan/ });
    await expect(group).toHaveAttribute("aria-expanded", "false");
    await group.click();
    await expect(group).toHaveAttribute("aria-expanded", "true");
    await expect(drawer.getByRole("link", { name: "PR, PO & Dokumen" })).toBeVisible();
});
