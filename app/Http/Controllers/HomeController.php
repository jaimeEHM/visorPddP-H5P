<?php

namespace App\Http\Controllers;

use App\Models\LtiH5pInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $initialPreview = null;
        $launch = $request->session()->get('lti.last_launch');

        if (is_array($launch) && Schema::hasTable('lti_h5p_instances')) {
            $issuer = isset($launch['iss']) && is_string($launch['iss']) ? $launch['iss'] : null;
            $resourceLinkId = isset($launch['resource_link_id']) && is_string($launch['resource_link_id']) ? $launch['resource_link_id'] : null;
            $deploymentId = isset($launch['deployment_id']) && is_string($launch['deployment_id']) ? $launch['deployment_id'] : null;
            $contextId = isset($launch['context']['id']) && is_string($launch['context']['id']) ? $launch['context']['id'] : null;

            if (is_string($issuer) && $issuer !== '' && is_string($resourceLinkId) && $resourceLinkId !== '') {
                $instance = LtiH5pInstance::query()
                    ->where('issuer', $issuer)
                    ->where('resource_link_id', $resourceLinkId)
                    ->where('deployment_id', $deploymentId)
                    ->where('context_id', $contextId)
                    ->first();

                if ($instance instanceof LtiH5pInstance) {
                    $previewId = (string) $instance->preview_id;
                    $previewToken = (string) $instance->preview_token;
                    $previewRoot = storage_path('app/h5p/previews/'.$previewId);
                    $tokenPath = $previewRoot.'/.preview-token';
                    $h5pJsonPath = $previewRoot.'/extracted/h5p.json';
                    $storedToken = File::exists($tokenPath) ? trim((string) File::get($tokenPath)) : '';

                    if (File::exists($h5pJsonPath) && $storedToken !== '' && hash_equals($storedToken, $previewToken)) {
                        $initialPreview = [
                            'previewId' => $previewId,
                            'previewToken' => $previewToken,
                        ];
                        $request->session()->put('h5p_preview_id', $previewId);
                    } else {
                        $instance->delete();
                        $request->session()->forget('h5p_preview_id');
                    }
                }
            }
        }

        return Inertia::render('Welcome', [
            'initialPreview' => $initialPreview,
        ]);
    }
}
