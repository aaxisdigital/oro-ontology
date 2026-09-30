/** Minimal Chaplin-style component: constructor runs initialize(options); dispose() flips `disposed`. */
export default class BaseComponent {
    disposed = false;

    constructor(options: any = {}) {
        this.initialize(options);
    }

    initialize(_options: any): void {
        // overridden by the component under test
    }

    dispose(): void {
        this.disposed = true;
    }
}
