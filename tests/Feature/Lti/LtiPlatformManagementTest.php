<?php

namespace Tests\Feature\Lti;

use App\Models\LtiPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LtiPlatformManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_lti_platforms_page_loads_with_tool_data(): void
    {
        $response = $this->get(route('lti.platforms'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LtiPlatforms')
                ->where('tool.jwks_url', route('cfrd.lti.jwks'))
                ->where('tool.login_initiation_url', route('cfrd.lti.oidc.login_initiation'))
                ->where('tool.launch_url', route('cfrd.lti.launch'))
            );
    }

    public function test_can_store_lti_platform_from_form(): void
    {
        $response = $this->post(route('lti.platforms.store'), [
            'name' => 'Canvas UdeC',
            'issuer' => 'https://canvas.example.test',
            'client_id' => '123456',
            'jwks_url' => 'https://canvas.example.test/jwks',
            'authorization_endpoint' => 'https://canvas.example.test/authorize',
            'token_endpoint' => 'https://canvas.example.test/token',
        ]);

        $response->assertRedirect(route('lti.platforms'));
        $this->assertDatabaseHas('lti_platforms', [
            'name' => 'Canvas UdeC',
            'issuer' => 'https://canvas.example.test',
            'client_id' => '123456',
        ]);
    }

    public function test_can_delete_lti_platform(): void
    {
        $platform = LtiPlatform::query()->create([
            'name' => 'Moodle',
            'issuer' => 'https://moodle.example.test',
            'client_id' => 'abc-987',
            'active' => true,
        ]);

        $response = $this->delete(route('lti.platforms.destroy', $platform));

        $response->assertRedirect(route('lti.platforms'));
        $this->assertDatabaseMissing('lti_platforms', [
            'id' => $platform->id,
        ]);
    }
}
