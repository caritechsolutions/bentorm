<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Checks the call relay from the outside. Prints a login that works for 30 minutes and the command to run on any
 * Linux machine OUTSIDE your network (scripts/check-turn.sh), which tests port 3478 (UDP and TCP) and the relay ports.
 */
class TurnTest extends Command
{
    protected $signature = 'bentorm:turn-test {--minutes=30}';

    protected $description = 'Print a short-lived call-relay login and the command to test the relay from outside';

    public function handle(): int
    {
        $urls = array_values(config('bentorm.calls.turn'));
        $secret = config('bentorm.calls.turn_secret');
        if (! $urls || ! $secret) {
            $this->error('The call relay is not set up (TURN_URLS / TURN_SECRET). Run the update with TURN_HOST=turn.yourdomain.');

            return self::FAILURE;
        }
        preg_match('/^turns?:([^:?]+)/', $urls[0], $m);
        $host = $m[1] ?? 'turn.example';
        $user = (now()->addMinutes(max(1, (int) $this->option('minutes')))->timestamp).':relay-test';
        $pass = base64_encode(hash_hmac('sha1', $user, $secret, true));

        $resolved = gethostbyname($host);
        $this->line("Relay: {$host}  (resolves to ".($resolved === $host ? 'NOTHING — add the DNS record' : $resolved).')');
        $conf = @file_get_contents('/etc/turnserver.conf');
        if ($conf !== false && preg_match('/^external-ip=(\S+)/m', $conf, $e)) {
            $this->line("Relay tells phones its address is: {$e[1]} (public/private)");
        }
        $this->line('');
        $this->line('Run this on a Linux machine OUTSIDE your network (as root), within '.(int) $this->option('minutes').' minutes:');
        $this->line('');
        $this->line(sprintf(" curl -fsSL \"https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/check-turn.sh?\$(date +%%s)\" | sudo bash -s -- %s '%s' '%s'", $host, $user, $pass));
        $this->line('');
        $this->line('(The login expires by itself; it only lets someone use the relay, nothing else.)');

        return self::SUCCESS;
    }
}
