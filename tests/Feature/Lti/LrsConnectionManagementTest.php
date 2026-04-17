<?php

namespace Tests\Feature\Lti;

use App\Models\LrsConnection;
use App\Models\LtiPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LrsConnectionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_lti_page_exposes_lrs_connections(): void
    {
        $platform = LtiPlatform::query()->create([
            'name' => 'Moodle James',
            'issuer' => 'https://moodlejames.cfrd.cl',
            'client_id' => 'client-1',
            'active' => true,
        ]);

        LrsConnection::query()->create([
            'name' => 'LRS Moodle',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'service-user',
            'basic_password' => 'secret-123',
            'xapi_version' => '1.0.3',
            'active' => true,
            'lti_platform_id' => $platform->id,
        ]);

        $this->get(route('lti.platforms'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LtiPlatforms')
                ->has('lrsConnections', 1)
                ->where('lrsConnections.0.name', 'LRS Moodle')
                ->where('lrsConnections.0.endpoint_url', 'https://lrs.example.test/xapi/statements')
                ->where('lrsConnections.0.has_password', true)
            );
    }

    public function test_can_store_lrs_connection(): void
    {
        $platform = LtiPlatform::query()->create([
            'name' => 'Moodle James',
            'issuer' => 'https://moodlejames.cfrd.cl',
            'client_id' => 'client-1',
            'active' => true,
        ]);

        $response = $this->post(route('lti.lrs.store'), [
            'lti_platform_id' => $platform->id,
            'name' => 'LRS Produccion',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'basic_password' => 'password-abc',
            'xapi_version' => '1.0.3',
        ]);

        $response->assertRedirect(route('lti.platforms'));
        $this->assertDatabaseHas('lrs_connections', [
            'name' => 'LRS Produccion',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'xapi_version' => '1.0.3',
        ]);
    }

    public function test_can_test_lrs_connection(): void
    {
        Http::fake([
            'https://lrs.example.test/*' => Http::response(['statements' => []], 200),
        ]);

        $connection = LrsConnection::query()->create([
            'name' => 'LRS Test',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'basic_password' => 'password-abc',
            'xapi_version' => '1.0.3',
            'active' => true,
        ]);

        $response = $this->post(route('lti.lrs.test', $connection));
        $response->assertRedirect(route('lti.platforms'));
    }

    public function test_lrs_test_handles_connection_failures_without_500(): void
    {
        Http::fake([
            'https://lrs.example.test/*' => Http::failedConnection(),
        ]);

        $connection = LrsConnection::query()->create([
            'name' => 'LRS Test',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'basic_password' => 'password-abc',
            'xapi_version' => '1.0.3',
            'active' => true,
        ]);

        $response = $this->post(route('lti.lrs.test', $connection));
        $response->assertRedirect(route('lti.platforms'));
        $response->assertSessionHas('error');
    }
}
