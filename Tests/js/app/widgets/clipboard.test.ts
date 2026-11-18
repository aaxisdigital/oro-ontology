import {afterEach, describe, expect, it, vi} from 'vitest';
import {readClipboard, writeClipboard} from '../../../../Resources/js-src/app/widgets/clipboard';

describe('clipboard — system clipboard', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        delete (document as any).execCommand;
    });

    it('writes through the async clipboard API', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        vi.stubGlobal('navigator', {clipboard: {writeText}});

        expect(await writeClipboard('x')).toBe(true);
        expect(writeText).toHaveBeenCalledWith('x');
    });

    it('reports a refused write', async () => {
        vi.stubGlobal('navigator', {clipboard: {writeText: () => Promise.reject(new Error('denied'))}});

        expect(await writeClipboard('x')).toBe(false);
    });

    it('falls back to execCommand without the async API', async () => {
        vi.stubGlobal('navigator', {});
        (document as any).execCommand = vi.fn(() => true);

        expect(await writeClipboard('x')).toBe(true);
        expect(document.querySelector('textarea')).toBeNull();

        (document as any).execCommand = vi.fn(() => {
            throw new Error('nope');
        });
        expect(await writeClipboard('x')).toBe(false);
    });

    it('reads the clipboard text, or null when it cannot', async () => {
        vi.stubGlobal('navigator', {clipboard: {readText: () => Promise.resolve('copied')}});
        expect(await readClipboard()).toBe('copied');

        vi.stubGlobal('navigator', {clipboard: {readText: () => Promise.reject(new Error('denied'))}});
        expect(await readClipboard()).toBeNull();

        vi.stubGlobal('navigator', {});
        expect(await readClipboard()).toBeNull();
    });
});
