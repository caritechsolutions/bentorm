<?php

namespace App\Http\Controllers\AppPages;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The driver's statement: what Ride Coop owes them, what they owe, payouts — and where to send the money. */
class StatementController extends Controller
{
    public function show(Request $request): View
    {
        $u = $request->user();
        abort_unless($u->isDriver(), 404);
        $entries = LedgerEntry::query()->for('driver', $u->id)->latest('id')->limit(100)->get();

        return view('app.statement.show', ['balance' => LedgerEntry::balance('driver', $u->id), 'entries' => $entries, 'profile' => $u->driverProfile,
            'tz' => $u->driverProfile?->serviceArea?->timezone ?? config('app.timezone')]);
    }

    public function bank(Request $request): View
    {
        abort_unless($request->user()->isDriver(), 404);

        return view('app.statement.bank', ['profile' => $request->user()->driverProfile]);
    }

    public function saveBank(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isDriver(), 404);
        $d = $request->validate([
            'bank_name' => ['required', 'string', 'max:80'],
            'bank_branch' => ['nullable', 'string', 'max:80'],
            'bank_account_name' => ['required', 'string', 'max:120'],
            'bank_account_number' => ['required', 'string', 'max:40', 'regex:/^[0-9 \-]{4,40}$/'],
        ], ['bank_account_number.regex' => 'Use the account number digits only.']);
        $request->user()->driverProfile->update($d);

        return redirect('/app/statement')->with('flash', 'Bank details saved. Payouts go to this account.');
    }
}
