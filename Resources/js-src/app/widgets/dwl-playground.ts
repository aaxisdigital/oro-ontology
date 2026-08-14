import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';

export interface DwlPlaygroundOptions {
    /** Entity whose stored records become the script's `payload` binding. */
    entityId: number;
    /** Human label for the dialog title and the exported filename. */
    entityLabel: string;
}

/** Server response of `aaxis_ontology_entity_dwl`. */
interface DwlRunResponse {
    success: boolean;
    result?: string;
    error?: string;
    format?: string;
    mime?: string;
    extension?: string;
    rows?: number;
    total?: number;
    truncated?: boolean;
}

/** What the last successful run produced — drives the export button. */
interface LastRun {
    output: string;
    mime: string;
    extension: string;
}

/** Rows processed by default; the user can lift the cap entirely (see the "Limit rows" switch). */
const DEFAULT_LIMIT = 100;

/**
 * The script the playground opens with — a pass-through transform, so the first Run shows the
 * entity's payload as-is and the user edits from there. Mirrors the DataWeave playground's
 * header/body split (`output <mime>` … `---` … expression).
 */
const DEFAULT_SCRIPT = 'output application/json\n---\npayload';

/**
 * DataWeave playground for an ontology entity, opened from the "dwl" action on the Entities grid.
 *
 * Two panes — the DWL script and the (read-only) Result — over the entity's stored record payloads,
 * which the script sees as the `payload` binding. Nothing runs until the user presses Run: the
 * result is deliberately NOT live, so an expensive transform never fires on every keystroke. When
 * the script (or the row limit) changes after a run, the Result pane greys out to signal that what
 * is shown no longer corresponds to the current script.
 *
 * Export writes exactly the text produced by the last run, in the format the script's
 * `output <mime>` header declared (the server reports the resolved format/extension).
 *
 * One instance per open; the dialog cleans itself up on close (✕, Escape or backdrop).
 */
export default class DwlPlayground {
    private readonly opts: DwlPlaygroundOptions;
    private dialog: Dialog | null = null;
    private $script: any = null;
    private $result: any = null;
    private $run: any = null;
    private $export: any = null;
    private $status: any = null;
    private $limitToggle: any = null;
    private $limitValue: any = null;
    private $total: any = null;
    private running = false;
    /** Script+limit signature of the last run; the Result is stale when the form drifts from it. */
    private ranSignature: string | null = null;
    private lastRun: LastRun | null = null;

    constructor(options: DwlPlaygroundOptions) {
        this.opts = options;
    }

    open(): void {
        this.dialog = new Dialog({
            title: __('aaxis.ontology.dwl.title', {entity: this.opts.entityLabel}),
            subtitle: __('aaxis.ontology.dwl.subtitle'),
            width: '920px',
            height: '640px',
            movable: true,
            bodyClass: 'aaxis-dwl',
            onClose: () => {
                this.dialog = null;
            }
        });
        const $body = this.dialog.open();
        $body.append(this.buildScriptPane(), this.buildResultPane());
        this.refreshStale();
        this.loadTotal();
        window.requestAnimationFrame(() => this.$script.trigger('focus'));
    }

