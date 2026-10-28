<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each public hostname opens only its own portal: newadmin.* the admin panel, fleet.* the fleet portal, store.* the
 * store portal. A portal address on the wrong hostname is sent to the right one (so an admin login on the fleet
 * address doesn't end up in the admin panel there). Portals without a hostname of their own live on the admin host.
 * Requests on other hosts (the server's own IP, local testing) are left alone.
 */
class PortalHosts
{
    /** Path patterns of each portal (its pages and the data they poll). */
    private const PORTALS = [
        'admin' => '#^(admin(/|$)|admin-live\.json$)#',
        'store' => '#^(store(/|$)|store-alerts/)#',
        'fleet' => '#^(fleet(/|$)|fleet-live/)#',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $hosts = array_filter([
            'admin' => config('bentorm.admin_host'),
            'store' => config('bentorm.store_host'),
            'fleet' => config('bentorm.fleet_host'),
        ]);
        $known = array_filter([...array_values($hosts), config('bentorm.support_host'), parse_url((string) config('app.url'), PHP_URL_HOST)]);
        $host = strtolower($request->getHost());
        if (! $hosts || ! in_array($host, array_map('strtolower', $known), true)) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        foreach (self::PORTALS as $portal => $pattern) {
            if (! preg_match($pattern, $path)) {
                continue;
            }
            $target = $hosts[$portal] ?? $hosts['admin'] ?? null;
            if (! $target || strtolower($target) === $host) {
                break;
            }
            if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
                abort(404);
            }
            $query = $request->getQueryString();

            return redirect()->away('https://'.$target.'/'.$path.($query ? '?'.$query : ''));
        }

        return $next($request);
    }
}
