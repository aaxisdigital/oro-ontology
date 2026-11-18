import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import BaseComponent from 'oroui/js/app/components/base/component';
import Dialog from 'aaxiscommon/js/app/widgets/dialog';
import {csrfToken} from './component-support';

/** A confirmation asked before queuing (translation keys + their placeholders). */
interface ConfirmOptions {
    title: string;
    question: string;
    button: string;
    params?: Record<string, unknown>;
}

interface MaintenanceOptions {
    _sourceElement: any;
    /** The POST route that QUEUES the job. */
    route: string;
    /** Translation key shown once the job is queued. */
    queued: string;
    /** Translation key shown when it could not be queued. */
    failed: string;
    confirm?: ConfirmOptions;
}

/**
 * System Configuration → Settings: a flow-history maintenance button — "Rebuild run index"
 * (FlowRunIndexStatusType) and "Clean up now" (FlowHistoryPurgeType) mount it with their own
 * route and messages. The job is QUEUED — a bucket rebuild lists days of keys and a cleanup walks
 * batches and day folders, longer than a request may wait — and the component says so; the
 * status above the button shows the outcome once the page is reloaded. An optional confirmation
 * guards destructive jobs.
 */
class FlowHistoryMaintenanceComponent extends BaseComponent {
    private $el!: any;
    private $result!: any;
    private options!: MaintenanceOptions;
    private busy!: boolean;

    initialize(options: MaintenanceOptions): void {
        this.options = options;
        this.$el = options._sourceElement;
        this.$result = this.$el.find('[data-role="maintenance-result"]');
        this.busy = false;
        this.$el.on('click.aaxisFlowHistoryMaintenance', '[data-role="maintenance-run"]', (e: any) => {
            e.preventDefault();
            const $button = $(e.currentTarget);
            if (this.options.confirm) {
                this.confirm(this.options.confirm, () => this.queue($button));
            } else {
                this.queue($button);
            }
        });
    }

    private confirm(confirm: ConfirmOptions, onConfirm: () => void): void {
        const dialog = new Dialog({title: __(confirm.title), width: '520px'});
        const $content = dialog.open();
        const $cancel = $('<button/>', {type: 'button', 'class': 'btn', text: __('Cancel')});
        const $confirm = $('<button/>', {type: 'button', 'class': 'btn btn-primary', text: __(confirm.button)});
        $content.append($('<div/>', {'class': 'aaxis-ontology-confirm'}).append(
            $('<p/>', {'class': 'aaxis-ontology-confirm__q', text: __(confirm.question, confirm.params || {})}),
            $('<div/>', {'class': 'aaxis-ontology-confirm__actions'}).append($cancel, $confirm)
        ));
        $cancel.on('click', () => dialog.close());
        $confirm.on('click', () => {
            dialog.close();
            onConfirm();
        });
    }

    private queue($button: any): void {
        if (this.busy) {
            return;
        }
        this.busy = true;
        $button.prop('disabled', true);
        fetch(routing.generate(this.options.route), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': csrfToken()}
        })
            .then(r => r.json().then((data: {success?: boolean}) => r.ok && data.success === true))
            .catch(() => false)
            .then(queued => {
                this.render(queued);
                // A queued job needs no second press; a failed request may be retried.
                $button.prop('disabled', queued);
            })
            .finally(() => {
                this.busy = false;
            });
    }

    private render(queued: boolean): void {
        this.$result.empty().removeAttr('hidden').append(
            $('<div/>', {'class': 'aaxis-connector-test__line ' + (queued ? 'is-ok' : 'is-fail')}).append(
                $('<span/>', {'class': 'fa ' + (queued ? 'fa-clock-o' : 'fa-times-circle'), 'aria-hidden': 'true'}),
                $('<span/>', {text: ' ' + __(queued ? this.options.queued : this.options.failed)})
            )
        );
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisFlowHistoryMaintenance');
        super.dispose();
    }
}

export default FlowHistoryMaintenanceComponent;
