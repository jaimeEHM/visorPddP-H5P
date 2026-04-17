<?php

namespace App\Http\Controllers;

use App\Models\LrsConnection;
use App\Models\LtiPlatform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str as SupportStr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class XapiStatementForwardController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'statements' => ['required', 'array', 'min:1'],
            'statements.*' => ['array'],
        ]);

        /** @var array<int, array<string, mixed>> $incomingStatements */
        $incomingStatements = $validated['statements'];
        $ltiLaunch = $request->session()->get('lti.last_launch');

        $connection = $this->resolveLrsConnection($ltiLaunch);
        if (! $connection instanceof LrsConnection) {
            return response()->json([
                'forwarded' => false,
                'message' => 'No hay conexion LRS activa para este LMS.',
            ], 202);
        }

        $normalizedStatements = array_map(
            fn (array $statement): array => $this->normalizeStatement($statement, $ltiLaunch),
            $incomingStatements
        );

        $forwardedCount = 0;
        $failedCount = 0;
        $lastErrorMessage = null;

        foreach ($normalizedStatements as $statement) {
            try {
                $response = Http::timeout(15)
                    ->withBasicAuth($connection->basic_username, $connection->basic_password)
                    ->withHeaders([
                        'X-Experience-API-Version' => $connection->xapi_version,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->post($connection->endpoint_url, $statement);
            } catch (ConnectionException $exception) {
                $endpointHost = parse_url($connection->endpoint_url, PHP_URL_HOST);
                $errorMessage = SupportStr::of($exception->getMessage())->squish()->limit(220)->toString();

                return response()->json([
                    'forwarded' => false,
                    'count' => $forwardedCount,
                    'failed' => count($normalizedStatements) - $forwardedCount,
                    'message' => 'No se pudo conectar al LRS configurado (host: '.($endpointHost ?: 'desconocido').'). '.$errorMessage,
                    'retry_after_seconds' => 30,
                ], 502);
            }

            if ($response->successful()) {
                $forwardedCount++;
                continue;
            }

            $failedCount++;
            $responseBody = SupportStr::of((string) $response->body())->squish()->limit(220)->toString();
            $lastErrorMessage = 'LRS respondio con HTTP '.$response->status()
                .($responseBody !== '' ? ' — '.$responseBody : '');
        }

        if ($failedCount > 0) {
            return response()->json([
                'forwarded' => $forwardedCount > 0,
                'count' => $forwardedCount,
                'failed' => $failedCount,
                'message' => $lastErrorMessage ?? 'Uno o mas statements fueron rechazados por el LRS.',
            ]);
        }

        return response()->json([
            'forwarded' => true,
            'count' => $forwardedCount,
        ]);
    }

    /**
     * @param  mixed  $ltiLaunch
     */
    private function resolveLrsConnection(mixed $ltiLaunch): ?LrsConnection
    {
        if (! Schema::hasTable('lrs_connections')) {
            return null;
        }

        $issuer = is_array($ltiLaunch) && isset($ltiLaunch['iss']) && is_string($ltiLaunch['iss'])
            ? trim($ltiLaunch['iss'])
            : '';

        if ($issuer !== '' && Schema::hasTable('lti_platforms')) {
            $platformId = LtiPlatform::query()
                ->where('issuer', $issuer)
                ->where('active', true)
                ->value('id');

            if (is_int($platformId)) {
                $connection = LrsConnection::query()
                    ->where('active', true)
                    ->where('lti_platform_id', $platformId)
                    ->latest('id')
                    ->first();
                if ($connection instanceof LrsConnection) {
                    return $connection;
                }
            }
        }

        return LrsConnection::query()
            ->where('active', true)
            ->whereNull('lti_platform_id')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $statement
     * @param  mixed  $ltiLaunch
     * @return array<string, mixed>
     */
    private function normalizeStatement(array $statement, mixed $ltiLaunch): array
    {
        $result = $statement;
        $result['version'] = '1.0.3';
        $result['id'] = is_string($result['id'] ?? null) && $result['id'] !== '' ? $result['id'] : (string) Str::uuid();
        $result['timestamp'] = is_string($result['timestamp'] ?? null) && $result['timestamp'] !== ''
            ? $result['timestamp']
            : now()->toIso8601String();

        if (! isset($result['verb'])) {
            $result['verb'] = [
                'id' => 'http://adlnet.gov/expapi/verbs/interacted',
                'display' => ['en' => 'interacted'],
            ];
        }

        if (! isset($result['object'])) {
            $result['object'] = [
                'id' => rtrim((string) config('app.url'), '/').'/h5p/unknown-resource',
                'definition' => [
                    'name' => ['en' => 'H5P resource'],
                    'type' => 'http://adlnet.gov/expapi/activities/lesson',
                ],
                'objectType' => 'Activity',
            ];
        }

        if (is_array($ltiLaunch)) {
            $email = is_string($ltiLaunch['email'] ?? null) ? trim($ltiLaunch['email']) : '';
            $issuer = is_string($ltiLaunch['iss'] ?? null) ? trim($ltiLaunch['iss']) : '';
            $subject = is_string($ltiLaunch['sub'] ?? null) ? trim($ltiLaunch['sub']) : '';
            $name = is_string($ltiLaunch['name'] ?? null) ? trim($ltiLaunch['name']) : '';

            if ($email !== '') {
                $actor = [
                    'mbox' => 'mailto:'.$email,
                ];
                if ($name !== '') {
                    $actor['name'] = $name;
                }
                $result['actor'] = $actor;
            } else {
                $actor = [];
                if ($name !== '') {
                    $actor['name'] = $name;
                }
                $actor['account'] = [
                    'homePage' => $issuer !== '' ? $issuer : rtrim((string) config('app.url'), '/'),
                    'name' => $subject !== '' ? $subject : 'unknown',
                ];
                $result['actor'] = $actor;
            }

            $existingContext = is_array($result['context'] ?? null) ? $result['context'] : [];
            $extensions = is_array($existingContext['extensions'] ?? null) ? $existingContext['extensions'] : [];
            $extensions['https://pddp.cfrd.cl/xapi/extensions/lti_issuer'] = $ltiLaunch['iss'] ?? null;
            $extensions['https://pddp.cfrd.cl/xapi/extensions/lti_deployment_id'] = $ltiLaunch['deployment_id'] ?? null;
            $extensions['https://pddp.cfrd.cl/xapi/extensions/lti_message_type'] = $ltiLaunch['message_type'] ?? null;
            $extensions['https://pddp.cfrd.cl/xapi/extensions/lti_roles'] = $ltiLaunch['roles'] ?? [];
            $extensions['https://pddp.cfrd.cl/xapi/extensions/lti_context'] = $ltiLaunch['context'] ?? null;
            $extensions['https://pddp.cfrd.cl/xapi/extensions/lti_resource_link_id'] = $ltiLaunch['resource_link_id'] ?? null;

            $platform = $existingContext['platform'] ?? null;
            if (! is_string($platform) || trim($platform) === '') {
                $existingContext['platform'] = (string) config('app.name');
            }

            $registration = $existingContext['registration'] ?? null;
            if (! is_string($registration) || trim($registration) === '') {
                $deploymentId = $ltiLaunch['deployment_id'] ?? null;
                $candidate = is_string($deploymentId) ? trim($deploymentId) : '';
                $existingContext['registration'] = $this->isLikelyXapiRegistrationValue($candidate)
                    ? $candidate
                    : null;
            }

            $existingContext['extensions'] = $extensions;
            $result['context'] = $existingContext;

            $existingResult = is_array($result['result'] ?? null) ? $result['result'] : [];
            $existingResult['extensions'] = array_merge(
                is_array($existingResult['extensions'] ?? null) ? $existingResult['extensions'] : [],
                [
                    'https://pddp.cfrd.cl/xapi/extensions/lti_launch_timestamp' => now()->toIso8601String(),
                    'https://pddp.cfrd.cl/xapi/extensions/lti_context_title' => $ltiLaunch['context']['title'] ?? null,
                ]
            );
            $result['result'] = $existingResult;
        }

        $result = $this->ensureActorHasValidInverseFunctionalIdentifier($result, $ltiLaunch);
        $result = $this->stripRedundantAgentObjectType($result);

        $cleaned = $this->removeNullRecursively($result);

        return is_array($cleaned) ? $cleaned : Arr::where($result, fn ($value) => $value !== null);
    }

    /**
     * xAPI exige que un Agent tenga exactamente un identificador funcional inverso (IFI) válido.
     * H5P standalone suele enviar `account` solo con `name`; el objeto Account requiere `homePage` y `name`.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function ensureActorHasValidInverseFunctionalIdentifier(array $statement, mixed $ltiLaunch): array
    {
        $actor = $statement['actor'] ?? null;
        if (! is_array($actor)) {
            return $this->withDefaultAgentActor($statement, $ltiLaunch);
        }

        if (($actor['objectType'] ?? 'Agent') === 'Group') {
            return $statement;
        }

        $defaultHomePage = $this->ltiIssuerHomePage($ltiLaunch) ?? rtrim((string) config('app.url'), '/');

        if (isset($actor['account']) && is_array($actor['account'])) {
            $account = $actor['account'];
            $name = isset($account['name']) && is_scalar($account['name']) ? trim((string) $account['name']) : '';
            $homePage = isset($account['homePage']) && is_scalar($account['homePage']) ? trim((string) $account['homePage']) : '';
            if ($name === '') {
                $name = (string) Str::uuid();
            }
            if ($homePage === '') {
                $homePage = $defaultHomePage;
            }
            $actor['account'] = [
                'homePage' => $homePage,
                'name' => $name,
            ];
            $statement['actor'] = $actor;

            return $statement;
        }

        if (isset($actor['mbox']) && is_string($actor['mbox'])) {
            $mbox = trim($actor['mbox']);
            if ($mbox !== '' && ! str_starts_with($mbox, 'mailto:')) {
                $actor['mbox'] = 'mailto:'.$mbox;
                $statement['actor'] = $actor;
            }
        }

        if ($this->actorHasValidInverseFunctionalIdentifier($statement['actor'])) {
            return $statement;
        }

        return $this->withDefaultAgentActor($statement, $ltiLaunch);
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function withDefaultAgentActor(array $statement, mixed $ltiLaunch): array
    {
        $defaultHomePage = $this->ltiIssuerHomePage($ltiLaunch) ?? rtrim((string) config('app.url'), '/');
        $statement['actor'] = [
            'account' => [
                'homePage' => $defaultHomePage,
                'name' => (string) Str::uuid(),
            ],
        ];

        return $statement;
    }

    /**
     * En Agent, `objectType` es opcional (por defecto Agent). Los statements tipo Moodle/JISC suelen omitirlo.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    private function stripRedundantAgentObjectType(array $statement): array
    {
        $actor = $statement['actor'] ?? null;
        if (! is_array($actor)) {
            return $statement;
        }

        if (($actor['objectType'] ?? null) !== 'Agent') {
            return $statement;
        }

        unset($actor['objectType']);
        $statement['actor'] = $actor;

        return $statement;
    }

    /**
     * @param  mixed  $actor
     */
    private function actorHasValidInverseFunctionalIdentifier(mixed $actor): bool
    {
        if (! is_array($actor)) {
            return false;
        }

        if (isset($actor['mbox']) && is_string($actor['mbox'])) {
            $mbox = trim($actor['mbox']);

            return str_starts_with($mbox, 'mailto:') && strlen($mbox) > 7;
        }

        if (isset($actor['mbox_sha1sum']) && is_string($actor['mbox_sha1sum']) && trim($actor['mbox_sha1sum']) !== '') {
            return true;
        }

        if (isset($actor['openid']) && is_string($actor['openid']) && trim($actor['openid']) !== '') {
            return true;
        }

        if (isset($actor['account']) && is_array($actor['account'])) {
            $homePage = isset($actor['account']['homePage']) && is_string($actor['account']['homePage'])
                ? trim($actor['account']['homePage'])
                : '';
            $name = isset($actor['account']['name']) && is_string($actor['account']['name'])
                ? trim($actor['account']['name'])
                : '';

            return $homePage !== '' && $name !== '';
        }

        return false;
    }

    private function ltiIssuerHomePage(mixed $ltiLaunch): ?string
    {
        if (! is_array($ltiLaunch)) {
            return null;
        }

        $issuer = $ltiLaunch['iss'] ?? null;

        return is_string($issuer) && trim($issuer) !== '' ? trim($issuer) : null;
    }

    /**
     * backendLRS valida `context.registration` como UUID (RFC 4122) o IRI con esquema;
     * los `deployment_id` LTI suelen ser cadenas cortas ("1", "2") y no pasan esa validación.
     */
    private function isLikelyXapiRegistrationValue(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        if (Str::isUuid($value)) {
            return true;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return is_string($scheme) && $scheme !== '';
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function removeNullRecursively(mixed $value): mixed
    {
        if (is_array($value)) {
            $cleaned = [];
            foreach ($value as $key => $item) {
                $normalizedItem = $this->removeNullRecursively($item);
                if ($normalizedItem === null) {
                    continue;
                }
                if (is_array($normalizedItem) && $normalizedItem === []) {
                    continue;
                }
                $cleaned[$key] = $normalizedItem;
            }

            return $cleaned;
        }

        if (is_object($value)) {
            return $this->removeNullRecursively((array) $value);
        }

        return $value;
    }
}
