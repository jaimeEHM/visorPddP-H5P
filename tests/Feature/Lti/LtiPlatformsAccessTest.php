<?php

namespace Tests\Feature\Lti;

use App\Http\Middleware\EnsureLtiPlatformsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LtiPlatformsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_platforms_page_loads_locked_without_access_session(): void
    {
        $this->get(route('lti.platforms'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LtiPlatforms')
                ->where('hasAccess', false)
            );
    }

    public function test_can_login_with_expected_password_and_access_platforms(): void
    {
        $response = $this->post(route('lti.login.store'), [
            'password' => 'cf753rd/,.',
        ]);

        $response->assertRedirect(route('lti.platforms'));
        $response->assertSessionHas(EnsureLtiPlatformsAccess::SESSION_KEY, true);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $response = $this->post(route('lti.login.store'), [
            'password' => 'incorrecta',
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHasErrors('password');
    }

    public function test_protected_write_routes_redirect_to_platforms_when_locked(): void
    {
        $this->post(route('lti.platforms.store'), [
            'name' => 'Bloqueado',
            'issuer' => 'https://locked.example.test',
            'client_id' => 'locked',
        ])->assertRedirect(route('lti.platforms'));
    }
}
