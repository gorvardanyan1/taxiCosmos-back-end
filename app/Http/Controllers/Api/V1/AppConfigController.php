<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppConfigRequest;
use App\Services\AppConfig\AppConfigService;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    /**
     * What the app needs at start-up: the update gate (`update.force_update`), feature flags, support contacts, legal links and the
     * vehicle classes. Public (no token), rate limited per address and cacheable: the answer is the same for everyone with the same
     * app, platform, version and language, and carries an ETag so a client can ask again cheaply (304).
     */
    public function __invoke(AppConfigRequest $request, AppConfigService $config): JsonResponse
    {
        $response = response()->json([
            'app' => $request->app()->value,
            'platform' => $request->platform()->value,
            ...$config->for($request->app(), $request->platform(), $request->version()),
        ]);

        $response->setPublic();
        $response->setMaxAge((int) config('taxikosmos.app_config.cache_seconds'));
        $response->setVary(['Accept-Language']);
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
