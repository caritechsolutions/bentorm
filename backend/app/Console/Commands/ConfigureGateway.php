<?php

namespace App\Console\Commands;

use App\Models\Gateway;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a gateway from the command line (used by the installer). Same validation and
 * encrypted storage as the admin panel; secrets left out keep their saved value.
 *
 *   php artisan bentorm:gateway smtp --name="Ride Coop email" --set host=smtp.example.com --set port=465 ... --test
 *   php artisan bentorm:gateway fcm --name="Push (Firebase)" --set-file service_account=/root/firebase.json --test
 */
class ConfigureGateway extends Command
{
    protected $signature = 'bentorm:gateway {plugin : Plugin key, e.g. smtp, twilio}
        {--name= : Gateway name (default: the plugin name)}
        {--priority=10 : Higher wins among active gateways of the same type}
        {--set=* : Config value as key=value (repeatable)}
        {--set-file=* : Config value read from a file, as key=/path/to/file (keeps long secrets off the command line)}
        {--test : Run the plugin\'s connection test afterwards and record the result}';

    protected $description = 'Create or update a payment / SMS / email / push gateway';

    public function handle(PluginManager $plugins): int
    {
        $key = (string) $this->argument('plugin');
        if (! isset($plugins->registry()[$key])) {
            $this->error("Unknown plugin '{$key}'. Available: ".implode(', ', array_keys($plugins->registry())));

            return self::FAILURE;
        }
        $class = $plugins->classFor($key);

        $incoming = [];
        foreach ((array) $this->option('set') as $pair) {
            if (! str_contains($pair, '=')) {
                $this->error("--set expects key=value, got '{$pair}'");

                return self::FAILURE;
            }
            [$k, $v] = explode('=', $pair, 2);
            $incoming[trim($k)] = $v;
        }
        foreach ((array) $this->option('set-file') as $pair) {
            [$k, $path] = array_pad(explode('=', $pair, 2), 2, '');
            if ($path === '' || ! is_readable($path)) {
                $this->error("--set-file: cannot read '{$path}'");

                return self::FAILURE;
            }
            $incoming[trim($k)] = trim((string) file_get_contents($path));
        }

        $gateway = Gateway::query()->where('plugin', $key)
            ->when($this->option('name'), fn ($q, $n) => $q->where('name', $n))
            ->orderBy('id')->first() ?? new Gateway(['plugin' => $key, 'type' => $class::type()->value]);

        try {
            $config = $plugins->prepareConfig($key, $incoming, $gateway->config ?? []);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $created = ! $gateway->exists;
        $gateway->fill([
            'name' => $this->option('name') ?: ($gateway->name ?: $class::name()),
            'config' => $config,
            'priority' => (int) $this->option('priority'),
            'is_active' => true,
        ])->save();
        $this->line(($created ? 'Created' : 'Updated')." {$gateway->type->value} gateway #{$gateway->id} \"{$gateway->name}\" ({$key})");

        if ($this->option('test')) {
            try {
                $result = $plugins->make($gateway)->test([]);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }
            $summary = mb_substr(($result['ok'] ? 'OK: ' : 'FAIL: ').$result['message'], 0, 255);
            $gateway->forceFill(['last_tested_at' => now(), 'last_test_result' => $summary])->save();
            $this->line('Test: '.$summary);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
