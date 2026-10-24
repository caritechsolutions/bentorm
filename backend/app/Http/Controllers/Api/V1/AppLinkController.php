<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Web pages inside the apps (help, tickets, statements…). The app asks for a link; the link signs the user in to a
 * web session once (2 minutes, single use) and lands on the page. Pages can then change on the server, no app update.
 */
class AppLinkController extends Controller
{
    public const TTL_SECONDS = 120;

    /** POST /app-links {page: "help" | "support/tickets/5" | …} → {url} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9][a-z0-9\/\-_]*(\?[a-zA-Z0-9=&_\-]*)?$/']]);
        $token = Str::random(48);
        Cache::put('applink:'.$token, ['user_id' => $request->user()->id, 'page' => $data['page']], self::TTL_SECONDS);

        $host = config('bentorm.support_host');
        $base = $host ? 'https://'.$host : url('/');

        return response()->json(['url' => $base.'/app/enter/'.$token, 'expires_in' => self::TTL_SECONDS]);
    }
}
