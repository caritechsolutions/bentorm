// Shop data models (shapes pinned by test/fixtures/shop_*.json, captured from the real backend).

double _d(dynamic v, [double fallback = 0]) => v == null ? fallback : (v is num ? v.toDouble() : double.tryParse('$v') ?? fallback);
int? _in(dynamic v) => v == null ? null : (v is num ? v.toInt() : int.tryParse('$v'));
String? _s(dynamic v) => v?.toString();
DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();
Map<String, dynamic> _m(dynamic v) => v is Map<String, dynamic> ? v : <String, dynamic>{};
List<dynamic> _l(dynamic v) => v is List ? v : const [];

class ShopCategory {
  ShopCategory({required this.slug, required this.name, this.description, this.icon, this.imageUrl, required this.stores, required this.openNow});

  final String slug;
  final String name;
  final String? description;
  final String? icon;
  final String? imageUrl;
  final int stores;
  final int openNow;

  factory ShopCategory.fromJson(Map<String, dynamic> j) => ShopCategory(
        slug: _s(j['slug']) ?? '',
        name: _s(j['name']) ?? '',
        description: _s(j['description']),
        icon: _s(j['icon']),
        imageUrl: _s(j['image_url']),
        stores: _in(j['stores']) ?? 0,
        openNow: _in(j['open_now']) ?? 0,
      );
}

class StoreSummary {
  StoreSummary({
    required this.slug,
    required this.name,
    this.description,
    this.category,
    this.logoUrl,
    this.coverUrl,
    required this.openNow,
    required this.prepMinutes,
    required this.minOrder,
    required this.currency,
    this.distanceKm,
    this.deliversHere,
  });

  final String slug;
  final String name;
  final String? description;
  final String? category;
  final String? logoUrl;
  final String? coverUrl;
  final bool openNow;
  final int prepMinutes;
  final double minOrder;
  final String currency;
  final double? distanceKm;
  final bool? deliversHere;

  factory StoreSummary.fromJson(Map<String, dynamic> j) => StoreSummary(
        slug: _s(j['slug']) ?? '',
        name: _s(j['name']) ?? '',
        description: _s(j['description']),
        category: _s(j['category']),
        logoUrl: _s(j['logo_url']),
        coverUrl: _s(j['cover_url']),
        openNow: j['open_now'] == true,
        prepMinutes: _in(j['prep_minutes']) ?? 0,
        minOrder: _d(j['min_order']),
        currency: _s(j['currency']) ?? 'BBD',
        distanceKm: j['distance_km'] == null ? null : _d(j['distance_km']),
        deliversHere: j['delivers_here'] is bool ? j['delivers_here'] as bool : null,
      );

  /// "20 min · 9.4 km" style line.
  String get facts => [
        if (openNow) 'About $prepMinutes min to prepare' else 'Closed now',
        if (distanceKm != null) '${distanceKm!.toStringAsFixed(1)} km',
      ].join(' · ');
}

class OptionChoice {
  const OptionChoice(this.name, this.price);
  final String name;
  final double price;
}

class OptionGroup {
  const OptionGroup({required this.name, required this.required, required this.multiple, required this.choices});
  final String name;
  final bool required;
  final bool multiple;
  final List<OptionChoice> choices;

  factory OptionGroup.fromJson(Map<String, dynamic> j) => OptionGroup(
        name: _s(j['name']) ?? '',
        required: j['required'] == true,
        multiple: j['multiple'] == true,
        choices: [for (final c in _l(j['choices'])) OptionChoice(_s(_m(c)['name']) ?? '', _d(_m(c)['price']))],
      );
}

class Product {
  Product({required this.id, required this.name, this.description, required this.price, this.photoUrl, required this.available, required this.options});

  final int id;
  final String name;
  final String? description;
  final double price;
  final String? photoUrl;
  final bool available;
  final List<OptionGroup> options;

  factory Product.fromJson(Map<String, dynamic> j) => Product(
        id: _in(j['id']) ?? 0,
        name: _s(j['name']) ?? '',
        description: _s(j['description']),
        price: _d(j['price']),
        photoUrl: _s(j['photo_url']),
        available: j['available'] != false,
        options: [for (final g in _l(j['options'])) OptionGroup.fromJson(_m(g))],
      );
}

class MenuSection {
  MenuSection(this.name, this.products);
  final String name;
  final List<Product> products;
}

class StoreMenu {
  StoreMenu({required this.store, this.phone, this.address, this.lat, this.lng, required this.sections});

  final StoreSummary store;
  final String? phone;
  final String? address;
  final double? lat;
  final double? lng;
  final List<MenuSection> sections;

  factory StoreMenu.fromJson(Map<String, dynamic> j) => StoreMenu(
        store: StoreSummary.fromJson(j),
        phone: _s(j['phone']),
        address: _s(j['address']),
        lat: j['lat'] == null ? null : _d(j['lat']),
        lng: j['lng'] == null ? null : _d(j['lng']),
        sections: [
          for (final s in _l(j['sections'])) MenuSection(_s(_m(s)['name']) ?? 'Menu', [for (final p in _l(_m(s)['products'])) Product.fromJson(_m(p))]),
        ],
      );
}

