<?php

namespace App\Plugins;

use App\Enums\GatewayType;
use App\Models\Gateway;
use App\Plugins\Contracts\EmailPlugin;
use App\Plugins\Contracts\PaymentPlugin;
use App\Plugins\Contracts\Plugin;
use App\Plugins\Contracts\SmsPlugin;
use App\Plugins\Email\LogEmail;
use App\Plugins\Sms\LogSms;
use Illuminate\Validation\ValidationException;

class PluginManager
{
    public const MASK = '••••••••';

    /** @return array<string, class-string<Plugin>> key => class */
    public function registry(): array
    {
        $out = [];
        foreach (config('plugins', []) as $class) {
            if (is_subclass_of($class, Plugin::class)) {
                $out[$class::key()] = $class;
            }
        }

        return $out;
    }

    /** Metadata for the admin panel. */
    public function available(?GatewayType $type = null): array
    {
        $list = [];
        foreach ($this->registry() as $key => $class) {
            if ($type && $class::type() !== $type) {
                continue;
            }
            $list[] = [
                'key' => $key,
                'name' => $class::name(),
                'type' => $class::type()->value,
                'description' => $class::description(),
                'config_schema' => $class::configSchema(),
                'supports_refunds' => is_subclass_of($class, PaymentPlugin::class) ? $class::supportsRefunds() : null,
                'supports_saved_cards' => is_subclass_of($class, PaymentPlugin::class) ? $class::supportsSavedCards() : null,
            ];
        }

        return $list;
    }

    public function classFor(string $key): string
    {
        return $this->registry()[$key] ?? throw ValidationException::withMessages(['plugin' => ["Unknown plugin '{$key}'."]]);
    }

    public function make(Gateway $gateway): Plugin
    {
        $class = $this->classFor($gateway->plugin);

        return new $class($gateway->config ?? [], $gateway);
    }

    /**
     * Active gateway of a type. Area-specific gateways win over global ones (service_area_id NULL),
     * then higher priority.
     */
    public function gatewayFor(GatewayType $type, ?int $serviceAreaId = null): ?Gateway
    {
        return Gateway::query()->where('type', $type->value)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('service_area_id')->when($serviceAreaId, fn ($q2) => $q2->orWhere('service_area_id', $serviceAreaId)))
            ->orderByRaw('service_area_id IS NULL')  // area-specific first
            ->orderByDesc('priority')->orderBy('id')
            ->get()
            ->first(fn (Gateway $g) => isset($this->registry()[$g->plugin]));
    }

    public function paymentGatewayFor(?int $serviceAreaId): ?Gateway
    {
        return $this->gatewayFor(GatewayType::Payment, $serviceAreaId);
    }

    public function sms(?int $serviceAreaId = null): SmsPlugin
    {
        $g = $this->gatewayFor(GatewayType::Sms, $serviceAreaId);

        return $g ? $this->make($g) : new LogSms;
    }

    public function email(?int $serviceAreaId = null): ?EmailPlugin
    {
        $g = $this->gatewayFor(GatewayType::Email, $serviceAreaId);

        return $g ? $this->make($g) : null;
    }

    public function push(?int $serviceAreaId = null): ?\App\Plugins\Contracts\PushPlugin
    {
        $g = $this->gatewayFor(GatewayType::Push, $serviceAreaId);

        return $g ? $this->make($g) : null;
    }

    public function payment(Gateway $gateway): PaymentPlugin
    {
        $p = $this->make($gateway);
        if (! $p instanceof PaymentPlugin) {
            throw new \LogicException("Gateway {$gateway->id} is not a payment gateway.");
        }

        return $p;
    }

    // ------------------------------------------------------------------ config helpers

    /** Validate and merge incoming config over existing, keeping secrets that come back masked or empty. */
    public function prepareConfig(string $pluginKey, array $incoming, array $existing = []): array
    {
        $schema = $this->classFor($pluginKey)::configSchema();
        $out = [];
        $errors = [];

        foreach ($schema as $f) {
            $k = $f['key'];
            $has = array_key_exists($k, $incoming);
            $v = $has ? $incoming[$k] : ($existing[$k] ?? ($f['default'] ?? null));

            if (($f['type'] ?? '') === 'secret' && ($v === self::MASK || $v === '' || $v === null) && isset($existing[$k])) {
                $v = $existing[$k];
            }

            $v = match ($f['type'] ?? 'string') {
                'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                'number' => $v === null || $v === '' ? null : (is_numeric($v) ? $v + 0 : ($errors[$k] = "{$f['label']} must be a number.")),
                default => $v === null ? null : (string) $v,
            };

            if (($f['required'] ?? false) && ($v === null || $v === '')) {
                $errors[$k] = "{$f['label']} is required.";
            }
            if (($f['type'] ?? '') === 'select' && $v !== null && $v !== '' && ! in_array($v, $f['options'] ?? [], true)) {
                $errors[$k] = "{$f['label']} must be one of: ".implode(', ', $f['options']);
            }
            if (($f['type'] ?? '') === 'url' && $v && ! filter_var($v, FILTER_VALIDATE_URL)) {
                $errors[$k] = "{$f['label']} must be a URL.";
            }
            if (($f['type'] ?? '') === 'email' && $v && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[$k] = "{$f['label']} must be an email address.";
            }
            $out[$k] = $v;
        }

        if ($errors) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ["config.{$k}" => [$m]])->all());
        }

        return $out;
    }

    /** Config for API output with secrets masked. */
    public function maskedConfig(Gateway $gateway): array
    {
        $schema = isset($this->registry()[$gateway->plugin]) ? $this->classFor($gateway->plugin)::configSchema() : [];
        $config = $gateway->config ?? [];
        foreach ($schema as $f) {
            if (($f['type'] ?? '') === 'secret') {
                $config[$f['key']] = ! empty($config[$f['key']]) ? self::MASK : null;
            }
        }

        return $config;
    }
}
