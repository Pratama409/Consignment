import { test, expect } from "@playwright/test";
import { execFileSync } from "node:child_process";

async function login(page, account = "admin") {
    await page.goto("/login");
    await page
        .getByLabel("Email", { exact: false })
        .fill(`${account}@browser.test`);
    await page.getByLabel(/^Kata sandi/).fill("Browser-testing-password-123");
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(
        page.getByRole("heading", { name: "Master Barang", exact: true }),
    ).toBeVisible();
}

async function screenshotWidths(page, name) {
    for (const width of [360, 768, 1280, 1920]) {
        await page.setViewportSize({ width, height: 1000 });
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: `test-results/${name}-${width}.png`,
            fullPage: true,
        });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
}

const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
    errors.set(page, []);
    page.on("pageerror", (error) => errors.get(page).push(error.message));
    await login(page);
});
test.afterEach(async ({ page }) => expect(errors.get(page)).toEqual([]));

test("admin completes Vendor, Lokasi, Barang create/detail/edit and Excel export", async ({
    page,
}) => {
    await screenshotWidths(page, "master-barang-empty");
    const sidebar = page.locator(".desktop-sidebar");
    await sidebar.getByRole("link", { name: "Vendor", exact: true }).click();
    await page
        .getByRole("button", { name: "Tambah Vendor", exact: true })
        .first()
        .click();
    let dialog = page.getByRole("dialog", {
        name: "Tambah Vendor",
        exact: true,
    });
    await dialog.getByLabel("Kode Vendor", { exact: false }).fill("UI-VENDOR");
    await dialog
        .getByLabel("Nama Vendor", { exact: false })
        .fill("Vendor Pengujian");
    await dialog.getByLabel("Nama PIC", { exact: false }).fill("Ibu Rina");
    await dialog.getByRole("button", { name: "Simpan Vendor" }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByText("Ibu Rina", { exact: false })).toBeVisible();
    await screenshotWidths(page, "master-vendor");

    await sidebar.getByRole("link", { name: "Lokasi", exact: true }).click();
    await page
        .getByRole("button", { name: "Tambah Lokasi", exact: true })
        .click();
    dialog = page.getByRole("dialog", { name: "Tambah Lokasi", exact: true });
    await dialog.getByLabel("Kode lokasi", { exact: false }).fill("UI-RACK");
    await dialog.getByLabel("Keterangan").fill("Rak pengujian");
    await dialog.getByRole("button", { name: "Simpan Lokasi" }).click();
    await expect(dialog).not.toBeVisible();
    await screenshotWidths(page, "master-lokasi");

    await sidebar.getByRole("link", { name: "Barang", exact: true }).click();
    await page
        .getByRole("link", { name: "Tambah Barang", exact: true })
        .first()
        .click();
    await page
        .getByRole("button", { name: "Simpan Barang", exact: true })
        .click();
    await expect(
        page.getByRole("alert").filter({ hasText: "Part number wajib diisi." }),
    ).toBeVisible();
    await expect(
        page.getByLabel("Part number", { exact: false }),
    ).toBeFocused();
    await page.getByLabel("Part number", { exact: false }).fill("000UI.");
    await page
        .getByLabel("Nama / deskripsi barang", { exact: false })
        .fill("Bearing consignment pengujian");
    await page
        .getByLabel("Jenis kepemilikan", { exact: false })
        .selectOption("CONSIGNMENT");
    await page.getByLabel(/^Satuan/).selectOption({ label: "PC — Piece" });
    await page.getByLabel("Target refill", { exact: false }).fill("15.125");
    await page
        .locator("#vendor-0")
        .selectOption({ label: "UI-VENDOR — Vendor Pengujian" });
    await page
        .locator("#vendor-location-0")
        .selectOption({ label: "UI-RACK — Rak pengujian" });
    await screenshotWidths(page, "barang-form");
    await page
        .getByRole("button", { name: "Simpan Barang", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Detail Barang", exact: true }),
    ).toBeVisible();
    await expect(page.getByText("15,125 PC", { exact: true })).toBeVisible();
    await screenshotWidths(page, "barang-detail");
    await page.getByRole("link", { name: "Edit Barang", exact: true }).click();
    await expect(
        page.getByLabel("Jenis kepemilikan", { exact: false }),
    ).toBeDisabled();
    await page.getByLabel("Target refill", { exact: false }).fill("30.5");
    await page
        .getByRole("button", { name: "Simpan Barang", exact: true })
        .click();
    await expect(page.getByText("30,5 PC", { exact: true })).toBeVisible();
    await page.getByRole("link", { name: "Kembali ke daftar barang" }).click();
    await page.getByLabel("Cari barang", { exact: true }).fill("000UI.");
    await expect(page.locator("tbody tr")).toHaveCount(1);
    await screenshotWidths(page, "master-barang");
    const download = page.waitForEvent("download");
    await page.getByRole("link", { name: "Export Excel", exact: true }).click();
    expect((await download).suggestedFilename()).toMatch(
        /^master-pn-.*\.xlsx$/,
    );
});

test("Excel upload requires review, allows correction, then confirms skipped duplicates", async ({
    page,
}) => {
    await page.goto("/master-data/import");
    await screenshotWidths(page, "import-upload");
    await page.locator("#import-file").setInputFiles({
        name: "browser-master.xlsx",
        mimeType:
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        buffer: execFileSync("php", ["tests/Browser/workbook.php"], {
            windowsHide: true,
        }),
    });
    await page.getByLabel("Buat Vendor dan lokasi", { exact: false }).check();
    await page
        .getByLabel("Target refill untuk barang baru", { exact: true })
        .fill("8");
    await page.getByRole("button", { name: "Validasi & Preview" }).click();
    await expect(
        page.getByRole("heading", { name: "Preview Master PN", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("button", { name: "Lanjut Konfirmasi" }),
    ).toBeDisabled();
    await screenshotWidths(page, "import-preview");
    await page
        .getByRole("button", {
            name: "Tinjau kelompok 000BROWSER.",
            exact: true,
        })
        .click();
    let dialog = page.getByRole("dialog", {
        name: "Tinjau Kelompok Barang",
        exact: true,
    });
    await dialog
        .getByLabel("Penanganan kelompok", { exact: false })
        .selectOption("create");
    await dialog.getByLabel("Target refill", { exact: true }).fill("0");
    await dialog.getByRole("button", { name: "Simpan Penanganan" }).click();
    await expect(dialog).not.toBeVisible();
    await page
        .getByRole("button", {
            name: "Tinjau kelompok 000BROWSER.",
            exact: true,
        })
        .click();
    await expect(
        dialog.getByLabel("Target refill", { exact: true }),
    ).toHaveValue("0");
    await dialog.getByRole("button", { name: "Batal", exact: true }).click();
    await page
        .getByRole("button", {
            name: "Tinjau kelompok CORRECT-BROWSER",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", { name: "Perbaiki baris 5", exact: true })
        .click();
    dialog = page.getByRole("dialog", {
        name: "Perbaiki Baris 5",
        exact: true,
    });
    await dialog.getByLabel("Kode lokasi", { exact: true }).fill("UNASSIGNED");
    await dialog
        .getByRole("button", { name: "Simpan Perbaikan", exact: true })
        .click();
    await expect(dialog).not.toBeVisible();
    await expect(
        page.getByRole("button", { name: "Lanjut Konfirmasi" }),
    ).toBeEnabled();
    await page.getByRole("button", { name: "Lanjut Konfirmasi" }).click();
    dialog = page.getByRole("dialog", {
        name: "Konfirmasi Import",
        exact: true,
    });
    await expect(
        dialog.getByRole("button", { name: "Import Sekarang" }),
    ).toBeDisabled();
    await dialog.getByRole("checkbox").check();
    await dialog.getByRole("button", { name: "Import Sekarang" }).click();
    await expect(
        page.getByRole("heading", { name: "Import Selesai", exact: true }),
    ).toBeVisible();
    await screenshotWidths(page, "import-result");
    const download = page.waitForEvent("download");
    await page.getByRole("link", { name: "Export Hasil", exact: true }).click();
    expect((await download).suggestedFilename()).toMatch(/\.xlsx$/);
});

test("normal user navigates all readable masters and cannot open admin flows", async ({
    page,
}) => {
    await page
        .locator(".desktop-sidebar")
        .getByRole("button", { name: "Keluar", exact: true })
        .click();
    await login(page, "viewer");
    await expect(
        page.getByRole("link", { name: "Tambah Barang", exact: true }),
    ).toHaveCount(0);
    await expect(
        page
            .locator(".desktop-sidebar")
            .getByRole("link", { name: "Import Master PN" }),
    ).toHaveCount(0);
    for (const [path, heading] of [
        ["vendor", "Master Vendor"],
        ["lokasi", "Master Lokasi"],
        ["satuan", "Master Satuan"],
    ]) {
        await page.goto(`/master-data/${path}`);
        await expect(
            page.getByRole("heading", { name: heading, exact: true }),
        ).toBeVisible();
        await expect(
            page.getByText("Akses baca", { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole("button", { name: /^Tambah / }),
        ).toHaveCount(0);
    }
    expect((await page.request.get("/master-data/import")).status()).toBe(403);
    expect(
        (await page.request.get("/master-data/barang/tambah")).status(),
    ).toBe(403);
});
