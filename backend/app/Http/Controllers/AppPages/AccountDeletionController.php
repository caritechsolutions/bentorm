<?php

namespace App\Http\Controllers\AppPages;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Public "Delete your Ride Coop account" page (the link the app stores ask for). The person proves the number is
 * theirs with a code by SMS, sees what happens, and confirms. The apps have the same in Account.
 */
class AccountDeletionController extends Controller
{
    public function show(Request $request): View
    {
        return view('app.delete-account', ['phone' => $request->session()->get('delete.phone'), 'blockers' => []]);
    }

    public function sendCode(Request $request, OtpService $otp): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        try {
            $phone = Phone::normalise($data['phone']);
        } catch (ValidationException) {
            return back()->withErrors(['phone' => 'Enter a valid mobile number.'])->withInput();
        }
        $user = User::query()->where('phone', $phone)->first();
        // Same answer either way, so the page doesn't reveal who has an account.
        if ($user && ! $user->isAdmin()) {
            try {
                $otp->request($phone);
            } catch (ValidationException $e) {
                return back()->withErrors(['phone' => collect($e->errors())->flatten()->first()])->withInput();
            }
        }
        $request->session()->put('delete.phone', $phone);

        return redirect('/account/delete')->with('flash', 'If this number has a Ride Coop account, we\'ve texted it a code.');
    }

    public function destroy(Request $request, OtpService $otp, AccountDeletionService $deletion): RedirectResponse|View
    {
        $phone = (string) $request->session()->get('delete.phone');
        $data = $request->validate(['code' => ['required', 'string', 'max:10'], 'confirm' => ['accepted']],
            ['confirm.accepted' => 'Tick the box to confirm.']);
        abort_if($phone === '', 419);
        $user = User::query()->where('phone', $phone)->first();
        try {
            $code = $otp->verify($phone, trim($data['code']), consume: false);
        } catch (ValidationException $e) {
            return back()->withErrors(['code' => collect($e->errors())->flatten()->first()]);
        }
        if (! $user || $user->isAdmin()) {
            return back()->withErrors(['code' => 'Incorrect code.']);
        }
        if ($blockers = $deletion->blockers($user)) {
            return view('app.delete-account', ['phone' => $phone, 'blockers' => $blockers]);
        }
        $otp->consume($code);
        $deletion->delete($user, 'web');
        $request->session()->forget('delete.phone');

        return view('app.message', ['title' => 'Your account is deleted', 'body' => 'Your Ride Coop account and personal details have been removed. Thank you for riding with us.']);
    }
}
