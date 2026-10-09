/**
 * Vitest setup file — runs once before all spec files.
 *
 * - Provides a global `window.print` stub so DebtsIndex's printReport()
 *   button does not throw in jsdom.
 * - Suppresses unhandled rejections from vue-router-link stubs.
 */
import { vi } from 'vitest';

if (typeof window !== 'undefined') {
    // Stub window.print so DebtsIndex.vue's print button doesn't throw.
    if (!window.print) {
        window.print = vi.fn();
    }

    // Some vue-router-link stub calls rely on $route/$router globals during render;
    // they don't actually exist outside router mode but DebtsIndex uses
    // <router-link :to="..."/> which the @vue/test-utils stub does not resolve.
    // Provide a tiny stub global for any code that probes `window.addToast`.
    if (!window.addToast) {
        window.addToast = vi.fn();
    }
}
