<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureLtiPlatformsAccess;
use App\Models\LrsConnection;
use App\Models\LtiPlatform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LtiPlatformController extends Controller
{
    public function index(Request $request): Response
    {
        $hasAccess = $request->session()->get(EnsureLtiPlatformsAccess::SESSION_KEY) === true;
        $platforms = LtiPlatform::query()
            ->orderByDesc('id')
            ->get([
                'id',
                'name',
                'issuer',
                'client_id',
                'jwks_json',
                'jwks_url',
                'authorization_endpoint',
                'token_endpoint',
                'active',
            ]);
        $lrsConnections = LrsConnection::query()
            ->with('platform:id,name,issuer')
            ->orderByDesc('id')
            ->get()
            ->map(fn (LrsConnection $connection): array => [
                'id' => $connection->id,
                'name' => $connection->name,
                'endpoint_url' => $connection->endpoint_url,
                'basic_username' => $connection->basic_username,
                'xapi_version' => $connection->xapi_version,
                'active' => $connection->active,
                'has_password' => $connection->basic_password !== '',
                'platform' => $connection->platform ? [
                    'id' => $connection->platform->id,
                    'name' => $connection->platform->name,
                    'issuer' => $connection->platform->issuer,
                ] : null,
            ])
            ->values();

        return Inertia::render('LtiPlatforms', [
            'hasAccess' => $hasAccess,
            'platforms' => $platforms,
            'lrsConnections' => $lrsConnections,
            'tool' => [
                'app_url' => rtrim((string) config('app.url'), '/'),
                'jwks_url' => route('cfrd.lti.jwks'),
                'login_initiation_url' => route('cfrd.lti.oidc.login_initiation'),
                'launch_url' => route('cfrd.lti.launch'),
            ],
            'platform_form_defaults' => $request->session()->get('platform_form_defaults', [
                'name' => '',
                'issuer' => '',
                'client_id' => '',
                'jwks_url' => '',
                'authorization_endpoint' => '',
                'token_endpoint' => '',
            ]),
            'lrs_form_defaults' => $request->session()->get('lrs_form_defaults', [
                'lti_platform_id' => null,
                'name' => '',
                'endpoint_url' => '',
                'basic_username' => '',
                'xapi_version' => '1.0.3',
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        LtiPlatform::query()->create($validated);

        return redirect()->route('lti.platforms')
            ->with('success', 'Plataforma LTI creada.');
    }

    public function update(Request $request, LtiPlatform $platform): RedirectResponse
    {
        $validated = $this->validatePayload($request, $platform);

        $platform->update($validated);

        return redirect()->route('lti.platforms')
            ->with('success', 'Plataforma LTI actualizada.');
    }

    public function destroy(LtiPlatform $platform): RedirectResponse
    {
        $platform->delete();

        return redirect()->route('lti.platforms')
            ->with('success', 'Plataforma LTI eliminada.');
    }

    public function syncJwks(LtiPlatform $platform): RedirectResponse
    {
        if (! is_string($platform->jwks_url) || $platform->jwks_url === '') {
            return redirect()->route('lti.platforms')
                ->with('error', 'La plataforma no tiene JWKS URL configurada.');
        }

        $response = Http::timeout(15)->acceptJson()->get($platform->jwks_url);
        if (! $response->successful()) {
            return redirect()->route('lti.platforms')
                ->with('error', 'No se pudo sincronizar JWKS desde la plataforma.');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['keys']) || ! is_array($payload['keys'])) {
            return redirect()->route('lti.platforms')
                ->with('error', 'El JWKS recibido es inválido.');
        }

        $platform->update([
            'jwks_json' => $payload,
        ]);

        return redirect()->route('lti.platforms')
            ->with('success', 'JWKS sincronizado correctamente.');
    }

    public function storeLrs(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lti_platform_id' => ['nullable', 'integer', 'exists:lti_platforms,id'],
            'name' => ['required', 'string', 'max:255'],
            'endpoint_url' => ['required', 'url', 'max:255'],
            'basic_username' => ['required', 'string', 'max:255'],
            'basic_password' => ['required', 'string', 'max:255'],
            'xapi_version' => ['required', 'string', 'max:20'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $validated['active'] = (bool) ($validated['active'] ?? true);
        LrsConnection::query()->create($validated);

        return redirect()->route('lti.platforms')
            ->with('success', 'Conexion LRS creada.');
    }

    public function destroyLrs(LrsConnection $connection): RedirectResponse
    {
        $connection->delete();

        return redirect()->route('lti.platforms')
            ->with('success', 'Conexion LRS eliminada.');
    }

    public function testLrs(LrsConnection $connection): RedirectResponse
    {
        try {
            $response = Http::timeout(15)
                ->withBasicAuth($connection->basic_username, $connection->basic_password)
                ->withHeaders([
                    'X-Experience-API-Version' => $connection->xapi_version,
                    'Accept' => 'application/json',
                ])
                ->get($connection->endpoint_url);
        } catch (ConnectionException) {
            return redirect()->route('lti.platforms')
                ->with('error', 'No se pudo conectar al LRS. Verifica DNS, red y puertos desde el servidor.');
        }

        if (! $response->successful()) {
            return redirect()->route('lti.platforms')
                ->with('error', 'No se pudo conectar al LRS (HTTP '.$response->status().').');
        }

        return redirect()->route('lti.platforms')
            ->with('success', 'Conexion LRS verificada correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?LtiPlatform $platform = null): array
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'issuer' => [
                'required',
                'url',
                'max:255',
                Rule::unique('lti_platforms', 'issuer')->ignore($platform?->id),
            ],
            'client_id' => ['required', 'string', 'max:255'],
            'jwks_url' => ['nullable', 'url', 'max:255'],
            'authorization_endpoint' => ['nullable', 'url', 'max:255'],
            'token_endpoint' => ['nullable', 'url', 'max:255'],
            'jwks_json' => ['nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $validated['active'] = (bool) ($validated['active'] ?? true);

        return $validated;
    }
}
