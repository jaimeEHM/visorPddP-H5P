<?php

namespace App\Http\Controllers\Lti;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureLtiPlatformsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlatformAccessController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $expectedPassword = (string) config('app.lti_platforms_password', 'cf753rd/,.');
        if (! hash_equals($expectedPassword, $validated['password'])) {
            return back()
                ->withErrors([
                    'password' => 'Contrasena incorrecta.',
                ])
                ->withInput();
        }

        $request->session()->put(EnsureLtiPlatformsAccess::SESSION_KEY, true);
        $request->session()->regenerate();

        return redirect()->route('lti.platforms');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(EnsureLtiPlatformsAccess::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('lti.platforms');
    }
}
