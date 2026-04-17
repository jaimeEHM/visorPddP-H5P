<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class H5PPreviewControllerTest extends TestCase
{
    public function test_can_upload_valid_h5p_and_get_preview_asset(): void
    {
        $file = $this->createValidH5pFile();

        $uploadResponse = $this->post(route('h5p.preview.upload'), [
            'file' => $file,
        ]);

        $uploadResponse->assertOk()->assertJsonStructure([
            'previewId',
            'previewToken',
            'viewerPath',
            'h5pJsonPath',
        ]);

        $previewId = (string) $uploadResponse->json('previewId');
        $previewToken = (string) $uploadResponse->json('previewToken');
        $assetResponse = $this->get(route('h5p.preview.asset', [
            'preview' => $previewId,
            'path' => 'h5p.json',
        ], false).'?token='.urlencode($previewToken));

        $assetResponse->assertOk();
    }

    public function test_can_upload_h5p_when_server_reports_octet_stream(): void
    {
        $file = $this->createValidH5pFile(mimeType: 'application/octet-stream');

        $uploadResponse = $this->post(route('h5p.preview.upload'), [
            'file' => $file,
        ]);

        $uploadResponse->assertOk()->assertJsonStructure([
            'previewId',
            'previewToken',
            'viewerPath',
            'h5pJsonPath',
        ]);
    }

    public function test_rejects_invalid_h5p_structure(): void
    {
        $invalid = $this->createInvalidH5pFile();

        $response = $this->post(route('h5p.preview.upload'), [
            'file' => $invalid,
        ]);

        $response->assertUnprocessable();
    }

    public function test_can_delete_current_preview(): void
    {
        $uploadResponse = $this->post(route('h5p.preview.upload'), [
            'file' => $this->createValidH5pFile(),
        ]);
        $uploadResponse->assertOk();

        $previewId = (string) $uploadResponse->json('previewId');
        $previewToken = (string) $uploadResponse->json('previewToken');

        $deleteResponse = $this->delete(route('h5p.preview.destroy', [], false).'?preview='
            .urlencode($previewId).'&token='.urlencode($previewToken));
        $deleteResponse->assertOk()->assertJson([
            'deleted' => true,
        ]);
    }

    private function createValidH5pFile(string $mimeType = 'application/zip'): UploadedFile
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
            $mimeType,
            null,
            true
        );
    }

    private function createInvalidH5pFile(): UploadedFile
    {
        $tempDirectory = storage_path('framework/testing/h5p-tests/'.uniqid('invalid_', true));
        File::ensureDirectoryExists($tempDirectory);

        $archivePath = $tempDirectory.'/resource.h5p';
        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('bad.json', '{}');
        $zip->close();

        return new UploadedFile(
            $archivePath,
            'invalid.h5p',
            'application/zip',
            null,
            true
        );
    }
}
