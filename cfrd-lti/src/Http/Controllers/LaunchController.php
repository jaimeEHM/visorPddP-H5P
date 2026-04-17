<?php

namespace Cfrd\Lti\Http\Controllers;

use App\Models\LtiPlatform;
use App\Services\Lti\LtiUserResolver;
use Cfrd\Lti\DeepLinking\DeepLinkingMessageInspector;
use Cfrd\Lti\Jwt\IdTokenValidator;
use Cfrd\Lti\LtiClaim;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LaunchController extends Controller
{
    private function inferJwksUrlFromIssuer(string $issuer): ?string
    {
        if ($issuer === '') {
            return null;
        }

        try {
            $wellKnownUrl = rtrim($issuer, '/').'/.well-known/openid-configuration';
            $response = Http::timeout(10)->acceptJson()->get($wellKnownUrl);
            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            $jwksUri = is_array($payload) ? ($payload['jwks_uri'] ?? null) : null;

            return is_string($jwksUri) && $jwksUri !== '' ? $jwksUri : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchJwksFromUrl(string $jwksUrl): ?array
    {
        if ($jwksUrl === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get($jwksUrl);
            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! isset($payload['keys']) || ! is_array($payload['keys']) || $payload['keys'] === []) {
                return null;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildJwksRetryCandidates(?LtiPlatform $platform, string $issuer): array
    {
        $candidates = [];

        if ($platform instanceof LtiPlatform && is_string($platform->jwks_url) && $platform->jwks_url !== '') {
            $candidates[] = $platform->jwks_url;
        }

        $discovered = $this->inferJwksUrlFromIssuer($issuer);
        if (is_string($discovered) && $discovered !== '') {
            $candidates[] = $discovered;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Canvas (y otros LMS) pueden enviar deployment_id como string o número en el JWT decodificado.
     */
    private function normalizeDeploymentId(mixed $raw): ?string
    {
        if (is_string($raw)) {
            $t = trim($raw);

            return $t !== '' ? $t : null;
        }
        if (is_int($raw) || is_float($raw)) {
            $s = (string) $raw;

            return $s !== '' ? $s : null;
        }

        return null;
    }

    private function blockedDeploymentResponse(Request $request, object $payload): ?SymfonyResponse
    {
        if (! Schema::hasTable('lti_deployments')) {
            return null;
        }

        $issuer = is_string($payload->iss ?? null) ? (string) $payload->iss : '';
        $deploymentId = $this->normalizeDeploymentId($payload->{LtiClaim::DEPLOYMENT_ID} ?? null);
        if ($issuer === '' || $deploymentId === null) {
            return null;
        }

        $deployment = DB::table('lti_deployments')
            ->where('issuer', $issuer)
            ->where('deployment_id', $deploymentId)
            ->first(['active', 'blocked_reason']);

        if (! $deployment || (bool) ($deployment->active ?? true)) {
            return null;
        }

        $reason = is_string($deployment->blocked_reason ?? null) && trim((string) $deployment->blocked_reason) !== ''
            ? trim((string) $deployment->blocked_reason)
            : 'Este despliegue fue inhabilitado por administración de la plataforma.';

        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Despliegue LTI inhabilitado',
                'reason' => $reason,
                'deployment_id' => $deploymentId,
                'issuer' => $issuer,
            ], 403);
        }

        return response()->view('lti-blocked', [
            'issuer' => $issuer,
            'deploymentId' => $deploymentId,
            'reason' => $reason,
        ], 403);
    }

    /**
     * Datos del último launch por deployment (contexto LTI) para identificar tipo de despliegue en UI.
     *
     * @return array<string, mixed>
     */
    private function deploymentLaunchSnapshot(object $payload): array
    {
        $contextClaim = $payload->{'https://purl.imsglobal.org/spec/lti/claim/context'} ?? null;
        $messageType = $payload->{LtiClaim::MESSAGE_TYPE} ?? null;
        $types = null;
        $ctxId = null;
        $ctxTitle = null;
        $ctxLabel = null;

        if (is_object($contextClaim)) {
            $ctxId = is_string($contextClaim->id ?? null) ? (string) $contextClaim->id : null;
            $ctxTitle = is_string($contextClaim->title ?? null) ? (string) $contextClaim->title : null;
            $ctxLabel = is_string($contextClaim->label ?? null) ? (string) $contextClaim->label : null;
            if (isset($contextClaim->type) && is_array($contextClaim->type)) {
                $filtered = array_values(array_filter(array_map(
                    static fn ($t) => is_string($t) ? $t : null,
                    $contextClaim->type
                )));
                $types = $filtered !== [] ? $filtered : null;
            }
        }

        return [
            'last_message_type' => is_string($messageType) ? $messageType : null,
            'last_context_id' => $ctxId,
            'last_context_title' => $ctxTitle,
            'last_context_label' => $ctxLabel,
            'last_context_types' => $types,
            'last_seen_at' => now(),
        ];
    }

    /**
     * Solo persiste metadatos de launch si la migración que añade esas columnas ya corrió.
     * Evita 500 en POST /lti/launch si el despliegue tiene código nuevo pero BD antigua.
     *
     * @return array<string, mixed>
     */
    private function deploymentSnapshotForDb(object $payload): array
    {
        if (! Schema::hasColumn('lti_deployments', 'last_message_type')) {
            return [];
        }

        return $this->deploymentLaunchSnapshot($payload);
    }

    private function persistDeploymentIfPossible(object $payload): void
    {
        $deploymentId = $this->normalizeDeploymentId($payload->{LtiClaim::DEPLOYMENT_ID} ?? null);
        $issuer = is_string($payload->iss ?? null) ? (string) $payload->iss : '';

        if ($deploymentId === null || $issuer === '') {
            return;
        }

        if (! Schema::hasTable('lti_deployments')) {
            return;
        }

        $platformId = null;
        if (Schema::hasTable('lti_platforms')) {
            $platformId = DB::table('lti_platforms')
                ->where('issuer', $issuer)
                ->where('active', true)
                ->value('id');
        }

        $snapshot = $this->deploymentSnapshotForDb($payload);

        $existing = DB::table('lti_deployments')
            ->where('issuer', $issuer)
            ->where('deployment_id', $deploymentId)
            ->first(['id']);

        if ($existing && isset($existing->id)) {
            DB::table('lti_deployments')
                ->where('id', $existing->id)
                ->update(array_merge($snapshot, [
                    'lti_platform_id' => is_int($platformId) ? $platformId : null,
                    'updated_at' => now(),
                ]));

            return;
        }

        DB::table('lti_deployments')->insert(array_merge($snapshot, [
            'issuer' => $issuer,
            'deployment_id' => $deploymentId,
            'active' => true,
            'blocked_reason' => null,
            'lti_platform_id' => is_int($platformId) ? $platformId : null,
            'updated_at' => now(),
            'created_at' => now(),
        ]));
    }

    /**
     * Guarda en sesión solo datos no sensibles del último launch,
     * para uso en la consola interna (Deep Linking/NRPS/AGS).
     *
     * @return array<string, mixed>
     */
    private function extractConsoleLaunchData(object $payload): array
    {
        $deepLinkingSettings = $payload->{LtiClaim::DEEP_LINKING_SETTINGS} ?? null;
        $namesRoleService = $payload->{LtiClaim::NAMES_ROLES_SERVICE} ?? null;
        $agsEndpoint = $payload->{LtiClaim::ENDPOINT} ?? null;
        $contextClaim = $payload->{'https://purl.imsglobal.org/spec/lti/claim/context'} ?? null;
        $resourceLinkClaim = $payload->{'https://purl.imsglobal.org/spec/lti/claim/resource_link'} ?? null;
        $rolesClaim = $payload->{'https://purl.imsglobal.org/spec/lti/claim/roles'} ?? null;

        return [
            'iss' => $payload->iss ?? null,
            'aud' => $payload->aud ?? null,
            'sub' => $payload->sub ?? null,
            'name' => $payload->name ?? null,
            'given_name' => $payload->given_name ?? null,
            'family_name' => $payload->family_name ?? null,
            'email' => $payload->email ?? null,
            'message_type' => $payload->{LtiClaim::MESSAGE_TYPE} ?? null,
            'deployment_id' => $payload->{LtiClaim::DEPLOYMENT_ID} ?? null,
            'resource_link_id' => is_object($resourceLinkClaim) ? ($resourceLinkClaim->id ?? null) : null,
            'roles' => is_array($rolesClaim) ? $rolesClaim : null,
            'context' => is_object($contextClaim) ? [
                'id' => $contextClaim->id ?? null,
                'label' => $contextClaim->label ?? null,
                'title' => $contextClaim->title ?? null,
                'type' => $contextClaim->type ?? null,
            ] : null,
            'deep_linking' => is_object($deepLinkingSettings) ? [
                'deep_link_return_url' => $deepLinkingSettings->deep_link_return_url ?? null,
            ] : null,
            'nrps' => is_object($namesRoleService) ? [
                'context_memberships_url' => $namesRoleService->context_memberships_url ?? null,
                'service_versions' => $namesRoleService->service_versions ?? null,
            ] : null,
            'ags' => is_object($agsEndpoint) ? [
                'lineitems' => $agsEndpoint->lineitems ?? null,
                'scope' => $agsEndpoint->scope ?? null,
            ] : null,
        ];
    }

    public function handle(
        Request $request,
        IdTokenValidator $idTokenValidator,
        DeepLinkingMessageInspector $deepLinkingInspector,
        LtiUserResolver $ltiUserResolver,
    ): SymfonyResponse {
        $idToken = $request->string('id_token')->toString();
        if ($idToken === '') {
            return response()->json(['error' => 'id_token requerido'], 422);
        }

        /** @var array<string, mixed>|null $oidc */
        $oidc = $request->session()->get('lti.oidc');
        $expectedState = is_array($oidc) ? (string) ($oidc['state'] ?? '') : '';
        $expectedNonce = is_array($oidc) ? (string) ($oidc['nonce'] ?? '') : null;
        $expectedIssuerFromSession = is_array($oidc) ? (string) ($oidc['issuer'] ?? '') : '';
        $expectedClientIdFromSession = is_array($oidc) ? (string) ($oidc['client_id'] ?? '') : '';

        // Si venimos desde /lti/oauth/authorize, el launch debe incluir el mismo state.
        if ($expectedState !== '') {
            $incomingState = $request->string('state')->toString();
            if ($incomingState === '' || ! hash_equals($expectedState, $incomingState)) {
                return response()->json(['error' => 'state inválido.'], 422);
            }
        }

        // Multi-LMS: resolver issuer/client_id/JWKS por el issuer que vino en OIDC (sesión),
        // con fallback a configuración por .env (config/cfrd-lti.php).
        $issuer = $expectedIssuerFromSession !== '' ? $expectedIssuerFromSession : (string) config('cfrd-lti.platform.issuer');
        $clientId = $expectedClientIdFromSession !== '' ? $expectedClientIdFromSession : (string) config('cfrd-lti.platform.client_id');

        $platform = null;
        if ($issuer !== '' && Schema::hasTable('lti_platforms')) {
            $platform = LtiPlatform::query()
                ->where('issuer', $issuer)
                ->where('active', true)
                ->first();
        }

        if ($platform) {
            if (($platform->client_id ?? '') !== '') {
                $clientId = (string) $platform->client_id;
            }
        }

        $jwks = $platform?->jwks_json;
        // Si la plataforma está registrada pero no tiene JWKS JSON (o el LMS rotó llaves),
        // intentar obtenerlo desde jwks_url on-the-fly para permitir lanzamientos sin intervención manual.
        if ((! is_array($jwks) || ($jwks['keys'] ?? null) === null || $jwks['keys'] === []) && $platform) {
            $candidateJwksUrl = $this->buildJwksRetryCandidates($platform, $issuer)[0] ?? null;
            if (is_string($candidateJwksUrl) && $candidateJwksUrl !== '') {
                $fetched = $this->fetchJwksFromUrl($candidateJwksUrl);
                if (is_array($fetched)) {
                    $jwks = $fetched;

                    // Persistir para próximos launches (best effort).
                    try {
                        $platform->update([
                            'jwks_json' => $fetched,
                            'jwks_url' => $candidateJwksUrl,
                        ]);
                    } catch (\Throwable) {
                        // Ignorar fallos de persistencia; igual validamos con lo obtenido.
                    }
                }
            }
        }
        if (! is_array($jwks) || ($jwks['keys'] ?? null) === null || $jwks['keys'] === []) {
            $jwks = config('cfrd-lti.platform.jwks');
        }

        if ($issuer === '' || $clientId === '') {
            return response()->json(['error' => 'issuer o client_id de plataforma no configurados'], 503);
        }
        if (! is_array($jwks) || ($jwks['keys'] ?? null) === null || $jwks['keys'] === []) {
            return response()->json(['error' => 'JWKS de plataforma no configurado (ni en BD ni en .env)'], 503);
        }

        try {
            $payload = $idTokenValidator->validate($idToken, $jwks, $issuer, $clientId, $expectedNonce);
        } catch (\DomainException $e) {
            // Si el LMS rotó llaves y tenemos JWKS cacheado en BD, el error típico es "kid invalid".
            // En ese caso re-intentamos 1 vez refrescando desde jwks_url (si existe).
            $msg = $e->getMessage();
            $shouldRetryWithFreshJwks = $platform
                && is_string($platform->jwks_url)
                && $platform->jwks_url !== ''
                && str_contains($msg, 'kid')
                && (str_contains($msg, 'invalid') || str_contains($msg, 'lookup'));

            if ($shouldRetryWithFreshJwks) {
                foreach ($this->buildJwksRetryCandidates($platform, $issuer) as $candidateJwksUrl) {
                    $fetched = $this->fetchJwksFromUrl($candidateJwksUrl);
                    if (is_array($fetched)) {
                        // Best effort persistencia para próximos launches
                        try {
                            $platform->update([
                                'jwks_json' => $fetched,
                                'jwks_url' => $candidateJwksUrl,
                            ]);
                        } catch (\Throwable) {
                            // noop
                        }
                        $payload = $idTokenValidator->validate($idToken, $fetched, $issuer, $clientId, $expectedNonce);
                        goto afterValidation;
                    }
                }
            }

            return response()->json(['error' => $msg], 422);
        }

        afterValidation:

        $blockedResponse = $this->blockedDeploymentResponse($request, $payload);
        if ($blockedResponse !== null) {
            return $blockedResponse;
        }

        $request->session()->put('lti.last_launch', $this->extractConsoleLaunchData($payload));
        $this->persistDeploymentIfPossible($payload);
        $request->session()->forget('lti.oidc');

        // SSO: si el launch trae un usuario (sub) válido, iniciamos sesión en Laravel para que
        // la app completa cargue dentro del iframe sin pasar por /login.
        $issuerForUser = is_string($payload->iss ?? null) ? (string) $payload->iss : '';
        $subjectForUser = is_string($payload->sub ?? null) ? (string) $payload->sub : '';
        if ($issuerForUser !== '' && $subjectForUser !== '') {
            // Canvas puede no enviar email/nombre dependiendo del privacy_level; se manejan como opcionales.
            $name = is_string($payload->name ?? null) ? (string) $payload->name : null;
            $email = is_string($payload->email ?? null) ? (string) $payload->email : null;
            $user = $ltiUserResolver->resolveOrCreate($issuerForUser, $subjectForUser, $name, $email);

            $currentUser = Auth::user();
            if (
                $currentUser !== null
                && ! $currentUser->lti_embed_only
                && (int) $currentUser->id !== (int) $user->id
            ) {
                $request->session()->put('lti.previous_web_user_id', (int) $currentUser->id);
            }

            Auth::login($user, true);
            $request->session()->regenerate();
            // La herramienta se abre en iframe; permite XHR/Inertia hasta la próxima navegación document top-level.
            $request->session()->put('lti.request_embedded', true);
        }

        $payloadResponse = [
            'status' => 'ok',
            'message_type' => $payload->{LtiClaim::MESSAGE_TYPE} ?? null,
            'is_deep_linking_request' => $deepLinkingInspector->isDeepLinkingRequest($payload),
            'is_resource_link_request' => $deepLinkingInspector->isResourceLinkRequest($payload),
            'console' => $request->session()->get('lti.last_launch'),
        ];

        // Canvas normalmente embebe la tool en iframe y espera contenido HTML.
        // Si no es una llamada AJAX, redirigimos a una landing (GET) donde la app puede renderizar UI.
        if (! $request->expectsJson()) {
            // El siguiente GET (seguir el redirect) es navegación "document" sin X-Inertia;
            // IsolateLtiSessionOnTopLevelNavigation no debe borrar aún `lti.last_launch`
            // (NRPS/AGS leen URLs desde ahí).
            $request->session()->flash('lti.preserve_last_launch_on_next_document', true);

            // En este proyecto la vista principal de herramienta es Welcome (preview H5P).
            return redirect()->to('/');
        }

        return response()->json($payloadResponse);
    }
}
