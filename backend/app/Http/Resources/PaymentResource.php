<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->user()?->isAdmin() ?? false;

        return [
            'id' => $this->public_id,
            'trip_id' => $this->trip?->public_id,
            'purpose' => $this->purpose,
            'method' => $this->method,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'redirect_url' => $this->when($this->status === 'pending', $this->redirect_url),
            'failure_reason' => $this->failure_reason,
            'gateway' => $this->when($admin, fn () => $this->gateway ? ['id' => $this->gateway->id, 'name' => $this->gateway->name, 'plugin' => $this->gateway->plugin] : null),
            'gateway_ref' => $this->when($admin, $this->gateway_ref),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
