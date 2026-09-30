import DataViewComponent from '../../../Resources/js-src/app/components/data-view-component';
import {FakeGrid, jsonResponse, pageElement, stubFetch} from './page-mocks';

export const LIST_URL = '/aaxis_ontology_data_list';

export const SYSTEMS = [{id: 1, name: 'ERP'}, {id: 2, name: 'CRM'}];
export const ENTITIES = [
    {id: 10, name: 'Order', systemId: 1, uniqueAttribute: 'id'},
    {id: 11, name: 'Invoice', systemId: 1, uniqueAttribute: null},
    {id: 20, name: 'Contact', systemId: 2, uniqueAttribute: 'email'},
    {id: 30, name: 'Orphan', systemId: null, uniqueAttribute: 'id'}
];

export function record(overrides: Record<string, unknown> = {}): any {
    return {
        id: 5, system: 'ERP', entity: 'Order', systemId: 1, entityId: 10, uniqueId: 'A-1',
        uuid: 'uuid-5', version: 3, payload: '{"id":"A-1","total":10}', updatedAt: '2026-05-12T10:00:00Z',
        ...overrides
    };
}

/** Mounts the Data View on a fresh page element; the list endpoint answers with `records`. */
export function mountDataView(
    options: Record<string, unknown> = {}, routes: Record<string, any> = {}, records: any[] = [record()]
) {
    const $el = pageElement();
    const fetchSpy = stubFetch({
        [LIST_URL]: jsonResponse({records, systems: SYSTEMS, entities: ENTITIES}),
        ...routes
    });
    const component: any = new (DataViewComponent as any)({_sourceElement: $el, canCreate: true, ...options});

    return {component, $el, fetchSpy, grid: FakeGrid.instances[0]};
}
