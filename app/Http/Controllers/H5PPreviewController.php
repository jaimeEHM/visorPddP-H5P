<?php

namespace App\Http\Controllers;

use App\Models\LtiH5pInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class H5PPreviewController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $uploaded = $request->file('file');
        if ($uploaded instanceof \Illuminate\Http\UploadedFile && ! $uploaded->isValid()) {
            $message = 'El archivo no pudo subirse.';

            if (config('app.debug')) {
                return response()->json([
                    'message' => $message,
                    'errors' => [
                        'file' => [$message],
                    ],
                    'debug' => [
                        'upload_error_code' => $uploaded->getError(),
                        'upload_error_message' => $uploaded->getErrorMessage(),
                        'client_original_name' => $uploaded->getClientOriginalName(),
                        'client_mime_type' => $uploaded->getClientMimeType(),
                        'client_original_extension' => $uploaded->getClientOriginalExtension(),
                        'ini' => [
                            'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
                            'post_max_size' => ini_get('post_max_size') ?: null,
                            'max_file_uploads' => ini_get('max_file_uploads') ?: null,
                            'max_input_time' => ini_get('max_input_time') ?: null,
                            'max_execution_time' => ini_get('max_execution_time') ?: null,
                            'memory_limit' => ini_get('memory_limit') ?: null,
                            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: null,
                        ],
                    ],
                ], 422);
            }

            return response()->json([
                'message' => $message,
                'errors' => [
                    'file' => [$message],
                ],
            ], 422);
        }

        $validated = $request->validate([
            // Algunos navegadores/servidores reportan H5P como zip u octet-stream.
            // `mimes:` puede fallar si el servidor detecta octet-stream y no mapea a zip/h5p.
            // Validamos extensión original (h5p/zip) y un set acotado de MIME típicos para ZIP.
            'file' => [
                'required',
                'file',
                // KB. 200MB para acomodar paquetes H5P grandes.
                'max:204800',
                'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile) {
                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($extension, ['h5p', 'zip'], true)) {
                        $fail('El archivo debe tener extensión .h5p (o .zip).');
                    }
                },
            ],
        ]);

        $previewId = (string) Str::ulid();
        $previewToken = Str::random(48);
        $rootPath = storage_path('app/h5p/previews/'.$previewId);
        $archivePath = $rootPath.'/resource.h5p';
        $extractPath = $rootPath.'/extracted';

        File::ensureDirectoryExists($extractPath);
        $validated['file']->move($rootPath, 'resource.h5p');

        $archive = new ZipArchive();
        $openResult = $archive->open($archivePath);
        if ($openResult !== true) {
            File::deleteDirectory($rootPath);

            return response()->json([
                'message' => 'No fue posible abrir el archivo H5P.',
            ], 422);
        }

        $archive->extractTo($extractPath);
        $archive->close();

        $h5pJson = $extractPath.'/h5p.json';
        $contentJson = $extractPath.'/content/content.json';
        if (! File::exists($h5pJson) || ! File::exists($contentJson)) {
            File::deleteDirectory($rootPath);

            return response()->json([
                'message' => 'El archivo no contiene una estructura H5P valida.',
            ], 422);
        }

        File::put($rootPath.'/.preview-token', $previewToken);
        $request->session()->put('h5p_preview_id', $previewId);
        $this->persistLtiInstanceMapping($request, $previewId, $previewToken);

        return response()->json([
            'previewId' => $previewId,
            'previewToken' => $previewToken,
            'viewerPath' => route('h5p.preview.asset', ['preview' => $previewId, 'path' => '']),
            'h5pJsonPath' => route('h5p.preview.asset', ['preview' => $previewId, 'path' => 'h5p.json']),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $previewId = $request->query('preview');
        $previewToken = $request->query('token');

        if (is_string($previewId) && $previewId !== '' && is_string($previewToken) && $previewToken !== '') {
            if (! $this->validatePreviewToken($previewId, $previewToken)) {
                abort(403);
            }

            File::deleteDirectory(storage_path('app/h5p/previews/'.$previewId));
            $this->forgetLtiInstanceMapping($request);

            return response()->json(['deleted' => true]);
        }

        $sessionPreviewId = $request->session()->pull('h5p_preview_id');
        if (! is_string($sessionPreviewId) || $sessionPreviewId === '') {
            return response()->json(['deleted' => true]);
        }

        File::deleteDirectory(storage_path('app/h5p/previews/'.$sessionPreviewId));
        $this->forgetLtiInstanceMapping($request);

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array{issuer: string, deployment_id: ?string, context_id: ?string, resource_link_id: string}|null
     */
    private function resolveLtiScope(Request $request): ?array
    {
        $launch = $request->session()->get('lti.last_launch');
        if (! is_array($launch)) {
            return null;
        }

        $issuer = isset($launch['iss']) && is_string($launch['iss']) ? trim($launch['iss']) : '';
        $resourceLinkId = isset($launch['resource_link_id']) && is_string($launch['resource_link_id']) ? trim($launch['resource_link_id']) : '';
        if ($issuer === '' || $resourceLinkId === '') {
            return null;
        }

        $deploymentId = isset($launch['deployment_id']) && is_string($launch['deployment_id']) ? trim($launch['deployment_id']) : null;
        $contextId = isset($launch['context']['id']) && is_string($launch['context']['id']) ? trim($launch['context']['id']) : null;

        return [
            'issuer' => $issuer,
            'deployment_id' => $deploymentId !== '' ? $deploymentId : null,
            'context_id' => $contextId !== '' ? $contextId : null,
            'resource_link_id' => $resourceLinkId,
        ];
    }

    private function persistLtiInstanceMapping(Request $request, string $previewId, string $previewToken): void
    {
        if (! Schema::hasTable('lti_h5p_instances')) {
            return;
        }

        $scope = $this->resolveLtiScope($request);
        if ($scope === null) {
            return;
        }

        $existing = LtiH5pInstance::query()
            ->where('issuer', $scope['issuer'])
            ->where('deployment_id', $scope['deployment_id'])
            ->where('context_id', $scope['context_id'])
            ->where('resource_link_id', $scope['resource_link_id'])
            ->first();

        $oldPreviewId = $existing?->preview_id;

        LtiH5pInstance::query()->updateOrCreate(
            [
                'issuer' => $scope['issuer'],
                'deployment_id' => $scope['deployment_id'],
                'context_id' => $scope['context_id'],
                'resource_link_id' => $scope['resource_link_id'],
            ],
            [
                'preview_id' => $previewId,
                'preview_token' => $previewToken,
            ]
        );

        if (is_string($oldPreviewId) && $oldPreviewId !== '' && $oldPreviewId !== $previewId) {
            File::deleteDirectory(storage_path('app/h5p/previews/'.$oldPreviewId));
        }
    }

    private function forgetLtiInstanceMapping(Request $request): void
    {
        if (! Schema::hasTable('lti_h5p_instances')) {
            return;
        }

        $scope = $this->resolveLtiScope($request);
        if ($scope === null) {
            return;
        }

        LtiH5pInstance::query()
            ->where('issuer', $scope['issuer'])
            ->where('deployment_id', $scope['deployment_id'])
            ->where('context_id', $scope['context_id'])
            ->where('resource_link_id', $scope['resource_link_id'])
            ->delete();
    }

    public function asset(Request $request, string $preview, string $path = ''): BinaryFileResponse
    {
        $sessionPreview = (string) $request->session()->get('h5p_preview_id', '');
        $validSession = $sessionPreview !== '' && $sessionPreview === $preview;
        abort_if(! $validSession, 403);

        return $this->buildAssetResponse($preview, $path);
    }

    public function assetWithToken(string $preview, string $token, string $path = ''): BinaryFileResponse
    {
        abort_if(! $this->validatePreviewToken($preview, $token), 403);

        return $this->buildAssetResponse($preview, $path);
    }

    private function validatePreviewToken(string $previewId, string $providedToken): bool
    {
        $tokenPath = storage_path('app/h5p/previews/'.$previewId.'/.preview-token');
        if (! File::exists($tokenPath)) {
            return false;
        }

        $storedToken = trim((string) File::get($tokenPath));
        if ($storedToken === '' || $providedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $providedToken);
    }

    private function buildAssetResponse(string $preview, string $path = ''): BinaryFileResponse
    {
        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        abort_if(str_contains($normalizedPath, '..'), 404);

        $basePath = storage_path('app/h5p/previews/'.$preview.'/extracted');
        $fullPath = $normalizedPath === '' ? $basePath : $basePath.'/'.$normalizedPath;

        if (File::isDirectory($fullPath)) {
            $fullPath = rtrim($fullPath, '/').'/index.html';
        }

        abort_if(! File::exists($fullPath), 404);

        $response = response()->file($fullPath);
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('Content-Disposition', 'inline; filename="'.basename($fullPath).'"');
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $contentTypeByExtension = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'otf' => 'font/otf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        $forcedContentType = Arr::get($contentTypeByExtension, $extension);
        if (is_string($forcedContentType)) {
            $response->headers->set('Content-Type', $forcedContentType);
        }

        return $response;
    }
}
