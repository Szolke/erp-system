<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Yaml\Yaml;

class ApiTesterController extends Controller
{
    public function openapi(): JsonResponse
    {
        Gate::authorize('api_tester.use');

        $path = storage_path('app/scribe/openapi.yaml');

        if (! file_exists($path)) {
            return response()->json([
                'message' => 'OpenAPI spec not found. Run: php artisan scribe:generate',
            ], 503);
        }

        $spec = Yaml::parseFile($path);

        return response()->json($spec);
    }
}
