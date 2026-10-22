<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->user()?->isAdmin() ?? false;

        return [
            'id' => $this->id,
            'type' => $this->whenLoaded('type', fn () => ['key' => $this->type->key, 'name' => $this->type->name]),
            'document_type_id' => $this->document_type_id,
            'owner' => $this->when($admin, fn () => [
                'kind' => $this->documentable instanceof Vehicle ? 'vehicle' : 'driver',
                'id' => $this->documentable_id,
                'driver_id' => $this->ownerId(),
            ]),
            'number' => $this->number,
            'expires_at' => $this->expires_at?->toDateString(),
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'mime' => $this->mime,
            'size' => $this->size,
            'original_name' => $this->original_name,
            'url' => route('files.document', ['document' => $this->id]),
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
