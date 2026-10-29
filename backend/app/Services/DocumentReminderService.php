<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Plugins\PluginManager;
use App\Support\PushMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reminds drivers, fleet owners and store owners before a document expires (30 and 7 days ahead) and when it has
 * expired — once each, by push and email. Nothing is sent when a new copy is already waiting for review.
 */
class DocumentReminderService
{
    public function __construct(private readonly PushService $push, private readonly PluginManager $plugins) {}

    /** @return int reminders sent */
    public function run(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $sent = 0;
        Document::query()
            ->whereIn('status', [DocumentStatus::Approved->value, DocumentStatus::Expired->value])
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $today->copy()->addDays(30))
            ->whereDate('expires_at', '>=', $today->copy()->subDays(14)) // long-expired: not again and again
            ->with(['type', 'documentable'])
            ->orderBy('id')
            ->each(function (Document $d) use ($today, &$sent) {
                $days = (int) $today->diffInDays($d->expires_at->copy()->startOfDay(), false);
                $stage = match (true) {
                    $days < 0 => $d->reminded_expired_at ? null : 'expired',
                    $days <= 7 => $d->reminded_7_at ? null : '7',
                    default => $d->reminded_30_at || $d->reminded_7_at ? null : '30',
                };
                if (! $stage || $this->replacementWaiting($d)) {
                    return;
                }
                $this->send($d, $stage, $days);
                $d->forceFill(match ($stage) {
                    'expired' => ['reminded_expired_at' => now(), 'reminded_7_at' => $d->reminded_7_at ?? now(), 'reminded_30_at' => $d->reminded_30_at ?? now()],
                    '7' => ['reminded_7_at' => now(), 'reminded_30_at' => $d->reminded_30_at ?? now()],
                    default => ['reminded_30_at' => now()],
                })->save();
                $sent++;
            });

        return $sent;
    }

    private function replacementWaiting(Document $d): bool
    {
        return Document::query()->where('documentable_type', $d->documentable_type)->where('documentable_id', $d->documentable_id)
            ->where('document_type_id', $d->document_type_id)->where('id', '>', $d->id)
            ->whereIn('status', [DocumentStatus::Pending->value, DocumentStatus::Approved->value])->exists();
    }

    private function send(Document $d, string $stage, int $days): void
    {
        $owner = $d->documentable;
        [$users, $what, $where] = match (true) {
            $owner instanceof DriverProfile => [[$owner->user], 'Your '.$d->type->name, 'in the Ride Coop Driver app (Menu → Account → Your documents)'],
            $owner instanceof Vehicle && $owner->fleet_id => [$owner->fleet?->users()->wherePivot('role', 'owner')->get()->all() ?? [],
                "The {$d->type->name} for {$owner->plate}", 'in the fleet portal (Cars → Documents)'],
            $owner instanceof Vehicle => [[$owner->driver], "The {$d->type->name} for {$owner->plate}", 'in the Ride Coop Driver app (Account → Vehicle documents)'],
            $owner instanceof Store => [$owner->owners()->get()->all(), "{$owner->name}'s {$d->type->name}", 'in the store portal (Overview → Documents)'],
            default => [[], '', ''],
        };
        $date = $d->expires_at->format('j M Y');
        [$title, $body] = match ($stage) {
            'expired' => ["{$d->type->name} expired", "{$what} expired on {$date}. Upload the new one {$where} — until it's approved you can't take trips".($owner instanceof Store ? ' or orders' : '').'.'],
            '7' => ["{$d->type->name} expires ".($days === 0 ? 'today' : "in {$days} day".($days === 1 ? '' : 's')), "{$what} expires on {$date}. Upload the new one {$where} now so you aren't taken off the road."],
            default => ["{$d->type->name} expires on {$date}", "{$what} expires on {$date}. Upload the new one {$where} before then."],
        };
        $mailer = $this->plugins->email();
        foreach (array_filter($users) as $u) {
            /** @var User $u */
            $this->push->notify($u->id, new PushMessage($title, $body, ['type' => 'document', 'document_id' => $d->id]));
            if ($u->email && $mailer) {
                try {
                    $mailer->send($u->email, $title, view('emails.notice', ['title' => $title, 'lines' => [$body]])->render(), $body);
                } catch (\Throwable $e) {
                    Log::warning('Document reminder email failed: '.$e->getMessage(), ['user' => $u->id]);
                }
            }
        }
    }
}
