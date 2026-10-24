<?php

namespace App\Jobs;

use App\Services\PushService;
use App\Support\PushMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $userId, public PushMessage $message) {}

    public function handle(PushService $push): void
    {
        $push->deliver($this->userId, $this->message);
    }
}