class MoneyLine {
  const MoneyLine(this.code, this.label, this.amount);
  final String code;
  final String label;
  final double amount;

  factory MoneyLine.fromJson(Map<String, dynamic> j) => MoneyLine(_s(j['code']) ?? '', _s(j['label']) ?? '', _d(j['amount']));
}

class ShopTotals {
  const ShopTotals({required this.items, required this.itemsTax, required this.delivery, required this.serviceFee, required this.total});
  final double items;
  final double itemsTax;
  final double delivery;
  final double serviceFee;
  final double total;

  factory ShopTotals.fromJson(Map<String, dynamic> j) => ShopTotals(
        items: _d(j['items']),
        itemsTax: _d(j['items_tax']),
        delivery: _d(j['delivery']),
        serviceFee: _d(j['service_fee']),
        total: _d(j['total']),
      );
}

/// A priced cart, before ordering.
class ShopQuote {
  ShopQuote({required this.currency, required this.lines, required this.totals, required this.etaMinutes});
  final String currency;
  final List<MoneyLine> lines;
  final ShopTotals totals;
  final int? etaMinutes;

  factory ShopQuote.fromJson(Map<String, dynamic> j) => ShopQuote(
        currency: _s(j['currency']) ?? 'BBD',
        lines: [for (final l in _l(j['lines'])) MoneyLine.fromJson(_m(l))],
        totals: ShopTotals.fromJson(_m(j['totals'])),
        etaMinutes: _in(j['eta_minutes']),
      );
}

class OrderItem {
  OrderItem({required this.id, required this.name, required this.quantity, required this.lineTotal, required this.optionsText, this.note, required this.removed});
  final int id;
  final String name;
  final int quantity;
  final double lineTotal;
  final String optionsText;
  final String? note;
  final bool removed;

  factory OrderItem.fromJson(Map<String, dynamic> j) => OrderItem(
        id: _in(j['id']) ?? 0,
        name: _s(j['name']) ?? '',
        quantity: _in(j['quantity']) ?? 1,
        lineTotal: _d(j['line_total']),
        optionsText: _s(j['options_text']) ?? '',
        note: _s(j['note']),
        removed: j['removed'] == true,
      );
}

/// The store asked to take items off (they ran out).
class ChangeRequest {
  ChangeRequest({required this.status, required this.items, this.note, required this.newTotal});
  final String status; // pending | accepted | declined
  final List<String> items;
  final String? note;
  final double newTotal;

  bool get pending => status == 'pending';

  factory ChangeRequest.fromJson(Map<String, dynamic> j) => ChangeRequest(
        status: _s(j['status']) ?? '',
        items: [
          for (final i in _l(j['items'])) (_in(_m(i)['quantity']) ?? 1) > 1 ? '${_in(_m(i)['quantity'])}× ${_s(_m(i)['name'])}' : (_s(_m(i)['name']) ?? ''),
        ],
        note: _s(j['note']),
        newTotal: _d(j['new_total']),
      );
}

/// Where the delivery stands, the driver, and the hand-over PIN (only the customer gets it).
class DeliveryState {
  DeliveryState({this.status, this.driverName, this.driverPhone, this.vehicle, this.driverLat, this.driverLng, this.pin, required this.noDriverAlert, required this.canCancel});

  /// searching · driver_assigned · at_store · on_the_way · delivered (null before a driver is asked for)
  final String? status;
  final String? driverName;
  final String? driverPhone;
  final String? vehicle; // "Blue Toyota Corolla · PB123"
  final double? driverLat;
  final double? driverLng;
  final String? pin;
  final bool noDriverAlert;
  final bool canCancel;

  bool get hasDriver => driverName != null;

  static DeliveryState? fromJson(dynamic v) {
    if (v is! Map<String, dynamic>) return null;
    final d = _m(v['driver']);
    final car = _m(d['vehicle']);
    final pos = _m(v['driver_position']);
    final carText = car.isEmpty
        ? null
        : [
            [_s(car['colour']), _s(car['make']), _s(car['model'])].whereType<String>().where((x) => x.isNotEmpty).join(' '),
            if (_s(car['plate']) != null) _s(car['plate'])!,
          ].where((x) => x.isNotEmpty).join(' · ');
    return DeliveryState(
      status: _s(v['status']),
      driverName: d.isEmpty ? null : _s(d['name']),
      driverPhone: _s(d['phone']),
      vehicle: carText,
      driverLat: pos.isEmpty ? null : _d(pos['lat']),
      driverLng: pos.isEmpty ? null : _d(pos['lng']),
      pin: _s(v['pin']),
      noDriverAlert: v['no_driver_alert'] == true,
      canCancel: v['can_cancel'] == true,
    );
  }
}

