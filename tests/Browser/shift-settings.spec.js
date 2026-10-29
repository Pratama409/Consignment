import { test, expect } from "@playwright/test";

async function login(page, email = "admin@browser.test") {
    await page.goto("/login");
    await page.getByLabel("Email", { exact: false }).fill(email);
    await page.getByLabel(/^Kata sandi/).fill("Browser-testing-password-123");
    await page.getByRole("button", { name: "Masuk ke aplikasi" }).click();
    await expect(page).toHaveURL(/master-data\/barang$/);
}

test("Admin schedules future shifts with validation and Warehouse views current labels", async ({ page, browser }) => {
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await login(page);
    await page.getByRole("link", { name: "Pengaturan Shift" }).click();
    await expect(page.getByRole("heading", { name: "Pengaturan Shift", exact: true })).toBeVisible();
    const current = page.getByRole("region", { name: "Shift saat ini" });
    await expect(current).toContainText("06:00");
    await expect(current).toContainText("18:00");
    await expect(current).toContainText("WITA");
    await page.getByLabel("Nama shift", { exact: false }).fill("Siang terjadwal");
    await page.getByLabel("Mulai shift", { exact: false }).fill("07:00");
    await page.getByLabel("Selesai shift", { exact: false }).fill("19:00");
    await page.getByLabel("Berlaku mulai", { exact: false }).fill("2020-01-01");
    await page.getByRole("button", { name: "Simpan Versi Mendatang" }).click();
    await expect(page.getByRole("alert")).toContainText("tanggal mendatang");
    const futureDate = await page.getByLabel("Berlaku mulai", { exact: false }).getAttribute("min");
    await page.getByLabel("Berlaku mulai", { exact: false }).fill(futureDate);
    await page.getByRole("button", { name: "Simpan Versi Mendatang" }).click();
    await expect(page.getByRole("status")).toContainText("Versi shift mendatang berhasil disimpan");
    await expect(page.getByRole("row").filter({ hasText: "Siang terjadwal" })).toContainText("07:00");
    await expect(current).toContainText("06:00");
    const code = page.getByLabel("Kode shift", { exact: false });
    const start = page.getByLabel("Mulai shift", { exact: false });
    const end = page.getByLabel("Selesai shift", { exact: false });
    const scheduled = expect.configure({ soft: true, timeout: 1000 });
    await code.selectOption("NIGHT");
    await scheduled(start).toHaveValue("19:00");
    await scheduled(end).toHaveValue("07:00");
    await code.selectOption("DAY");
    await scheduled(start).toHaveValue("07:00");
    await scheduled(end).toHaveValue("19:00");
    await page.reload();
    await expect(page.getByRole("row").filter({ hasText: "Siang terjadwal" })).toBeVisible();
    await scheduled(page.getByLabel("Nama shift", { exact: false })).toHaveValue("Siang terjadwal");
    await scheduled(start).toHaveValue("07:00");
    await scheduled(end).toHaveValue("19:00");
    await code.selectOption("NIGHT");
    await scheduled(start).toHaveValue("19:00");
    await scheduled(end).toHaveValue("07:00");
    await page.getByLabel("Nama shift", { exact: false }).fill("Malam terjadwal");
    const secondDate = await page.getByLabel("Berlaku mulai", { exact: false }).inputValue();
    await page.getByRole("button", { name: "Simpan Versi Mendatang" }).click();
    const secondDay = page.getByRole("row").filter({ hasText: secondDate }).filter({ hasText: "DAY" });
    const secondNight = page.getByRole("row").filter({ hasText: secondDate }).filter({ hasText: "NIGHT" });
    await expect(secondNight).toContainText("Malam terjadwal");
    await scheduled(secondDay).toContainText("07:00");
    await scheduled(secondDay).toContainText("19:00");
    await scheduled(secondNight).toContainText("19:00");
    await scheduled(secondNight).toContainText("07:00");
    await expect(current).toContainText("06:00");
    await expect(current).toContainText("18:00");
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByLabel("Nama shift", { exact: false })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const warehouseContext = await browser.newContext();
    try {
        const warehouse = await warehouseContext.newPage();
        await login(warehouse, "viewer@browser.test");
        await warehouse.getByRole("link", { name: "Pengaturan Shift" }).click();
        await expect(warehouse.getByRole("region", { name: "Shift saat ini" })).toContainText("06:00");
        await expect(warehouse.getByRole("button", { name: "Simpan Versi Mendatang" })).toHaveCount(0);
        await expect(warehouse.getByRole("table")).toHaveCount(0);
        const csrf = (await warehouse.context().cookies()).find((cookie) => cookie.name === "XSRF-TOKEN");
        expect((await warehouse.request.post("/settings/shifts", {
            headers: { "X-XSRF-TOKEN": decodeURIComponent(csrf.value) },
            data: {},
        })).status()).toBe(403);
    } finally {
        await warehouseContext.close();
    }
    expect(errors).toEqual([]);
});
