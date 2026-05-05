<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { platforms } from '@/routes/lti';
import { assetWithToken, destroy, upload } from '@/actions/App/Http/Controllers/H5PPreviewController';
import { getH5P } from '@/lib/h5p-loader';

const page = usePage();
const props = defineProps<{
    initialPreview?: {
        previewId: string;
        previewToken: string;
    } | null;
}>();
const fileInputRef = ref<HTMLInputElement | null>(null);
const previewId = ref<string | null>(null);
const previewToken = ref<string | null>(null);
const uploading = ref(false);
const loadingPreview = ref(false);
const error = ref<string | null>(null);
const dropActive = ref(false);
const previewSectionRef = ref<HTMLElement | null>(null);
const viewerRef = ref<HTMLElement | null>(null);
const floatingButtonRef = ref<HTMLButtonElement | null>(null);
const isEmbeddedInIframe = ref(false);
const isPreviewFullscreen = ref(false);
const floatingButtonPosition = ref({ x: 16, y: 16 });
const isDraggingFloatingButton = ref(false);
let activePointerId: number | null = null;
let dragStartClientX = 0;
let dragStartClientY = 0;
let dragStartPositionX = 16;
let dragStartPositionY = 16;
let h5pInstance: { destroy?: () => void } | null = null;
let iframeAutoResizeInterval: number | null = null;
let iframeMutationObserver: MutationObserver | null = null;
let xapiFlushInterval: number | null = null;
let pendingStatements: Array<Record<string, unknown>> = [];
let xapiDispatcherHandler: ((event: { data?: { statement?: Record<string, unknown> } }) => void) | null = null;
let isFlushingXapi = false;
const xapiRetryBackoffMs = ref(0);
let xapiNextRetryAt = 0;
let xapiConsecutiveFailures = 0;
/** Contador global para correlacionar logs en consola. */
let xapiStatementSeq = 0;

function xapiConsoleLog(phase: 'capturado' | 'lote-envio' | 'respuesta', payload: unknown): void {
    const tag = '[xAPI PDDP]';
    try {
        const serialized = JSON.stringify(payload, null, 2);
        console.info(`${tag} ${phase}`, payload, '\nJSON:\n', serialized);
    } catch {
        console.info(`${tag} ${phase}`, payload);
    }
}

const xapiStats = ref({
    captured: 0,
    forwarded: 0,
    failed: 0,
    lastError: '',
});

const ltiRoles = computed(() => (Array.isArray(page.props.ltiRoles) ? page.props.ltiRoles : []));
const ltiIssuer = computed(() => (typeof page.props.ltiIssuer === 'string' ? page.props.ltiIssuer : ''));
const integratedLtiIssuers = computed(() =>
    Array.isArray(page.props.integratedLtiIssuers)
        ? page.props.integratedLtiIssuers.map((issuer) => String(issuer))
        : [],
);
const isIntegratedIssuer = computed(() =>
    ltiIssuer.value !== '' && integratedLtiIssuers.value.includes(ltiIssuer.value),
);
const isMoodleLaunch = computed(() => isIntegratedIssuer.value && /moodle/i.test(ltiIssuer.value));
const shouldApplyMoodleRestrictions = computed(() => isMoodleLaunch.value && isEmbeddedInIframe.value);
const isStudentRole = computed(() =>
    ltiRoles.value.some((role) => /(student|alumno|learner)/i.test(String(role))),
);
const canManageResource = computed(() => !isStudentRole.value);
const selectedView = ref<'admin' | 'student'>(canManageResource.value ? 'admin' : 'student');
const showViewToggle = computed(() => !shouldApplyMoodleRestrictions.value);
const isAdminView = computed(() => (shouldApplyMoodleRestrictions.value ? canManageResource.value : selectedView.value === 'admin'));

const standaloneAssets = {
    script: '/vendor/h5p-standalone/frame.bundle.js',
    style: '/vendor/h5p-standalone/styles/h5p.css',
};

function toSameOriginPath(url: string): string {
    try {
        const parsed = new URL(url, window.location.origin);
        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    } catch {
        return url;
    }
}

function getCsrfToken(): string {
    const token = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');
    return token ?? '';
}

