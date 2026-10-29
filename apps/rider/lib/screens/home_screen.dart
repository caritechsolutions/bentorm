import 'dart:async';

import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart' show AppPages;

import '../api/api_client.dart';
import '../api/models.dart';
import '../api/shop_models.dart';
import '../brand.dart';
import '../config.dart';
import '../state/scope.dart';
import '../widgets/ride_map.dart';
import 'cards_screen.dart';
import 'history_screen.dart';
import 'places_screen.dart';
import 'rewards_screens.dart';
import 'search_screen.dart';
import 'shop/checkout_screen.dart';
import 'shop/order_screen.dart';
import 'shop/orders_screen.dart';
import 'shop/shop_screen.dart';
import 'trip_screen.dart';
import 'upcoming_screen.dart';

/// Book a ride: pickup → where to → choose a vehicle → pay → request.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  LatLngPoint? _me;
  Place? _pickup;

  /// The pickup is "where I am" (not a place the rider picked): it follows the rider when they come back.
  bool _pickupIsHere = false;
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
  List<SavedPlace> _places = const [];
  DateTime? _when; // null = now
  String? _promo; // checked code, sent with the estimate and the request
  bool _useCredit = false;
  double _creditBalance = 0;
  List<Trip> _owed = const []; // e.g. a cancellation fee on a cash ride: blocks new bookings until paid
  bool _paying = false;
  List<NearbyCar> _cars = const [];
  Timer? _carsTimer;
  bool _away = false; // a trip or another screen is open: no need to fetch cars

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // Free cars around the pickup, refreshed every 15 s while this screen is showing.
    _carsTimer = Timer.periodic(const Duration(seconds: 15), (_) => _loadCars());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _carsTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadCars() async {
    final at = _pickup ?? (_me == null ? null : Place(name: '', lat: _me!.lat, lng: _me!.lng));
    if (_away || at == null || !mounted) return;
    final cars = await AppScope.of(context).api.nearbyCars(at.lat, at.lng, vehicleType: _dropoff != null ? _selected : null);
    if (mounted) setState(() => _cars = cars);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Back from the bank's card page: see whether it's paid.
    if (state == AppLifecycleState.resumed && _owed.isNotEmpty) _loadOwed();
    // Back in the app before booking: if the pickup is "where I am", it moves with the rider.
    if (state == AppLifecycleState.resumed && !_away && _pickupIsHere && _dropoff == null) _pickupHere();
  }

  Future<void> _loadOwed() async {
    try {
      final owed = await AppScope.of(context).api.outstanding();
      if (mounted) setState(() => _owed = owed);
    } on ApiException {
      // Not essential: booking still says what's owed.
    }
  }

  /// Pay what's owed: a saved card at once, or the card page (any card).
  Future<void> _payOwed(Trip t) async {
    final api = AppScope.of(context).api;
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Text('Pay ${money(t.amountDue, t.currency)}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Text(_owedReason(t), style: const TextStyle(color: Colors.black54)),
          ),
          for (final c in _cards)
            ListTile(
              key: Key('pay-owed-card-${c.id}'),
              leading: const Icon(Icons.credit_card),
              title: Text(c.label),
              onTap: () => Navigator.pop(ctx, c.id),
            ),
          ListTile(
            key: const Key('pay-owed-page'),
            leading: const Icon(Icons.lock_outline),
            title: Text(_cards.isEmpty ? 'Pay by card' : 'Pay with another card'),
            subtitle: const Text('Opens the secure card page'),
            onTap: () => Navigator.pop(ctx, '_page'),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 4, 16, 12),
            child: Text('Paid in cash already? Contact Ride Coop support and we\'ll clear it.', style: TextStyle(color: Colors.black54, fontSize: 13)),
          ),
        ]),
      ),
    );
    if (choice == null || !mounted) return;
    setState(() => _paying = true);
    try {
      final url = await api.payOutstanding(t.id, cardId: choice == '_page' ? null : choice);
      if (url != null) {
        await CheckoutScreen.openUrl(Uri.parse(url));
      } else if (mounted) {
        _snack('Paid — thank you. You can book again.');
      }
      await _loadOwed();
    } on ApiException catch (e) {
      if (mounted) _snack(e.status == 402 ? 'The card was declined. Try another card.' : e.message);
    } finally {
      if (mounted) setState(() => _paying = false);
    }
  }

  String _owedReason(Trip t) {
    final d = t.requestedAt;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    final when = d == null ? '' : ' on ${d.day} ${months[d.month - 1]}';
    return t.status == 'cancelled' ? 'Cancellation fee for the ride you cancelled$when, after a driver was on the way.' : 'Unpaid fare for your ride$when.';
  }

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
    _loadPlaces();
    _loadCredit();
    _loadOwed();
    await _pickupHere(onlyIfEmpty: true);
  }

  /// Use where the rider is now as the pickup (fresh GPS fix + address). With [onlyIfEmpty], keep one already set.
  Future<void> _pickupHere({bool onlyIfEmpty = false}) async {
    final scope = AppScope.of(context);
    final me = await scope.location.current();
    if (!mounted) return;
    final point = me ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng);
    setState(() => _me = me ?? _me);
    if (onlyIfEmpty && _pickup != null) return;
    final here = await scope.api.reverse(point.lat, point.lng);
    if (!mounted || (onlyIfEmpty && _pickup != null)) return;
    setState(() {
      _pickup = here ?? Place(name: me != null ? 'Current location' : 'Bridgetown', lat: point.lat, lng: point.lng);
      _pickupIsHere = true;
    });
    _loadCars();
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

  Future<void> _loadPlaces() async {
    if (!AppScope.of(context).config.on('saved_places')) return;
    try {
      final p = await AppScope.of(context).api.savedPlaces();
      if (mounted) setState(() => _places = p);
    } on ApiException {
      // Not essential.
    }
  }

  Future<void> _loadCredit() async {
    try {
      final c = await AppScope.of(context).api.credit();
      if (mounted) setState(() => _creditBalance = c.balance);
    } on ApiException {
      // Not essential.
    }
  }

  void _goTo(SavedPlace p) {
    setState(() => _dropoff = p.place);
    _quote();
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
        saved: _places,
      ),
    ));
    if (place != null && mounted) {
      setState(() {
        _pickup = place;
        // Picking "Current location" keeps following the rider; any other place stays put.
        _pickupIsHere = _me != null && (place.lat - _me!.lat).abs() < 0.0002 && (place.lng - _me!.lng).abs() < 0.0002;
      });
      _quote();
    }
  }

  Future<void> _chooseDropoff() async {
    final place = await Navigator.of(context).push<Place>(MaterialPageRoute(
      builder: (_) => SearchScreen(title: 'Where to?', near: _me ?? (_pickup == null ? null : LatLngPoint(_pickup!.lat, _pickup!.lng)), saved: _places),
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
      final e = await AppScope.of(context)
          .api
          .estimate(_pickup!, _dropoff!, passengers: _passengers, luggage: _luggage, stops: _stops, scheduledAt: _when, promoCode: _promo);
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
        _estimateError = e.field('promo_code') ?? e.field('scheduled_at') ?? e.field('pickup') ?? e.field('dropoff') ?? e.field('stops') ?? e.field('stops.0') ?? e.field('stops.1') ?? e.message;
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
        scheduledAt: _when,
        promoCode: _promo,
        useCredit: _useCredit && _creditBalance > 0,
      );
      if (!mounted) return;
      if (trip.status == 'scheduled') {
        _snack('Ride booked for ${scheduleLabel(trip.scheduledAt ?? _when!)}. We\'ll remind you when your driver is on the way.');
      }
      await _openTrip(trip);
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.field('payment') != null) _loadOwed();
      _snack(e.status == 402 ? e.message : (e.field('payment') ?? e.field('scheduled_at') ?? e.field('promo_code') ?? e.field('vehicle_type') ?? e.field('passengers') ?? e.field('luggage') ?? e.field('pickup') ?? e.field('dropoff') ?? e.message));
    } finally {
      if (mounted) setState(() => _requesting = false);
    }
  }

  Future<void> _openTrip(Trip trip) async {
    _away = true;
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TripScreen(initial: trip)));
    _away = false;
    if (!mounted) return;
    _loadCars();
    // Back from the trip: start fresh from where the rider is now (not the last trip's pickup).
    _pickupHere();
    setState(() {
      _dropoff = null;
      _stops.clear();
      _estimate = null;
      _estimateError = null;
      _when = null;
      _promo = null;
      _useCredit = false;
    });
    _loadCards();
    _loadCredit();
    _loadOwed();
  }

  /// Now or a time later (within the window the admin set).
  Future<void> _pickWhen() async {
    final cfg = AppScope.of(context).config;
    final minMin = cfg.number('scheduled.min_minutes', 30).toInt();
    final maxDays = cfg.number('scheduled.max_days', 7).toInt();
    final picked = await showModalBottomSheet<DateTime?>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (_) => _WhenSheet(current: _when, minMinutes: minMin, maxDays: maxDays),
    );
    if (!mounted || picked == null) return;
    setState(() => _when = picked.millisecondsSinceEpoch == 0 ? null : picked);
    _quote();
  }

  Future<void> _enterPromo() async {
    final api = AppScope.of(context).api;
    final ctl = TextEditingController(text: _promo ?? '');
    String? error;
    bool checking = false;
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setD) => AlertDialog(
          title: const Text('Promo code'),
          content: TextField(
            key: const Key('promo-input'),
            controller: ctl,
            autofocus: true,
            textCapitalization: TextCapitalization.characters,
            decoration: InputDecoration(hintText: 'e.g. WELCOME10', errorText: error),
          ),
          actions: [
            if (_promo != null) TextButton(onPressed: () => Navigator.pop(ctx, ''), child: const Text('Remove')),
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
            FilledButton(
              key: const Key('promo-apply'),
              onPressed: checking
                  ? null
                  : () async {
                      if (ctl.text.trim().isEmpty) return;
                      setD(() {
                        checking = true;
                        error = null;
                      });
                      try {
                        final p = await api.checkPromo(ctl.text, forWhat: 'rides', amount: _quoteSelected?.fare);
                        if (ctx.mounted) Navigator.pop(ctx, p.code);
                      } on ApiException catch (e) {
                        setD(() => error = e.field('code') ?? e.message);
                      } finally {
                        if (ctx.mounted) setD(() => checking = false);
                      }
                    },
              child: const Text('Apply'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || result == null) return;
    setState(() => _promo = result.isEmpty ? null : result);
    _quote();
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
              cars: _cars,
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
          for (final t in _owed)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Container(
                key: const Key('owed-banner'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: Brand.red.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.red)),
                child: Row(children: [
                  const Icon(Icons.receipt_long, color: Brand.red),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('You owe ${money(t.amountDue, t.currency)}', style: const TextStyle(fontWeight: FontWeight.bold)),
                      Text(_owedReason(t), style: const TextStyle(color: Colors.black54, fontSize: 13)),
                      const Text('Pay it to book your next ride.', style: TextStyle(color: Colors.black54, fontSize: 13)),
                    ]),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(
                    key: const Key('pay-owed'),
                    style: FilledButton.styleFrom(minimumSize: const Size(0, 40)),
                    onPressed: _paying ? null : () => _payOwed(t),
                    child: const Text('Pay now'),
                  ),
                ]),
              ),
            ),
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
          if (_places.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(children: [
                  for (final p in _places)
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ActionChip(
                        key: Key('saved-${p.id}'),
                        avatar: Icon(placeIcon(p.kind), size: 18),
                        label: Text(p.label),
                        onPressed: () => _goTo(p),
                      ),
                    ),
                ]),
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
            if (AppScope.of(context).config.on('scheduled_rides'))
              TextButton.icon(
                key: const Key('when'),
                onPressed: _pickWhen,
                icon: Icon(_when == null ? Icons.schedule : Icons.event, size: 18),
                label: Text(_when == null ? 'Schedule a later ride' : 'Pickup ${scheduleLabel(_when!)}'),
              ),
            if (AppScope.of(context).config.on('promos'))
              TextButton.icon(
                key: const Key('promo'),
                onPressed: _enterPromo,
                icon: const Icon(Icons.local_offer_outlined, size: 18),
                label: Text(_promo ?? 'Promo code'),
              ),
          ],
        ),
        if (_creditBalance > 0)
          SwitchListTile(
            key: const Key('use-credit'),
            contentPadding: EdgeInsets.zero,
            dense: true,
            title: Text('Use ride credit (${money(_creditBalance, q?.currency ?? 'BBD')})'),
            value: _useCredit,
            onChanged: (v) => setState(() => _useCredit = v),
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
              : Text(q == null
                  ? (_when == null ? 'Request' : 'Book')
                  : '${_when == null ? 'Request' : 'Book'} ${q.displayName} · ${money(q.afterDiscount, q.currency)}'),
        ),
        TextButton(
          onPressed: () => setState(() {
            _dropoff = null;
            _when = null;
            _promo = null;
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
                Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text(money(quote.afterDiscount, quote.currency), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  if (quote.discount > 0)
                    Text(money(quote.fare, quote.currency),
                        style: const TextStyle(color: Colors.black45, fontSize: 13, decoration: TextDecoration.lineThrough)),
                ]),
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
    final cfg = AppScope.of(context).config;
    final user = session.user;
    return Drawer(
      child: SafeArea(
        child: SingleChildScrollView(
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
            if (cfg.on('scheduled_rides'))
              ListTile(
                key: const Key('drawer-upcoming'),
                leading: const Icon(Icons.event),
                title: const Text('Booked rides'),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.of(context).push(MaterialPageRoute(builder: (_) => const UpcomingScreen()));
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
            if (cfg.on('saved_places'))
              ListTile(
                key: const Key('drawer-places'),
                leading: const Icon(Icons.bookmark_border),
                title: const Text('Saved places'),
                onTap: () async {
                  Navigator.pop(context);
                  await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const PlacesScreen()));
                  onChanged();
                },
              ),
            ListTile(
              key: const Key('drawer-credit'),
              leading: const Icon(Icons.savings_outlined),
              title: const Text('Ride credit'),
              onTap: () {
                Navigator.pop(context);
                Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CreditScreen()));
              },
            ),
            if (cfg.on('referrals'))
              ListTile(
                key: const Key('drawer-invite'),
                leading: const Icon(Icons.card_giftcard),
                title: const Text('Invite friends'),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.of(context).push(MaterialPageRoute(builder: (_) => const InviteScreen()));
                },
              ),
            if (cfg.on('support'))
              ListTile(
                key: const Key('drawer-help'),
                leading: const Icon(Icons.help_outline),
                title: const Text('Help'),
                onTap: () async {
                  final client = AppScope.of(context).api.client;
                  final messenger = ScaffoldMessenger.of(context);
                  Navigator.pop(context);
                  try {
                    await AppPages.open(client, 'help');
                  } on ApiException catch (e) {
                    messenger.showSnackBar(SnackBar(content: Text(e.message)));
                  }
                },
              ),
            const Divider(),
            ...LegalLinks.tiles(context, AppScope.of(context).api.client),
            AccountDeletion.tile(context, session),
            ListTile(
              key: const Key('sign-out'),
              leading: const Icon(Icons.logout),
              title: const Text('Sign out'),
              onTap: () async {
                final push = AppScope.of(context).push;
                Navigator.pop(context);
                await push?.forget();
                await session.signOut();
              },
            ),
          ],
          ),
        ),
      ),
    );
  }
}

