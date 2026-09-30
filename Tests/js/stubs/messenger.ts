import {vi} from 'vitest';

/** Stand-in for oroui/js/messenger. Assert on the spies, e.g. `expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'x')`. */
export default {
    notificationFlashMessage: vi.fn(),
    notificationMessage: vi.fn()
};
