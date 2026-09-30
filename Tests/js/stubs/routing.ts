/** Stand-in for FOSJsRouting: `generate('name', {a: 1})` -> `/name?a=1`. */
export default {
    generate(name: string, params: Record<string, unknown> = {}): string {
        const query = new URLSearchParams(Object.entries(params).map(([k, v]) => [k, String(v)])).toString();
        return `/${name}${query ? '?' + query : ''}`;
    }
};