IconData placeIcon(String kind) => switch (kind) { 'home' => Icons.home_outlined, 'work' => Icons.work_outline, _ => Icons.place_outlined };

/// Day chips and 15-minute times. Pops with the time, or DateTime(0) for "Now".
class _WhenSheet extends StatefulWidget {
  const _WhenSheet({this.current, required this.minMinutes, required this.maxDays});
  final DateTime? current;
  final int minMinutes;
  final int maxDays;

  @override
  State<_WhenSheet> createState() => _WhenSheetState();
}

class _WhenSheetState extends State<_WhenSheet> {
  late final DateTime _earliest;
  late int _day;

  @override
  void initState() {
    super.initState();
    final e = DateTime.now().add(Duration(minutes: widget.minMinutes + 5));
    // Round up to the next quarter hour.
    _earliest = DateTime(e.year, e.month, e.day, e.hour, (e.minute ~/ 15) * 15).add(Duration(minutes: e.minute % 15 == 0 ? 0 : 15));
    final c = widget.current;
    final today = DateTime(_earliest.year, _earliest.month, _earliest.day);
    _day = c == null ? 0 : DateTime(c.year, c.month, c.day).difference(today).inDays.clamp(0, widget.maxDays);
  }

  List<DateTime> _times() {
    final first = DateTime(_earliest.year, _earliest.month, _earliest.day).add(Duration(days: _day));
    final latest = DateTime.now().add(Duration(days: widget.maxDays));
    return [
      for (var m = 0; m < 24 * 60; m += 15)
        if (!first.add(Duration(minutes: m)).isBefore(_earliest) && !first.add(Duration(minutes: m)).isAfter(latest)) first.add(Duration(minutes: m)),
    ];
  }

