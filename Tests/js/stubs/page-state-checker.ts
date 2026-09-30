import {vi} from 'vitest';

/** Stand-in for oronavigation's page-state-checker service. */
export default {
    registerChecker: vi.fn(),
    removeChecker: vi.fn()
};
