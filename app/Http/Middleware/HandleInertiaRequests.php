<?php

namespace App\Http\Middleware;

use App\Models\LtiPlatform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $ltiLaunch = $request->session()->get('lti.last_launch');
        $ltiRoles = is_array($ltiLaunch) && isset($ltiLaunch['roles']) && is_array($ltiLaunch['roles'])
            ? $ltiLaunch['roles']
            : [];
        $ltiIssuer = is_array($ltiLaunch) && isset($ltiLaunch['iss']) && is_string($ltiLaunch['iss'])
            ? $ltiLaunch['iss']
            : null;
        $integratedLtiIssuers = [];
        if (Schema::hasTable('lti_platforms')) {
            $integratedLtiIssuers = LtiPlatform::query()
                ->where('active', true)
                ->pluck('issuer')
                ->filter(fn ($issuer) => is_string($issuer) && trim($issuer) !== '')
                ->values()
                ->all();
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'ltiRoles' => $ltiRoles,
            'ltiIssuer' => $ltiIssuer,
            'integratedLtiIssuers' => $integratedLtiIssuers,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
