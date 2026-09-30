import {vi} from 'vitest';

/** Stand-in for oroui/js/mediator: every method is a spy. */
export default {
    on: vi.fn(),
    off: vi.fn(),
    once: vi.fn(),
    trigger: vi.fn(),
    execute: vi.fn()
};
