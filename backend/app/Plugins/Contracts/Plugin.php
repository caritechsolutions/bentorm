<?php

namespace App\Plugins\Contracts;

use App\Enums\GatewayType;

/**
 * A plugin is a class; a Gateway row is a configured instance of it.
 *
 * configSchema() returns field definitions the admin panel renders as a form:
 *   ['key' => 'api_key', 'label' => 'API key', 'type' => 'string|secret|bool|number|select|text|url|email',
 *    'required' => true, 'options' => [...] (select), 'default' => ..., 'help' => '...']
 * Fields of type "secret" are encrypted at rest and never returned in full by the API.
 */
interface Plugin
{
    public static function key(): string;

    public static function name(): string;

    public static function type(): GatewayType;

    public static function description(): string;

    public static function configSchema(): array;

    /**
     * Connectivity / credential check. $context may carry a destination (phone or email) for a real test message.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(array $context = []): array;
}
