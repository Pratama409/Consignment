import { test, expect } from "@playwright/test";

const failures = new WeakMap();

async function csrfHeaders(page) {
    const cookie = (await page.context().cookies()).find(
        (item) => item.name === "XSRF-TOKEN",
    );
    expect(cookie).toBeDefined();
    return { "X-XSRF-TOKEN": decodeURIComponent(cookie.value) };
}

async function login(page, account = "admin") {
    await page.goto("/login");
    await page
        .getByLabel("Email", { exact: false })
        .fill(`${account}@browser.test`);
    await page.getByLabel(/^Kata sandi/).fill("Browser-testing-password-123");
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(page).toHaveURL(/master-data\/barang$/);
    await page.goto("/master-data/satuan");
    await expect(
        page.getByRole("heading", { name: "Master Satuan", exact: true }),
    ).toBeVisible();
}

test.beforeEach(async ({ page }) => {
    const errors = [];
    failures.set(page, errors);
    page.on("pageerror", (error) => errors.push(error.message));
    await login(page);
});

test.afterEach(async ({ page }) => {
    expect(failures.get(page)).toEqual([]);
});

for (const width of [360, 768, 1280, 1920]) {
    test(`layout is usable at ${width}px without page overflow`, async ({
        page,
    }) => {
        await page.setViewportSize({ width, height: 1000 });
        await expect(page.locator("tbody tr")).toHaveCount(5);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
        await expect(page.locator(".desktop-sidebar")).toBeVisible({
            visible: width >= 1280,
        });
        await expect(page.locator("#nprogress")).toHaveCount(0);
        await page.mouse.move(0, 0);
        await page.screenshot({
            path: `test-results/master-satuan-${width}.png`,
            fullPage: true,
        });
    });
}

test("mobile navigation traps focus and Escape returns focus to its trigger", async ({
    page,
}) => {
    await page.setViewportSize({ width: 360, height: 800 });
    const trigger = page.getByRole("button", { name: "Buka navigasi" });
    await trigger.click();
    const dialog = page.getByRole("dialog", { name: "Navigasi", exact: true });
    await expect(dialog).toBeVisible();
    for (let index = 0; index < 8; index++) {
        await page.keyboard.press("Tab");
        expect(
            await page.evaluate(() =>
                Boolean(document.activeElement.closest("dialog[open]")),
            ),
        ).toBe(true);
    }
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
});

test("admin can add, edit, deactivate and reactivate a unit", async ({
    page,
}) => {
    await page
        .getByRole("button", { name: "Tambah Satuan", exact: true })
        .click();
    let dialog = page.getByRole("dialog", {
        name: "Tambah Satuan",
        exact: true,
    });
    await expect(
        dialog.getByLabel("Kode satuan", { exact: false }),
    ).toBeFocused();
    await dialog.getByLabel("Kode satuan", { exact: false }).fill("e2ebox");
    await dialog
        .getByLabel("Nama satuan", { exact: false })
        .fill("Box pengujian");
    await dialog.getByRole("button", { name: "Simpan Satuan" }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole("status")).toContainText(
        "berhasil ditambahkan",
    );

    let row = page.getByRole("row").filter({ hasText: "E2EBOX" });
    await expect(row).toContainText("Box pengujian");
    await row.getByRole("button", { name: "Edit satuan E2EBOX" }).click();
    dialog = page.getByRole("dialog", { name: "Edit Satuan", exact: true });
    await dialog
        .getByLabel("Nama satuan", { exact: false })
        .fill("Box diperbarui");
    await dialog.getByRole("button", { name: "Simpan Satuan" }).click();
    await expect(dialog).not.toBeVisible();
    row = page.getByRole("row").filter({ hasText: "E2EBOX" });
    await expect(row).toContainText("Box diperbarui");

    await row
        .getByRole("button", { name: "Nonaktifkan satuan E2EBOX" })
        .click();
    dialog = page.getByRole("dialog", {
        name: "Nonaktifkan Satuan?",
        exact: true,
    });
    await expect(dialog).toContainText("E2EBOX");
    await dialog
        .getByRole("button", { name: "Nonaktifkan Satuan", exact: true })
        .click();
    await expect(dialog).not.toBeVisible();
    await expect(row.locator(".status-badge")).toHaveText("Nonaktif");
    await row.getByRole("button", { name: "Aktifkan satuan E2EBOX" }).click();
    dialog = page.getByRole("dialog", {
        name: "Aktifkan Satuan?",
        exact: true,
    });
    await dialog
        .getByRole("button", { name: "Aktifkan Satuan", exact: true })
        .click();
    await expect(dialog).not.toBeVisible();
    await expect(row.locator(".status-badge")).toHaveText("Aktif");
});

