<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;


class PaymentMethodResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'expired' => $this->isExpired(),
            'holder_name' => $this->holder_name,
            'is_default' => $this->is_default,
            'gateway_id' => $this->gateway_id,
            'redirect_url' => $this->when($this->status === 'pending', $this->redirect_url),
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
