export const references = {
    categories: {
        key: "categories",
        routeKey: "categoriesIndex",
        title: "Master Kategori",
        noun: "Kategori",
        lower: "kategori",
        plural: "kategori",
        icon: "layers",
        codeLabel: "Kode kategori",
        nameLabel: "Nama kategori",
        codePlaceholder: "Contoh: FILTER",
        namePlaceholder: "Nama kelompok barang",
        codeLength: 50,
        nameLength: 150,
        description:
            "Kelola kelompok produk secara terpisah dari jenis kepemilikan barang.",
        usage: "barang aktif",
        note: "Kategori tidak menentukan pemilik atau cara pelacakan barang. Barang lama tetap belum dikategorikan sampai dipilih pengguna.",
    },
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
    departments: {
        key: "departments",
        routeKey: "departmentsIndex",
        title: "Master Departemen",
        noun: "Departemen",
        lower: "departemen",
        plural: "departemen",
        icon: "layers",
        codeLabel: "Kode departemen",
        nameLabel: "Nama departemen",
        codePlaceholder: "Contoh: OPS",
        namePlaceholder: "Nama departemen",
        codeLength: 50,
        nameLength: 150,
        description:
            "Kelola departemen yang dipakai pemohon pada Material Request.",
        usage: "permintaan aktif",
        note: "Departemen yang sudah dipakai Material Request tetap tersimpan. Nonaktifkan untuk menghentikan pemakaian baru.",
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
