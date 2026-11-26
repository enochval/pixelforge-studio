import { onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Refreshes the generations prop while any of them is still running.
 *
 * A partial reload rather than a full visit: only the `generations` prop is
 * re-fetched, so the form keeps whatever the user has typed and the page does
 * not flicker on every tick.
 *
 * Polling stops as soon as nothing is in flight. An interval that kept running
 * would send a request every few seconds to every open tab for as long as the
 * page stayed open, which costs real money in a deployed environment and
 * achieves nothing — there is no change left to find.
 *
 * Chosen over websockets deliberately. The latency is seconds rather than
 * milliseconds, which is immaterial for work the provider takes 5 to 30 seconds
 * to do, and it needs no broadcast server in the deployed environment.
 */
export function useGenerationPolling(generations, { intervalMs = 3000 } = {}) {
    // Held outside the watcher so start and stop refer to the same interval,
    // and so unmounting can clear one that is running.
    const timer = ref(null);

    const anythingInFlight = () => generations.value.some((generation) => !generation.is_terminal);

    const stop = () => {
        if (timer.value !== null) {
            clearInterval(timer.value);
            timer.value = null;
        }
    };

    const start = () => {
        if (timer.value !== null) {
            return;
        }

        timer.value = setInterval(() => {
            router.reload({ only: ['generations', 'balance'] });
        }, intervalMs);
    };

    watch(
        anythingInFlight,
        (inFlight) => (inFlight ? start() : stop()),
        { immediate: true },
    );

    // Without this the interval outlives the component and keeps reloading a
    // page nobody is looking at.
    onUnmounted(stop);
}
