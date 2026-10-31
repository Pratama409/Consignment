import { ref } from "vue";

const theme = ref("light");

function read() {
    try {
        const saved = localStorage.getItem("theme");
        if (saved === "light" || saved === "dark") return saved;
    } catch {
        // Storage can be blocked; fall back to the system preference.
    }
    return window.matchMedia?.("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

function apply(value) {
    theme.value = value;
    document.documentElement.dataset.theme = value;
}

export function useTheme() {
    if (typeof document !== "undefined" && document.documentElement.dataset.theme) {
        theme.value = document.documentElement.dataset.theme;
    } else if (typeof document !== "undefined") {
        apply(read());
    }

    function toggle() {
        const next = theme.value === "dark" ? "light" : "dark";
        apply(next);
        try {
            localStorage.setItem("theme", next);
        } catch {
            // The choice still applies for this visit.
        }
    }

    return { theme, toggle };
}
