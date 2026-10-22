<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentTypeResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Vehicle;
use App\Services\ComplianceService;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $docs, private readonly ComplianceService $compliance) {}

    /** GET /driver/documents — checklist for the driver profile. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['checklist' => $this->checklist($request, $request->user()->driverProfile()->firstOrCreate([]))]);
    }

    /** POST /driver/documents (multipart: document_type, file, number?, expires_at?) */
    public function store(Request $request): JsonResponse
    {
        return $this->upload($request, $request->user()->driverProfile()->firstOrCreate([]));
    }

    /** GET /driver/vehicles/{vehicle}/documents */
    public function vehicleIndex(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->driver_id === $request->user()->id, 403, 'Not your vehicle.');

        return response()->json(['checklist' => $this->checklist($request, $vehicle)]);
    }

    /** POST /driver/vehicles/{vehicle}/documents */
    public function vehicleStore(Request $request, Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->driver_id === $request->user()->id, 403, 'Not your vehicle.');

        return $this->upload($request, $vehicle);
    }

    /** DELETE /driver/documents/{document} — withdraw an upload that hasn't been approved. */
    public function destroy(Request $request, Document $document): JsonResponse
    {
        abort_unless($document->ownerId() === $request->user()->id, 403);
        if ($document->status === DocumentStatus::Approved) {
            abort(409, 'Approved documents cannot be deleted. Upload a replacement instead.');
        }
        $this->docs->delete($document);

        return response()->json(['message' => 'Document removed.']);
    }

    private function upload(Request $request, Model $owner): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', 'exists:document_types,key'],
            'file' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'])->max(15 * 1024)],
            'number' => ['nullable', 'string', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $type = DocumentType::query()->where('key', $data['document_type'])->firstOrFail();

        $doc = $this->docs->upload($owner, $type, $request->file('file'), $data['number'] ?? null, $data['expires_at'] ?? null, $request->user());

        return (new DocumentResource($doc->load('type')))->response()->setStatusCode(201);
    }

    private function checklist(Request $request, Model $owner): array
    {
        return array_map(fn ($row) => [
            'type' => (new DocumentTypeResource($row['type']))->resolve($request),
            'state' => $row['state'],
            'document' => $row['document'] ? (new DocumentResource($row['document']->load('type')))->resolve($request) : null,
            'pending_replacement' => $row['pending_replacement'] ? (new DocumentResource($row['pending_replacement']->load('type')))->resolve($request) : null,
        ], $this->compliance->checklist($owner));
    }
}
