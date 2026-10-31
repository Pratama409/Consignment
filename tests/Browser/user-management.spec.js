import { test, expect } from "@playwright/test";

async function login(page, email = "admin@browser.test", password = "Browser-testing-password-123") {
    await page.goto("/login");
    await page.getByLabel("Email", { exact: false }).fill(email);
    await page.getByLabel(/^Kata sandi/).fill(password);
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(page).toHaveURL(/master-data\/barang$/);
}

test("admin manages users with validation, filters, preserved password and explicit status actions", async ({ page }) => {
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await login(page);
    await page.locator(".desktop-sidebar").getByRole("link", { name: "User Management", exact: true }).click();
    await page.getByRole("link", { name: "Tambah Pengguna", exact: true }).click();
    await page.getByRole("button", { name: "Simpan Pengguna", exact: true }).click();
    await expect(page.getByRole("alert").first()).toBeVisible();
    await expect(page.getByLabel("Nama pengguna", { exact: false })).toBeFocused();
    await page.getByLabel("Nama pengguna", { exact: false }).fill("Operator Browser");
    await page.getByLabel("Email pengguna", { exact: false }).fill("operator@browser.test");
    await page.getByLabel("Kata sandi pengguna", { exact: false }).fill("Operator-password-123");
    await page.getByLabel("Role pengguna", { exact: false }).selectOption("vendor");
    await page.getByRole("button", { name: "Simpan Pengguna", exact: true }).click();
    await expect(page.locator("#vendor-id-error")).toBeVisible();
    await page.getByLabel("Role pengguna", { exact: false }).selectOption("warehouse");
    await page.getByRole("button", { name: "Simpan Pengguna", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Manajemen Pengguna", exact: true })).toBeVisible();
    await page.getByLabel("Cari pengguna", { exact: true }).fill("Operator Browser");
    await page.getByLabel("Filter role pengguna").selectOption("warehouse");
    await expect(page.locator("tbody tr")).toHaveCount(1);
    let row = page.getByRole("row").filter({ hasText: "operator@browser.test" });
    await row.getByRole("link", { name: "Edit pengguna Operator Browser" }).click();
    await expect(page.getByLabel("Kata sandi pengguna", { exact: false })).toHaveValue("");
    await page.getByLabel("Nama pengguna", { exact: false }).fill("Operator Diperbarui");
    for (const width of [360, 768, 1280, 1920]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: `test-results/user-form-${width}.png`, fullPage: true });
    }
    await page.getByRole("button", { name: "Simpan Pengguna", exact: true }).click();
    row = page.getByRole("row").filter({ hasText: "operator@browser.test" });
    await row.getByRole("button", { name: "Nonaktifkan pengguna Operator Diperbarui" }).click();
    let dialog = page.getByRole("dialog", { name: "Nonaktifkan Pengguna?", exact: true });
    await expect(dialog).toContainText("Operator Diperbarui");
    await dialog.getByRole("button", { name: "Nonaktifkan Pengguna", exact: true }).click();
    await expect(row.locator(".status-badge")).toHaveText("Nonaktif");
    await row.getByRole("button", { name: "Aktifkan pengguna Operator Diperbarui" }).click();
    dialog = page.getByRole("dialog", { name: "Aktifkan Pengguna?", exact: true });
    await dialog.getByRole("button", { name: "Aktifkan Pengguna", exact: true }).click();
    await expect(row.locator(".status-badge")).toHaveText("Aktif");
    for (const width of [360, 768, 1280, 1920]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: `test-results/user-index-${width}.png`, fullPage: true });
    }
    await page.locator(".desktop-sidebar").getByRole("button", { name: "Keluar", exact: true }).click();
    await login(page, "operator@browser.test", "Operator-password-123");
    await expect(page.locator(".desktop-sidebar").getByRole("link", { name: "User Management" })).toHaveCount(0);
    expect((await page.request.get("/users")).status()).toBe(403);
    expect(errors).toEqual([]);
});

