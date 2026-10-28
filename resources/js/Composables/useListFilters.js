import { onBeforeUnmount, reactive, ref, watch } from "vue";
import { router } from "@inertiajs/vue3";

export function useListFilters(props, url, defaults = {}) {
    const search = reactive({ ...props.filters });
    const filtering = ref(false);
    let timer;
    watch(
        () => props.filters,
        (filters) => Object.assign(search, filters),
    );
    onBeforeUnmount(() => clearTimeout(timer));
    function apply() {
        clearTimeout(timer);
        router.get(
            typeof url === "function" ? url() : url,
            { ...search, q: search.q?.trim() || undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => {
                    filtering.value = true;
                },
                onFinish: () => {
                    filtering.value = false;
                },
            },
        );
    }
    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(apply, 350);
    }
    function reset() {
        Object.assign(search, {
            q: "",
            status: "all",
            sort: "code",
            direction: "asc",
            ...defaults,
        });
        apply();
    }
    function sort(column) {
        search.direction =
            search.sort === column && search.direction === "asc"
                ? "desc"
                : "asc";
        search.sort = column;
        apply();
    }
    return { search, filtering, apply, schedule, reset, sort };
}

export function withCurrentQuery(url) {
    const target = new URL(url, window.location.origin);
    target.search = window.location.search;
    return target.toString();
}
