import {resolve} from 'node:path';
import {defineConfig} from 'vitest/config';

// The widgets import Oro platform modules that only exist inside the Oro webpack build. Tests swap
// them for the small stand-ins in Tests/js/stubs; jQuery is the real one, running on jsdom.
const stub = (name: string): string => resolve(__dirname, 'Tests/js/stubs', name);
// Other Aaxis bundles import CommonBundle's widgets as `aaxiscommon/js/...`; use the sibling checkout's source.
const commonDir = process.env.AAXIS_COMMON_DIR ?? resolve(__dirname, '../oro-common');

export default defineConfig({
    resolve: {
        alias: [
            {find: 'orotranslation/js/translator', replacement: stub('translator.ts')},
            {find: 'routing', replacement: stub('routing.ts')},
            {find: 'oroui/js/app/components/base/component', replacement: stub('base-component.ts')},
            {find: 'oroui/js/messenger', replacement: stub('messenger.ts')},
            {find: 'oroui/js/mediator', replacement: stub('mediator.ts')},
            {find: 'oroui/js/modal', replacement: stub('modal.ts')},
            {find: 'oroform/js/app/views/select2-view', replacement: stub('select2-view.ts')},
            {find: 'oronavigation/js/app/services/page-state-checker', replacement: stub('page-state-checker.ts')},
            {find: 'jquery.select2', replacement: stub('empty.ts')},
            // CodeMirror modes/addons only register themselves on the real CodeMirror; the stub needs none.
            {find: /^codemirror\/(mode|addon)\/.+$/, replacement: stub('empty.ts')},
            {find: /^codemirror$/, replacement: stub('codemirror.ts')},
            {find: /^aaxiscommon\/js\/(.+)$/, replacement: resolve(commonDir, 'Resources/js-src/$1.ts')}
        ]
    },
    test: {
        environment: 'jsdom',
        include: ['Tests/js/**/*.test.ts'],
        setupFiles: ['Tests/js/setup.ts'],
        restoreMocks: true,
        coverage: {
            provider: 'v8',
            include: ['Resources/js-src/**/*.ts'],
            exclude: ['Resources/js-src/types/**', '**/*.d.ts'],
            reporter: ['text-summary', 'lcov'],
            reportsDirectory: process.env.COVERAGE_DIR ?? 'coverage'
        }
    }
});
