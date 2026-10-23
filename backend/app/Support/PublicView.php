<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resolve an API resource as an anonymous viewer — for payloads that several people receive (websocket
 * broadcasts go to rider AND driver). Nested resources read the container's request, so it is swapped
 * for the duration: nothing that depends on who is asking (PIN, phone numbers, earnings) can leak.
 */
final class PublicView
{
    public static function resolve(JsonResource $resource): array
    {
        $app = app();
        $original = $app->bound('request') ? $app['request'] : null;
        $anon = new Request;
        $app->instance('request', $anon);
        // Binding a request makes the framework attach the signed-in user to it; detach again.
        $anon->setUserResolver(fn () => null);
        try {
            return json_decode(json_encode($resource->resolve($anon)), true);
        } finally {
            if ($original) {
                $app->instance('request', $original);
            }
        }
    }
}
