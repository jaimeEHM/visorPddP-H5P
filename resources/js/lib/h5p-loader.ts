let h5pLoadPromise: Promise<any> | null = null;

export async function getH5P(): Promise<any> {
    if (h5pLoadPromise) {
        return h5pLoadPromise;
    }

    h5pLoadPromise = import('h5p-standalone').then((module) => {
        const maybeDefault = (module as any).default;
        const H5P = maybeDefault?.H5P ?? (module as any).H5P ?? maybeDefault ?? module;

        if (typeof H5P !== 'function') {
            throw new Error('No se pudo inicializar h5p-standalone.');
        }

        return H5P;
    });

    return h5pLoadPromise;
}
