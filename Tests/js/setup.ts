import $ from 'jquery';
import {afterEach, beforeEach, vi} from 'vitest';

beforeEach(() => {
    // Stub spies (messenger, mediator, ...) are module singletons: forget calls from the previous test.
    vi.clearAllMocks();
});

// Widgets append to <body>; start every test from a clean document.
afterEach(() => {
    $(document.body).empty();
    document.body.removeAttribute('class');
});
