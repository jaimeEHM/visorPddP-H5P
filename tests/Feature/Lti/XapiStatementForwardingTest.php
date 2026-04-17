<?php

namespace Tests\Feature\Lti;

use App\Models\LrsConnection;
use App\Models\LtiPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XapiStatementForwardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_forwards_statements_to_lrs_for_current_lms_issuer(): void
    {
        $platform = LtiPlatform::query()->create([
            'name' => 'Moodle James',
            'issuer' => 'https://moodlejames.cfrd.cl',
            'client_id' => 'client-1',
            'active' => true,
        ]);

        LrsConnection::query()->create([
            'lti_platform_id' => $platform->id,
            'name' => 'LRS Moodle',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'basic_password' => 'password-abc',
            'xapi_version' => '1.0.3',
            'active' => true,
        ]);

        Http::fake([
            'https://lrs.example.test/*' => Http::response([], 200),
        ]);

        $response = $this->withSession([
            'lti.last_launch' => [
                'iss' => 'https://moodlejames.cfrd.cl',
                'sub' => 'user-123',
                'name' => 'Student One',
                'email' => 'student@example.test',
                'roles' => ['Learner'],
                'deployment_id' => '8f571fba-100c-5a86-b72c-fda3cbdf5d17',
                'context' => [
                    'id' => 'course-1',
                    'title' => 'Curso',
                ],
                'resource_link_id' => 'resource-77',
            ],
        ])->postJson(route('xapi.statements.forward'), [
            'statements' => [
                [
                    'verb' => [
                        'id' => 'http://adlnet.gov/expapi/verbs/answered',
                        'display' => ['en-US' => 'answered'],
                    ],
                    'object' => [
                        'id' => 'https://pddp.cfrd.cl/h5p/example',
                        'objectType' => 'Activity',
                    ],
                    'result' => [
                        'score' => ['scaled' => 0.9],
                        'success' => true,
                    ],
                ],
            ],
        ]);

        $response->assertOk()->assertJson([
            'forwarded' => true,
            'count' => 1,
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            if (! is_array($payload)) {
                return false;
            }

            $statement = $payload;

            return isset($statement['actor'])
                && isset($statement['context'])
                && isset($statement['result'])
                && (($statement['actor']['mbox'] ?? null) === 'mailto:student@example.test')
                && (($statement['actor']['name'] ?? null) === 'Student One')
                && ! array_key_exists('objectType', $statement['actor'])
                && (($statement['context']['registration'] ?? null) === '8f571fba-100c-5a86-b72c-fda3cbdf5d17')
                && (($statement['context']['extensions']['https://pddp.cfrd.cl/xapi/extensions/lti_issuer'] ?? null) === 'https://moodlejames.cfrd.cl');
        });
    }

    public function test_preserves_context_platform_and_registration_when_already_set(): void
    {
        $platform = LtiPlatform::query()->create([
            'name' => 'Moodle James',
            'issuer' => 'https://moodlejames.cfrd.cl',
            'client_id' => 'client-1',
            'active' => true,
        ]);

        LrsConnection::query()->create([
            'lti_platform_id' => $platform->id,
            'name' => 'LRS Moodle',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'basic_password' => 'password-abc',
            'xapi_version' => '1.0.3',
            'active' => true,
        ]);

        Http::fake([
            'https://lrs.example.test/*' => Http::response([], 200),
        ]);

        $this->withSession([
            'lti.last_launch' => [
                'iss' => 'https://moodlejames.cfrd.cl',
                'sub' => 'user-123',
                'name' => 'Admin User',
                'email' => 'admin@udec.cl',
                'roles' => ['Learner'],
                'deployment_id' => '2',
                'context' => [
                    'id' => 'course-1',
                    'title' => 'Curso',
                ],
                'resource_link_id' => 'resource-77',
            ],
        ])->postJson(route('xapi.statements.forward'), [
            'statements' => [
                [
                    'verb' => [
                        'id' => 'http://id.tincanapi.com/verb/viewed',
                        'display' => ['en' => 'Viewed'],
                    ],
                    'object' => [
                        'id' => 'https://moodlejames.cfrd.cl/mod/quiz/view.php?id=4',
                        'objectType' => 'Activity',
                    ],
                    'context' => [
                        'language' => 'en',
                        'platform' => 'MoodleJaime',
                        'registration' => '8f571fba-100c-5a86-b72c-fda3cbdf5d17',
                    ],
                ],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $payload = $request->data();
            if (! is_array($payload)) {
                return false;
            }

            return ($payload['context']['platform'] ?? null) === 'MoodleJaime'
                && ($payload['context']['registration'] ?? null) === '8f571fba-100c-5a86-b72c-fda3cbdf5d17'
                && ($payload['actor']['mbox'] ?? null) === 'mailto:admin@udec.cl'
                && ($payload['actor']['name'] ?? null) === 'Admin User';
        });
    }

    public function test_omits_context_registration_when_lti_deployment_id_is_not_uuid_nor_iri(): void
    {
        $platform = LtiPlatform::query()->create([
            'name' => 'Moodle James',
            'issuer' => 'https://moodlejames.cfrd.cl',
            'client_id' => 'client-1',
            'active' => true,
        ]);

        LrsConnection::query()->create([
            'lti_platform_id' => $platform->id,
            'name' => 'LRS Moodle',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'basic_password' => 'password-abc',
            'xapi_version' => '1.0.3',
            'active' => true,
        ]);

        Http::fake([
            'https://lrs.example.test/*' => Http::response([], 200),
        ]);

        $this->withSession([
            'lti.last_launch' => [
                'iss' => 'https://moodlejames.cfrd.cl',
                'sub' => 'user-1',
                'email' => 'a@example.test',
                'deployment_id' => '2',
                'context' => [],
            ],
        ])->postJson(route('xapi.statements.forward'), [
            'statements' => [
                [
                    'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/interacted'],
                    'object' => [
                        'id' => 'https://pddp.cfrd.cl/h5p/x',
                        'objectType' => 'Activity',
                    ],
                ],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $payload = $request->data();
            if (! is_array($payload) || ! isset($payload['context'])) {
                return false;
            }

            return ! array_key_exists('registration', $payload['context'])
                && ($payload['context']['extensions']['https://pddp.cfrd.cl/xapi/extensions/lti_deployment_id'] ?? null) === '2';
        });
    }

    public function test_returns_accepted_when_no_lrs_connection_is_available(): void
    {
        $response = $this->withSession([
            'lti.last_launch' => [
                'iss' => 'https://moodlejames.cfrd.cl',
            ],
        ])->postJson(route('xapi.statements.forward'), [
            'statements' => [
                ['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/interacted']],
            ],
        ]);

        $response->assertStatus(202)->assertJson([
            'forwarded' => false,
        ]);
    }

    public function test_completes_h5p_style_account_actor_when_lti_session_is_missing(): void
    {
        config(['app.url' => 'https://pddp.cfrd.cl']);

        LrsConnection::query()->create([
            'lti_platform_id' => null,
            'name' => 'LRS global',
            'endpoint_url' => 'https://lrs.example.test/xapi/statements',
            'basic_username' => 'svc-lrs',
            'basic_password' => 'password-abc',
            'xapi_version' => '1.0.3',
            'active' => true,
        ]);

        Http::fake([
            'https://lrs.example.test/*' => Http::response([], 200),
        ]);

        $response = $this->postJson(route('xapi.statements.forward'), [
            'statements' => [
                [
                    'actor' => [
                        'objectType' => 'Agent',
                        'account' => [
                            'name' => '200eeff6-1c2e-4346-9cbc-defe6d323d5e',
                        ],
                    ],
                    'verb' => [
                        'id' => 'http://adlnet.gov/expapi/verbs/attempted',
                        'display' => ['en-US' => 'attempted'],
                    ],
                    'object' => [
                        'id' => 'https://pddp.cfrd.cl/h5p-preview-token/t/n',
                        'objectType' => 'Activity',
                    ],
                ],
            ],
        ]);

        $response->assertOk()->assertJson([
            'forwarded' => true,
            'count' => 1,
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            if (! is_array($payload)) {
                return false;
            }

            $homePage = $payload['actor']['account']['homePage'] ?? null;
            $name = $payload['actor']['account']['name'] ?? null;

            return $homePage === 'https://pddp.cfrd.cl'
                && $name === '200eeff6-1c2e-4346-9cbc-defe6d323d5e';
        });
    }
}
