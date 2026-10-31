import { ref } from "vue";

const collapsed = ref(false);
let loaded = false;

/** Desktop sidebar width mode, remembered in the browser. */
export function useSidebar() {
    if (!loaded && typeof localStorage !== "undefined") {
        loaded = true;
        try {
            collapsed.value = localStorage.getItem("sidebar") === "collapsed";
        } catch {
            // Storage can be blocked; the sidebar simply starts expanded.
        }
    }

    function toggle() {
        collapsed.value = !collapsed.value;
        try {
            localStorage.setItem("sidebar", collapsed.value ? "collapsed" : "expanded");
        } catch {
            // The choice still applies for this visit.
        }
    }

    return { collapsed, toggle };
}