  String _dayLabel(int i) {
    if (i == 0) return 'Today';
    if (i == 1) return 'Tomorrow';
    final d = DateTime(_earliest.year, _earliest.month, _earliest.day).add(Duration(days: i));
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return '${days[d.weekday - 1]} ${d.day}';
  }

  @override
  Widget build(BuildContext context) {
    final times = _times();
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.6,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Text('When do you want to be picked up?', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          ),
          ListTile(
            key: const Key('when-now'),
            leading: const Icon(Icons.bolt),
            title: const Text('Now — find a driver straight away'),
            onTap: () => Navigator.pop(context, DateTime.fromMillisecondsSinceEpoch(0)),
          ),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(children: [
              for (var i = 0; i <= widget.maxDays; i++)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(key: Key('when-day-$i'), label: Text(_dayLabel(i)), selected: _day == i, onSelected: (_) => setState(() => _day = i)),
                ),
            ]),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: times.isEmpty
                ? const Center(child: Text('No times left on this day.'))
                : ListView(children: [
                    for (final t in times)
                      ListTile(
                        key: Key('when-time-${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}'),
                        title: Text(scheduleLabel(t)),
                        onTap: () => Navigator.pop(context, t),
                      ),
                  ]),
          ),
        ]),
      ),
    );
  }
}
