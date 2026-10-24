<?php

namespace App\Http\Controllers\AppPages;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/** Single-use sign-in from an app link (see AppLinkController). */
class EnterController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $link = Cache::pull('applink:'.$token);
        $user = $link ? User::query()->whereKey($link['user_id'])->where('is_active', true)->first() : null;
        if (! $user) {
            return redirect('/app/signed-out');
        }
        // A phone may be shared: drop whoever was signed in to these pages before.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('in_app', true);

        return redirect('/app/'.ltrim($link['page'], '/'));
    }
}