function normalizeIncomingStatement(input: unknown): Record<string, unknown> | null {
    if (!input || typeof input !== 'object' || Array.isArray(input)) {
        return null;
    }
    const candidate = input as Record<string, unknown>;
    if (!candidate.verb && !candidate.object && !candidate.actor && !candidate.result) {
        return null;
    }

    return candidate;
}

function enqueueXapiStatement(statement: unknown): void {
    const normalized = normalizeIncomingStatement(statement);
    if (!normalized) {
        return;
    }
    xapiStatementSeq += 1;
    const seq = xapiStatementSeq;
    xapiConsoleLog('capturado', {
        seq,
        pendientesAntesDeEncolar: pendingStatements.length,
        statement: normalized,
    });
    pendingStatements.push(normalized);
    xapiStats.value.captured += 1;
    void flushXapiStatements();
}

function extractStatementsFromMessage(payload: unknown): Array<Record<string, unknown>> {
    if (!payload) {
        return [];
    }

    const parsedPayload = (() => {
        if (typeof payload === 'string') {
            try {
                return JSON.parse(payload);
            } catch {
                return null;
            }
        }
        return payload;
    })();

    if (!parsedPayload || typeof parsedPayload !== 'object') {
        return [];
    }

    const data = parsedPayload as Record<string, unknown>;
    const directStatement = normalizeIncomingStatement(data.statement ?? data.xapi ?? data);
    const arrayStatements = Array.isArray(data.statements)
        ? data.statements
            .map((statement) => normalizeIncomingStatement(statement))
            .filter((statement): statement is Record<string, unknown> => statement !== null)
        : [];

    return [...(directStatement ? [directStatement] : []), ...arrayStatements];
}

async function flushXapiStatements(): Promise<void> {
    if (isFlushingXapi || pendingStatements.length === 0) {
        return;
    }
    if (xapiNextRetryAt > Date.now()) {
        return;
    }
    isFlushingXapi = true;

    const batch = [...pendingStatements];
    pendingStatements = [];

    xapiConsoleLog('lote-envio', {
        cantidad: batch.length,
        statements: batch.map((statement, index) => ({
            index,
            keys: Object.keys(statement),
            statement,
        })),
    });

    try {
        const response = await fetch('/xapi/statements/forward', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ statements: batch }),
        });

        if (!response.ok) {
            pendingStatements = [...batch, ...pendingStatements];
            xapiStats.value.failed += batch.length;
            const payload = (await response.json().catch(() => ({}))) as { message?: string; retry_after_seconds?: number };
            xapiConsoleLog('respuesta', {
                ok: false,
                httpStatus: response.status,
                payload,
                loteReencolado: batch.length,
            });
            xapiConsecutiveFailures += 1;
            const suggestedRetryMs = Number(payload.retry_after_seconds ?? 0) * 1000;
            const exponentialRetryMs = Math.min(60000, 1000 * 2 ** Math.min(xapiConsecutiveFailures, 6));
            xapiRetryBackoffMs.value = Math.max(suggestedRetryMs, exponentialRetryMs);
            xapiNextRetryAt = Date.now() + xapiRetryBackoffMs.value;
            xapiStats.value.lastError = typeof payload.message === 'string'
                ? payload.message
                : `HTTP ${response.status} al reenviar xAPI`;
            return;
        }

        const payload = (await response.json().catch(() => ({}))) as { forwarded?: boolean; count?: number; message?: string; failed?: number };
        xapiConsoleLog('respuesta', {
            ok: true,
            httpStatus: response.status,
            payload,
            batchSize: batch.length,
        });
        if (payload.forwarded) {
            xapiStats.value.forwarded += Number(payload.count ?? batch.length);
            xapiStats.value.lastError = '';
            xapiConsecutiveFailures = 0;
            xapiRetryBackoffMs.value = 0;
            xapiNextRetryAt = 0;
        } else {
            pendingStatements = [...batch, ...pendingStatements];
            xapiStats.value.failed += batch.length;
            xapiConsecutiveFailures += 1;
            xapiRetryBackoffMs.value = Math.min(60000, 1000 * 2 ** Math.min(xapiConsecutiveFailures, 6));
            xapiNextRetryAt = Date.now() + xapiRetryBackoffMs.value;
            xapiStats.value.lastError = typeof payload.message === 'string' ? payload.message : 'No se pudo reenviar xAPI';
        }
    } catch {
        pendingStatements = [...batch, ...pendingStatements];
        xapiStats.value.failed += batch.length;
        xapiStats.value.lastError = 'Fallo de red al reenviar xAPI';
        xapiConsecutiveFailures += 1;
        xapiRetryBackoffMs.value = Math.min(60000, 1000 * 2 ** Math.min(xapiConsecutiveFailures, 6));
        xapiNextRetryAt = Date.now() + xapiRetryBackoffMs.value;
    } finally {
        isFlushingXapi = false;
    }
}

