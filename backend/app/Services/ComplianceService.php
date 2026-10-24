<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DriverProfile;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

/**
 * Answers "is this driver / vehicle allowed to work?" from their documents.
 */
class ComplianceService
{
    public const EXPIRING_SOON_DAYS = 30;

    /**
     * Per document type: what's on file and its state.
     *
     * @param DriverProfile|Vehicle $owner
     * @return array<int, array{type: DocumentType, document: ?Document, state: string}>
     */
    public function checklist(Model $owner): array
    {
        $appliesTo = $owner instanceof Vehicle ? 'vehicle' : ($owner instanceof Store ? 'store' : 'driver');
        $areaId = $owner instanceof Vehicle ? $owner->driver?->driverProfile?->service_area_id : $owner->service_area_id;

        $types = DocumentType::query()->applicable($appliesTo, $areaId)
            // Store documents can be for one shop category only (e.g. a food-handling permit for restaurants).
            ->when($owner instanceof Store, fn ($q) => $q->where(fn ($w) => $w->whereNull('shop_category_id')->orWhere('shop_category_id', $owner->shop_category_id)))
            ->get();
        $docs = $owner->documents()->where('status', '!=', DocumentStatus::Superseded->value)
            ->orderByDesc('id')->get()->groupBy('document_type_id');

        $out = [];
        foreach ($types as $type) {
            $list = $docs->get($type->id, collect());
            // Prefer a valid approved document; otherwise the newest one.
            $approved = $list->first(fn (Document $d) => $this->isValid($d));
            $latest = $list->first();
            $pendingReplacement = $approved && $latest && $latest->id !== $approved->id ? $latest : null;
            $doc = $approved ?? $latest;

            $out[] = [
                'type' => $type,
                'document' => $doc,
                'pending_replacement' => $pendingReplacement,
                'state' => $this->state($doc),
            ];
        }

        return $out;
    }

    /** Problems that block a driver profile (documents only). @return string[] */
    public function driverIssues(DriverProfile $profile): array
    {
        $issues = [];
        if (! $profile->service_area_id) {
            $issues[] = 'Select the area you will drive in.';
        }

        return array_merge($issues, $this->missing($this->checklist($profile)));
    }

    /** What's still missing before a store can be approved. @return string[] */
    public function storeIssues(Store $store): array
    {
        $issues = [];
        if ($store->lat === null || $store->lng === null) {
            $issues[] = 'Pin the store\'s location on the map.';
        } elseif (! $store->service_area_id) {
            $issues[] = 'The store is outside the areas we serve.';
        }
        if (! $store->products()->exists()) {
            $issues[] = 'Add at least one product.';
        }

        return array_merge($issues, $this->missing($this->checklist($store)));
    }

    /** @return string[] */
    public function vehicleIssues(Vehicle $vehicle): array
    {
        return $this->missing($this->checklist($vehicle));
    }

    /** Everything that stops a driver going online right now. @return string[] */
    public function canGoOnline(User $driver): array
    {
        $profile = $driver->driverProfile;
        if (! $profile) {
            return ['Complete your driver profile.'];
        }

        $issues = [];
        if ($profile->status !== DriverStatus::Approved) {
            $issues[] = match ($profile->status) {
                DriverStatus::Pending => 'Your account is awaiting approval.',
                DriverStatus::Suspended => 'Your account is suspended. '.($profile->status_note ?? ''),
                DriverStatus::Rejected => 'Your application was not approved. '.($profile->status_note ?? ''),
                default => 'Your account is not approved.',
            };
        }
        $issues = array_merge($issues, $this->driverIssues($profile));

        $vehicle = $profile->currentVehicle;
        if (! $vehicle) {
            $issues[] = 'Add and select a vehicle.';
        } else {
            if (! $vehicle->isApproved()) {
                $issues[] = "Vehicle {$vehicle->plate} is awaiting approval.";
            }
            foreach ($this->vehicleIssues($vehicle) as $i) {
                $issues[] = "Vehicle {$vehicle->plate}: {$i}";
            }
        }

        return array_values(array_map('trim', $issues));
    }

    public function isValid(Document $d): bool
    {
        return $d->status === DocumentStatus::Approved && (! $d->expires_at || $d->expires_at->endOfDay()->isFuture());
    }

    // ------------------------------------------------------------------ internals

    private function state(?Document $d): string
    {
        if (! $d) {
            return 'missing';
        }
        if ($d->status === DocumentStatus::Approved) {
            if ($d->expires_at && ! $d->expires_at->endOfDay()->isFuture()) {
                return 'expired';
            }
            if ($d->expires_at && $d->expires_at->lte(now()->addDays(self::EXPIRING_SOON_DAYS))) {
                return 'expiring_soon';
            }

            return 'approved';
        }

        return $d->status->value; // pending | rejected | expired
    }

    /** @return string[] */
    private function missing(array $checklist): array
    {
        $issues = [];
        foreach ($checklist as $row) {
            if (! $row['type']->is_required || in_array($row['state'], ['approved', 'expiring_soon'], true)) {
                continue;
            }
            $issues[] = match ($row['state']) {
                'missing' => "Upload your {$row['type']->name}.",
                'pending' => "{$row['type']->name} is awaiting review.",
                'rejected' => "{$row['type']->name} was rejected".($row['document']?->review_note ? ": {$row['document']->review_note}" : '').'. Please upload a new one.',
                'expired' => "{$row['type']->name} has expired. Please upload a current one.",
                default => "{$row['type']->name} needs attention.",
            };
        }

        return $issues;
    }
}
