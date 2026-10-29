<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Document;
use App\Models\LedgerEntry;
use App\Models\PaymentMethod;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * "Delete my account" (in the apps and on the web page the app stores link to). Personal details are removed and
 * the login can't be used again; trips, payments and ratings stay as anonymous records for the accounts and the
 * other person's history. The phone number is free to sign up again later as a new account.
 */
class AccountDeletionService
{
    public function __construct(private readonly PaymentService $payments) {}

    /** Why this account can't be deleted right now (empty = it can). @return string[] */
    public function blockers(User $user): array
    {
        $out = [];
        if ($user->isAdmin()) {
            $out[] = 'Admin accounts are removed by another admin.';
        }
        if ($user->activeTrip()) {
            $out[] = 'Finish or cancel your current ride first.';
        }
        if ($user->outstandingTrips()->exists()) {
            $out[] = 'Pay what you owe for a past ride first.';
        }
        $balance = LedgerEntry::balance('driver', $user->id);
        if ($balance < 0) {
            $out[] = sprintf('Settle the BBD %.2f you owe Ride Coop from cash rides first.', abs($balance));
        }
        if ($user->stores()->wherePivot('role', 'owner')->exists() || $user->fleets()->wherePivot('role', 'owner')->exists()) {
            $out[] = 'You own a store or fleet on Ride Coop. Add another owner or ask Ride Coop to close it first.';
        }

        return $out;
    }

    public function delete(User $user, string $via): void
    {
        if ($b = $this->blockers($user)) {
            abort(response()->json(['message' => $b[0], 'blockers' => $b], 409));
        }
        $owed = LedgerEntry::balance('driver', $user->id);

        DB::transaction(function () use ($user, $via, $owed) {
            foreach (PaymentMethod::query()->where('user_id', $user->id)->get() as $card) {
                try {
                    $this->payments->remove($card);
                } catch (\Throwable $e) {
                    report($e);
                    $card->delete();
                }
            }
            SavedPlace::query()->where('user_id', $user->id)->delete();
            DeviceToken::query()->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->stores()->detach();
            $user->fleets()->detach();

            if ($profile = $user->driverProfile) {
                // Driver documents (licence, ID…) are personal: files and records go. Vehicles stay for trip history.
                foreach (Document::query()->where('documentable_type', $profile->getMorphClass())->where('documentable_id', $profile->id)->get() as $d) {
                    try {
                        Storage::disk($d->disk)->delete($d->path);
                    } catch (\Throwable) {
                    }
                    $d->delete();
                }
                $profile->forceFill(['is_online' => false, 'licence_number' => null, 'psv_permit_number' => null,
                    'bank_name' => null, 'bank_account_name' => null, 'bank_account_number' => null, 'bank_branch' => null])->save();
                app(DriverLocationService::class)->goOffline($user);
            }

            DB::table('account_deletions')->insert(['user_id' => $user->id, 'role' => $user->role->value, 'via' => $via,
                'owed_to_user' => $owed > 0 ? round($owed, 2) : 0, 'created_at' => now(), 'updated_at' => now()]);

            $user->forceFill([
                'name' => 'Deleted user',
                'phone' => 'deleted-'.$user->id,
                'email' => null,
                'password' => null,
                'avatar_path' => null,
                'is_active' => false,
                'remember_token' => null,
            ])->save();
            $user->delete(); // soft delete: trips and payments keep pointing at it
        });
        Log::info('Account deleted', ['user_id' => $user->id, 'via' => $via]);
    }
}
