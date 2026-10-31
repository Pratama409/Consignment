// One place that turns a raw status code into a readable label and a colour tone.
const labels = {
    REQUESTED: "Menunggu Vendor",
    REVISION_PENDING: "Revisi diajukan",
    SHIPPING: "Dalam pengiriman",
    PARTIAL: "Diterima sebagian",
    PARTIALLY_RECEIVED: "Diterima sebagian",
    PARTIALLY_ISSUED: "Dikeluarkan sebagian",
    CLOSED_PARTIAL: "Ditutup sebagian",
    DISPATCHED: "Dikirim",
    PENDING_APPROVAL: "Menunggu approval",
    READY_TO_PICK_UP: "Siap diambil",
    COUNTING: "Sedang dihitung",
    COUNTED: "Menunggu persetujuan",
    RESOLVING: "Menyelesaikan selisih",
    RESERVATION_PENDING: "Menunggu reservasi",
    PR_PENDING: "Menunggu PR",
    VALIDATION_PENDING: "Menunggu validasi",
    PO_PENDING: "Menunggu PO",
    REQUIRES_REVIEW: "Perlu ditinjau",
    REVERSED: "Dibalik",
    ISSUED: "Diserahkan",
    READY: "Siap",
    PREPARING: "Disiapkan",
    PENDING: "Menunggu",
    DRAFT: "Draft",
    POSTED: "Diposting",
    ACTIVATED: "Aktif",
};

const toneByCode = {
    ok: /^(COMPLETED?|ISSUED|POSTED|ACTIVE|ACTIVATED|APPROVED|RECEIVED|APPLIED|VALID|FINALIZED|ACCEPTED|READY|AGREED|RESOLVED|CLOSED)$/,
    danger: /^(REJECTED|CANCELLED|VOID|FAILED|DISPUTED|EXPIRED|REVERSED)$/,
    warn: /^(PENDING|PARTIAL|PARTIALLY_RECEIVED|PARTIALLY_ISSUED|DRAFT|REQUIRES_REVIEW|REVISION_PENDING|REQUESTED|COUNTED|RESOLVING|PENDING_APPROVAL|.*_PENDING)$/,
    info: /^(SHIPPING|DISPATCHED|COUNTING|PREPARING|PREVIEW|IN_REVIEW|SUBMITTED|SENT)$/,
};

export function describeStatus(code) {
    const value = String(code ?? "").toUpperCase();
    const tone = Object.entries(toneByCode).find(([, pattern]) => pattern.test(value))?.[0] ?? "neutral";
    const label = labels[value] ?? (value ? value.charAt(0) + value.slice(1).toLowerCase().replace(/_/g, " ") : "—");
    return { label, tone };
}

export const toneClass = { ok: "active", warn: "warning", danger: "danger", info: "info", neutral: "inactive" };
