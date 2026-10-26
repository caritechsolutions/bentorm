<?php

namespace App\Console\Commands;

use App\Models\Call;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Why didn't an in-app call connect? Shows the last calls with what each phone sent: the offer/answer and its
 * connection candidates by type — host (phone's own network), srflx (its public address) and relay (through the
 * call relay). No relay candidates from a phone = it could not reach the relay (ports, DNS or the relay itself).
 * Signalling is kept for an hour, so run this soon after a test call.
 */
class CallDiagnostics extends Command
{
    protected $signature = 'bentorm:calls {--last=5 : how many recent calls}';

    protected $description = 'Show recent in-app calls and the connection candidates each phone offered';

    public function handle(): int
    {
        $calls = Call::query()->with(['caller', 'callee'])->latest('id')->limit(max(1, (int) $this->option('last')))->get();
        if ($calls->isEmpty()) {
            $this->info('No calls yet.');

            return self::SUCCESS;
        }
        foreach ($calls as $c) {
            $this->line('');
            $this->line(sprintf('<info>Call %s</info>  %s  %s  %s  status=%s via=%s', $c->public_id, $c->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                $c->video ? 'video' : 'audio', ($c->caller?->firstName() ?? '?').' → '.($c->callee?->firstName() ?? '?'), $c->status, $c->via ?? 'app'));
            foreach ([[$c->caller_id, $c->callee_id, 'caller'], [$c->callee_id, $c->caller_id, 'callee']] as [$from, $to, $who]) {
                $msgs = Cache::get("call:{$c->id}:to:{$to}");
                if ($msgs === null) {
                    $this->line("  {$who}: nothing kept (more than an hour ago, or nothing sent)");

                    continue;
                }
                $this->line("  {$who}: ".$this->summary($msgs));
            }
        }
        $this->line('');
        $this->line('host = phone\'s own network · srflx = its public address · relay = through the call relay (needs the ports open).');

        return self::SUCCESS;
    }

    /** @param array<int, array{type: string, payload: array}> $msgs */
    public function summary(array $msgs): string
    {
        $types = ['offer' => 0, 'answer' => 0];
        $cands = ['host' => 0, 'srflx' => 0, 'prflx' => 0, 'relay' => 0];
        $relayProto = [];
        foreach ($msgs as $m) {
            $lines = [];
            if (in_array($m['type'], ['offer', 'answer'], true)) {
                $types[$m['type']]++;
                preg_match_all('/a=(candidate:[^\r\n]+)/', (string) ($m['payload']['sdp'] ?? ''), $mm);
                $lines = $mm[1];
            } elseif ($m['type'] === 'candidate') {
                $lines = [(string) ($m['payload']['candidate'] ?? '')];
            }
            foreach ($lines as $l) {
                if (preg_match('/ typ (host|srflx|prflx|relay)/', $l, $t)) {
                    $cands[$t[1]]++;
                    if ($t[1] === 'relay' && preg_match('/ (udp|tcp) /i', $l, $p)) {
                        $relayProto[strtolower($p[1])] = true;
                    }
                }
            }
        }
        $out = [];
        foreach ($types as $k => $v) {
            $out[] = $v ? "{$k} ✓" : "no {$k}";
        }
        foreach ($cands as $k => $v) {
            $out[] = "{$k} {$v}".($k === 'relay' && $relayProto ? ' ('.implode('/', array_keys($relayProto)).')' : '');
        }

        return implode(' · ', $out);
    }
}
