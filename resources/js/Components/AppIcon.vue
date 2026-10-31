<script setup>
defineProps({ name: { type: String, required: true } });

const paths = {
    warehouse: ["m3 9 9-5 9 5v11H3Z", "M3 9h18M8 20v-8h8v8M8 16h8"],
    dashboard: ["M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"],
    layers: ["m12 3 10 5-10 5L2 8Z", "m2 12 10 5 10-5M2 16l10 5 10-5"],
    box: ["m12 3 9 5v9l-9 5-9-5V8Z", "m3 8 9 5 9-5M12 13v9M7.5 5.5l9 5"],
    truck: [
        "M3 6h11v12H3zM14 10h4l3 4v4h-7",
        "M7 18a2 2 0 1 0 0 .01M18 18a2 2 0 1 0 0 .01",
    ],
    ruler: ["m16 3 5 5L8 21l-5-5Z", "m14 5 3 3m-6 0 2 2m-5 1 3 3m-6 0 2 2"],
    pin: [
        "M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z",
        "M15 10a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z",
    ],
    upload: ["M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"],
    chevronDown: ["m6 9 6 6 6-6"],
    chevronLeft: ["m15 6-6 6 6 6"],
    chevronRight: ["m9 6 6 6-6 6"],
    arrowRight: ["M4 12h16m-6-6 6 6-6 6"],
    arrowUp: ["M12 19V5m-5 5 5-5 5 5"],
    arrowDown: ["M12 5v14m-5-5 5 5 5-5"],
    logout: ["M9 4H4v16h5m5-13 5 5-5 5M9 12h10"],
    menu: ["M4 6h16M4 12h16M4 18h16"],
    close: ["m6 6 12 12M6 18 18 6"],
    search: ["M18 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0ZM15 15l6 6"],
    plus: ["M12 5v14M5 12h14"],
    pencil: ["m15 4 5 5M4 20l4-1L21 6a2.8 2.8 0 0 0-4-4L4 15Z"],
    power: ["M12 3v9M6 5a9 9 0 1 0 12 0"],
    check: ["m5 12 4 4L19 6"],
    checkCircle: ["M21 11v1a9 9 0 1 1-5.3-8.2", "m9 11 3 3L22 4"],
    pause: ["M9 5v14M15 5v14"],
    info: ["M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM12 11v6M12 7h.01"],
    shield: ["m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z", "m8 12 3 3 5-5"],
    lock: ["M5 10h14v11H5zM8 10V6a4 4 0 0 1 8 0v4M12 14v3"],
    mail: ["M3 5h18v14H3z", "m3 5 9 8 9-8"],
    alert: ["m12 3 10 18H2Z", "M12 10v5M12 18h.01"],
    sun: ["M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z", "M12 2v2M12 20v2M2 12h2M20 12h2m-3.5-6.5L17 7M7 17l-1.5 1.5m0-13L7 7m10 10 1.5 1.5"],
    moon: ["M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5Z"],
    activity: ["M3 12h4l3-8 4 16 3-8h4"],
    chart: ["M3 3v18h18", "M8 16v-5M13 16V8M18 16v-9"],
    eye: [
        "M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z",
        "M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z",
    ],
};
</script>

<template>
    <svg
        class="icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        <path
            v-for="(path, index) in paths[name] ?? paths.box"
            :key="index"
            :d="path"
        />
    </svg>
</template>
