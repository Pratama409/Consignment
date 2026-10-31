import { onMounted, ref } from "vue";

/** Animates a number from zero to `target` once, unless the user prefers reduced motion. */
export function useCountUp(target, { duration = 700, decimals = 0 } = {}) {
    const value = ref(Number(target) || 0);

    onMounted(() => {
        const end = Number(target) || 0;
        const reduce = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
        if (reduce || end === 0) return;
        const start = performance.now();
        const factor = 10 ** decimals;
        const step = (now) => {
            const progress = Math.min(1, (now - start) / duration);
            const eased = 1 - (1 - progress) ** 3;
            value.value = Math.round(end * eased * factor) / factor;
            if (progress < 1) requestAnimationFrame(step);
        };
        value.value = 0;
        requestAnimationFrame(step);
    });

    return value;
}
