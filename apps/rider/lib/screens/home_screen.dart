import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../api/shop_models.dart';
import '../brand.dart';
import '../config.dart';
import '../state/scope.dart';
import '../widgets/ride_map.dart';
import 'cards_screen.dart';
import 'history_screen.dart';
import 'search_screen.dart';
import 'shop/order_screen.dart';
import 'shop/orders_screen.dart';
import 'shop/shop_screen.dart';
import 'trip_screen.dart';

/// Book a ride: pickup → where to → choose a vehicle → pay → request.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  LatLngPoint? _me;
  Place? _pickup;
  Place? _dropoff;
  final List<Place> _stops = [];
  static const maxStops = 2;
  Estimate? _estimate;
  String? _estimateError;
  bool _estimating = false;
  String? _selected;
  int _passengers = 1;
  int _luggage = 0;
  final Set<String> _features = {};
  List<PaymentCard> _cards = const [];
  String _payment = 'cash'; // 'cash' or a card id
  bool _requesting = false;
  bool _started = false;
  List<Order> _orders = const [];

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!_started) {
      _started = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => _init());
    }
  }

  Future<void> _init() async {
    final scope = AppScope.of(context);
    // Resume a ride in progress (app restarted mid-trip).
    try {
      final active = await scope.api.activeTrip();
      if (active.trip != null && mounted) {
        await _openTrip(active.trip!);
      }
    } on ApiException {
      // Offline: carry on to booking; the request will surface the problem.
    }
    _loadCards();
    _loadOrders();
    final me = await scope.location.current();
    if (!mounted) return;
    final point = me ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng);
    setState(() => _me = me);
    if (_pickup == null) {
      final here = await scope.api.reverse(point.lat, point.lng);
      if (!mounted || _pickup != null) return;
      setState(() => _pickup = here ?? Place(name: me != null ? 'Current location' : 'Bridgetown', lat: point.lat, lng: point.lng));
    }
  }

  Future<void> _loadCards() async {
    try {
      final cards = await AppScope.of(context).api.cards();
      if (!mounted) return;
      setState(() {
        _cards = cards.where((c) => c.usable).toList();
        if (_payment != 'cash' && !_cards.any((c) => c.id == _payment)) _payment = 'cash';
      });
    } on ApiException {
      // Cash still works.
    }
  }

  /// Shop orders still in progress, shown as banners on the start panel.
  Future<void> _loadOrders() async {
    try {
      final orders = await AppScope.of(context).api.activeOrders();
      if (mounted) setState(() => _orders = orders);
    } on ApiException {
      // Not essential: the banner just doesn't show.
    }
  }

  Future<void> _openShop() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ShopScreen()));
    if (mounted) _loadOrders();
  }

  Future<void> _openOrder(Order o) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(initial: o)));
    if (mounted) _loadOrders();
  }

  Future<void> _choosePickup() async {
    final here = _me == null ? null : await AppScope.of(context).api.reverse(_me!.lat, _me!.lng);
    if (!mounted) return;
    final place = await Navigator.of(context).push<Place>(MaterialPageRoute(
      builder: (_) => SearchScreen(
        title: 'Pickup',
        near: _me,
        currentLocation: _me == null ? null : (here ?? Place(name: 'Current location', lat: _me!.lat, lng: _me!.lng)),
      ),
    ));
    if (place != null && mounted) {
      setState(() => _pickup = place);
      _quote();
    }
  }

  Future<void> _chooseDropoff() async {
    final place = await Navigator.of(context).push<Place>(MaterialPageRoute(
      builder: (_) => SearchScreen(title: 'Where to?', near: _me ?? (_pickup == null ? null : LatLngPoint(_pickup!.lat, _pickup!.lng))),
    ));
    if (place != null && mounted) {
      setState(() => _dropoff = place);
      _quote();
    }
  }

  Future<void> _addStop() async {
    final place = await Navigator.of(context).push<Place>(MaterialPageRoute(
      builder: (_) => SearchScreen(title: 'Add a stop', near: _me ?? (_pickup == null ? null : LatLngPoint(_pickup!.lat, _pickup!.lng))),
    ));
    if (place != null && mounted) {
      setState(() => _stops.add(place));
      _quote();
    }
  }

  void _removeStop(int i) {
    setState(() => _stops.removeAt(i));
    _quote();
  }

  Future<void> _quote() async {
    if (_pickup == null || _dropoff == null) return;
    setState(() {
      _estimating = true;
      _estimateError = null;
    });
    try {
      final e = await AppScope.of(context).api.estimate(_pickup!, _dropoff!, passengers: _passengers, luggage: _luggage, stops: _stops);
      if (!mounted) return;
      setState(() {
        _estimate = e;
        if (!e.quotes.any((q) => q.vehicleType == _selected)) _selected = e.quotes.isEmpty ? null : e.quotes.first.vehicleType;
        if (e.quotes.isEmpty) _estimateError = 'No vehicle can carry $_passengers riders and $_luggage bags. Try fewer.';
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _estimate = null;
        _estimateError = e.field('pickup') ?? e.field('dropoff') ?? e.field('stops') ?? e.field('stops.0') ?? e.field('stops.1') ?? e.message;
      });
    } finally {
      if (mounted) setState(() => _estimating = false);
    }
  }

  Quote? get _quoteSelected => _estimate?.quotes.where((q) => q.vehicleType == _selected).firstOrNull;

  Future<void> _request() async {
    final q = _quoteSelected;
    if (q == null || _pickup == null || _dropoff == null) return;
    final api = AppScope.of(context).api;
    setState(() => _requesting = true);
    try {
      final trip = await api.requestTrip(
        pickup: _pickup!,
        dropoff: _dropoff!,
        vehicleType: q.vehicleType,
        paymentMethod: _payment == 'cash' ? 'cash' : 'card',
        cardId: _payment == 'cash' ? null : _payment,
        passengers: _passengers,
        luggage: _luggage,
        features: _features.toList(),
        stops: _stops,
      );
      if (!mounted) return;
      await _openTrip(trip);
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(e.status == 402 ? e.message : (e.field('vehicle_type') ?? e.field('passengers') ?? e.field('luggage') ?? e.field('pickup') ?? e.field('dropoff') ?? e.message));
    } finally {
      if (mounted) setState(() => _requesting = false);
    }
  }

  Future<void> _openTrip(Trip trip) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TripScreen(initial: trip)));
    if (!mounted) return;
    // Back from the trip: start fresh from where the rider is.
    setState(() {
      _dropoff = null;
      _stops.clear();
      _estimate = null;
      _estimateError = null;
    });
    _loadCards();
  }

  void _snack(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  Future<void> _choosePayment() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.payments_outlined),
              title: const Text('Cash'),
              subtitle: const Text('Pay the driver at the end of the ride'),
              trailing: _payment == 'cash' ? const Icon(Icons.check, color: Brand.blue) : null,
              onTap: () => Navigator.pop(ctx, 'cash'),
            ),
            for (final c in _cards)
              ListTile(
                leading: const Icon(Icons.credit_card),
                title: Text(c.label),
                subtitle: Text('Expires ${c.expiry}'),
                trailing: _payment == c.id ? const Icon(Icons.check, color: Brand.blue) : null,
                onTap: () => Navigator.pop(ctx, c.id),
              ),
            ListTile(
              leading: const Icon(Icons.add_card),
              title: const Text('Add or manage cards'),
              onTap: () => Navigator.pop(ctx, '_manage'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || choice == null) return;
    if (choice == '_manage') {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CardsScreen()));
      await _loadCards();
      if (mounted && _payment == 'cash' && _cards.isNotEmpty) setState(() => _payment = (_cards.where((c) => c.isDefault).firstOrNull ?? _cards.first).id);
    } else {
      setState(() => _payment = choice);
    }
  }

  Future<void> _chooseFeatures() async {
    const options = {
      'wheelchair_accessible': 'Wheelchair accessible',
      'child_seat': 'Child seat',
      'pet_friendly': 'Pet friendly',
      'extra_luggage': 'Extra luggage space',
    };
    await showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Padding(padding: EdgeInsets.only(bottom: 8), child: Text('Ride needs', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16))),
              for (final e in options.entries)
                CheckboxListTile(
                  title: Text(e.value),
                  value: _features.contains(e.key),
                  onChanged: (v) {
                    setSheet(() => v == true ? _features.add(e.key) : _features.remove(e.key));
                    setState(() {});
                  },
                ),
            ],
          ),
        ),
      ),
    );
  }

  String get _paymentLabel => _payment == 'cash' ? 'Cash' : (_cards.where((c) => c.id == _payment).firstOrNull?.label ?? 'Card');

  @override
  Widget build(BuildContext context) {
    final user = AppScope.of(context).session.user;
    final pickupPt = _pickup == null ? null : LatLngPoint(_pickup!.lat, _pickup!.lng);
    final dropPt = _dropoff == null ? null : LatLngPoint(_dropoff!.lat, _dropoff!.lng);
    return Scaffold(
      drawer: _AccountDrawer(onChanged: _loadCards, onOrders: _loadOrders),
      body: Stack(
        children: [
          Positioned.fill(
            child: RideMap(
              pickup: pickupPt,
              dropoff: dropPt,
              stops: [for (final s in _stops) LatLngPoint(s.lat, s.lng)],
              route: _estimate?.route?.polyline,
              center: _me,
              bottomPadding: _dropoff == null ? 220 : 420,
            ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Builder(
                builder: (ctx) => FloatingActionButton.small(
                  heroTag: 'menu',
                  backgroundColor: Colors.white,
                  foregroundColor: Brand.charcoal,
                  onPressed: () => Scaffold.of(ctx).openDrawer(),
                  child: const Icon(Icons.menu),
                ),
              ),
            ),
          ),
          Align(
            alignment: Alignment.bottomCenter,
            child: ConstrainedBox(
              constraints: BoxConstraints(maxWidth: 560, maxHeight: MediaQuery.of(context).size.height * 0.68),
              child: Material(
                elevation: 12,
                color: Colors.white,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
                child: SafeArea(
                  top: false,
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
                    child: _dropoff == null ? _start(user?.firstName) : _booking(),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _start(String? firstName) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            const _ModeChip(key: Key('mode-ride'), icon: Icons.local_taxi, label: 'Ride', selected: true),
            const SizedBox(width: 8),
            _ModeChip(key: const Key('mode-shop'), icon: Icons.shopping_bag_outlined, label: 'Shop', selected: false, onTap: _openShop),
          ]),
          for (final o in _orders)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Material(
                color: o.hasPendingChange ? Brand.yellow.withValues(alpha: 0.25) : Brand.blue.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(12),
                child: InkWell(
                  key: Key('active-order-${o.id}'),
                  borderRadius: BorderRadius.circular(12),
                  onTap: () => _openOrder(o),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Row(children: [
                      const Icon(Icons.shopping_bag, color: Brand.blue),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text('${o.storeName} · order ${o.number}', style: const TextStyle(fontWeight: FontWeight.bold)),
                          Text(o.hasPendingChange ? 'The store needs your answer' : o.statusLabel, style: const TextStyle(color: Colors.black54)),
                        ]),
                      ),
                      const Icon(Icons.chevron_right),
                    ]),
                  ),
                ),
              ),
            ),
          const SizedBox(height: 12),
          Text(firstName == null ? 'Where are you going?' : 'Hi $firstName, where to?', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          _StopRow(icon: Icons.trip_origin, color: Brand.blue, label: _pickup?.label ?? 'Finding your location…', onTap: _choosePickup),
          const SizedBox(height: 8),
          Material(
            color: const Color(0xFFF5F6F7),
            borderRadius: BorderRadius.circular(12),
            child: InkWell(
              key: const Key('where-to'),
              borderRadius: BorderRadius.circular(12),
              onTap: _chooseDropoff,
              child: const Padding(
                padding: EdgeInsets.all(16),
                child: Row(children: [Icon(Icons.search), SizedBox(width: 12), Text('Where to?', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600))]),
              ),
            ),
          ),
        ],
      );

  Widget _booking() {
    final q = _quoteSelected;
    final route = _estimate?.route;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _StopRow(icon: Icons.trip_origin, color: Brand.blue, label: _pickup?.label ?? '', onTap: _choosePickup),
        const SizedBox(height: 6),
        for (var i = 0; i < _stops.length; i++)
          Padding(
            padding: const EdgeInsets.only(top: 2),
            child: Row(children: [
              const Icon(Icons.adjust, color: Colors.orange, size: 20),
              const SizedBox(width: 12),
              Expanded(child: Text('Stop ${i + 1}: ${_stops[i].label}', key: Key('stop-$i'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 15))),
              IconButton(key: Key('remove-stop-$i'), visualDensity: VisualDensity.compact, tooltip: 'Remove stop', icon: const Icon(Icons.close, size: 18), onPressed: () => _removeStop(i)),
            ]),
          ),
        const SizedBox(height: 6),
        _StopRow(icon: Icons.place, color: Brand.red, label: _dropoff!.label, onTap: _chooseDropoff),
        if (_stops.length < maxStops)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const Key('add-stop'),
              onPressed: _addStop,
              icon: const Icon(Icons.add_location_alt_outlined, size: 18),
              label: Text(_stops.isEmpty ? 'Add a stop' : 'Add another stop'),
            ),
          ),
        if (route != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text('${distanceText(route.distanceM)} · about ${durationText(route.durationS)}', style: const TextStyle(color: Colors.black54)),
          ),
        const SizedBox(height: 8),
        // Wrap, not Row: on 360-wide phones the three controls don't fit on one line.
        Wrap(
          spacing: 8,
          runSpacing: 4,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            _Stepper(icon: Icons.person, value: _passengers, min: 1, max: 8, onChanged: (v) => setState(() => _passengers = v), onDone: _quote),
            _Stepper(icon: Icons.luggage, value: _luggage, min: 0, max: 8, onChanged: (v) => setState(() => _luggage = v), onDone: _quote),
            TextButton.icon(
              onPressed: _chooseFeatures,
              icon: const Icon(Icons.tune, size: 18),
              label: Text(_features.isEmpty ? 'Needs' : 'Needs (${_features.length})'),
            ),
          ],
        ),
        const Divider(),
        if (_estimating) const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
        if (!_estimating && _estimateError != null)
          Padding(padding: const EdgeInsets.symmetric(vertical: 16), child: Text(_estimateError!, key: const Key('estimate-error'), style: const TextStyle(color: Brand.red))),
        if (!_estimating && _estimate != null)
          for (final quote in _estimate!.quotes)
            _QuoteTile(quote: quote, selected: quote.vehicleType == _selected, onTap: () => setState(() => _selected = quote.vehicleType)),
        const SizedBox(height: 8),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: Icon(_payment == 'cash' ? Icons.payments_outlined : Icons.credit_card),
          title: Text(_paymentLabel),
          subtitle: _payment != 'cash' && q != null ? Text('We hold ${money(q.holdAmount, q.currency)} and charge the final fare') : null,
          trailing: const Icon(Icons.chevron_right),
          onTap: _choosePayment,
        ),
        FilledButton(
          key: const Key('request'),
          onPressed: q == null || _requesting || _estimating ? null : _request,
          child: _requesting
              ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
              : Text(q == null ? 'Request' : 'Request ${q.displayName} · ${money(q.fare, q.currency)}'),
        ),
        TextButton(
          onPressed: () => setState(() {
            _dropoff = null;
            _stops.clear();
            _estimate = null;
            _estimateError = null;
          }),
          child: const Text('Cancel'),
        ),
      ],
    );
  }
}

