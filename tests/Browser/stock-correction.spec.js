import { test, expect } from "@playwright/test";

async function login(page, email) {
    await page.goto("/login");
    await page.getByLabel("Email", { exact: false }).fill(email);
    await page.getByLabel(/^Kata sandi/).fill("Browser-testing-password-123");
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(page).toHaveURL(/master-data\/barang$/);
}

test("Warehouse creates a correction draft and Admin rejects it with visible scope", async ({ page, browser }) => {
    await login(page, "viewer@browser.test");
    await page.getByRole("link", { name: "Koreksi Stok", exact: true }).click();
    await page.getByRole("link", { name: "Ajukan Koreksi" }).click();
    await page.getByLabel("Barang").selectOption({ label: "BROWSER-STOCK · Browser stock item" });
    await page.getByLabel("Lokasi").selectOption({ label: "UNASSIGNED · Lokasi belum ditentukan" });
    await page.getByLabel("Quantity").fill("1.2500");
    await page.getByLabel("Alasan dan bukti").fill("Selisih hitung browser");
    await page.getByRole("button", { name: "Simpan Draft" }).click();
    await expect(page.getByRole("row").filter({ hasText: "Selisih hitung browser" })).toContainText("UNASSIGNED");

    const adminContext = await browser.newContext();
    try {
        const admin = await adminContext.newPage();
        await login(admin, "admin@browser.test");
        await admin.getByRole("link", { name: "Koreksi Stok", exact: true }).click();
        admin.once("dialog", (dialog) => dialog.accept("Bukti tidak sesuai"));
        await admin.getByRole("row").filter({ hasText: "Selisih hitung browser" }).getByRole("button", { name: "Tolak" }).click();
        await expect(admin.getByRole("row").filter({ hasText: "Selisih hitung browser" })).toContainText("REJECTED");
        await expect(admin.getByPlaceholder("Cari nomor transaksi")).toBeVisible();
    } finally {
        await adminContext.close();
    }
});
