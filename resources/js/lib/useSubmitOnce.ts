import { useRef } from 'react';

/**
 * Guards a form submit or action against a second click that lands before React re-renders the
 * button as disabled (a double-click fires both clicks in the same tick). Without it Inertia starts
 * a second visit, cancels the first one — which had already succeeded on the server — and shows the
 * second visit's answer instead ("Only active riders can be suspended.").
 *
 * Call `start(done)` with the visit; pass `done` as the visit's onFinish.
 */
export function useSubmitOnce(): (start: (done: () => void) => void) => void {
    const busy = useRef(false);

    return (start) => {
        if (busy.current) return;
        busy.current = true;
        start(() => {
            busy.current = false;
        });
    };
}
