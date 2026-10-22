<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /** POST /me/avatar (multipart: photo) */
    public function uploadAvatar(Request $request): UserResource
    {
        $request->validate(['photo' => ['required', File::image()->max(10 * 1024)]]);
        $user = $request->user();
        $file = $request->file('photo');

        ImageOptimizer::optimise($file->getRealPath(), (string) $file->getMimeType(), 800);
        $path = Storage::disk('local')->putFileAs("avatars/{$user->id}", $file, Str::uuid().'.'.($file->guessExtension() ?: 'jpg'));

        if ($user->avatar_path) {
            Storage::disk('local')->delete($user->avatar_path);
        }
        $user->forceFill(['avatar_path' => $path])->save();

        return (new UserResource($user->fresh()->load('driverProfile')))->asSelf();
    }

    /** GET /files/avatars/{user} — any signed-in user (riders see driver photos and vice versa). */
    public function avatar(User $user): StreamedResponse
    {
        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** GET /files/documents/{document} — owner, admin, or a rider on a trip with this driver/vehicle (rider-visible types only). */
    public function document(Request $request, Document $document): StreamedResponse
    {
        $user = $request->user();
        $document->loadMissing(['type', 'documentable']);

        $allowed = $user->isAdmin() || $document->ownerId() === $user->id || $this->riderMaySee($user, $document);
        abort_unless($allowed, 403, 'You cannot view this file.');
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, max-age=3600',
            'Content-Type' => $document->mime ?: 'application/octet-stream',
        ]);
    }

    private function riderMaySee(User $user, Document $doc): bool
    {
        if (! $doc->type?->visible_to_riders) {
            return false;
        }
        $owner = $doc->documentable;
        $q = Trip::query()->where('rider_id', $user->id)
            ->where(fn ($w) => $w->whereIn('status', array_map(fn ($s) => $s->value, TripStatus::active()))
                ->orWhere('completed_at', '>', now()->subDay()));

        return match (true) {
            $owner instanceof Vehicle => $q->where('vehicle_id', $owner->id)->exists(),
            $owner instanceof DriverProfile => $q->where('driver_id', $owner->user_id)->exists(),
            default => false,
        };
    }
}
