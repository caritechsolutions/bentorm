<?php

namespace App\Plugins;

use App\Models\Gateway;

abstract class AbstractPlugin
{
    public function __construct(protected array $config = [], protected ?Gateway $gateway = null)
    {
        foreach (static::configSchema() as $field) {
            if (! array_key_exists($field['key'], $this->config) && array_key_exists('default', $field)) {
                $this->config[$field['key']] = $field['default'];
            }
        }
    }

    abstract public static function configSchema(): array;

    protected function cfg(string $key, mixed $default = null): mixed
    {
        $v = $this->config[$key] ?? null;

        return ($v === null || $v === '') ? $default : $v;
    }

    public static function description(): string
    {
        return '';
    }
}