test("Vendor selection and filters persist, and deactivation ends an existing Vendor session", async ({ page, browser }) => {
    await login(page);
    await page.goto("/master-data/vendor");
    await page.getByRole("button", { name: "Tambah Vendor", exact: true }).first().click();
    const vendorDialog = page.getByRole("dialog", { name: "Tambah Vendor", exact: true });
    await vendorDialog.getByLabel("Kode Vendor", { exact: false }).fill("USER-SCOPE");
    await vendorDialog.getByLabel("Nama Vendor", { exact: false }).fill("User scope supplier");
    await vendorDialog.getByRole("button", { name: "Simpan Vendor", exact: true }).click();
    await expect(vendorDialog).not.toBeVisible();

    await page.goto("/users/create");
    await page.getByLabel("Nama pengguna", { exact: false }).fill("Vendor Browser");
    await page.getByLabel("Email pengguna", { exact: false }).fill("vendor-user@browser.test");
    await page.getByLabel("Kata sandi pengguna", { exact: false }).fill("Vendor-password-123");
    await page.getByLabel("Role pengguna", { exact: false }).selectOption("vendor");
    const vendorSelect = page.getByLabel("Vendor pengguna", { exact: false });
    await vendorSelect.selectOption({ label: "USER-SCOPE — User scope supplier" });
    const vendorId = await vendorSelect.inputValue();
    await page.getByRole("button", { name: "Simpan Pengguna", exact: true }).click();
    await expect(page).toHaveURL(/\/users$/);
    await page.getByLabel("Filter role pengguna").selectOption("vendor");
    await expect(page).toHaveURL((url) => url.searchParams.get("role") === "vendor");
    await page.getByLabel("Filter Vendor pengguna").selectOption(vendorId);
    await expect(page).toHaveURL((url) => url.searchParams.get("vendor") === vendorId);
    await page.getByLabel("Filter status pengguna").selectOption("active");
    await expect(page).toHaveURL((url) => url.searchParams.get("status") === "active");
    const row = page.getByRole("row").filter({ hasText: "vendor-user@browser.test" });
    await expect(row).toBeVisible();
    await page.reload();
    await expect(page.getByLabel("Filter Vendor pengguna")).toHaveValue(vendorId);
    await row.getByRole("link", { name: "Edit pengguna Vendor Browser" }).click();
    await expect(vendorSelect).toHaveValue(vendorId);
    await page.getByRole("link", { name: "Kembali ke daftar pengguna", exact: true }).click();

    const vendorContext = await browser.newContext();
    try {
        const vendorPage = await vendorContext.newPage();
        await login(vendorPage, "vendor-user@browser.test", "Vendor-password-123");
        const sidebar = vendorPage.locator(".desktop-sidebar");
        for (const name of ["Manajemen Pengguna", "Kategori", "Satuan", "Lokasi", "Import Master PN"]) {
            await expect(sidebar.getByRole("link", { name, exact: true })).toHaveCount(0);
        }
        expect((await vendorPage.request.get("/users")).status()).toBe(403);
        await row.getByRole("button", { name: "Nonaktifkan pengguna Vendor Browser" }).click();
        await page.getByRole("dialog", { name: "Nonaktifkan Pengguna?", exact: true })
            .getByRole("button", { name: "Nonaktifkan Pengguna", exact: true }).click();
        await expect(row.locator(".status-badge")).toHaveText("Nonaktif");
        await vendorPage.reload();
        await expect(vendorPage).toHaveURL(/\/login$/);
        await page.getByLabel("Filter status pengguna").selectOption("inactive");
        await expect(page).toHaveURL((url) => url.searchParams.get("status") === "inactive");
        await expect(row).toBeVisible();
        await page.getByLabel("Filter status pengguna").selectOption("active");
        await expect(row).toHaveCount(0);
    } finally {
        await vendorContext.close();
    }
});
