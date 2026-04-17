<?php

namespace App\Http\Controllers\Lti;

use App\Models\LtiPlatform;
use Cfrd\Lti\Oidc\OidcLoginInitiationValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OidcAuthorizeController extends Controller
{
    public function redirectToPlatform(Request $request, OidcLoginInitiationValidator $validator): RedirectResponse
    {
        $allowedTargetLinkUriPatterns = array_values(array_filter(config('cfrd-lti.oidc.allowed_target_link_uri_patterns', [])));

        try {
            $params = $validator->validate($request->query->all(), $allowedTargetLinkUriPatterns);
        } catch (\DomainException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }

        $authorizationEndpoint = (string) config('cfrd-lti.platform.authorization_endpoint');

        if (is_string($params['iss'] ?? null) && $params['iss'] !== '' && Schema::hasTable('lti_platforms')) {
            $platform = LtiPlatform::query()
                ->where('issuer', $params['iss'])
                ->where('active', true)
                ->first();

            if ($platform instanceof LtiPlatform && is_string($platform->authorization_endpoint) && $platform->authorization_endpoint !== '') {
                $authorizationEndpoint = $platform->authorization_endpoint;
            }
        }

        if ($authorizationEndpoint === '') {
            throw new HttpException(503, 'LTI: falta LTI_PLATFORM_AUTHORIZATION_ENDPOINT en .env');
        }

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $request->session()->put('lti.oidc', [
            'state' => $state,
            'nonce' => $nonce,
            'issuer' => $params['iss'],
            'client_id' => $params['client_id'],
        ]);

        $query = http_build_query([
            'scope' => 'openid',
            'response_type' => 'id_token',
            'response_mode' => 'form_post',
            'client_id' => $params['client_id'],
            'redirect_uri' => url('/lti/launch'),
            'login_hint' => $params['login_hint'],
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'none',
            'lti_message_hint' => $params['lti_message_hint'],
        ]);

        return redirect()->away($authorizationEndpoint.'?'.$query);
    }
}
