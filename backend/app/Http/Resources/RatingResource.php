<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;


class RatingResource extends Resource
{
    public function toArray(Request $request): array
    {
        return [
            'rater_id' => $this->rater_id,
            'ratee_id' => $this->ratee_id,
            'score' => $this->score,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