test("duplicate validation preserves inputs and modal keyboard focus", async ({
    page,
}) => {
    const trigger = page.getByRole("button", {
        name: "Tambah Satuan",
        exact: true,
    });
    await trigger.click();
    const dialog = page.getByRole("dialog", {
        name: "Tambah Satuan",
        exact: true,
    });
    await dialog.getByLabel("Kode satuan", { exact: false }).fill("pc");
    await dialog
        .getByLabel("Nama satuan", { exact: false })
        .fill("Nama dipertahankan");
    await dialog.getByRole("button", { name: "Simpan Satuan" }).click();
    await expect(dialog.getByRole("alert")).toContainText(
        "Kode satuan sudah digunakan",
    );
    await expect(
        dialog.getByLabel("Nama satuan", { exact: false }),
    ).toHaveValue("Nama dipertahankan");
    await expect(
        dialog.getByLabel("Kode satuan", { exact: false }),
    ).toBeFocused();
    for (let index = 0; index < 9; index++) {
        await page.keyboard.press("Tab");
        expect(
            await page.evaluate(() =>
                Boolean(document.activeElement.closest("dialog[open]")),
            ),
        ).toBe(true);
    }
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
});

test("search, status and server pagination work together", async ({ page }) => {
    for (let index = 1; index <= 12; index++) {
        const response = await page.request.post("/master-data/satuan", {
            headers: await csrfHeaders(page),
            form: {
                code: `PAGE${String(index).padStart(2, "0")}`,
                name: `Pagination fixture ${index}`,
                is_active: "1",
            },
        });
        expect(response.ok()).toBe(true);
    }
    await page
        .getByLabel("Cari kode atau nama satuan")
        .fill("Pagination fixture");
    await expect(page.locator("tbody tr")).toHaveCount(10);
    await page.getByLabel("Filter status satuan").selectOption("active");
    await expect(page).toHaveURL(/status=active/);
    await page
        .getByRole("link", { name: "Halaman berikutnya", exact: true })
        .click();
    await expect(page.locator("tbody tr")).toHaveCount(2);
    await expect(page).toHaveURL(/page=2/);
    await expect(page).toHaveURL(/q=Pagination/);
    await expect(page.getByLabel("Filter status satuan")).toHaveValue("active");
});

test("no search results is distinct from loading failure", async ({ page }) => {
    await page
        .getByLabel("Cari kode atau nama satuan")
        .fill("not-found-anywhere-xyz");
    await expect(
        page.getByRole("heading", { name: "Satuan tidak ditemukan" }),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Reset filter", exact: true })
        .first()
        .click();
    await expect(page.locator("tbody tr").first()).toBeVisible();

    await page.route("**/master-data/satuan?**", (route) =>
        route.abort("failed"),
    );
    await page.getByLabel("Cari kode atau nama satuan").fill("network-failure");
    await expect(page.getByRole("alert")).toContainText("Koneksi terputus");
    await expect(page.locator("tbody tr").first()).toBeVisible();
    await page.unroute("**/master-data/satuan?**");
    await page.getByRole("button", { name: "Muat ulang", exact: true }).click();
    await expect(page.getByRole("alert")).not.toBeVisible();
});

test("normal user only sees read access and server rejects direct changes", async ({
    page,
}) => {
    await page
        .locator(".desktop-sidebar")
        .getByRole("button", { name: "Keluar", exact: true })
        .click();
    await expect(page).toHaveURL(/\/login$/);
    await login(page, "viewer");
    await expect(page.getByText("Akses baca", { exact: true })).toBeVisible();
    await expect(
        page.getByRole("button", { name: "Tambah Satuan", exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole("button", { name: /^Edit satuan/ }),
    ).toHaveCount(0);
    const response = await page.request.post("/master-data/satuan", {
        headers: await csrfHeaders(page),
        form: { code: "DENIED", name: "Denied", is_active: "1" },
    });
    expect(response.status()).toBe(403);
});
