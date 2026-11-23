import {describe, expect, it} from 'vitest';
import createDwlInput from '../../../../Resources/js-src/app/widgets/dwl-input';

describe('dwl-input — single-line text or DWL field', () => {
    it('renders a plain text input with the switch inside, off by default', () => {
        const field = createDwlInput({value: '/v1/orders', dwl: false});

        expect(field.$el.hasClass('aaxis-dwl-input')).toBe(true);
        expect(field.$input.is('input[type="text"].form-control.aaxis-dwl-input__input')).toBe(true);
        expect(field.$el.find('.aaxis-dwl-input__switch').find(field.$toggle)).toHaveLength(1);
        expect(field.$el.find('.aaxis-flow-editor__switch-text').text()).toBe('aaxis.ontology.flow_editor.dwl_toggle_label');
        expect(field.value()).toBe('/v1/orders');
        expect(field.isDwl()).toBe(false);
        expect(field.$input.hasClass('aaxis-dwl-input__input--code')).toBe(false);
        expect(field.$input.attr('spellcheck')).toBe('true');
        expect(field.$input.attr('aria-label')).toBeUndefined();
        expect(field.$input.attr('maxlength')).toBeUndefined();
    });

    it('shows DWL mode in code style, following the switch', () => {
        const field = createDwlInput({value: 'payload.path', dwl: true, label: 'Path', placeholder: 'p', maxlength: 99, inputClass: 'extra'});

        expect(field.isDwl()).toBe(true);
        expect(field.$input.hasClass('aaxis-dwl-input__input--code')).toBe(true);
        expect(field.$input.attr('spellcheck')).toBe('false');
        expect(field.$input.hasClass('extra')).toBe(true);
        expect(field.$input.attr('aria-label')).toBe('Path');
        expect(field.$input.attr('placeholder')).toBe('p');
        expect(field.$input.attr('maxlength')).toBe('99');

        field.$toggle.prop('checked', false).trigger('change');
        expect(field.isDwl()).toBe(false);
        expect(field.$input.hasClass('aaxis-dwl-input__input--code')).toBe(false);

        field.$input.val('typed');
        expect(field.value()).toBe('typed');
    });

    it('is DWL only when fixed: code style, no switch', () => {
        const field = createDwlInput({value: '{header: false}', dwl: false, fixed: true});

        expect(field.$el.hasClass('aaxis-dwl-input--fixed')).toBe(true);
        expect(field.$el.find('.aaxis-dwl-input__switch')).toHaveLength(0);
        expect(field.isDwl()).toBe(true);
        expect(field.$input.hasClass('aaxis-dwl-input__input--code')).toBe(true);
        expect(field.$input.attr('spellcheck')).toBe('false');

        field.$toggle.prop('checked', false).trigger('change');
        expect(field.isDwl()).toBe(true);
    });
});
