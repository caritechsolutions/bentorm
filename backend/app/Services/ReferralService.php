<?php

namespace App\Services;

use App\Models\CreditEntry;
use App\Models\User;
use App\Support\Features;
use App\Support\PushMessage;
use Illuminate\Validation\ValidationException;

/** Invite a friend: each customer has a code; a new customer enters it; after their first completed ride or order both get credit. */
class ReferralService
{
    public const DAYS_TO_ENTER = 30;

    public function __construct(private readonly PushService $push, private readonly PromoService $promos) {}

    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }
        $base = substr(strtoupper(preg_replace('/[^A-Za-z]/', '', $user->firstName()) ?: 'RIDE'), 0, 5);
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L: easy to read out
        do {
            $code = $base.implode('', array_map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 3)));
        } while (User::query()->where('referral_code', $code)->exists());
        $user->forceFill(['referral_code' => $code])->save();

        return $code;
    }

    /** A new customer enters a friend's code (within 30 days of joining, before their first ride or order). */
    public function apply(User $user, string $code): User
    {
        Features::require('referrals', 'Invite a friend');
        $fail = fn (string $m) => throw ValidationException::withMessages(['code' => [$m]]);
        $friend = User::query()->where('referral_code', strtoupper(trim($code)))->first();
        if (! $friend) {
            $fail('We couldn\'t find that invite code.');
        }
        if ($friend->id === $user->id) {
            $fail('That\'s your own code — share it with friends instead.');
        }
        if ($user->referred_by_id) {
            $fail('You\'ve already used an invite code.');
        }
        if ($user->created_at?->lt(now()->subDays(self::DAYS_TO_ENTER)) || ! $this->promos->isNewCustomer($user)) {
            $fail('Invite codes are for new customers, before their first ride or order.');
        }
        $user->forceFill(['referred_by_id' => $friend->id])->save();

        return $user;
    }

    /** After a completed ride or delivered order: reward both once, on the new customer's first. */
    public function onCompleted(User $user): void
    {
        if (! $user->referred_by_id || $user->referral_rewarded_at || ! Features::on('referrals')) {
            return;
        }
        if (! User::query()->whereKey($user->id)->whereNull('referral_rewarded_at')->update(['referral_rewarded_at' => now()])) {
            return; // already rewarded (two completions at once)
        }
        $friend = User::query()->find($user->referred_by_id);
        $mine = Features::number('referral.referee_credit');
        $theirs = Features::number('referral.referrer_credit');
        if ($mine > 0) {
            CreditEntry::query()->create(['user_id' => $user->id, 'amount' => $mine, 'reason' => 'referral_bonus', 'note' => 'Invited by '.$friend?->firstName()]);
            $this->push->notify($user->id, new PushMessage('You earned ride credit', sprintf('%.2f credit for joining with %s\'s invite. It\'s used on your next ride or order if you choose.', $mine, $friend?->firstName() ?? 'a friend'), ['type' => 'credit']));
        }
        if ($friend && $theirs > 0) {
            CreditEntry::query()->create(['user_id' => $friend->id, 'amount' => $theirs, 'reason' => 'referral_bonus', 'note' => 'Invited '.$user->firstName()]);
            $this->push->notify($friend->id, new PushMessage('Your invite paid off', sprintf('%s took their first trip. You earned %.2f ride credit.', $user->firstName(), $theirs), ['type' => 'credit']));
        }
    }
}
