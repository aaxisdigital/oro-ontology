import {vi} from 'vitest';

/**
 * Stand-in for oroui/js/modal. Every instance is recorded in `Modal.instances` with the options it
 * was built with, so tests can assert what was shown (title/content/buttons) and drive its events.
 */
export default class Modal {
    static instances: Modal[] = [];

    private handlers: Record<string, Array<(...args: any[]) => void>> = {};
    open = vi.fn();
    close = vi.fn();
    dispose = vi.fn();

    constructor(public options: any = {}) {
        Modal.instances.push(this);
    }

    on(event: string, handler: (...args: any[]) => void): this {
        (this.handlers[event] ||= []).push(handler);
        return this;
    }

    /** Test helper: fire an event as the real modal would (e.g. `modal.emit('ok')`). */
    emit(event: string, ...args: any[]): void {
        (this.handlers[event] || []).forEach(handler => handler(...args));
    }
}
