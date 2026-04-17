<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { home } from '@/routes';
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

type Platform = {
    id: number;
    name: string | null;
    issuer: string;
    client_id: string | null;
    jwks_json?: Record<string, unknown> | null;
    jwks_url: string | null;
    authorization_endpoint: string | null;
    token_endpoint: string | null;
    active: boolean;
};

type LrsConnection = {
    id: number;
    name: string;
    endpoint_url: string;
    basic_username: string;
    xapi_version: string;
    active: boolean;
    has_password: boolean;
    platform: {
        id: number;
        name: string | null;
        issuer: string;
    } | null;
};

const props = defineProps<{
    platforms: Platform[];
    lrsConnections: LrsConnection[];
    tool: {
        app_url: string;
        jwks_url: string;
        login_initiation_url: string;
        launch_url: string;
    };
    platform_form_defaults: {
        name: string;
        issuer: string;
        client_id: string;
        jwks_url: string;
        authorization_endpoint: string;
        token_endpoint: string;
    };
    lrs_form_defaults: {
        lti_platform_id: number | null;
        name: string;
        endpoint_url: string;
        basic_username: string;
        xapi_version: string;
    };
}>();
const page = usePage();
const flashSuccess = computed(() => {
    const flash = (page.props as { flash?: { success?: unknown } }).flash;
    return typeof flash?.success === 'string' ? flash.success : null;
});
const flashError = computed(() => {
    const flash = (page.props as { flash?: { error?: unknown } }).flash;
    return typeof flash?.error === 'string' ? flash.error : null;
});

const createForm = useForm({
    name: props.platform_form_defaults.name,
    issuer: props.platform_form_defaults.issuer,
    client_id: props.platform_form_defaults.client_id,
    jwks_url: props.platform_form_defaults.jwks_url,
    authorization_endpoint: props.platform_form_defaults.authorization_endpoint,
    token_endpoint: props.platform_form_defaults.token_endpoint,
    active: true,
});

const hasPlatforms = computed(() => props.platforms.length > 0);
const lrsFilter = ref('');
const lrsCreateForm = useForm({
    lti_platform_id: props.lrs_form_defaults.lti_platform_id,
    name: props.lrs_form_defaults.name,
    endpoint_url: props.lrs_form_defaults.endpoint_url,
    basic_username: props.lrs_form_defaults.basic_username,
    basic_password: '',
    xapi_version: props.lrs_form_defaults.xapi_version || '1.0.3',
    active: true,
});
const filteredLrsConnections = computed(() => {
    const query = lrsFilter.value.trim().toLowerCase();
    if (query === '') {
        return props.lrsConnections;
    }

    return props.lrsConnections.filter((connection) => {
        const platformLabel = connection.platform?.name || connection.platform?.issuer || '';
        const candidate = `${connection.name} ${connection.endpoint_url} ${connection.basic_username} ${platformLabel}`.toLowerCase();
        return candidate.includes(query);
    });
});

