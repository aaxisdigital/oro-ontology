/** Stand-in for orotranslation: the "translation" is the key itself, plus any `{{ x }}` params applied. */
export default function __(key: string, params?: Record<string, unknown>): string {
    if (!params) {
        return key;
    }
    return Object.entries(params).reduce(
        (text, [name, value]) => text.replace(new RegExp(`\\{\\{\\s*${name}\\s*\\}\\}`, 'g'), String(value)),
        key
    );
}
