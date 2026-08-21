import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import routing from 'routing';
import BaseComponent from 'oroui/js/app/components/base/component';
import {csrfToken} from './component-support';

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
 * System Configuration → Bucket: the "Test connection" button (mounted by BucketTestType's widget
 * block). Collects the section's CURRENT input values — by config-form field name, so unsaved
 * edits are what gets tested; untouched key fields still hold the '*' placeholder, which the
 * server resolves from the saved encrypted values — and probes them through the same
 * ConnectorTester bucket arm as the connector popups, rendering the same overall + per-step lines.
 */
class BucketConfigTestComponent extends BaseComponent {
    private $el!: any;
    private $result!: any;
    private testing!: boolean;

    initialize(options: {_sourceElement: any}): void {
        this.$el = options._sourceElement;
        this.$result = this.$el.find('[data-role="bucket-config-test-result"]');
        this.testing = false;
        this.$el.on('click.aaxisBucketConfigTest', '[data-role="bucket-config-test"]', (e: any) => {
            e.preventDefault();
            this.run($(e.currentTarget));
        });
    }

    /** Current value of one Bucket-section input, located by its config-form field name. */
    private value(field: string): string {
        return String($(`[name$="[aaxis_ontology___bucket_${field}][value]"]`).val() ?? '');
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

        fetch(routing.generate('aaxis_ontology_config_bucket_test'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Header': csrfToken()},
            body: JSON.stringify({
                config: {
                    endpoint_url: this.value('endpoint_url'),
                    access_key: this.value('access_key'),
                    secret_key: this.value('secret_key'),
                    bucket_name: this.value('name')
                }
            })
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
        this.$el.off('.aaxisBucketConfigTest');
        super.dispose();
    }
}

export default BucketConfigTestComponent;
