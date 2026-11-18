/**
 * System clipboard access shared by the bundle's pages (flow editor copy/paste, the Events page's
 * copy buttons): the async Clipboard API, with the legacy hidden-textarea copy where it is missing.
 */

/** Writes text to the system clipboard; false when the browser refused. */
export function writeClipboard(text: string): Promise<boolean> {
    if (navigator.clipboard?.writeText) {
        return navigator.clipboard.writeText(text).then(() => true, () => false);
    }
    // No async clipboard API (an insecure origin): the legacy hidden-textarea copy.
    const textarea = document.createElement('textarea');
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }
    textarea.remove();
    return Promise.resolve(copied);
}

/** Reads the system clipboard's text; null when the browser cannot or will not tell. */
export function readClipboard(): Promise<string | null> {
    if (!navigator.clipboard?.readText) {
        return Promise.resolve(null);
    }
    return navigator.clipboard.readText().then(text => text, () => null);
}
