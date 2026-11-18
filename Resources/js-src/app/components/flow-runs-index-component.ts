import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import BaseComponent from 'oroui/js/app/components/base/component';
import {csrfToken} from './component-support';

/**
 * System Configuration → Settings: the "Rebuild run index" button (mounted by
 * FlowRunIndexStatusType's widget block). It QUEUES the rebuild — on a bucket it lists days of
 * keys, longer than a request may wait — and says so; the status above the button shows the
 * outcome once the page is reloaded.
 */
class FlowRunsIndexComponent extends BaseComponent {
    private $el!: any;
    private $result!: any;
    private busy!: boolean;

    initialize(options: {_sourceElement: any}): void {
        this.$el = options._sourceElement;
        this.$result = this.$el.find('[data-role="flow-runs-rebuild-result"]');
        this.busy = false;
        this.$el.on('click.aaxisFlowRunsIndex', '[data-role="flow-runs-rebuild"]', (e: any) => {
            e.preventDefault();
            this.rebuild($(e.currentTarget));
        });
    }

    private rebuild($button: any): void {
        if (this.busy) {
            return;
        }
        this.busy = true;
        $button.prop('disabled', true);
        fetch(routing.generate('aaxis_ontology_config_flow_runs_rebuild'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': csrfToken()}
        })
            .then(r => r.json().then((data: {success?: boolean}) => r.ok && data.success === true))
            .catch(() => false)
            .then(queued => {
                this.render(queued);
                // A queued rebuild needs no second press; a failed request may be retried.
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
                $('<span/>', {
                    text: ' ' + __(queued
                        ? 'aaxis.ontology.config.flow_runs_index.queued'
                        : 'aaxis.ontology.config.flow_runs_index.queue_failed')
                })
            )
        );
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisFlowRunsIndex');
        super.dispose();
    }
}

export default FlowRunsIndexComponent;
