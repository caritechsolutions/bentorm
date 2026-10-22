<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\GatewayType;
use App\Http\Controllers\Controller;
use App\Models\Gateway;
use App\Plugins\PluginManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GatewayAdminController extends Controller
{
    public function __construct(private readonly PluginManager $plugins) {}

    /** GET /admin/plugins?type= — installed plugins and their config forms. */
    public function plugins(Request $request): JsonResponse
    {
        $type = $request->query('type') ? GatewayType::from($request->query('type')) : null;

        return response()->json(['plugins' => $this->plugins->available($type)]);
    }

    /** GET /admin/gateways?type= */
    public function index(Request $request): JsonResponse
    {
        $q = Gateway::query()->orderBy('type')->orderByDesc('priority');
        if ($t = $request->query('type')) {
            $q->where('type', $t);
        }

        return response()->json($q->get()->map(fn (Gateway $g) => $this->present($g))->values());
    }

    /** POST /admin/gateways {plugin, name, config, service_area_id?, priority?, is_active?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plugin' => ['required', 'string', Rule::in(array_keys($this->plugins->registry()))],
            'name' => ['required', 'string', 'max:128'],
            'config' => ['nullable', 'array'],
            'service_area_id' => ['nullable', 'exists:service_areas,id'],
            'priority' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $class = $this->plugins->classFor($data['plugin']);

        $g = Gateway::query()->create([
            'type' => $class::type(),
            'plugin' => $data['plugin'],
            'name' => $data['name'],
            'config' => $this->plugins->prepareConfig($data['plugin'], $data['config'] ?? []),
            'service_area_id' => $data['service_area_id'] ?? null,
            'priority' => $data['priority'] ?? 0,
            'is_active' => $data['is_active'] ?? false,
        ]);

        return response()->json($this->present($g), 201);
    }

    /** PATCH /admin/gateways/{gateway} — secrets sent back masked or empty are kept unchanged. */
    public function update(Request $request, Gateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'config' => ['sometimes', 'array'],
            'service_area_id' => ['nullable', 'exists:service_areas,id'],
            'priority' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('config', $data)) {
            $data['config'] = $this->plugins->prepareConfig($gateway->plugin, $data['config'], $gateway->config ?? []);
        }
        $gateway->update($data);

        return response()->json($this->present($gateway->fresh()));
    }

    /** DELETE /admin/gateways/{gateway} */
    public function destroy(Gateway $gateway): JsonResponse
    {
        if ($gateway->type === GatewayType::Payment && \App\Models\Payment::query()->where('gateway_id', $gateway->id)->exists()) {
            abort(409, 'This gateway has payments. Deactivate it instead.');
        }
        $gateway->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /** POST /admin/gateways/{gateway}/test {to?} — phone for SMS, email address for email. */
    public function test(Request $request, Gateway $gateway): JsonResponse
    {
        $data = $request->validate(['to' => ['nullable', 'string', 'max:255']]);
        try {
            $result = $this->plugins->make($gateway)->test(array_filter(['to' => $data['to'] ?? null]));
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        $gateway->forceFill(['last_tested_at' => now(), 'last_test_result' => mb_substr(($result['ok'] ? 'OK: ' : 'FAIL: ').$result['message'], 0, 255)])->save();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    private function present(Gateway $g): array
    {
        return [
            'id' => $g->id,
            'type' => $g->type,
            'plugin' => $g->plugin,
            'name' => $g->name,
            'config' => $this->plugins->maskedConfig($g),
            'service_area_id' => $g->service_area_id,
            'priority' => $g->priority,
            'is_active' => $g->is_active,
            'webhook_url' => $g->type === GatewayType::Payment ? route('payments.webhook', ['gateway' => $g->id]) : null,
            'last_tested_at' => $g->last_tested_at?->toIso8601String(),
            'last_test_result' => $g->last_test_result,
        ];
    }
}
