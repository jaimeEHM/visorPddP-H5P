<?php

namespace Cfrd\Lti\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Punto de entrada OIDC third-party: el LMS redirige aquí con iss, login_hint, etc.
 * Canvas espera una redirección HTTP al paso siguiente, no JSON (ver docs/integrarLTI.md).
 */
class OidcLoginInitiationController extends Controller
{
    public function show(Request $request): RedirectResponse
    {
        $params = array_merge($request->query(), $request->post());
        $qs = http_build_query($params);
        $target = '/lti/oauth/authorize'.($qs !== '' ? '?'.$qs : '');

        return redirect()->to($target);
    }
}
