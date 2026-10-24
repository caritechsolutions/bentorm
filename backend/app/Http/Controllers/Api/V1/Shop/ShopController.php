<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Enums\StoreStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopCategory;
use App\Models\Store;
use App\Services\GeoService;
use App\Services\OrderService;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Customer side of the Shop: browse, price a cart, order, follow it. */
class ShopController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    /** GET /shop/categories?lat=&lng= — with how many stores are open now (near the customer, when given). */
    public function categories(Request $request): JsonResponse
    {
        $stores = $this->visibleStores($request)->get();

        return response()->json(ShopCategory::query()->where('is_active', true)->orderBy('sort_order')->get()->map(fn (ShopCategory $c) => [
            'slug' => $c->slug, 'name' => $c->name, 'description' => $c->description, 'icon' => $c->icon, 'image_url' => $c->imageUrl(),
            'stores' => $stores->where('shop_category_id', $c->id)->count(),
            'open_now' => $stores->where('shop_category_id', $c->id)->filter(fn (Store $s) => $s->isAcceptingOrders())->count(),
        ])->values());
    }

    /** GET /shop/stores?category=&q=&lat=&lng= — open ones first, then nearest. */
    public function stores(Request $request): JsonResponse
    {
        $data = $request->validate(['category' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:60']]);
        $q = $this->visibleStores($request)->with('category');
        if (filled($data['category'] ?? null)) {
            $q->whereHas('category', fn ($c) => $c->where('slug', $data['category']));
        }
        if (filled($data['q'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($data['q'])).'%';
            $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('description', 'like', $term)
                ->orWhereHas('products', fn ($p) => $p->where('is_available', true)->where('name', 'like', $term)));
        }

        return response()->json($q->get()->map(fn (Store $s) => $this->storeSummary($s, $request))
            ->sortBy([fn ($a, $b) => $b['open_now'] <=> $a['open_now'], fn ($a, $b) => ($a['distance_km'] ?? 999) <=> ($b['distance_km'] ?? 999)])
            ->values());
    }

    /** GET /shop/stores/{slug} — the menu. */
    public function store(Request $request, string $slug): JsonResponse
    {
        $store = $this->visibleStores($request, withinArea: false)->where('slug', $slug)->firstOrFail();
        $sections = $store->sections()->orderBy('sort_order')->get();
        $products = $store->products()->orderBy('sort_order')->orderBy('name')->get();
        $item = fn (Product $p) => [
            'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'price' => $p->price, 'photo_url' => $p->photoUrl(),
            'size' => $p->size, 'available' => $p->is_available, 'options' => $p->options ?? [],
        ];
        $groups = $sections->map(fn (MenuSection $sec) => ['id' => $sec->id, 'name' => $sec->name,
            'products' => $products->where('menu_section_id', $sec->id)->map($item)->values()])->filter(fn ($g) => $g['products']->isNotEmpty())->values();
        $loose = $products->whereNull('menu_section_id')->map($item)->values();
        if ($loose->isNotEmpty()) {
            $groups->push(['id' => null, 'name' => $groups->isEmpty() ? 'Menu' : 'More', 'products' => $loose]);
        }

        return response()->json($this->storeSummary($store, $request) + [
            'phone' => $store->phone, 'address' => $store->address, 'lat' => $store->lat, 'lng' => $store->lng,
            'hours' => $store->hours, 'sections' => $groups,
        ]);
    }

    /** POST /shop/quote — prices a cart to an address (nothing is stored). */
    public function quote(Request $request): JsonResponse
    {
        [$store, $data] = $this->cartRequest($request);
        $q = $this->orders->quote($store, $data['items'], (float) $data['lat'], (float) $data['lng']);

        return response()->json([
            'store' => $store->slug, 'currency' => $q['totals']['currency'], 'items' => $q['lines'], 'lines' => $q['breakdown']['lines'],
            'totals' => ['items' => $q['totals']['items_subtotal'], 'items_tax' => $q['totals']['items_tax'], 'delivery' => $q['totals']['delivery_fee'],
                'service_fee' => $q['totals']['service_fee'], 'total' => $q['totals']['total']],
            'prices_include_vat' => $q['breakdown']['prices_include_vat'],
            'distance_m' => $q['route']->distanceM, 'prep_minutes' => $store->currentPrepMinutes(),
            'eta_minutes' => $store->currentPrepMinutes() + (int) ceil($q['route']->durationS / 60) + 10,
        ]);
    }

    /** POST /shop/orders — place it; the card hold decides whether it goes straight to the store. */
    public function place(Request $request): JsonResponse
    {
        [$store, $data] = $this->cartRequest($request, true);
        $order = $this->orders->place($request->user(), $store, $data['items'], (float) $data['lat'], (float) $data['lng'],
            $data['address'] ?? null, $data['note'] ?? null, $data['payment_method_id'] ?? null);

        return (new OrderResource($order->fresh(['items', 'store', 'card', 'payments'])))->response()->setStatusCode(201);
    }

    /** GET /shop/orders — history, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection(Order::query()->where('customer_id', $request->user()->id)
            ->where('status', '!=', 'pending_payment')->orWhere(fn ($q) => $q->where('customer_id', $request->user()->id)->where('created_at', '>', now()->subHour()))
            ->with(['items', 'store', 'card', 'payments'])->latest('id')->paginate(20));
    }

    /** GET /shop/orders/active — orders still in progress (the app shows a banner for each). */
    public function active(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection(Order::query()->where('customer_id', $request->user()->id)
            ->whereIn('status', ['pending_payment', 'placed', 'accepted', 'ready'])->with(['items', 'store', 'card', 'payments'])->latest('id')->get());
    }

    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless($order->customer_id === $request->user()->id, 404);

        return new OrderResource($order->load(['items', 'store', 'card', 'payments']));
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        return new OrderResource($this->orders->cancelByCustomer($order, $request->user())->load(['card', 'payments']));
    }

    /** POST /shop/orders/{id}/change {accept: bool} — the store asked to take items off. */
    public function respondToChange(Request $request, Order $order): OrderResource
    {
        $data = $request->validate(['accept' => ['required', 'boolean']]);

        return new OrderResource($this->orders->respondToChange($order, $request->user(), (bool) $data['accept'])->load(['card', 'payments']));
    }

    // ---------------------------------------------------------------- helpers

    /** Approved stores; with lat/lng given, only those in that service area. */
    private function visibleStores(Request $request, bool $withinArea = true)
    {
        $q = Store::query()->where('status', StoreStatus::Approved->value)->with('serviceArea');
        if ($withinArea && $request->filled(['lat', 'lng'])) {
            $area = app(GeoService::class)->resolve((float) $request->input('lat'), (float) $request->input('lng'))->service;
            $q->where('service_area_id', $area?->id ?? 0);
        }

        return $q;
    }

    private function storeSummary(Store $s, Request $request): array
    {
        $km = $request->filled(['lat', 'lng']) && $s->lat !== null
            ? round(Geo::haversine((float) $s->lat, (float) $s->lng, (float) $request->input('lat'), (float) $request->input('lng')) / 1000, 1) : null;

        return [
            'slug' => $s->slug, 'name' => $s->name, 'description' => $s->description, 'category' => $s->category?->slug,
            'logo_url' => $s->logoUrl(), 'cover_url' => $s->coverUrl(), 'open_now' => $s->isAcceptingOrders(),
            'prep_minutes' => $s->currentPrepMinutes(), 'min_order' => $s->min_order, 'currency' => $s->serviceArea?->currency ?? 'BBD',
            'distance_km' => $km, 'delivers_here' => $km === null ? null : $km <= $s->delivery_radius_km,
        ];
    }

    /** @return array{0: Store, 1: array} */
    private function cartRequest(Request $request, bool $placing = false): array
    {
        $data = $request->validate([
            'store' => ['required', 'string'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.options.*.group' => ['required', 'string'],
            'items.*.options.*.choices' => ['required', 'array'],
            'items.*.note' => ['nullable', 'string', 'max:200'],
        ] + ($placing ? [
            'address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'payment_method_id' => ['nullable', 'string'],
        ] : []));
        $store = Store::query()->where('slug', $data['store'])->where('status', StoreStatus::Approved->value)->first();
        abort_unless($store, 404, 'This store is not available.');

        return [$store, $data];
    }
}