/// Ride / Shop switch at the top of the start panel.
class _ModeChip extends StatelessWidget {
  const _ModeChip({super.key, required this.icon, required this.label, required this.selected, this.onTap});
  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Material(
        color: selected ? Brand.charcoal : const Color(0xFFF5F6F7),
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          borderRadius: BorderRadius.circular(20),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Icon(icon, size: 18, color: selected ? Colors.white : Brand.charcoal),
              const SizedBox(width: 6),
              Text(label, style: TextStyle(fontWeight: FontWeight.w600, color: selected ? Colors.white : Brand.charcoal)),
            ]),
          ),
        ),
      );
}

class _StopRow extends StatelessWidget {
  const _StopRow({required this.icon, required this.color, required this.label, required this.onTap});
  final IconData icon;
  final Color color;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        borderRadius: BorderRadius.circular(8),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(children: [
            Icon(icon, color: color, size: 20),
            const SizedBox(width: 12),
            Expanded(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 15))),
            const Icon(Icons.edit_outlined, size: 16, color: Colors.black38),
          ]),
        ),
      );
}

class _Stepper extends StatelessWidget {
  const _Stepper({required this.icon, required this.value, required this.min, required this.max, required this.onChanged, required this.onDone});
  final IconData icon;
  final int value;
  final int min;
  final int max;
  final ValueChanged<int> onChanged;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(color: const Color(0xFFF5F6F7), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          IconButton(
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.remove, size: 18),
            onPressed: value > min ? () { onChanged(value - 1); onDone(); } : null,
          ),
          Icon(icon, size: 18),
          Padding(padding: const EdgeInsets.symmetric(horizontal: 4), child: Text('$value', style: const TextStyle(fontWeight: FontWeight.bold))),
          IconButton(
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.add, size: 18),
            onPressed: value < max ? () { onChanged(value + 1); onDone(); } : null,
          ),
        ]),
      );
}