function onWindowMessageCapture(event: MessageEvent): void {
    for (const statement of extractStatementsFromMessage(event.data)) {
        enqueueXapiStatement(statement);
    }
}

function installXapiCapturing(): void {
    window.addEventListener('message', onWindowMessageCapture);

    const maybeH5pGlobal = (window as unknown as { H5P?: { externalDispatcher?: { on?: Function; off?: Function } } }).H5P;
    const dispatcher = maybeH5pGlobal?.externalDispatcher;
    if (dispatcher && typeof dispatcher.on === 'function') {
        xapiDispatcherHandler = (event) => {
            enqueueXapiStatement(event?.data?.statement ?? null);
        };
        dispatcher.on('xAPI', xapiDispatcherHandler);
    }

    xapiFlushInterval = window.setInterval(() => {
        void flushXapiStatements();
    }, 2500);
}

function uninstallXapiCapturing(): void {
    window.removeEventListener('message', onWindowMessageCapture);
    if (xapiFlushInterval !== null) {
        window.clearInterval(xapiFlushInterval);
        xapiFlushInterval = null;
    }

    const maybeH5pGlobal = (window as unknown as { H5P?: { externalDispatcher?: { off?: Function } } }).H5P;
    const dispatcher = maybeH5pGlobal?.externalDispatcher;
    if (dispatcher && typeof dispatcher.off === 'function' && xapiDispatcherHandler) {
        dispatcher.off('xAPI', xapiDispatcherHandler);
    }
    xapiDispatcherHandler = null;
}

function installIframeXapiBridge(): void {
    const container = viewerRef.value;
    if (!container) {
        return;
    }

    const frames = Array.from(container.querySelectorAll('iframe'));
    for (const frame of frames) {
        const iframe = frame as HTMLIFrameElement & { __xapiBridgeBound?: boolean };
        if (iframe.__xapiBridgeBound) {
            continue;
        }

        try {
            const frameWindow = iframe.contentWindow as (Window & {
                H5P?: {
                    externalDispatcher?: {
                        on?: (eventName: string, handler: (event: { data?: { statement?: unknown } }) => void) => void;
                    };
                };
            }) | null;
            const dispatcher = frameWindow?.H5P?.externalDispatcher;
            if (!dispatcher || typeof dispatcher.on !== 'function') {
                continue;
            }

            dispatcher.on('xAPI', (event: { data?: { statement?: unknown } }) => {
                window.postMessage(
                    {
                        source: 'pddp-h5p-xapi',
                        statement: event?.data?.statement ?? null,
                    },
                    window.location.origin,
                );
            });

            iframe.__xapiBridgeBound = true;
        } catch {
            // El bridge se vuelve a intentar en el siguiente ciclo.
        }
    }
}

function resetViewer(): void {
    if (iframeAutoResizeInterval !== null) {
        window.clearInterval(iframeAutoResizeInterval);
        iframeAutoResizeInterval = null;
    }
    if (iframeMutationObserver) {
        iframeMutationObserver.disconnect();
        iframeMutationObserver = null;
    }

    if (h5pInstance && typeof h5pInstance.destroy === 'function') {
        h5pInstance.destroy();
    }
    h5pInstance = null;
    if (viewerRef.value) {
        viewerRef.value.innerHTML = '';
    }
}

