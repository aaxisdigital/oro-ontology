import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import BaseComponent from 'oroui/js/app/components/base/component';
import {csrfToken} from './component-support';

interface ConnectorViewTestOptions {
    _sourceElement: any;
    connectorId: number;
}

interface TestStep {
    label: string;
    success: boolean;
    message?: string;
}

interface TestOutcome {
    success: boolean;
    message?: string;
    steps?: TestStep[];
}

/**
 * The connector VIEW page's "Test" button: probes the SAVED configuration through the
 * aaxis_ontology_connector_test_stored endpoint (no config travels — the id is enough) and
 * renders the same overall + per-step lines as the Configure popup's test.
 *
 * The button itself lives in the page title bar (the twig navButtons block), outside this
 * component's element, so the click is document-delegated; the result renders under the
 * Configuration block, where this component is mounted.
 */
class ConnectorViewTestComponent extends BaseComponent {
    private $result!: any;
    private connectorId!: number;
    private testing!: boolean;

    initialize(options: ConnectorViewTestOptions): void {
        this.connectorId = Number(options.connectorId);
        this.testing = false;
        this.$result = options._sourceElement.find('[data-role="connector-test-result"]');
        $(document).on('click.aaxisConnectorViewTest', '[data-role="connector-test-stored"]', (e: any) => {
            e.preventDefault();
            this.run($(e.currentTarget));
        });
    }

    private run($button: any): void {
        if (this.testing) {
            return;
        }
        this.testing = true;
        $button.prop('disabled', true);
        this.$result.empty()
            .append(this.line('is-progress', 'fa-circle-o-notch fa-spin', __('aaxis.common.grid.testing')))
            .removeAttr('hidden');

        fetch(routing.generate('aaxis_ontology_connector_test_stored', {id: this.connectorId}), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': csrfToken()}
        })
            .then(r => r.json())
            .then((outcome: TestOutcome) => this.render(outcome))
            .catch(() => this.render({success: false, message: __('aaxis.common.grid.test_error')}))
            .finally(() => {
                this.testing = false;
                $button.prop('disabled', false);
            });
    }

    private render(outcome: TestOutcome): void {
        this.$result.empty().removeAttr('hidden');
        const ok = !!outcome.success;
        this.$result.append(this.line(
            ok ? 'is-ok' : 'is-fail',
            ok ? 'fa-check-circle' : 'fa-times-circle',
            outcome.message || __(ok ? 'aaxis.common.grid.test_success' : 'aaxis.common.grid.test_error')
        ));
        (outcome.steps || []).forEach(step => {
            this.$result.append(this.line(
                step.success ? 'is-ok' : 'is-fail',
                step.success ? 'fa-check-circle' : 'fa-times-circle',
                step.label + (step.message ? ' — ' + step.message : ''),
                true
            ));
        });
    }

    private line(state: string, icon: string, text: string, step = false): any {
        return $('<div/>', {'class': 'aaxis-connector-test__' + (step ? 'step' : 'line') + ' ' + state}).append(
            $('<span/>', {'class': 'fa ' + icon, 'aria-hidden': 'true'}),
            $('<span/>', {text: ' ' + text})
        );
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        $(document).off('click.aaxisConnectorViewTest');
        super.dispose();
    }
}

export default ConnectorViewTestComponent;
