declare module 'h5p-standalone' {
    export interface H5PStandaloneOptions {
        h5pJsonPath: string;
        contentJsonPath: string;
        librariesPath: string;
        frameJs?: string;
        frameCss?: string;
        frame?: boolean;
        copyright?: boolean;
        export?: boolean;
        embed?: boolean;
        icon?: boolean;
    }

    export class H5P {
        constructor(container: HTMLElement, options: H5PStandaloneOptions);
        destroy?(): void;
    }

    const defaultExport: { H5P: typeof H5P } | typeof H5P;
    export default defaultExport;
}
