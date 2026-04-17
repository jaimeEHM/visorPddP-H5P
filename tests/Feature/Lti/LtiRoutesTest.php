<?php

namespace Tests\Feature\Lti;

use Tests\TestCase;

class LtiRoutesTest extends TestCase
{
    public function test_jwks_route_is_available(): void
    {
        $response = $this->get(route('cfrd.lti.jwks'));

        $response->assertStatus(503);
        $response->assertJsonStructure(['keys']);
    }

    public function test_oidc_login_initiation_redirects_to_authorize_endpoint(): void
    {
        $response = $this->get(route('cfrd.lti.oidc.login_initiation', [
            'iss' => 'https://canvasjames.cfrd.cl',
            'login_hint' => 'hint-123',
            'target_link_uri' => 'https://pddp.cfrd.cl/lti/launch',
        ]));

        $response->assertRedirect();
        $response->assertRedirectContains('/lti/oauth/authorize');
        $response->assertRedirectContains('login_hint=hint-123');
    }

    public function test_launch_requires_id_token(): void
    {
        $response = $this->post(route('cfrd.lti.launch'));

        $response->assertUnprocessable();
        $response->assertJson([
            'error' => 'id_token requerido',
        ]);
    }

    public function test_oidc_authorize_route_redirects_to_platform_endpoint(): void
    {
        config()->set('cfrd-lti.platform.authorization_endpoint', 'https://moodlejames.cfrd.cl/mod/lti/auth.php');
        config()->set('cfrd-lti.oidc.allowed_target_link_uri_patterns', ['https://pddp.cfrd.cl/lti/launch']);

        $response = $this->get(route('lti.oauth.authorize', [
            'iss' => 'https://moodlejames.cfrd.cl',
            'login_hint' => '2',
            'target_link_uri' => 'https://pddp.cfrd.cl/lti/launch',
            'lti_message_hint' => '{"cmid":6}',
            'client_id' => 'NCt1S265UHUDlwe',
        ]));

        $response->assertRedirect();
        $response->assertRedirectContains('https://moodlejames.cfrd.cl/mod/lti/auth.php');
        $response->assertRedirectContains('response_type=id_token');
        $response->assertRedirectContains('redirect_uri=');
    }
}
