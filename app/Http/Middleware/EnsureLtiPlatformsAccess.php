<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLtiPlatformsAccess
{
    public const SESSION_KEY = 'lti.platforms.authenticated';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if ($request->session()->get(self::SESSION_KEY) === true) {
            return $next($request);
        }

        return redirect()->route('lti.platforms')
            ->with('error', 'Debes ingresar la contrasena para administrar plataformas.');
    }
}