    /** Fills the "/ N total records" label on open, before anything has been run. */
    private loadTotal(): void {
        fetch(routing.generate('aaxis_ontology_entity_dwl_count', {id: this.opts.entityId}), {
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then((data: {success?: boolean; total?: number}) => {
                if (data?.success === true && typeof data.total === 'number') {
                    this.setTotal(data.total);
                } else {
                    this.clearTotal();
                }
            })
            // The count is informational — a failure must not block using the playground.
            .catch(() => this.clearTotal());
    }

    private setTotal(total: number): void {
        if (this.$total) {
            this.$total.text(__('aaxis.ontology.dwl.total_records', {total: total.toLocaleString()}));
        }
    }

    private clearTotal(): void {
        if (this.$total) {
            this.$total.text('');
        }
    }

    // --- Build ----------------------------------------------------------------

    private buildScriptPane(): any {
        const $pane = $('<div/>', {'class': 'aaxis-dwl__pane'});

        const $head = $('<div/>', {'class': 'aaxis-dwl__pane-head'});
        $head.append($('<h3/>', {'class': 'aaxis-dwl__pane-title', text: __('aaxis.ontology.dwl.script_label')}));

        // Limit controls: the switch owns whether a cap applies at all, the number the cap itself.
        this.$limitToggle = $('<input/>', {type: 'checkbox', 'class': 'aaxis-dwl__limit-check'}).prop('checked', true);
        this.$limitValue = $('<input/>', {
            type: 'number', 'class': 'form-control aaxis-dwl__limit-value', min: 1, step: 1,
            title: __('aaxis.ontology.dwl.limit_title')
        }).val(String(DEFAULT_LIMIT));
        // Sits right after the limit box ("/ 1,916 total records") so the cap can be judged against
        // the real volume before running. Filled by loadTotal() on open, refreshed by every run.
        this.$total = $('<span/>', {
            'class': 'aaxis-dwl__total', text: __('aaxis.ontology.dwl.total_loading')
        });
        // The total is a SIBLING of the label, not inside it: plain text inside a <label> that wraps
        // the checkbox would toggle the limit whenever the user clicked the record count.
        const $limit = $('<div/>', {'class': 'aaxis-dwl__limit-group'}).append(
            $('<label/>', {'class': 'aaxis-dwl__limit'}).append(
                this.$limitToggle,
                $('<span/>', {text: __('aaxis.ontology.dwl.limit_label')}),
                this.$limitValue
            ),
            this.$total
        );

        this.$run = $('<button/>', {
            type: 'button', 'class': 'btn btn-primary aaxis-dwl__run',
            title: __('aaxis.ontology.dwl.run_title')
        }).append(
            $('<span/>', {'class': 'fa fa-play', 'aria-hidden': 'true'}),
            $('<span/>', {text: ' ' + __('aaxis.ontology.dwl.run')})
        );

        $head.append($('<div/>', {'class': 'aaxis-dwl__pane-tools'}).append($limit, this.$run));
        $pane.append($head);

        this.$script = $('<textarea/>', {
            'class': 'aaxis-dwl__editor', spellcheck: false, autocomplete: 'off',
            'aria-label': __('aaxis.ontology.dwl.script_label')
        }).val(DEFAULT_SCRIPT);
        $pane.append(this.$script);

        this.$script.on('input', () => this.refreshStale());
        this.$limitToggle.on('change', () => {
            this.$limitValue.prop('disabled', !this.$limitToggle.is(':checked'));
            this.refreshStale();
        });
        this.$limitValue.on('input', () => this.refreshStale());
        this.$run.on('click', (e: any) => {
            e.preventDefault();
            this.run();
        });

        return $pane;
    }

    private buildResultPane(): any {
        const $pane = $('<div/>', {'class': 'aaxis-dwl__pane'});

        const $head = $('<div/>', {'class': 'aaxis-dwl__pane-head'});
        $head.append($('<h3/>', {'class': 'aaxis-dwl__pane-title', text: __('aaxis.ontology.dwl.result_label')}));

        this.$status = $('<span/>', {'class': 'aaxis-dwl__status', 'aria-live': 'polite'});
        this.$export = $('<button/>', {
            type: 'button', 'class': 'btn aaxis-dwl__export',
            title: __('aaxis.ontology.dwl.export_title')
        }).append(
            $('<span/>', {'class': 'fa fa-download', 'aria-hidden': 'true'}),
            $('<span/>', {text: ' ' + __('aaxis.ontology.dwl.export')})
        ).prop('disabled', true);

        $head.append($('<div/>', {'class': 'aaxis-dwl__pane-tools'}).append(this.$status, this.$export));
        $pane.append($head);

        this.$result = $('<textarea/>', {
            'class': 'aaxis-dwl__editor aaxis-dwl__editor--result', readonly: 'readonly', spellcheck: false,
            'aria-label': __('aaxis.ontology.dwl.result_label')
        });
        $pane.append(this.$result);

        this.$export.on('click', (e: any) => {
            e.preventDefault();
            this.exportResult();
        });

        return $pane;
    }

    // --- Run ------------------------------------------------------------------

    /** Identifies the inputs a result belongs to, so drift can be detected without re-running. */
    private signature(): string {
        return JSON.stringify([String(this.$script.val() ?? ''), this.limit()]);
    }

    private limit(): number | null {
        if (!this.$limitToggle.is(':checked')) {
            return null;
        }
        const raw = Number.parseInt(String(this.$limitValue.val() ?? ''), 10);

        return Number.isNaN(raw) || raw < 1 ? DEFAULT_LIMIT : raw;
    }

    private run(): void {
        if (this.running) {
            return;
        }
        const signature = this.signature();
        const script = String(this.$script.val() ?? '');
        if (script.trim() === '') {
            this.showError(__('aaxis.ontology.dwl.script_required'), signature);
            return;
        }

        this.running = true;
        this.$run.prop('disabled', true);
        this.$run.find('.fa').removeClass('fa-play').addClass('fa-spinner fa-spin');
        this.$status.text(__('aaxis.ontology.dwl.running'));

        this.post(routing.generate('aaxis_ontology_entity_dwl', {id: this.opts.entityId}), {
            script,
            limit: this.limit()
        })
            .then(data => this.applyResponse(data, signature))
            .catch(() => this.showError(__('aaxis.ontology.dwl.run_error'), signature))
            .finally(() => {
                this.running = false;
                if (this.dialog) {
                    this.$run.prop('disabled', false);
                    this.$run.find('.fa').removeClass('fa-spinner fa-spin').addClass('fa-play');
                }
            });
    }

    private applyResponse(data: DwlRunResponse, signature: string): void {
        if (!this.dialog) {
            return; // closed while the request was in flight
        }
        if (!data || data.success !== true) {
            // A script error IS a result for these inputs: show it in the Result pane (not as a
            // flash message) and stamp the signature, so the message is not also greyed as stale.
            this.showError(data?.error || __('aaxis.ontology.dwl.run_error'), signature);
            return;
        }

        // The run counted the same way the label does — keep the header in sync with reality
        // (records may have been added or removed since the dialog was opened).
        if (typeof data.total === 'number') {
            this.setTotal(data.total);
        }

        const output = String(data.result ?? '');
        this.$result.val(output).removeClass('is-error');
        this.lastRun = {
            output,
            mime: data.mime || 'application/json',
            extension: data.extension || 'json'
        };
        this.$export.prop('disabled', output === '');
        this.ranSignature = signature;
        this.$status.text(this.describeRun(data));
        this.refreshStale();
    }

    /**
     * What this result covers, e.g. "100 record(s) — limited" / "42 record(s)". The TOTAL is not
     * repeated here: it now lives next to the row limit at the top of the dialog.
     */
    private describeRun(data: DwlRunResponse): string {
        const rows = Number(data.rows ?? 0).toLocaleString();

        return data.truncated
            ? __('aaxis.ontology.dwl.status_limited', {rows})
            : __('aaxis.ontology.dwl.status_rows', {rows});
    }

    /**
     * Shows a failure in the Result pane. `signature` is the input signature the failure belongs to
     * — stamping it keeps the pane out of the "stale" state, so the red message stays legible
     * instead of being greyed out on top of being an error.
     */
    private showError(message: string, signature: string): void {
        this.$result.val(message).addClass('is-error');
        this.$status.text('');
        // An error produced no output — there is nothing meaningful to export.
        this.lastRun = null;
        this.$export.prop('disabled', true);
        this.ranSignature = signature;
        this.refreshStale();
    }

    /**
     * Greys the Result pane out whenever the script/limit no longer match what produced it (and
     * before the first run), so a stale result is never mistaken for the current script's output.
     */
    private refreshStale(): void {
        const stale = this.ranSignature === null || this.ranSignature !== this.signature();
        this.$result.toggleClass('is-stale', stale);
        this.$result.attr('title', stale ? __('aaxis.ontology.dwl.stale_hint') : '');
    }

    // --- Export ---------------------------------------------------------------

    /** Saves the last run's output verbatim, named/typed after the script's declared output format. */
    private exportResult(): void {
        if (!this.lastRun) {
            return;
        }
        const blob = new Blob([this.lastRun.output], {type: this.lastRun.mime + ';charset=utf-8'});
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = this.filename(this.lastRun.extension);
        document.body.appendChild(link);
        link.click();
        link.remove();
        // Revoke on the next tick — Safari needs the URL alive for the duration of the click.
        window.setTimeout(() => window.URL.revokeObjectURL(url), 0);
    }

    private filename(extension: string): string {
        // split/filter/join rather than trimming with anchored quantifiers (no backtracking).
        const slug = String(this.opts.entityLabel || 'entity')
            .toLowerCase()
            .split(/[^a-z0-9]+/)
            .filter(Boolean)
            .join('-') || 'entity';
        const now = new Date();
        const pad = (value: number): string => String(value).padStart(2, '0');
        const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}`
            + `-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;

        return `${slug}-dwl-${stamp}.${extension}`;
    }

    // --- Transport ------------------------------------------------------------

    private csrf(): string {
        const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
        const match = new RegExp('(?:^|; )' + name + '=([^;]*)').exec(document.cookie);

        return match ? decodeURIComponent(match[1]) : '';
    }

    private post(url: string, body: any): Promise<DwlRunResponse> {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': this.csrf()},
            body: JSON.stringify(body)
        }).then(r => r.json());
    }
}
