<?php

namespace Cfrd\Lti\Http\Controllers;

use Cfrd\Lti\Support\JwkExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

class JwksController extends Controller
{
    public function show(): JsonResponse|Response
    {
        $path = (string) config('cfrd-lti.public_key_path');
        $kid = (string) config('cfrd-lti.key_id');

        if (! File::isReadable($path)) {
            return response()->json(['keys' => []], 503);
        }

        $pem = File::get($path);
        $jwk = JwkExporter::fromRsaPem($pem, $kid);

        return response()->json(['keys' => [$jwk]]);
    }
}
