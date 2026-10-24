<?php

namespace App\Services;

use App\Jobs\SendPushJob;
use App\Models\DeviceToken;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Support\PushMessage;
use Illuminate\Support\Facades\Log;

/** Sends push notifications to a user's registered devices (no-op until a Push gateway is set up). */
class PushService
{
    public function __construct(private readonly PluginManager $plugins) {}

    /** Queue it — never slow a request (or a dispatch wave) down on Google. */
    public function notify(?int $userId, PushMessage $message): void
    {
        if ($userId && DeviceToken::query()->where('user_id', $userId)->exists()) {
            SendPushJob::dispatch($userId, $message)->afterCommit();
        }
    }

    /** Deliver now (from the job). Forgets tokens the provider says are dead. Returns how many devices got it. */
    public function deliver(int $userId, PushMessage $message): int
    {
        $plugin = $this->plugins->push();
        if (! $plugin) {
            return 0;
        }
        $sent = 0;
        foreach (DeviceToken::query()->where('user_id', $userId)->get() as $device) {
            try {
                if ($plugin->send($device->token, $message)) {
                    $sent++;
                    $device->forceFill(['last_used_at' => now()])->save();
                } else {
                    $device->delete();
                }
            } catch (\Throwable $e) {
                Log::warning('Push failed: '.$e->getMessage(), ['user_id' => $userId, 'device' => $device->id]);
            }
        }

        return $sent;
    }

    public function forUser(User $user): int
    {
        return DeviceToken::query()->where('user_id', $user->id)->count();
    }
}
