<?php

namespace App\Jobs;

use App\Plugins\PluginManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Emails a sign-in code (queued: SMTP can take seconds; the SMS goes out synchronously). */
class SendOtpEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 5;

    public function __construct(public string $email, public string $code, public int $minutes) {}

    public function handle(PluginManager $plugins): void
    {
        $mailer = $plugins->email();
        if (! $mailer) {
            Log::info("EMAIL to {$this->email}: sign-in code {$this->code} (no email gateway configured)");

            return;
        }
        $brand = config('bentorm.brand_name', 'Ride Coop');
        $html = view('emails.otp', ['brand' => $brand, 'code' => $this->code, 'minutes' => $this->minutes])->render();
        $mailer->send($this->email, "{$this->code} is your {$brand} code", $html,
            "Your {$brand} sign-in code is {$this->code}. It expires in {$this->minutes} minutes. Never share this code.");
    }

    public function failed(\Throwable $e): void
    {
        Log::error('OTP email failed: '.$e->getMessage(), ['email' => $this->email]);
    }
}
