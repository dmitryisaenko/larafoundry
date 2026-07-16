// Reference-counted body scroll-lock, shared across ALL overlay surfaces (Modal,
// ConfirmDialog, AdminFilterDrawer, …). The counter lives in true module scope so
// overlapping overlays coordinate: the body `overflow-hidden` class is added on
// the first lock and only removed when the LAST surface unlocks — a surface
// closing under another must not unlock the page beneath it.
//
// Each caller gets its own closure with a per-instance guard, so a double unlock
// (open → unmount) decrements the shared counter only once. SSR-safe (no-op when
// `document` is absent).
let count = 0;

export function useScrollLock() {
    let lockedByThisInstance = false;

    function lock() {
        if (typeof document === 'undefined' || lockedByThisInstance) {
            return;
        }
        lockedByThisInstance = true;
        count += 1;
        // Idempotent: always reflect "at least one overlay open" on the body, so
        // the class can't drift from the counter even if something toggled it.
        document.body.classList.add('overflow-hidden');
    }

    function unlock() {
        if (typeof document === 'undefined' || !lockedByThisInstance) {
            return;
        }
        lockedByThisInstance = false;
        count = Math.max(0, count - 1);
        if (count === 0) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    return { lock, unlock };
}
