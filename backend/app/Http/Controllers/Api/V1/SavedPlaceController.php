<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedPlace;
use App\Support\Features;
use App\Support\GeoJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** GET/POST /me/places, PATCH/DELETE /me/places/{id}. Saving Home or Work again replaces it. */
class SavedPlaceController extends Controller
{
    public const MAX = 12;

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->list($request));
    }

    public function store(Request $request): JsonResponse
    {
        Features::require('saved_places', 'Saved places');
        $d = $this->validated($request);
        $user = $request->user();
        if ($d['kind'] !== 'other') {
            $place = SavedPlace::query()->updateOrCreate(['user_id' => $user->id, 'kind' => $d['kind']], $d);
        } else {
            if (SavedPlace::query()->where('user_id', $user->id)->count() >= self::MAX) {
                throw ValidationException::withMessages(['label' => ['You can save up to '.self::MAX.' places. Remove one first.']]);
            }
            $place = SavedPlace::query()->create($d + ['user_id' => $user->id]);
        }

        return response()->json($place->toApi(), 201);
    }

    public function update(Request $request, SavedPlace $place): JsonResponse
    {
        abort_unless($place->user_id === $request->user()->id, 404);
        $place->update($this->validated($request, partial: true));

        return response()->json($place->toApi());
    }

    public function destroy(Request $request, SavedPlace $place): JsonResponse
    {
        abort_unless($place->user_id === $request->user()->id, 404);
        $place->delete();

        return response()->json($this->list($request));
    }

    private function list(Request $request): array
    {
        return SavedPlace::query()->where('user_id', $request->user()->id)
            ->orderByRaw("CASE kind WHEN 'home' THEN 0 WHEN 'work' THEN 1 ELSE 2 END")->orderBy('label')->get()
            ->map(fn (SavedPlace $p) => $p->toApi())->all();
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $d = $request->validate([
            'kind' => [$req, 'in:home,work,other'],
            'label' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => [$req, 'numeric', 'between:-90,90'],
            'lng' => [$req, 'numeric', 'between:-1000,1000'], // map taps past the date line are wrapped below
            'place_id' => ['nullable', 'string', 'max:255'],
        ]);
        if (isset($d['lng'])) {
            $d['lng'] = GeoJson::wrapLng((float) $d['lng']);
        }
        if (isset($d['kind']) && $d['kind'] !== 'other') {
            $d['label'] = $d['kind'] === 'home' ? 'Home' : 'Work';
        } elseif (! $partial && blank($d['label'] ?? null)) {
            throw ValidationException::withMessages(['label' => ['Give this place a name.']]);
        }

        return $d;
    }
}
