<?php

namespace Tests\Feature\Lti;

use App\Models\LtiH5pInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class LtiH5pInstancePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_persists_preview_for_current_lti_instance(): void
    {
        $launch = $this->launchSession('course-a-resource-1');

        $uploadResponse = $this->withSession([
            'lti.last_launch' => $launch,
        ])->post(route('h5p.preview.upload'), [
            'file' => $this->createValidH5pFile(),
        ]);

        $uploadResponse->assertOk();
        $previewId = (string) $uploadResponse->json('previewId');
        $previewToken = (string) $uploadResponse->json('previewToken');

        $this->assertDatabaseHas('lti_h5p_instances', [
            'issuer' => 'https://moodlejames.cfrd.cl',
            'resource_link_id' => 'course-a-resource-1',
            'preview_id' => $previewId,
            'preview_token' => $previewToken,
        ]);

        $this->withSession([
            'lti.last_launch' => $launch,
        ])->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('initialPreview.previewId', $previewId)
                ->where('initialPreview.previewToken', $previewToken)
            );
    }

    public function test_same_lti_instance_replaces_existing_resource_mapping(): void
    {
        $launch = $this->launchSession('course-a-resource-2');

        $firstUpload = $this->withSession(['lti.last_launch' => $launch])->post(route('h5p.preview.upload'), [
            'file' => $this->createValidH5pFile(),
        ]);
        $firstUpload->assertOk();
        $firstPreviewId = (string) $firstUpload->json('previewId');

        $secondUpload = $this->withSession(['lti.last_launch' => $launch])->post(route('h5p.preview.upload'), [
            'file' => $this->createValidH5pFile(),
        ]);
        $secondUpload->assertOk();
        $secondPreviewId = (string) $secondUpload->json('previewId');

        $this->assertNotSame($firstPreviewId, $secondPreviewId);
        $this->assertSame(1, LtiH5pInstance::query()->count());
        $this->assertDatabaseHas('lti_h5p_instances', [
            'resource_link_id' => 'course-a-resource-2',
            'preview_id' => $secondPreviewId,
        ]);
    }

    public function test_different_lti_instances_keep_independent_resources(): void
    {
        $this->withSession(['lti.last_launch' => $this->launchSession('course-a-resource-3')])
            ->post(route('h5p.preview.upload'), [
                'file' => $this->createValidH5pFile(),
            ])
            ->assertOk();

        $this->withSession(['lti.last_launch' => $this->launchSession('course-b-resource-1')])
            ->post(route('h5p.preview.upload'), [
                'file' => $this->createValidH5pFile(),
            ])
            ->assertOk();

        $this->assertSame(2, LtiH5pInstance::query()->count());
        $this->assertDatabaseHas('lti_h5p_instances', ['resource_link_id' => 'course-a-resource-3']);
        $this->assertDatabaseHas('lti_h5p_instances', ['resource_link_id' => 'course-b-resource-1']);
    }

    public function test_delete_removes_mapping_for_current_lti_instance(): void
    {
        $launch = $this->launchSession('course-c-resource-1');

        $uploadResponse = $this->withSession(['lti.last_launch' => $launch])->post(route('h5p.preview.upload'), [
            'file' => $this->createValidH5pFile(),
        ]);
        $uploadResponse->assertOk();

        $previewId = (string) $uploadResponse->json('previewId');
        $previewToken = (string) $uploadResponse->json('previewToken');

        $this->withSession(['lti.last_launch' => $launch])
            ->delete(route('h5p.preview.destroy', [], false).'?preview='.urlencode($previewId).'&token='.urlencode($previewToken))
            ->assertOk();

        $this->assertDatabaseMissing('lti_h5p_instances', [
            'resource_link_id' => 'course-c-resource-1',
        ]);
    }

    public function test_home_cleans_stale_mapping_when_preview_files_are_missing(): void
    {
        $instance = LtiH5pInstance::query()->create([
            'issuer' => 'https://moodlejames.cfrd.cl',
            'deployment_id' => '2',
            'context_id' => 'course-context-id',
            'resource_link_id' => 'course-d-resource-1',
            'preview_id' => 'missing-preview',
            'preview_token' => 'invalid-token',
        ]);

        $this->withSession(['lti.last_launch' => $this->launchSession('course-d-resource-1')])
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('initialPreview', null)
            );

        $this->assertDatabaseMissing('lti_h5p_instances', [
            'id' => $instance->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function launchSession(string $resourceLinkId): array
    {
        return [
            'iss' => 'https://moodlejames.cfrd.cl',
            'deployment_id' => '2',
            'resource_link_id' => $resourceLinkId,
            'context' => [
                'id' => 'course-context-id',
            ],
        ];
    }

    private function createValidH5pFile(): UploadedFile
    {
        $tempDirectory = storage_path('framework/testing/h5p-tests/'.uniqid('valid_', true));
        File::ensureDirectoryExists($tempDirectory);

        $archivePath = $tempDirectory.'/resource.h5p';
        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('h5p.json', '{"title":"Test"}');
        $zip->addFromString('content/content.json', '{"text":"ok"}');
        $zip->close();

        return new UploadedFile(
            $archivePath,
            'resource.h5p',
            'application/zip',
            null,
            true
        );
    }
}