function submitCreate(): void {
    createForm.post('/lti/plataformas', {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

function removePlatform(platform: Platform): void {
    if (!confirm(`Eliminar plataforma "${platform.name || platform.issuer}"?`)) {
        return;
    }

    router.delete(`/lti/plataformas/${platform.id}`, {
        preserveScroll: true,
    });
}

function syncJwks(platform: Platform): void {
    router.post(`/lti/plataformas/${platform.id}/sync-jwks`, {}, { preserveScroll: true });
}

function submitLrsCreate(): void {
    lrsCreateForm.post('/lti/lrs/connections', {
        preserveScroll: true,
        onSuccess: () => {
            lrsCreateForm.reset('name', 'endpoint_url', 'basic_username', 'basic_password');
            lrsCreateForm.xapi_version = '1.0.3';
        },
    });
}

function removeLrsConnection(connection: LrsConnection): void {
    if (!confirm(`Eliminar conexion LRS "${connection.name}"?`)) {
        return;
    }

    router.delete(`/lti/lrs/connections/${connection.id}`, {
        preserveScroll: true,
    });
}

function testLrsConnection(connection: LrsConnection): void {
    router.post(`/lti/lrs/connections/${connection.id}/test`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Integracion LTI" />

    <main class="min-h-dvh bg-white px-4 py-8 text-gray-900">
        <div class="mx-auto w-full max-w-6xl space-y-6">
            <header class="flex items-center justify-between">
                <h1 class="text-3xl font-semibold text-[#223c6a]">Integracion de plataformas LTI</h1>
                <Link :href="home()" class="rounded-md border border-[#223c6a] px-4 py-2 text-sm font-medium text-[#223c6a] hover:bg-[#223c6a] hover:text-white">
                    Volver al preview
                </Link>
            </header>

            <section v-if="flashSuccess || flashError" class="rounded-xl border p-3 text-sm" :class="flashError ? 'border-red-300 bg-red-50 text-red-800' : 'border-green-300 bg-green-50 text-green-800'">
                {{ flashError || flashSuccess }}
            </section>

            <section class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <h2 class="text-lg font-semibold text-[#223c6a]">Datos para Canvas/Moodle</h2>
                <div class="mt-3 grid gap-3 text-sm md:grid-cols-2">
                    <div class="rounded-md border border-gray-200 bg-white p-3">
                        <p class="font-semibold">Tool Issuer / APP_URL</p>
                        <p class="mt-1 break-all text-gray-700">{{ props.tool.app_url }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white p-3">
                        <p class="font-semibold">JWKS URL</p>
                        <p class="mt-1 break-all text-gray-700">{{ props.tool.jwks_url }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white p-3">
                        <p class="font-semibold">OIDC Login Initiation URL</p>
                        <p class="mt-1 break-all text-gray-700">{{ props.tool.login_initiation_url }}</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white p-3">
                        <p class="font-semibold">Launch URL</p>
                        <p class="mt-1 break-all text-gray-700">{{ props.tool.launch_url }}</p>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4">
                <h2 class="text-lg font-semibold text-[#223c6a]">Registrar plataforma LMS</h2>
                <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="submitCreate">
                    <input v-model="createForm.name" type="text" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Nombre (Canvas UdeC, Moodle, etc.)" />
                    <input v-model="createForm.issuer" type="url" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Issuer (https://...)" required />
                    <input v-model="createForm.client_id" type="text" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Client ID" required />
                    <input v-model="createForm.jwks_url" type="url" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="JWKS URL (https://...)" />
                    <input v-model="createForm.authorization_endpoint" type="url" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Authorization endpoint" />
                    <input v-model="createForm.token_endpoint" type="url" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Token endpoint" />
                    <div class="md:col-span-2">
                        <button type="submit" class="rounded-md bg-[#223c6a] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a2f53]" :disabled="createForm.processing">
                            Guardar plataforma
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4">
                <h2 class="text-lg font-semibold text-[#223c6a]">Plataformas registradas</h2>
                <p v-if="!hasPlatforms" class="mt-2 text-sm text-gray-500">Aun no hay plataformas registradas.</p>
                <div v-else class="mt-3 space-y-3">
                    <article v-for="platform in props.platforms" :key="platform.id" class="rounded-lg border border-gray-200 p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="space-y-1 text-sm">
                                <p class="font-semibold text-[#223c6a]">{{ platform.name || 'Sin nombre' }}</p>
                                <p><span class="font-medium">Issuer:</span> {{ platform.issuer }}</p>
                                <p><span class="font-medium">Client ID:</span> {{ platform.client_id || '—' }}</p>
                                <p><span class="font-medium">JWKS URL:</span> {{ platform.jwks_url || '—' }}</p>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-md border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100" @click="syncJwks(platform)">
                                    Sync JWKS
                                </button>
                                <button type="button" class="rounded-md bg-[#d21428] px-3 py-1 text-xs font-medium text-white hover:bg-[#ad1021]" @click="removePlatform(platform)">
                                    Eliminar
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4">
                <h2 class="text-lg font-semibold text-[#223c6a]">Integracion LRS (xAPI)</h2>
                <p class="mt-1 text-sm text-gray-600">
                    Configura endpoint xAPI `statements`, credenciales Basic Auth y version de xAPI.
                </p>

                <form class="mt-4 grid gap-3 md:grid-cols-2" @submit.prevent="submitLrsCreate">
                    <input v-model="lrsCreateForm.name" type="text" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Nombre de conexion LRS" required />
                    <select v-model="lrsCreateForm.lti_platform_id" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option :value="null">Sin plataforma asociada</option>
                        <option v-for="platform in props.platforms" :key="platform.id" :value="platform.id">
                            {{ platform.name || platform.issuer }}
                        </option>
                    </select>
                    <input v-model="lrsCreateForm.endpoint_url" type="url" class="rounded-md border border-gray-300 px-3 py-2 text-sm md:col-span-2" placeholder="https://tu-lrs/xapi/statements" required />
                    <input v-model="lrsCreateForm.basic_username" type="text" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Basic username" required />
                    <input v-model="lrsCreateForm.basic_password" type="password" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="Basic password" required />
                    <input v-model="lrsCreateForm.xapi_version" type="text" class="rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="1.0.3" required />
                    <div class="md:col-span-2">
                        <button type="submit" class="rounded-md bg-[#223c6a] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a2f53]" :disabled="lrsCreateForm.processing">
                            Guardar conexion LRS
                        </button>
                    </div>
                </form>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-[#223c6a]">Conexiones LRS registradas</h2>
                    <input v-model="lrsFilter" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm md:w-80" placeholder="Filtrar por nombre, endpoint o plataforma" />
                </div>

                <p v-if="filteredLrsConnections.length === 0" class="mt-3 text-sm text-gray-500">
                    No hay conexiones LRS para el filtro actual.
                </p>
                <div v-else class="mt-3 space-y-3">
                    <article v-for="connection in filteredLrsConnections" :key="connection.id" class="rounded-lg border border-gray-200 p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="space-y-1 text-sm">
                                <p class="font-semibold text-[#223c6a]">{{ connection.name }}</p>
                                <p><span class="font-medium">Endpoint:</span> {{ connection.endpoint_url }}</p>
                                <p><span class="font-medium">Usuario:</span> {{ connection.basic_username }}</p>
                                <p><span class="font-medium">xAPI:</span> {{ connection.xapi_version }}</p>
                                <p>
                                    <span class="font-medium">Plataforma:</span>
                                    {{ connection.platform?.name || connection.platform?.issuer || 'Sin asociar' }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <button type="button" class="rounded-md border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100" @click="testLrsConnection(connection)">
                                    Probar
                                </button>
                                <button type="button" class="rounded-md bg-[#d21428] px-3 py-1 text-xs font-medium text-white hover:bg-[#ad1021]" @click="removeLrsConnection(connection)">
                                    Eliminar
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </main>
</template>
