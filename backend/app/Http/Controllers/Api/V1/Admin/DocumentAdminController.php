<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentTypeResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DocumentAdminController extends Controller
{
    // ---------------------------------------------------------------- types

    /** GET /admin/document-types */
    public function types(): AnonymousResourceCollection
    {
        return DocumentTypeResource::collection(DocumentType::query()->orderBy('applies_to')->orderBy('sort_order')->get());
    }

    /** POST /admin/document-types */
    public function storeType(Request $request): JsonResponse
    {
        $type = DocumentType::query()->create($this->validateType($request, true));

        return (new DocumentTypeResource($type))->response()->setStatusCode(201);
    }

    /** PATCH /admin/document-types/{documentType} */
    public function updateType(Request $request, DocumentType $documentType): DocumentTypeResource
    {
        $documentType->update($this->validateType($request, false, $documentType));

        return new DocumentTypeResource($documentType);
    }

    // ---------------------------------------------------------------- review queue

    /** GET /admin/documents?status=pending&document_type=&driver_id= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Document::query()->with(['type', 'documentable'])->where('status', $request->query('status', 'pending'));
        if ($t = $request->query('document_type')) {
            $q->whereHas('type', fn ($w) => $w->where('key', $t));
        }
        if ($driverId = $request->integer('driver_id')) {
            $q->where(fn ($w) => $w
                ->where(fn ($d) => $d->where('documentable_type', \App\Models\DriverProfile::class)
                    ->whereIn('documentable_id', \App\Models\DriverProfile::query()->where('user_id', $driverId)->select('id')))
                ->orWhere(fn ($v) => $v->where('documentable_type', \App\Models\Vehicle::class)
                    ->whereIn('documentable_id', \App\Models\Vehicle::query()->where('driver_id', $driverId)->select('id'))));
        }
        // Oldest first: a queue.
        return DocumentResource::collection($q->orderBy('id')->paginate(50));
    }

    /** GET /admin/documents/{document} */
    public function show(Document $document): DocumentResource
    {
        return new DocumentResource($document->load(['type', 'documentable']));
    }

    /** POST /admin/documents/{document}/review  {decision: approve|reject, note?, expires_at?} */
    public function review(Request $request, Document $document, DocumentService $docs): DocumentResource
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        return new DocumentResource($docs->review($document->load('type'), $data['decision'] === 'approve', $data['note'] ?? null, $request->user(), $data['expires_at'] ?? null)->load('documentable'));
    }

    private function validateType(Request $request, bool $creating, ?DocumentType $existing = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'key' => [$req, 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', Rule::unique('document_types', 'key')->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:255'],
            'applies_to' => [$req, 'in:driver,vehicle'],
            'service_area_id' => ['nullable', 'exists:service_areas,id'],
            'is_required' => ['sometimes', 'boolean'],
            'requires_expiry' => ['sometimes', 'boolean'],
            'requires_number' => ['sometimes', 'boolean'],
            'is_image_only' => ['sometimes', 'boolean'],
            'visible_to_riders' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