function autoResizeH5pIframes(): void {
    const container = viewerRef.value;
    if (!container) {
        return;
    }

    const frames = Array.from(container.querySelectorAll('iframe'));
    for (const frame of frames) {
        const iframe = frame as HTMLIFrameElement & { __autoResizeBound?: boolean };
        if (!iframe.__autoResizeBound) {
            iframe.__autoResizeBound = true;
            iframe.addEventListener('load', () => autoResizeH5pIframes(), { passive: true });
        }

        try {
            const frameDocument = iframe.contentDocument ?? iframe.contentWindow?.document ?? null;
            if (!frameDocument) {
                continue;
            }

            const bodyHeight = frameDocument.body?.scrollHeight ?? 0;
            const docHeight = frameDocument.documentElement?.scrollHeight ?? 0;
            const clientHeight = frameDocument.documentElement?.clientHeight ?? 0;
            const viewportBasedHeight = isPreviewFullscreen.value ? Math.floor(window.innerHeight * 0.82) : 320;
            const targetHeight = Math.max(bodyHeight, docHeight, clientHeight, viewportBasedHeight);
            if (targetHeight > 0) {
                iframe.style.height = `${targetHeight}px`;
                iframe.style.minHeight = '320px';
                iframe.style.width = '100%';
                iframe.style.overflow = 'hidden';
                iframe.removeAttribute('scrolling');
            }
        } catch {
            // Si el iframe aún no es accesible, el siguiente ciclo lo volverá a intentar.
        }
    }
}

function installAutoResizeForH5pFrames(): void {
    const container = viewerRef.value;
    if (!container) {
        return;
    }

    autoResizeH5pIframes();
    installIframeXapiBridge();

    iframeMutationObserver = new MutationObserver(() => {
        autoResizeH5pIframes();
        installIframeXapiBridge();
    });
    iframeMutationObserver.observe(container, {
        childList: true,
        subtree: true,
    });

    iframeAutoResizeInterval = window.setInterval(() => {
        autoResizeH5pIframes();
        installIframeXapiBridge();
    }, 400);
}

async function renderPreview(currentPreviewId: string): Promise<void> {
    loadingPreview.value = true;
    error.value = null;
    resetViewer();

    try {
        await nextTick();

        const container = viewerRef.value;
        const H5P = await getH5P();
        if (!container || typeof H5P !== 'function') {
            throw new Error('No fue posible inicializar el visor H5P.');
        }

        if (!previewToken.value) {
            throw new Error('Token de preview no disponible.');
        }

        h5pInstance = new H5P(container, {
            h5pJsonPath: toSameOriginPath(assetWithToken.url({ preview: currentPreviewId, token: previewToken.value })),
            contentJsonPath: toSameOriginPath(
                assetWithToken.url({ preview: currentPreviewId, token: previewToken.value, path: 'content' }),
            ),
            librariesPath: toSameOriginPath(
                assetWithToken.url({ preview: currentPreviewId, token: previewToken.value, path: 'libraries' }),
            ),
            frameJs: standaloneAssets.script,
            frameCss: standaloneAssets.style,
            frame: true,
            copyright: false,
            export: false,
            embed: false,
            icon: false,
        });

        installAutoResizeForH5pFrames();
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'No fue posible renderizar el recurso H5P.';
    } finally {
        loadingPreview.value = false;
    }
}

async function uploadFile(file: File): Promise<void> {
    uploading.value = true;
    error.value = null;

    try {
        const formData = new FormData();
        formData.append('file', file);

        const response = await fetch(toSameOriginPath(upload.url()), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
            },
            body: formData,
        });

        const rawText = await response.text();
        const data: unknown = rawText ? JSON.parse(rawText) : null;
        if (!response.ok) {
            const messageFromApi =
                typeof data === 'object' && data !== null && 'message' in data ? (data as { message?: unknown }).message : null;
            const errorsFromApi =
                typeof data === 'object' && data !== null && 'errors' in data ? (data as { errors?: unknown }).errors : null;

            const firstFileError =
                typeof errorsFromApi === 'object' &&
                errorsFromApi !== null &&
                'file' in errorsFromApi &&
                Array.isArray((errorsFromApi as { file?: unknown }).file) &&
                typeof (errorsFromApi as { file: unknown[] }).file[0] === 'string'
                    ? ((errorsFromApi as { file: string[] }).file[0] as string)
                    : null;

            const message =
                typeof messageFromApi === 'string' && messageFromApi.trim() !== ''
                    ? messageFromApi
                    : 'No fue posible cargar el archivo.';

            const details =
                firstFileError && firstFileError.trim() !== '' ? ` (${firstFileError})` : rawText.trim() ? ` (${rawText})` : '';

            throw new Error(`${message}${details}`);
        }

        if (typeof data !== 'object' || data === null) {
            throw new Error('Respuesta inesperada del servidor.');
        }

        const payload = data as { previewId?: unknown; previewToken?: unknown };
        if (typeof payload.previewId !== 'string' || typeof payload.previewToken !== 'string') {
            throw new Error('Respuesta inesperada del servidor.');
        }

        previewId.value = payload.previewId;
        previewToken.value = payload.previewToken;
        await renderPreview(payload.previewId);
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Error al cargar el H5P.';
    } finally {
        uploading.value = false;
    }
}