class _QuoteTile extends StatelessWidget {
  const _QuoteTile({required this.quote, required this.selected, required this.onTap});
  final Quote quote;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Material(
          color: selected ? Brand.yellow.withValues(alpha: 0.18) : Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: BorderSide(color: selected ? Brand.yellow : const Color(0xFFE3E5E8), width: selected ? 2 : 1),
          ),
          child: InkWell(
            key: Key('quote-${quote.vehicleType}'),
            borderRadius: BorderRadius.circular(12),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                Icon(quote.vehicleType == 'xl' ? Icons.airport_shuttle : Icons.local_taxi, size: 36, color: Brand.charcoal),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(quote.displayName, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                    Text(
                      [if (quote.description != null) quote.description!, '${quote.maxPassengers} seats', '${quote.maxLuggage} bags'].join(' · '),
                      style: const TextStyle(color: Colors.black54, fontSize: 13),
                    ),
                    if (quote.surge > 1) Text('Busy time ×${quote.surge.toStringAsFixed(2)}', style: const TextStyle(color: Brand.red, fontSize: 12)),
                  ]),
                ),
                Text(money(quote.fare, quote.currency), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ]),
            ),
          ),
        ),
      );
}

class _AccountDrawer extends StatelessWidget {
  const _AccountDrawer({required this.onChanged, required this.onOrders});
  final VoidCallback onChanged;
  final VoidCallback onOrders;

