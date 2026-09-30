import {vi} from 'vitest';

/**
 * Stand-in for CodeMirror 5. jsdom has no layout, so the real editor is not worth running: this
 * fake editor keeps its value and exposes spies for the calls the widgets make.
 */
export function createFakeEditor(initial = ''): any {
    let value = initial;
    const handlers: Record<string, Array<(...args: any[]) => void>> = {};
    const editor: any = {
        getValue: vi.fn(() => value),
        setValue: vi.fn((next: string) => {
            value = next;
            (handlers.change || []).forEach(handler => handler(editor));
        }),
        on: vi.fn((event: string, handler: (...args: any[]) => void) => {
            (handlers[event] ||= []).push(handler);
        }),
        off: vi.fn(),
        refresh: vi.fn(),
        focus: vi.fn(),
        setOption: vi.fn(),
        getOption: vi.fn(),
        setSize: vi.fn(),
        toTextArea: vi.fn(),
        save: vi.fn(),
        getWrapperElement: vi.fn(() => document.createElement('div')),
        /** Test helper: fire an editor event, e.g. `editor.emit('change')`. */
        emit: (event: string, ...args: any[]) => (handlers[event] || []).forEach(handler => handler(editor, ...args))
    };
    return editor;
}

const CodeMirror: any = {
    fromTextArea: vi.fn((textarea: HTMLTextAreaElement) => createFakeEditor(textarea?.value ?? '')),
    runMode: vi.fn(),
    defineMode: vi.fn(),
    defineMIME: vi.fn()
};

export default CodeMirror;
