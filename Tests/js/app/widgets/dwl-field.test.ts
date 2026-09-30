import $ from 'jquery';
import {describe, expect, it} from 'vitest';
import createDwlField, {prettyPrintDwl} from '../../../../Resources/js-src/app/widgets/dwl-field';

describe('prettyPrintDwl', () => {
    it('re-indents every line to its brace depth, two spaces per level', () => {
        const code = '%dw 2.0\noutput application/json\n---\n{\nname: payload.name,\nitems: [\n1,\n2\n]\n}';

        expect(prettyPrintDwl(code)).toBe(
            '%dw 2.0\noutput application/json\n---\n{\n  name: payload.name,\n  items: [\n    1,\n    2\n  ]\n}'
        );
    });

    it('dedents a line that starts with closers by exactly the closers it leads with', () => {
        expect(prettyPrintDwl('f(\n{\na: 1\n})')).toBe('f(\n  {\n    a: 1\n})');
        expect(prettyPrintDwl('f(\n{\na: 1\n}\n)')).toBe('f(\n  {\n    a: 1\n  }\n)');
    });

    it('keeps blank lines empty and strips existing indentation', () => {
        expect(prettyPrintDwl('{\n\n      a: 1\n}')).toBe('{\n\n  a: 1\n}');
    });

    it('ignores delimiters inside single- and double-quoted strings', () => {
        expect(prettyPrintDwl('{\na: "}{",\nb: \'[(\',\nc: 1\n}')).toBe('{\n  a: "}{",\n  b: \'[(\',\n  c: 1\n}');
    });

    it('honours backslash escapes inside strings', () => {
        expect(prettyPrintDwl('{\na: "\\"}",\nb: 1\n}')).toBe('{\n  a: "\\"}",\n  b: 1\n}');
    });

    it('ignores delimiters after a // comment', () => {
        expect(prettyPrintDwl('{\na: 1 // {[(\nb: 2\n}')).toBe('{\n  a: 1 // {[(\n  b: 2\n}');
    });

    it('never goes negative when there are more closers than openers', () => {
        expect(prettyPrintDwl('}\n}\na')).toBe('}\n}\na');
    });

    it('leaves an empty string alone', () => {
        expect(prettyPrintDwl('')).toBe('');
    });
});

describe('createDwlField', () => {
    it('opens as plain text with the switch off: no code styling, spellcheck on', () => {
        const field = createDwlField({label: 'Content', value: '{\na:1\n}', dwl: false});

        expect(field.isDwl()).toBe(false);
        expect(field.value()).toBe('{\na:1\n}');
        expect(field.$textarea.attr('spellcheck')).toBe('true');
        expect(field.$textarea.hasClass('aaxis-dwl-field__textarea--code')).toBe(false);
        expect(field.$textarea.attr('aria-label')).toBe('Content');
        expect(field.$el.find('.aaxis-dwl-field__label').text()).toBe('Content');
    });

    it('pretty-prints the value and switches to code styling when it opens in DWL mode', () => {
        const field = createDwlField({label: 'Code', value: '{\na:1\n}', dwl: true});

        expect(field.isDwl()).toBe(true);
        expect(field.value()).toBe('{\n  a:1\n}');
        expect(field.$textarea.attr('spellcheck')).toBe('false');
        expect(field.$textarea.hasClass('aaxis-dwl-field__textarea--code')).toBe(true);
    });

    it('reformats and restyles when the switch is turned on, and restyles back when it is turned off', () => {
        const field = createDwlField({label: 'Code', value: '{\na:1\n}', dwl: false});
        const $switch = field.$el.find('input[type="checkbox"]');

        $switch.prop('checked', true).trigger('change');
        expect(field.isDwl()).toBe(true);
        expect(field.value()).toBe('{\n  a:1\n}');
        expect(field.$textarea.hasClass('aaxis-dwl-field__textarea--code')).toBe(true);

        field.$textarea.val('{\n      a:1\n}');
        $switch.prop('checked', false).trigger('change');
        expect(field.isDwl()).toBe(false);
        expect(field.value()).toBe('{\n      a:1\n}'); // switching OFF never rewrites the text
        expect(field.$textarea.attr('spellcheck')).toBe('true');
        expect(field.$textarea.hasClass('aaxis-dwl-field__textarea--code')).toBe(false);
    });

    it('a fixed field is always DWL and renders no switch', () => {
        const field = createDwlField({label: 'Script', value: 'a', dwl: false, fixed: true});

        expect(field.isDwl()).toBe(true);
        expect(field.$el.find('input[type="checkbox"]')).toHaveLength(0);
        expect(field.$textarea.hasClass('aaxis-dwl-field__textarea--code')).toBe(true);
    });

    it('renders the switch with its title and label, right of any host tools', () => {
        const $tools = $('<button class="host-tool"></button>');
        const field = createDwlField({label: 'Body', value: '', dwl: false, $tools});

        const $right = field.$el.find('.aaxis-dwl-field__tools');
        expect($right.children().first().is('.host-tool')).toBe(true);
        const $label = $right.find('label.aaxis-flow-editor__switch');
        expect($label.attr('title')).toBe('aaxis.ontology.flow_editor.dwl_toggle_title');
        expect($label.find('.aaxis-flow-editor__switch-text').text()).toBe('aaxis.ontology.flow_editor.dwl_toggle_label');
        expect($right.children().last()[0]).toBe($label[0]);
    });

    it('applies the host classes to the textarea, label and row', () => {
        const field = createDwlField({
            label: 'X', value: '', dwl: false, editorClass: 'my-editor', labelClass: 'my-label', rowClass: 'my-row'
        });

        expect(field.$textarea.attr('class')).toBe('form-control aaxis-dwl-field__textarea my-editor');
        expect(field.$el.find('.aaxis-dwl-field__label').attr('class')).toBe('aaxis-dwl-field__label my-label');
        expect(field.$el.find('.aaxis-dwl-field__row').attr('class')).toBe('aaxis-dwl-field__row my-row');
    });

    it('uses bare class names when the host gives none', () => {
        const field = createDwlField({label: 'X', value: '', dwl: false});

        expect(field.$textarea.attr('class')).toBe('form-control aaxis-dwl-field__textarea');
        expect(field.$el.find('.aaxis-dwl-field__row').attr('class')).toBe('aaxis-dwl-field__row');
    });

    it('exposes the live textarea value through value()', () => {
        const field = createDwlField({label: 'X', value: 'a', dwl: false});

        field.$textarea.val('typed');

        expect(field.value()).toBe('typed');
    });
});