async function replaceCurrent(file: File): Promise<void> {
    await deleteCurrent();
    await uploadFile(file);
}

async function deleteCurrent(): Promise<void> {
    resetViewer();
    error.value = null;

    const query: Record<string, string> = {};
    if (previewId.value && previewToken.value) {
        query.preview = previewId.value;
        query.token = previewToken.value;
    }

    const response = await fetch(toSameOriginPath(destroy.url(Object.keys(query).length > 0 ? { query } : undefined)), {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': getCsrfToken(),
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        const rawText = await response.text();
        const data: unknown = rawText ? JSON.parse(rawText) : null;
        const messageFromApi =
            typeof data === 'object' && data !== null && 'message' in data ? (data as { message?: unknown }).message : null;
        const message =
            typeof messageFromApi === 'string' && messageFromApi.trim() !== '' ? messageFromApi : 'No fue posible eliminar el recurso.';
        throw new Error(rawText.trim() ? `${message} (${rawText})` : message);
    }

    previewId.value = null;
    previewToken.value = null;
}

function onFilePicked(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];
    if (!file) {
        return;
    }
    if (previewId.value && canManageResource.value) {
        void replaceCurrent(file);
    } else {
        void uploadFile(file);
    }
    target.value = '';
}

function onDrop(event: DragEvent): void {
    event.preventDefault();
    dropActive.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (!file) {
        return;
    }
    if (previewId.value && canManageResource.value) {
        void replaceCurrent(file);
    } else if (!previewId.value) {
        void uploadFile(file);
    }
}

function clampFloatingButtonPosition(nextX: number, nextY: number): { x: number; y: number } {
    const section = previewSectionRef.value;
    if (!section) {
        return { x: nextX, y: nextY };
    }

    const maxX = Math.max(8, section.clientWidth - 180);
    const maxY = Math.max(8, section.clientHeight - 48);

    return {
        x: Math.min(Math.max(8, nextX), maxX),
        y: Math.min(Math.max(8, nextY), maxY),
    };
}

function onFloatingButtonPointerMove(event: PointerEvent): void {
    if (!isDraggingFloatingButton.value || activePointerId !== event.pointerId) {
        return;
    }

    const deltaX = event.clientX - dragStartClientX;
    const deltaY = event.clientY - dragStartClientY;
    floatingButtonPosition.value = clampFloatingButtonPosition(
        dragStartPositionX + deltaX,
        dragStartPositionY + deltaY,
    );
}

function stopFloatingButtonDrag(): void {
    isDraggingFloatingButton.value = false;
    activePointerId = null;
}

function onFloatingButtonPointerDown(event: PointerEvent): void {
    if (event.button !== 0) {
        return;
    }

    activePointerId = event.pointerId;
    dragStartClientX = event.clientX;
    dragStartClientY = event.clientY;
    dragStartPositionX = floatingButtonPosition.value.x;
    dragStartPositionY = floatingButtonPosition.value.y;
    isDraggingFloatingButton.value = true;

    floatingButtonRef.value?.setPointerCapture(event.pointerId);
}

function onFloatingButtonPointerUp(event: PointerEvent): void {
    if (activePointerId !== event.pointerId) {
        return;
    }

    const movedDistance = Math.hypot(event.clientX - dragStartClientX, event.clientY - dragStartClientY);
    stopFloatingButtonDrag();

    if (movedDistance < 5) {
        void toggleFullscreenPreview();
    }
}

async function toggleFullscreenPreview(): Promise<void> {
    const section = previewSectionRef.value;
    if (!section) {
        return;
    }

    if (!document.fullscreenElement) {
        await section.requestFullscreen();
        return;
    }

    await document.exitFullscreen();
}

