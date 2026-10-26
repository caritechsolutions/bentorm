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
            $this->line('  '.($c->answered_at ? 'answered after '.$c->created_at->diffInSeconds($c->answered_at).' s' : 'not answered')
                .($c->ended_at ? ', ended after '.$c->created_at->diffInSeconds($c->ended_at).' s' : ''));
            foreach ([[$c->caller_id, $c->callee_id, 'caller'], [$c->callee_id, $c->caller_id, 'callee']] as [$from, $to, $who]) {
                $msgs = Cache::get("call:{$c->id}:to:{$to}");
                if ($msgs === null) {
                    $this->line("  {$who}: nothing kept (more than an hour ago, or nothing sent)");

                    continue;
                }
                [$summary, $states] = $this->summary($msgs);
                $this->line("  {$who}: {$summary}");
                if ($states) {
                    $this->line('      phone said: '.implode(' → ', $states));
                }
            }
        }
        $this->line('');
        $this->line('host = phone\'s own network · srflx = its public address · relay = through the call relay (needs the ports open).');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{type: string, payload: array}>  $msgs
     * @return array{0: string, 1: string[]} the summary line and what the phone reported (media_ok, connected, errors…)
     */
    public function summary(array $msgs): array
    {
        $types = ['offer' => 0, 'answer' => 0];
        $cands = ['host' => 0, 'srflx' => 0, 'prflx' => 0, 'relay' => 0];
        $relayProto = [];
        $states = [];
        foreach ($msgs as $m) {
            $lines = [];
            if ($m['type'] === 'state') {
                $states[] = ($m['payload']['state'] ?? '?').(isset($m['payload']['detail']) ? ' ('.$m['payload']['detail'].')' : '');

                continue;
            }
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

        return [implode(' · ', $out), $states];
    }
}
