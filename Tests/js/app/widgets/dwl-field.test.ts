import $ from 'jquery';
import {describe, expect, it, vi} from 'vitest';
import createDwlField, {formatDwl, prettyPrintDwl} from '../../../../Resources/js-src/app/widgets/dwl-field';

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

    it('a fixed field is always DWL: its switch shows ON and locked, after the format wand', () => {
        const field = createDwlField({label: 'Script', value: 'a', dwl: false, fixed: true});
        const $switch = field.$el.find('.aaxis-flow-editor__switch');

        expect(field.isDwl()).toBe(true);
        expect($switch.hasClass('is-locked')).toBe(true);
        expect($switch.attr('title')).toBe('aaxis.ontology.flow_editor.dwl_fixed_title');
        expect($switch.find('input').prop('checked')).toBe(true);
        expect($switch.find('input').prop('disabled')).toBe(true);
        expect($switch.prev().is('[data-role="dwl-format"]')).toBe(true);
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

describe('formatDwl', () => {
    it('puts each entry of an object literal on its own line, nested ones too', () => {
        expect(formatDwl('{statusCode: 200, body: {id: payload.id, total: payload.total}}')).toBe(
            '{\n  statusCode: 200,\n  body: {\n    id: payload.id,\n    total: payload.total\n  }\n}'
        );
    });

    it('keeps short lists, one-entry objects and parentheses inline', () => {
        expect(formatDwl('[1, 2, 3]')).toBe('[1, 2, 3]');
        expect(formatDwl('{a: 1}')).toBe('{a: 1}');
        expect(formatDwl('payload map ((item, index) -> item.id)')).toBe('payload map ((item, index) -> item.id)');
        expect(formatDwl('payload.items[0]')).toBe('payload.items[0]');
    });

    it('breaks long lists and lists of objects', () => {
        expect(formatDwl('[{a: 1}, {a: 2}]')).toBe('[\n  {a: 1},\n  {a: 2}\n]');
        const long = `[${Array.from({length: 20}, (_v, i) => `"value${i}"`).join(', ')}]`;
        expect(formatDwl(long).split('\n')).toHaveLength(22);
    });

    it('never touches strings or comments, and keeps a comment trailing a comma on its line', () => {
        expect(formatDwl('{a: "x, {y}", b: 1}')).toBe('{\n  a: "x, {y}",\n  b: 1\n}');
        expect(formatDwl('{a: 1, // note, {not code}\nb: 2}')).toBe('{\n  a: 1, // note, {not code}\n  b: 2\n}');
        expect(formatDwl('{a: 1, /* x, y */ b: 2}')).toBe('{\n  a: 1,\n  /* x, y */ b: 2\n}');
    });

    it('keeps existing line breaks, re-indents, and changes nothing the second time', () => {
        const once = formatDwl('%dw 2.0\noutput application/json\n---\n{\n      a: 1, b: [1, 2],\n c: 3}');
        expect(once).toBe('%dw 2.0\noutput application/json\n---\n{\n  a: 1,\n  b: [1, 2],\n  c: 3\n}');
        expect(formatDwl(once)).toBe(once);
    });
});

describe('createDwlField format wand', () => {
    it('formats the code (firing input) from a wand before the switch', () => {
        const field = createDwlField({label: 'Code', value: '{a: 1, b: 2}', dwl: true});
        const onInput = vi.fn();
        field.$textarea.on('input', onInput);
        const $wand = field.$el.find('[data-role="dwl-format"]');

        expect($wand.next().is('.aaxis-flow-editor__switch')).toBe(true);
        expect($wand.attr('title')).toBe('aaxis.ontology.flow_editor.dwl_format');
        $wand.trigger('click');

        expect(field.value()).toBe('{\n  a: 1,\n  b: 2\n}');
        expect(onInput).toHaveBeenCalled();
    });

    it('is disabled in plain-text mode and while the host disables the field', () => {
        const field = createDwlField({label: 'Message', value: '{a: 1, b: 2}', dwl: false});
        const $wand = field.$el.find('[data-role="dwl-format"]');
        expect($wand.prop('disabled')).toBe(true);

        field.$el.find('input[type="checkbox"]').prop('checked', true).trigger('change');
        expect($wand.prop('disabled')).toBe(false);

        field.$textarea.prop('disabled', true).trigger('aaxis:dwl-sync');
        expect($wand.prop('disabled')).toBe(true);
    });

    it('shows on fixed DWL fields too, and can be left out', () => {
        expect(createDwlField({label: 'Code', value: '', dwl: true, fixed: true}).$el.find('[data-role="dwl-format"]')).toHaveLength(1);
        expect(createDwlField({label: 'Code', value: '', dwl: true, format: false}).$el.find('[data-role="dwl-format"]')).toHaveLength(0);
    });
});