class Order {
  Order({
    required this.id,
    required this.number,
    required this.status,
    required this.statusLabel,
    required this.storeName,
    this.storeSlug,
    this.storePhone,
    required this.items,
    required this.currency,
    required this.totals,
    required this.lines,
    this.address,
    this.note,
    this.prepMinutes,
    this.changeRequest,
    this.declineReason,
    this.cancelledBy,
    required this.paymentStatus,
    this.cardLabel,
    this.redirectUrl,
    this.createdAt,
    this.expiresAt,
    this.readyBy,
    this.delivery,
    this.storeLat,
    this.storeLng,
    this.dropoffLat,
    this.dropoffLng,
  });

  final DeliveryState? delivery;
  final double? storeLat;
  final double? storeLng;
  final double? dropoffLat;
  final double? dropoffLng;

  final String id;
  final String number;
  final String status; // pending_payment | placed | accepted | ready | declined | cancelled | expired
  final String statusLabel;
  final String storeName;
  final String? storeSlug;
  final String? storePhone;
  final List<OrderItem> items;
  final String currency;
  final ShopTotals totals;
  final List<MoneyLine> lines;
  final String? address;
  final String? note;
  final int? prepMinutes;
  final ChangeRequest? changeRequest;
  final String? declineReason;
  final String? cancelledBy;
  final String? paymentStatus;
  final String? cardLabel;
  final String? redirectUrl;
  final DateTime? createdAt;
  final DateTime? expiresAt;
  final DateTime? readyBy;

  bool get isActive => const ['pending_payment', 'placed', 'accepted', 'ready', 'picked_up'].contains(status);
  bool get canCancel => delivery?.canCancel ?? (status == 'pending_payment' || status == 'placed');
  bool get needsBank => status == 'pending_payment' && redirectUrl != null;
  bool get hasPendingChange => changeRequest?.pending == true;

  /// Step on the progress bar: 0 sent, 1 preparing, 2 ready, 3 on the way, 4 delivered.
  int get step => switch (status) { 'accepted' => 1, 'ready' => 2, 'picked_up' => 3, 'delivered' => 4, _ => 0 };

  String get _driver => delivery?.driverName ?? 'Your driver';

  /// One line for what's happening now.
  String get headline => switch (status) {
        'pending_payment' => 'Confirm the payment with your bank',
        'placed' => 'Waiting for $storeName to accept',
        'accepted' || 'ready' when delivery?.status == 'at_store' => '$_driver is collecting your order',
        'accepted' || 'ready' when delivery?.status == 'driver_assigned' => '$_driver will collect your order',
        'accepted' || 'ready' when delivery?.noDriverAlert == true => 'Still looking for a driver',
        'accepted' => '$storeName is preparing your order',
        'ready' => 'Your order is packed and ready',
        'picked_up' => '$_driver is on the way with your order',
        'delivered' => 'Delivered — enjoy!',
        'declined' => '$storeName couldn\'t take your order',
        'expired' => '$storeName didn\'t answer in time',
        'cancelled' => cancelledBy == 'system' ? 'Your card wasn\'t accepted' : 'Order cancelled',
        _ => statusLabel,
      };

  factory Order.fromJson(Map<String, dynamic> j) {
    final store = _m(j['store']);
    final payment = _m(j['payment']);
    final card = _m(payment['card']);
    final ts = _m(j['timestamps']);
    final change = j['change_request'];
    return Order(
      id: _s(j['id']) ?? '',
      number: _s(j['number']) ?? '',
      status: _s(j['status']) ?? '',
      statusLabel: _s(j['status_label']) ?? '',
      storeName: _s(store['name']) ?? 'Store',
      storeSlug: _s(store['slug']),
      storePhone: _s(store['phone']),
      items: [for (final i in _l(j['items'])) OrderItem.fromJson(_m(i))],
      currency: _s(j['currency']) ?? 'BBD',
      totals: ShopTotals.fromJson(_m(j['totals'])),
      lines: [for (final l in _l(j['lines'])) MoneyLine.fromJson(_m(l))],
      address: _s(_m(j['dropoff'])['address']),
      note: _s(j['note']),
      prepMinutes: _in(j['prep_minutes']),
      changeRequest: change is Map<String, dynamic> ? ChangeRequest.fromJson(change) : null,
      declineReason: _s(j['decline_reason']),
      cancelledBy: _s(j['cancelled_by']),
      paymentStatus: _s(payment['status']),
      cardLabel: card.isEmpty ? null : '${_s(card['brand']) ?? 'Card'} •••• ${_s(card['last4']) ?? ''}',
      redirectUrl: _s(payment['redirect_url']),
      createdAt: _dt(ts['created_at']),
      expiresAt: _dt(ts['expires_at']),
      readyBy: _dt(ts['ready_by']),
      delivery: DeliveryState.fromJson(j['delivery']),
      storeLat: store['lat'] == null ? null : _d(store['lat']),
      storeLng: store['lng'] == null ? null : _d(store['lng']),
      dropoffLat: _m(j['dropoff'])['lat'] == null ? null : _d(_m(j['dropoff'])['lat']),
      dropoffLng: _m(j['dropoff'])['lng'] == null ? null : _d(_m(j['dropoff'])['lng']),
    );
  }
}
