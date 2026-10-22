<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Encode floats like 0.0 and 12.0 with their decimal part. Money fields then always arrive as doubles,
 * which matters for typed clients (Dart's `as double` throws on an int).
 */
class PreserveZeroFraction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Resources set the flag themselves (App\Http\Resources\Resource); here we cover response()->json([...]).
        if ($response instanceof JsonResponse && is_array($response->original) && ! ($response->getEncodingOptions() & JSON_PRESERVE_ZERO_FRACTION)) {
            $original = $response->original; // un-encoded data; the encoded body has already lost "x.0"
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRESERVE_ZERO_FRACTION);
            $response->setData($original);
        }

        return $response;
    }
}