  @override
  Widget build(BuildContext context) {
    final session = AppScope.of(context).session;
    final user = session.user;
    return Drawer(
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.all(20),
              child: Row(children: [
                CircleAvatar(
                  radius: 28,
                  backgroundColor: Brand.yellow,
                  child: Text((user?.name.isNotEmpty ?? false) ? user!.name[0].toUpperCase() : '?', style: const TextStyle(fontSize: 24, color: Brand.charcoal)),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(user?.name ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                    Text(user?.phone ?? '', style: const TextStyle(color: Colors.black54)),
                  ]),
                ),
              ]),
            ),
            const Divider(),
            ListTile(
              leading: const Icon(Icons.history),
              title: const Text('Your trips'),
              onTap: () {
                Navigator.pop(context);
                Navigator.of(context).push(MaterialPageRoute(builder: (_) => const HistoryScreen()));
              },
            ),
            ListTile(
              key: const Key('drawer-orders'),
              leading: const Icon(Icons.shopping_bag_outlined),
              title: const Text('Your orders'),
              onTap: () async {
                Navigator.pop(context);
                await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const OrdersScreen()));
                onOrders();
              },
            ),
            ListTile(
              leading: const Icon(Icons.credit_card),
              title: const Text('Payment'),
              onTap: () async {
                Navigator.pop(context);
                await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CardsScreen()));
                onChanged();
              },
            ),
            const Spacer(),
            ListTile(
              leading: const Icon(Icons.logout),
              title: const Text('Sign out'),
              onTap: () {
                Navigator.pop(context);
                session.signOut();
              },
            ),
          ],
        ),
      ),
    );
  }
}
