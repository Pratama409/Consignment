export const references = {
    units: {
        key: "units",
        routeKey: "unitsIndex",
        title: "Master Satuan",
        noun: "Satuan",
        lower: "satuan",
        plural: "satuan",
        icon: "ruler",
        codeLabel: "Kode satuan",
        nameLabel: "Nama satuan",
        codePlaceholder: "Contoh: PC",
        namePlaceholder: "Contoh: Piece",
        codeLength: 30,
        nameLength: 100,
        description:
            "Kelola satuan barang sebagai referensi yang konsisten di seluruh Master Data.",
        usage: "barang aktif",
        note: "Satuan yang sudah digunakan tetap disimpan. Nonaktifkan satuan untuk menghentikan pemakaian baru.",
    },
    vendors: {
        key: "vendors",
        routeKey: "vendorsIndex",
        title: "Master Vendor",
        noun: "Vendor",
        lower: "Vendor",
        plural: "Vendor",
        icon: "truck",
        codeLabel: "Kode Vendor",
        nameLabel: "Nama Vendor",
        codePlaceholder: "Contoh: NOVENA",
        namePlaceholder: "Nama Vendor",
        codeLength: 50,
        nameLength: 150,
        description:
            "Kelola identitas Vendor dan PIC yang menyediakan barang consignment.",
        usage: "hubungan aktif",
        note: "WAREHOUSE adalah pihak internal, bukan Vendor. Vendor yang sudah digunakan tetap disimpan dalam riwayat.",
    },
    locations: {
        key: "locations",
        routeKey: "locationsIndex",
        title: "Master Lokasi",
        noun: "Lokasi",
        lower: "lokasi",
        plural: "lokasi",
        icon: "pin",
        codeLabel: "Kode lokasi",
        nameLabel: "Keterangan",
        codePlaceholder: "Contoh: FL503C",
        namePlaceholder: "Nama rak atau keterangan lokasi",
        codeLength: 50,
        nameLength: 150,
        description:
            "Tata referensi penempatan barang internal dan barang milik Vendor.",
        usage: "penempatan aktif",
        note: "UNASSIGNED adalah lokasi sistem untuk penempatan yang belum diketahui. Kode dan statusnya tetap dipertahankan.",
    },
};

export function formatQuantity(value) {
    const [whole, decimals = ""] = String(value ?? "0").split(".");
    const fraction = decimals.replace(/0+$/, "");
    return (
        whole.replace(/\B(?=(\d{3})+(?!\d))/g, ".") +
        (fraction ? `,${fraction}` : "")
    );
}