onMounted(() => {
    isEmbeddedInIframe.value = window.self !== window.top;
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    installXapiCapturing();
    document.addEventListener('visibilitychange', onVisibilityChangeFlush);
    window.addEventListener('beforeunload', onBeforeUnloadFlush);

    const initialPreview = props.initialPreview;
    if (!initialPreview?.previewId || !initialPreview.previewToken) {
        return;
    }

    previewId.value = initialPreview.previewId;
    previewToken.value = initialPreview.previewToken;
    void renderPreview(initialPreview.previewId);
});

onUnmounted(() => {
    document.removeEventListener('fullscreenchange', handleFullscreenChange);
    uninstallXapiCapturing();
    void flushXapiStatements();
    document.removeEventListener('visibilitychange', onVisibilityChangeFlush);
    window.removeEventListener('beforeunload', onBeforeUnloadFlush);
});

function handleFullscreenChange(): void {
    isPreviewFullscreen.value = Boolean(document.fullscreenElement);
    autoResizeH5pIframes();
    window.setTimeout(() => autoResizeH5pIframes(), 120);
    window.setTimeout(() => autoResizeH5pIframes(), 320);
}

function onVisibilityChangeFlush(): void {
    if (document.visibilityState === 'hidden') {
        void flushXapiStatements();
    }
}

function onBeforeUnloadFlush(): void {
    void flushXapiStatements();
}

function forceXapiRetryNow(): void {
    xapiNextRetryAt = 0;
    void flushXapiStatements();
}

const xapiRetryInSeconds = computed(() => {
    if (xapiNextRetryAt <= Date.now()) {
        return 0;
    }
    return Math.ceil((xapiNextRetryAt - Date.now()) / 1000);
});
</script>

