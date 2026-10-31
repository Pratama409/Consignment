import { test, expect } from "@playwright/test";

// Relies on opening-balance.spec.js having activated 2.5000 of BROWSER-STOCK (specs run serially, in file order).
async function login(page, email) {
    await page.goto("/login");
    await page.getByLabel("Email", { exact: false }).fill(email);
    await page.getByLabel(/^Kata sandi/).fill("Browser-testing-password-123");
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(page).toHaveURL(/master-data\/barang$/);
}

const sidebar = (page) => page.locator(".desktop-sidebar");

test("Admin opens a session, Warehouse counts blind, Admin resolves the difference and prints the report", async ({ page, browser }) => {
    await login(page, "admin@browser.test");
    await sidebar(page).getByRole("link", { name: "Stock Opname", exact: true }).click();
    await page.getByRole("link", { name: "Buat Sesi" }).click();
    await page.getByRole("button", { name: "Buat Sesi" }).click();
    await expect(page.getByRole("heading", { level: 1 })).toContainText(/^SO-\d{8}-001$/);
    const sessionUrl = page.url();

    const warehouseContext = await browser.newContext();
    try {
        const warehouse = await warehouseContext.newPage();
        await login(warehouse, "viewer@browser.test");
        await warehouse.goto(sessionUrl);
        await expect(warehouse.getByText("Stok sistem tidak ditampilkan.")).toBeVisible();
        await expect(warehouse.getByText("2.5000")).toHaveCount(0);
        await warehouse.getByLabel("Hitung BROWSER-STOCK · Browser stock item").fill("1");
        await warehouse.getByRole("button", { name: "Simpan", exact: true }).click();
        warehouse.once("dialog", (dialog) => dialog.accept());
        await warehouse.getByRole("button", { name: "Kirim hasil hitung" }).click();
        await expect(warehouse.getByText("Menunggu persetujuan")).toBeVisible();
        await expect(warehouse.getByText("2.5000")).toHaveCount(0);
    } finally {
        await warehouseContext.close();
    }

    await page.reload();
    await expect(page.getByRole("row").filter({ hasText: "BROWSER-STOCK" })).toContainText("2.5000");
    await expect(page.getByRole("row").filter({ hasText: "BROWSER-STOCK" })).toContainText("-1.5000");
    await expect(page.getByRole("row").filter({ hasText: "BROWSER-STOCK" })).toContainText("Kurang");
    page.once("dialog", (dialog) => dialog.accept());
    await page.getByRole("button", { name: "Setujui hasil" }).click();
    await expect(page.getByRole("row").filter({ hasText: "BROWSER-STOCK" })).toContainText("DRAFT");

    await sidebar(page).getByRole("link", { name: "Koreksi Stok", exact: true }).click();
    const draft = page.getByRole("row").filter({ hasText: "Selisih Stock Opname" });
    page.once("dialog", (dialog) => dialog.accept("Disetujui"));
    await draft.getByRole("button", { name: "Setujui" }).click();
    page.once("dialog", (dialog) => dialog.accept());
    await draft.getByRole("button", { name: "Posting" }).click();
    await expect(draft).toContainText("POSTED");

    await page.goto(sessionUrl);
    await page.getByRole("button", { name: "Tutup sesi" }).click();
    await expect(page.getByText("Sesi Stock Opname ditutup.")).toBeVisible();
    await page.getByRole("link", { name: "Laporan" }).click();
    await expect(page.getByRole("heading", { name: "Laporan Stock Opname" })).toBeVisible();
    await expect(page.getByText("Admin Pengujian").first()).toBeVisible();
});
