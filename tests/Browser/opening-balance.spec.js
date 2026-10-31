import { test, expect } from "@playwright/test";

async function login(page, email = "admin@browser.test") {
    await page.goto("/login");
    await page.getByLabel("Email", { exact: false }).fill(email);
    await page.getByLabel(/^Kata sandi/).fill("Browser-testing-password-123");
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(page).toHaveURL(/master-data\/barang$/);
}

test("Admin corrects a manual opening preview then activates it", async ({ page }) => {
    await login(page);
    await page.getByRole("link", { name: "Saldo Awal", exact: true }).click();
    await page.getByRole("button", { name: "Entri manual" }).click();
    await page.getByLabel("Tanggal dan waktu cut-off").fill("2026-10-28T10:30");
    await page.getByLabel("ITEM_CODE baris 2").fill("UNKNOWN");
    await page.getByLabel("LOCATION_CODE baris 2").fill("UNASSIGNED");
    await page.getByLabel("QUANTITY baris 2").fill("1.0000");
    await page.getByRole("button", { name: "Validasi & Preview" }).click();
    await expect(page.getByRole("alert")).toContainText("Baris 2");

    await page.getByLabel("ITEM_CODE baris 2").fill("BROWSER-STOCK");
    await page.getByRole("button", { name: "Validasi & Preview" }).click();
    await expect(page.getByRole("heading", { name: "Preview Saldo Awal" })).toBeVisible();
    await expect(page.getByRole("row").filter({ hasText: "BROWSER-STOCK" })).toContainText("1.0000");

    await page.getByRole("link", { name: "Kembali" }).click();
    await page.getByRole("button", { name: "Entri manual" }).click();
    await page.getByLabel("Tanggal dan waktu cut-off").fill("2026-10-28T10:30");
    await page.getByLabel("ITEM_CODE baris 2").fill("BROWSER-STOCK");
    await page.getByLabel("LOCATION_CODE baris 2").fill("UNASSIGNED");
    await page.getByLabel("QUANTITY baris 2").fill("2.5000");
    await page.getByRole("button", { name: "Validasi & Preview" }).click();
    await expect(page.getByRole("row").filter({ hasText: "BROWSER-STOCK" })).toContainText("2.5000");

    page.once("dialog", (dialog) => dialog.accept());
    await page.getByRole("button", { name: "Aktifkan Saldo Awal" }).click();
    await expect(page.getByRole("status")).toContainText("Saldo awal berhasil diaktifkan");
    await expect(page.getByText("ACTIVATED", { exact: true })).toBeVisible();
});