<template>
    <Head title="Preview H5P" />

    <main class="h-dvh overflow-hidden bg-white px-4 py-4 text-gray-900 md:py-6">
        <div class="mx-auto flex h-full w-full max-w-6xl flex-col gap-4 md:gap-6">
            <header class="shrink-0 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <template v-if="showViewToggle">
                        <button
                            type="button"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="isAdminView ? 'bg-[#223c6a] text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-100'"
                            @click="selectedView = 'admin'"
                        >
                            Vista admin
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-4 py-2 text-sm font-medium transition"
                            :class="!isAdminView ? 'bg-[#223c6a] text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-100'"
                            @click="selectedView = 'student'"
                        >
                            Vista estudiante
                        </button>
                    </template>
                    <Link
                        v-if="isAdminView && !shouldApplyMoodleRestrictions"
                        :href="platforms()"
                        class="rounded-md border border-[#223c6a] px-4 py-2 text-sm font-medium text-[#223c6a] hover:bg-[#223c6a] hover:text-white"
                    >
                        Integraciones
                    </Link>
                </div>
            </header>

            <section v-if="isAdminView" class="shrink-0 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <input ref="fileInputRef" type="file" class="hidden" accept=".h5p,.zip" @change="onFilePicked" />

                <button
                    type="button"
                    class="flex w-full flex-col items-center justify-center rounded-lg border-2 border-dashed px-6 py-10 text-center transition"
                    :class="dropActive ? 'border-[#223c6a] bg-blue-50' : 'border-gray-300 bg-white'"
                    @click="fileInputRef?.click()"
                    @dragover.prevent="dropActive = true"
                    @dragleave.prevent="dropActive = false"
                    @drop="onDrop"
                >
                    <span class="text-base font-medium text-[#223c6a]">Arrastra y suelta un archivo H5P aqui</span>
                    <span class="mt-1 text-sm text-gray-500">o haz clic para seleccionar desde tu equipo</span>
                </button>

                <div v-if="previewId && canManageResource" class="mt-4 flex flex-wrap gap-2">
                    <button type="button" class="rounded-md bg-[#223c6a] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a2f53]" @click="fileInputRef?.click()">
                        Reemplazar H5P
                    </button>
                    <button type="button" class="rounded-md bg-[#d21428] px-4 py-2 text-sm font-medium text-white hover:bg-[#ad1021]" @click="deleteCurrent">
                        Eliminar H5P
                    </button>
                </div>
            </section>

            <section ref="previewSectionRef" class="relative flex min-h-0 flex-1 flex-col rounded-xl border border-gray-200 bg-white p-4">
                <h2 v-if="isAdminView" class="mb-3 text-lg font-semibold text-[#223c6a]">Vista previa</h2>
                <button
                    v-if="!isAdminView && previewId"
                    ref="floatingButtonRef"
                    type="button"
                    class="absolute z-20 flex h-10 w-10 items-center justify-center rounded-full border border-[#223c6a] bg-white/95 text-[#223c6a] shadow transition hover:bg-[#223c6a] hover:text-white active:scale-95"
                    :style="{
                        left: `${floatingButtonPosition.x}px`,
                        top: `${floatingButtonPosition.y}px`,
                    }"
                    :title="isPreviewFullscreen ? 'Salir de pantalla completa' : 'Pantalla completa'"
                    @pointerdown.prevent="onFloatingButtonPointerDown"
                    @pointermove.prevent="onFloatingButtonPointerMove"
                    @pointerup.prevent="onFloatingButtonPointerUp"
                    @pointercancel.prevent="stopFloatingButtonDrag"
                >
                    <svg v-if="!isPreviewFullscreen" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                        <path
                            fill-rule="evenodd"
                            d="M3.75 2.5A1.25 1.25 0 0 0 2.5 3.75v3a.75.75 0 0 0 1.5 0V4h2.75a.75.75 0 0 0 0-1.5h-3Zm9.5 0a.75.75 0 0 0 0 1.5H16v2.75a.75.75 0 0 0 1.5 0v-3A1.25 1.25 0 0 0 16.25 2.5h-3Zm4.25 10.75a.75.75 0 0 0-1.5 0V16h-2.75a.75.75 0 0 0 0 1.5h3a1.25 1.25 0 0 0 1.25-1.25v-3Zm-14.25 0a.75.75 0 0 1 1.5 0V16h2.75a.75.75 0 0 1 0 1.5h-3A1.25 1.25 0 0 1 2.5 16.25v-3Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                        <path
                            fill-rule="evenodd"
                            d="M6.75 2.5a.75.75 0 0 1 0 1.5H4v2.75a.75.75 0 0 1-1.5 0v-3A1.25 1.25 0 0 1 3.75 2.5h3Zm6.5 0a1.25 1.25 0 0 1 1.25 1.25v3a.75.75 0 0 1-1.5 0V4h-2.75a.75.75 0 0 1 0-1.5h3ZM4 13.25a.75.75 0 0 0-1.5 0v3a1.25 1.25 0 0 0 1.25 1.25h3a.75.75 0 0 0 0-1.5H4v-2.75Zm10.5 0a.75.75 0 0 1 1.5 0v3a1.25 1.25 0 0 1-1.25 1.25h-3a.75.75 0 0 1 0-1.5H14v-2.75Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
                <p v-if="uploading || loadingPreview" class="text-sm text-gray-600">Procesando recurso...</p>
                <p v-else-if="error" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
                <p v-else-if="!previewId" class="text-sm text-gray-500">Aun no hay recurso cargado.</p>
                <div v-if="shouldApplyMoodleRestrictions" class="mb-3 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700">
                    <span class="mr-3">xAPI capturados: {{ xapiStats.captured }}</span>
                    <span class="mr-3">enviados: {{ xapiStats.forwarded }}</span>
                    <span class="mr-3">fallidos: {{ xapiStats.failed }}</span>
                    <span v-if="xapiRetryInSeconds > 0" class="mr-3 text-[#223c6a]">
                        reintento en: {{ xapiRetryInSeconds }}s
                    </span>
                    <button
                        type="button"
                        class="mr-3 rounded border border-[#223c6a] px-2 py-0.5 text-[11px] text-[#223c6a] hover:bg-[#223c6a] hover:text-white"
                        @click="forceXapiRetryNow"
                    >
                        Reintentar ahora
                    </button>
                    <span v-if="xapiStats.lastError" class="text-[#d21428]">({{ xapiStats.lastError }})</span>
                </div>

                <div ref="viewerRef" class="min-h-0 flex-1 overflow-auto rounded-md border border-gray-100" />
            </section>
        </div>
    </main>
</template>
