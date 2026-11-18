/**
 * Copy / paste of flow-editor elements through the SYSTEM clipboard. The editor's "Copy" turns a
 * selection into a JSON document and its "Paste" (right-click on empty canvas) reads one back, so
 * elements travel between tabs, flows and browser windows alike.
 *
 * Pure helpers only — the editor component owns the canvas side (placing tiles, links, selection);
 * the system clipboard itself is read and written through ./clipboard.
 */

export const CLIPBOARD_FORMAT = 'aaxis-ontology-flow-steps';
export const CLIPBOARD_VERSION = 1;

/** Step names are capped like the server's (FlowStepValidator: ≤ 64 characters). */
const MAX_NAME_LENGTH = 64;

export interface ClipboardStep {
    id: string;
    type: string;
    name: string;
    /** Position RELATIVE to the copied selection's top-left corner. */
    x: number;
    y: number;
    config: Record<string, any> | null;
    inSide?: string;
    outSides?: (string | null)[];
    /** The source tile was red (incomplete config) — the pasted one starts red too. */
    invalid?: boolean;
}

export interface ClipboardLink {
    from: string;
    fromPort: number;
    to: string;
}

export interface ClipboardDoc {
    format: string;
    version: number;
    steps: ClipboardStep[];
    links: ClipboardLink[];
}

/** What a copied step is read from (the editor's PlacedStep satisfies it). */
export interface CopySource {
    id: string;
    type: string;
    name: string;
    x: number;
    y: number;
    config?: Record<string, any> | null;
    inSide?: string;
    outSides?: (string | undefined)[];
    invalid?: boolean;
}

/** How the parser learns about step types without depending on the editor. */
export interface StepTypeInfo {
    /** A type the editor knows (present in the toolbox). */
    known: (type: string) => boolean;
    trigger: (type: string) => boolean;
    ports: (type: string) => number;
}

/**
 * The clipboard document of a selection: positions relative to its top-left corner, configs
 * deep-copied, and only the links whose BOTH ends are copied (a link leaving the selection has
 * nothing to point at once pasted).
 */
export function buildClipboardDoc(steps: CopySource[], links: ClipboardLink[]): ClipboardDoc {
    const ids = new Set(steps.map(s => s.id));
    const minX = Math.min(...steps.map(s => s.x));
    const minY = Math.min(...steps.map(s => s.y));

    return {
        format: CLIPBOARD_FORMAT,
        version: CLIPBOARD_VERSION,
        steps: steps.map(s => ({
            id: s.id,
            type: s.type,
            name: s.name,
            x: s.x - minX,
            y: s.y - minY,
            config: s.config ? JSON.parse(JSON.stringify(s.config)) : null,
            ...(s.inSide ? {inSide: s.inSide} : {}),
            ...(s.outSides?.some(Boolean) ? {outSides: s.outSides.map(side => side || null)} : {}),
            ...(s.invalid ? {invalid: true} : {})
        })),
        links: links
            .filter(l => ids.has(l.from) && ids.has(l.to))
            .map(l => ({from: l.from, fromPort: l.fromPort, to: l.to}))
    };
}

/**
 * Parses and STRICTLY validates clipboard text; null = not a copy of flow elements. The rules
 * mirror a stored design's (unique ids and names, known types, one link per port, one incoming
 * link per element, none into a trigger) plus at most ONE trigger, since a canvas holds one.
 */
export function parseClipboardDoc(text: string, types: StepTypeInfo): ClipboardDoc | null {
    let doc: any;
    try {
        doc = JSON.parse(text);
    } catch {
        return null;
    }
    if (!isPlainObject(doc) || doc.format !== CLIPBOARD_FORMAT || doc.version !== CLIPBOARD_VERSION
        || !Array.isArray(doc.steps) || doc.steps.length === 0
    ) {
        return null;
    }

    const byId: Record<string, any> = {};
    const names = new Set<string>();
    let triggers = 0;
    for (const step of doc.steps) {
        if (!validStep(step, types) || byId[step.id] || names.has(step.name.trim().toLowerCase())) {
            return null;
        }
        byId[step.id] = step;
        names.add(step.name.trim().toLowerCase());
        triggers += types.trigger(step.type) ? 1 : 0;
    }
    if (triggers > 1) {
        return null;
    }

    const links = doc.links === undefined ? [] : doc.links;
    if (!Array.isArray(links) || !validLinks(links, byId, types)) {
        return null;
    }

    return {
        format: CLIPBOARD_FORMAT,
        version: CLIPBOARD_VERSION,
        steps: doc.steps.map((s: any) => ({
            id: s.id,
            type: s.type,
            name: s.name.trim(),
            x: s.x,
            y: s.y,
            config: s.config ? JSON.parse(JSON.stringify(s.config)) : null,
            ...(s.inSide !== undefined ? {inSide: s.inSide} : {}),
            ...(s.outSides !== undefined ? {outSides: s.outSides} : {}),
            ...(s.invalid === true ? {invalid: true} : {})
        })),
        links: links.map((l: any) => ({from: l.from, fromPort: l.fromPort, to: l.to}))
    };
}

function validStep(step: any, types: StepTypeInfo): boolean {
    return isPlainObject(step)
        && typeof step.type === 'string' && types.known(step.type)
        && typeof step.id === 'string' && step.id !== ''
        && typeof step.name === 'string' && step.name.trim() !== '' && step.name.trim().length <= MAX_NAME_LENGTH
        && Number.isFinite(step.x) && Number.isFinite(step.y) && step.x >= 0 && step.y >= 0
        && (step.config === undefined || step.config === null || isPlainObject(step.config));
}

function validLinks(links: any[], byId: Record<string, any>, types: StepTypeInfo): boolean {
    const usedPorts = new Set<string>();
    const usedInputs = new Set<string>();
    for (const link of links) {
        if (!isPlainObject(link) || !byId[link.from] || !byId[link.to] || link.from === link.to
            || !Number.isInteger(link.fromPort) || link.fromPort < 0
            || link.fromPort >= types.ports(byId[link.from].type)
            || types.trigger(byId[link.to].type)
            || usedPorts.has(`${link.from}:${link.fromPort}`) || usedInputs.has(link.to)
        ) {
            return false;
        }
        usedPorts.add(`${link.from}:${link.fromPort}`);
        usedInputs.add(link.to);
    }
    return true;
}

/**
 * The name a pasted step gets. A TRIGGER always gains " (copy)": the flow is named after its
 * trigger, so pasting one verbatim would clash with the source flow's name. Any other step keeps
 * its name while it is free on the target canvas. When the name is taken, the suffix counts up
 * (" (copy 2)", " (copy 3)", …), and the base is trimmed so the result stays within 64 characters.
 *
 * @param taken lowercased names already used (the canvas, and steps pasted before this one)
 */
export function pastedName(name: string, trigger: boolean, taken: Set<string>): string {
    if (!trigger && !taken.has(name.toLowerCase())) {
        return name;
    }
    for (let n = 1; ; n++) {
        const suffix = n === 1 ? ' (copy)' : ` (copy ${n})`;
        const candidate = name.slice(0, MAX_NAME_LENGTH - suffix.length).trimEnd() + suffix;
        if (!taken.has(candidate.toLowerCase())) {
            return candidate;
        }
    }
}

function isPlainObject(value: any): boolean {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
