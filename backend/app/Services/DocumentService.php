<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ImageOptimizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    public const DISK = 'local'; // private: storage/app/private

    /** @param DriverProfile|Vehicle $owner */
    public function upload(Model $owner, DocumentType $type, UploadedFile $file, ?string $number, ?string $expiresAt, User $by): Document
    {
        $appliesTo = $owner instanceof Vehicle ? 'vehicle' : 'driver';
        if ($type->applies_to !== $appliesTo) {
            throw ValidationException::withMessages(['document_type' => ["'{$type->name}' is a {$type->applies_to} document."]]);
        }
        if (! $type->is_active) {
            throw ValidationException::withMessages(['document_type' => ['This document type is no longer accepted.']]);
        }
        if ($type->requires_number && blank($number)) {
            throw ValidationException::withMessages(['number' => ["{$type->name} requires the document number."]]);
        }
        if ($type->requires_expiry && blank($expiresAt)) {
            throw ValidationException::withMessages(['expires_at' => ["{$type->name} requires an expiry date."]]);
        }
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        if ($type->is_image_only && ! str_starts_with((string) $mime, 'image/')) {
            throw ValidationException::withMessages(['file' => ["{$type->name} must be a photo."]]);
        }

        $dir = sprintf('documents/%s/%d/%s', $appliesTo, $owner->getKey(), $type->key);
        $name = Str::uuid()->toString().'.'.($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');

        // Optimise in place before storing.
        ImageOptimizer::optimise($file->getRealPath(), (string) $mime);
        clearstatcache(true, $file->getRealPath());
        $path = Storage::disk(self::DISK)->putFileAs($dir, $file, $name);

        return DB::transaction(function () use ($owner, $type, $path, $file, $mime, $number, $expiresAt, $by) {
            // Earlier unapproved uploads of this type are replaced. An approved one stays valid until this is reviewed.
            $owner->documents()->where('document_type_id', $type->id)
                ->whereIn('status', [DocumentStatus::Pending->value, DocumentStatus::Rejected->value, DocumentStatus::Expired->value])
                ->update(['status' => DocumentStatus::Superseded->value]);

            return $owner->documents()->create([
                'document_type_id' => $type->id,
                'uploaded_by' => $by->id,
                'number' => $number,
                'expires_at' => $expiresAt,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $mime,
                'size' => (int) @filesize(Storage::disk(self::DISK)->path($path)) ?: $file->getSize(),
                'status' => DocumentStatus::Pending,
            ]);
        });
    }

    public function review(Document $doc, bool $approve, ?string $note, User $admin, ?string $expiresAt = null): Document
    {
        if (! in_array($doc->status, [DocumentStatus::Pending, DocumentStatus::Approved, DocumentStatus::Rejected], true)) {
            abort(409, "A {$doc->status->value} document cannot be reviewed.");
        }
        if ($approve && $doc->type->requires_expiry && ! ($expiresAt ?? $doc->expires_at)) {
            throw ValidationException::withMessages(['expires_at' => ['Set the expiry date before approving.']]);
        }

        return DB::transaction(function () use ($doc, $approve, $note, $admin, $expiresAt) {
            if ($approve) {
                Document::query()->where('documentable_type', $doc->documentable_type)->where('documentable_id', $doc->documentable_id)
                    ->where('document_type_id', $doc->document_type_id)->where('id', '!=', $doc->id)
                    ->where('status', DocumentStatus::Approved->value)
                    ->update(['status' => DocumentStatus::Superseded->value]);
            }
            $doc->forceFill([
                'status' => $approve ? DocumentStatus::Approved : DocumentStatus::Rejected,
                'review_note' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'expires_at' => $expiresAt ?? $doc->expires_at,
            ])->save();

            return $doc->fresh(['type']);
        });
    }

    public function delete(Document $doc): void
    {
        Storage::disk($doc->disk)->delete($doc->path);
        $doc->delete();
    }
}
