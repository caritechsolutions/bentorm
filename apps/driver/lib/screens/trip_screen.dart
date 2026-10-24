import 'dart:async';

import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/models.dart';
import '../navigation/navigation_screen.dart';
import '../state/scope.dart';
import '../widgets/driver_map.dart';

/// Pick up → arrive → start → complete (or cancel). Checks every few seconds in case the rider cancels.
class TripScreen extends StatefulWidget {
  const TripScreen({super.key, required this.initial, this.pollEvery = const Duration(seconds: 4)});
  final DriverTrip initial;
  final Duration pollEvery;

  /// Tests replace this (no browser in tests).
  static Future<bool> Function(Uri url) openUrl = (url) => launchUrl(url, mode: LaunchMode.externalApplication);

  /// Opens in-app navigation (tests replace this: the Navigation SDK needs a real device).
  static Future<NavOutcome?> Function(BuildContext context, NavTarget target) openInApp = (context, target) =>
      Navigator.of(context).push<NavOutcome>(MaterialPageRoute(builder: (_) => NavigationScreen(target: target)));

  @override
  State<TripScreen> createState() => _TripScreenState();
}

class _TripScreenState extends State<TripScreen> {
  late DriverTrip _trip = widget.initial;
  Timer? _poll;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _poll = Timer.periodic(widget.pollEvery, (_) => _refresh());
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  void _set(DriverTrip t) {
    if (!mounted) return;
    setState(() => _trip = t);
    AppScope.of(context).engine.tripChanged(t);
    if (!t.isActive) _poll?.cancel();
  }

  Future<void> _refresh() async {
    if (!mounted || !_trip.isActive) return;
    try {
      final t = await AppScope.of(context).api.trip(_trip.id);
      if (!mounted || t.signature == _trip.signature) return;
      final newRequest = t.hasPendingStopChange && !_trip.hasPendingStopChange;
      final withdrawn = _trip.hasPendingStopChange && t.stopChange?.status == 'withdrawn';
      _set(t);
      if (newRequest) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${t.riderFirstName} wants to change the stops.')));
      } else if (withdrawn) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${t.riderFirstName} withdrew the stop change.')));
      }
    } on ApiException {
      // Try again next tick.
    }
  }

  Future<void> _act(Future<DriverTrip> Function() call) async {
    setState(() => _busy = true);
    try {
      _set(await call());
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      await _refresh();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// In-app turn-by-turn when the admin has it on (and under the monthly limit); otherwise the Google Maps app.
  Future<void> _navigate(TripStop to, String kind, String label) async {
    final url = Uri.parse('https://www.google.com/maps/dir/?api=1&destination=${to.lat},${to.lng}&travelmode=driving');
    final api = AppScope.of(context).api;
    final id = _trip.id;
    var inApp = false;
    try {
      inApp = await api.navigation(id, kind, check: true) && await NavigationScreen.acceptTerms() && await api.navigation(id, kind);
    } catch (_) {
      inApp = false; // offline, older server, terms declined: fall back to Google Maps
    }
    if (!mounted) return;
    if (!inApp) {
      await TripScreen.openUrl(url);
      return;
    }
    final outcome = await TripScreen.openInApp(context, NavTarget(lat: to.lat, lng: to.lng, title: '$label: ${to.address ?? ''}'.trim(), kind: kind));
    if (outcome == NavOutcome.useExternal) await TripScreen.openUrl(url);
  }

  /// Asks for the rider's PIN and starts the trip. The dialog stays open on a wrong PIN.
  Future<void> _start() async {
    final api = AppScope.of(context).api;
    final started = await showDialog<DriverTrip>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _PinDialog(
        title: 'PIN from ${_trip.riderFirstName}',
        body: 'Ask the rider for the 4-digit PIN shown in their app. This makes sure you have the right person.',
        action: 'Start trip',
        submit: (pin) => api.start(_trip.id, pin),
      ),
    );
    if (started != null) _set(started);
  }

  /// At the customer's door: their PIN finishes the delivery (and charges their card).
  Future<void> _deliver() async {
    final scope = AppScope.of(context);
    final fix = await scope.location.current();
    if (!mounted) return;
    final done = await showDialog<DriverTrip>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _PinDialog(
        title: 'PIN from ${_trip.riderFirstName}',
        body: 'Hand over the order, then ask ${_trip.riderFirstName} for the 4-digit PIN shown in their app.',
        action: 'Delivered',
        submit: (pin) => scope.api.complete(_trip.id, lat: fix?.lat, lng: fix?.lng, pin: pin),
      ),
    );
    if (done != null) _set(done);
  }

  Future<void> _callStore(String phone) => TripScreen.openUrl(Uri.parse('tel:${phone.replaceAll(RegExp(r'[^0-9+]'), '')}'));

  Future<void> _complete() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Complete this trip?'),
        content: Text('Only complete when ${_trip.riderFirstName} has been dropped off.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Not yet')),
          FilledButton(key: const Key('confirm-complete'), onPressed: () => Navigator.pop(ctx, true), child: const Text('Complete')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final scope = AppScope.of(context);
    final fix = await scope.location.current();
    await _act(() => scope.api.complete(_trip.id, lat: fix?.lat, lng: fix?.lng));
  }

  Future<void> _cancel() async {
    final reasons = _trip.isDelivery
        ? const ['Store is taking too long', 'Store is closed', 'Problem with my vehicle', 'Other']
        : const ['Rider didn\'t show up', 'Rider asked me to cancel', 'Problem with my vehicle', 'Other'];
    final reason = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Padding(padding: EdgeInsets.only(bottom: 8), child: Text('Why are you cancelling?', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16))),
          for (final r in reasons) ListTile(key: Key('reason-$r'), title: Text(r), onTap: () => Navigator.pop(ctx, r)),
        ]),
      ),
    );
    if (reason == null || !mounted) return;
    final api = AppScope.of(context).api;
    await _act(() => api.cancel(_trip.id, reason));
  }

  @override
  Widget build(BuildContext context) {
    final t = _trip;
    final engine = AppScope.of(context).engine;
    return PopScope(
      canPop: !t.isActive,
      child: Scaffold(
        appBar: AppBar(automaticallyImplyLeading: !t.isActive, title: Text(_title(t), key: const Key('trip-title'))),
        body: Stack(children: [
          Positioned.fill(
            child: ListenableBuilder(
              listenable: engine,
              builder: (_, __) => DriverMap(
                pickup: t.status == 'in_progress' ? null : t.pickup.point,
                dropoff: t.status == 'accepted' ? null : t.dropoff.point,
                stops: t.status == 'accepted' ? const [] : [for (final s in t.stops) if (!s.reached) s.point],
                me: engine.position,
                bottomPadding: 300,
              ),
            ),
          ),
          Align(
            alignment: Alignment.bottomCenter,
            child: ConstrainedBox(
              constraints: BoxConstraints(maxWidth: 560, maxHeight: MediaQuery.of(context).size.height * 0.7),
              child: Material(
                elevation: 12,
                color: Colors.white,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
                child: SafeArea(
                  top: false,
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.all(16),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: _panel(t)),
                  ),
                ),
              ),
            ),
          ),
        ]),
      ),
    );
  }

  String _title(DriverTrip t) => t.isDelivery
      ? switch (t.status) {
          'accepted' => 'Collect order ${t.delivery!.orderNumber}',
          'arrived' => 'At ${t.delivery!.storeName}',
          'in_progress' => 'Deliver to ${t.riderFirstName}',
          'completed' => 'Delivered',
          'cancelled' => 'Delivery cancelled',
          _ => t.status,
        }
      : switch (t.status) {
        'accepted' => 'Pick up ${t.riderFirstName}',
        'arrived' => 'Waiting for ${t.riderFirstName}',
        'in_progress' => 'Taking ${t.riderFirstName}',
        'completed' => 'Trip complete',
        'cancelled' => 'Ride cancelled',
        _ => t.status,
      };

  List<Widget> _panel(DriverTrip t) {
    if (t.isDelivery) return _deliveryPanel(t);
    final cur = t.currency.isEmpty ? null : t.currency;
    final riderInfo = <Widget>[
      Row(children: [
        CircleAvatar(backgroundColor: Brand.yellow, child: Text(t.riderFirstName.isEmpty ? '?' : t.riderFirstName[0].toUpperCase(), style: const TextStyle(color: Brand.charcoal))),
        const SizedBox(width: 12),
        Expanded(child: Text(t.riderName ?? 'Rider', key: const Key('rider-name'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold))),
        Text('${t.passengers} ${t.passengers == 1 ? 'rider' : 'riders'}${t.luggage > 0 ? ' · ${t.luggage} bags' : ''}'),
      ]),
      if (t.riderNote != null && t.riderNote!.isNotEmpty)
        Padding(padding: const EdgeInsets.only(top: 8), child: Text('Note: ${t.riderNote}', key: const Key('rider-note'), style: const TextStyle(fontStyle: FontStyle.italic))),
      if (t.features.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text('Needs: ${t.features.map((f) => f.replaceAll('_', ' ')).join(', ')}')),
      const SizedBox(height: 4),
      Text('${t.isCash ? 'Cash' : 'Card'} · estimate ${money(t.estimatedFare, cur)}', style: const TextStyle(color: Colors.black54)),
      const Divider(height: 20),
    ];

    final change = t.hasPendingStopChange ? [_stopChangeCard(t)] : const <Widget>[];

    switch (t.status) {
      case 'accepted':
        return [
          ...change,
          ...riderInfo,
          _stop(Icons.trip_origin, Brand.blue, 'Pickup', t.pickup.address),
          ..._onward(t),
          const SizedBox(height: 12),
          OutlinedButton.icon(key: const Key('navigate'), onPressed: () => _navigate(t.pickup, 'pickup', 'Pickup'), icon: const Icon(Icons.navigation), label: const Text('Navigate to pickup')),
          const SizedBox(height: 8),
          FilledButton(key: const Key('arrived'), onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.arrive(t.id)), child: const Text('I\'ve arrived')),
          _cancelButton(),
        ];
      case 'arrived':
        return [
          ...change,
          ...riderInfo,
          ..._onward(t),
          const SizedBox(height: 12),
          FilledButton.icon(
            key: const Key('start'),
            onPressed: _busy ? null : _start,
            icon: const Icon(Icons.lock_open),
            label: const Text('Enter PIN and start'),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text('Ask ${t.riderFirstName} for their 4-digit ride PIN.', textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
          ),
          _cancelButton(),
        ];
      case 'in_progress':
        final next = t.nextStopIndex;
        return [
          ...change,
          ...riderInfo,
          if (next != null) ...[
            _stop(Icons.adjust, Colors.orange, 'Stop ${next + 1} of ${t.stops.length}', t.stops[next].address, key: const Key('next-stop')),
            const SizedBox(height: 12),
            OutlinedButton.icon(key: const Key('navigate'), onPressed: () => _navigate(t.stops[next], 'stop', 'Stop ${next + 1}'), icon: const Icon(Icons.navigation), label: Text('Navigate to stop ${next + 1}')),
            const SizedBox(height: 8),
            FilledButton(
              key: const Key('reached-stop'),
              onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.reachStop(t.id, next)),
              child: Text('Arrived at stop ${next + 1}'),
            ),
            const SizedBox(height: 12),
            _stop(Icons.place, Brand.red, 'Then drop-off', t.dropoff.address),
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: TextButton(key: const Key('complete'), onPressed: _busy ? null : _complete, child: const Text('Complete trip early')),
            ),
          ] else ...[
            for (final s in t.stops) _stop(Icons.check_circle, const Color(0xFF1E9E5A), 'Stop done', s.address),
            _stop(Icons.place, Brand.red, 'Drop-off', t.dropoff.address),
            const SizedBox(height: 12),
            OutlinedButton.icon(key: const Key('navigate'), onPressed: () => _navigate(t.dropoff, 'dropoff', 'Drop-off'), icon: const Icon(Icons.navigation), label: const Text('Navigate to drop-off')),
            const SizedBox(height: 8),
            FilledButton(key: const Key('complete'), onPressed: _busy ? null : _complete, child: const Text('Complete trip')),
          ],
        ];
      case 'completed':
        return [
          if (t.isCash)
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.25), borderRadius: BorderRadius.circular(14)),
              child: Column(children: [
                const Text('Collect cash', style: TextStyle(fontSize: 16)),
                Text(money(t.finalFare, cur), key: const Key('collect'), style: const TextStyle(fontSize: 34, fontWeight: FontWeight.bold)),
                Text('from ${t.riderFirstName}'),
              ]),
            )
          else
            Text('${money(t.finalFare, cur)} paid by card', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          if (t.earnings != null) Text('You earned ${money(t.earnings, cur)}', key: const Key('earned'), style: const TextStyle(fontSize: 18, color: Color(0xFF1E9E5A), fontWeight: FontWeight.w600)),
          const SizedBox(height: 16),
          FilledButton(key: const Key('done'), onPressed: () => Navigator.of(context).pop(), child: const Text('Done')),
        ];
      default:
        return [
          Text(
            t.cancelledBy == 'rider' ? '${t.riderFirstName} cancelled the ride.' : (t.cancelledBy == 'driver' ? 'You cancelled this ride.' : 'This ride was cancelled.'),
            key: const Key('cancelled-message'),
            style: const TextStyle(fontSize: 16),
          ),
          const SizedBox(height: 16),
          FilledButton(key: const Key('done'), onPressed: () => Navigator.of(context).pop(), child: const Text('Done')),
        ];
    }
  }

  /// Delivery: go to the store → the store taps "Picked up" → go to the customer → their PIN finishes it.
  List<Widget> _deliveryPanel(DriverTrip t) {
    final d = t.delivery!;
    final cur = t.currency.isEmpty ? null : t.currency;
    final order = <Widget>[
      Text('Order ${d.orderNumber} for ${d.customerName}', key: const Key('delivery-order'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
      const SizedBox(height: 4),
      for (final i in d.items) Text(i, style: const TextStyle(fontSize: 15)),
      if (d.note != null && d.note!.isNotEmpty)
        Padding(padding: const EdgeInsets.only(top: 6), child: Text('Note: ${d.note}', key: const Key('rider-note'), style: const TextStyle(fontStyle: FontStyle.italic))),
      if (d.earnings != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('You earn ${money(d.earnings, cur)} · paid by card', style: const TextStyle(color: Colors.black54))),
      const Divider(height: 20),
    ];
    final callStore = d.storePhone == null
        ? const <Widget>[]
        : [TextButton.icon(key: const Key('call-store'), onPressed: () => _callStore(d.storePhone!), icon: const Icon(Icons.phone), label: Text('Call ${d.storeName}'))];

    switch (t.status) {
      case 'accepted':
        return [
          ...order,
          _stop(Icons.storefront, Brand.blue, 'Collect at', t.pickup.address),
          Padding(padding: const EdgeInsets.only(top: 8), child: _stop(Icons.place, Brand.red, 'Then deliver to', t.dropoff.address)),
          const SizedBox(height: 12),
          OutlinedButton.icon(key: const Key('navigate'), onPressed: () => _navigate(t.pickup, 'pickup', 'Store'), icon: const Icon(Icons.navigation), label: const Text('Navigate to the store')),
          const SizedBox(height: 8),
          FilledButton(key: const Key('arrived'), onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.arrive(t.id)), child: const Text('I\'m at the store')),
          ...callStore,
          _cancelButton(),
        ];
      case 'arrived':
        return [
          ...order,
          Container(
            key: const Key('waiting-store'),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.25), borderRadius: BorderRadius.circular(14)),
            child: Row(children: [
              const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5)),
              const SizedBox(width: 12),
              Expanded(child: Text('Ask for order ${d.orderNumber}. When ${d.storeName} hands it over they tap "Picked up" — this screen moves on by itself.')),
            ]),
          ),
          ...callStore,
          _cancelButton(),
        ];
      case 'in_progress':
        return [
          ...order,
          _stop(Icons.place, Brand.red, 'Deliver to ${d.customerName}', t.dropoff.address),
          const SizedBox(height: 12),
          OutlinedButton.icon(key: const Key('navigate'), onPressed: () => _navigate(t.dropoff, 'dropoff', 'Customer'), icon: const Icon(Icons.navigation), label: const Text('Navigate to the customer')),
          const SizedBox(height: 8),
          FilledButton.icon(key: const Key('deliver'), onPressed: _busy ? null : _deliver, icon: const Icon(Icons.lock_open), label: const Text('Enter PIN — delivered')),
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text('${d.customerName} reads you a 4-digit PIN from their app.', textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
          ),
        ];
      case 'completed':
        return [
          const Text('Order delivered', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          if (t.earnings != null) Text('You earned ${money(t.earnings, cur)}', key: const Key('earned'), style: const TextStyle(fontSize: 18, color: Color(0xFF1E9E5A), fontWeight: FontWeight.w600)),
          const SizedBox(height: 16),
          FilledButton(key: const Key('done'), onPressed: () => Navigator.of(context).pop(), child: const Text('Done')),
        ];
      default:
        return [
          Text(t.cancelledBy == 'driver' ? 'You cancelled this delivery.' : 'This delivery was called off. You don\'t need to go to the store.',
              key: const Key('cancelled-message'), style: const TextStyle(fontSize: 16)),
          const SizedBox(height: 16),
          FilledButton(key: const Key('done'), onPressed: () => Navigator.of(context).pop(), child: const Text('Done')),
        ];
    }
  }

  /// Stops still to come, then the drop-off.
  List<Widget> _onward(DriverTrip t) => [
        for (var i = 0; i < t.stops.length; i++)
          Padding(padding: const EdgeInsets.only(top: 8), child: _stop(Icons.adjust, Colors.orange, 'Stop ${i + 1}', t.stops[i].address)),
        Padding(padding: const EdgeInsets.only(top: 8), child: _stop(Icons.place, Brand.red, 'Drop-off', t.dropoff.address)),
      ];

  Widget _stopChangeCard(DriverTrip t) {
    final c = t.stopChange!;
    final cur = t.currency.isEmpty ? null : t.currency;
    return Container(
      key: const Key('stop-change'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.3), borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('${t.riderFirstName} wants to change the stops', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
        const SizedBox(height: 6),
        if (c.stops.isEmpty) const Text('Remove all stops and go straight to the drop-off.'),
        for (var i = 0; i < c.stops.length; i++) Text('Stop ${i + 1}: ${c.stops[i].address ?? 'Stop'}'),
        if (c.estimatedFare != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('New fare estimate ${money(c.estimatedFare, cur)}', style: const TextStyle(color: Colors.black54))),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(
            child: OutlinedButton(
              key: const Key('decline-stops'),
              onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.declineStops(t.id)),
              child: const Text('Decline'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: FilledButton(
              key: const Key('accept-stops'),
              onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.acceptStops(t.id)),
              child: const Text('Accept'),
            ),
          ),
        ]),
      ]),
    );
  }

  Widget _stop(IconData icon, Color color, String label, String? address, {Key? key}) => Row(key: key, children: [
        Icon(icon, color: color),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: const TextStyle(color: Colors.black54, fontSize: 13)),
            Text(address ?? '—', style: const TextStyle(fontSize: 16)),
          ]),
        ),
      ]);

  Widget _cancelButton() => Padding(
        padding: const EdgeInsets.only(top: 8),
        child: TextButton(key: const Key('cancel-ride'), onPressed: _busy ? null : _cancel, style: TextButton.styleFrom(foregroundColor: Brand.red), child: Text(_trip.isDelivery ? 'Cancel delivery' : 'Cancel ride')),
      );
}

/// Four-digit PIN entry. Calls [submit]; closes with the updated trip, or shows the server's reason.
class _PinDialog extends StatefulWidget {
  const _PinDialog({required this.title, required this.body, required this.action, required this.submit});
  final String title;
  final String body;
  final String action;
  final Future<DriverTrip> Function(String pin) submit;

  @override
  State<_PinDialog> createState() => _PinDialogState();
}

class _PinDialogState extends State<_PinDialog> {
  final _pin = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _pin.dispose();
    super.dispose();
  }

  Future<void> _go() async {
    final pin = _pin.text.trim();
    if (!RegExp(r'^\d{4}$').hasMatch(pin)) {
      setState(() => _error = 'The PIN is 4 digits.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final t = await widget.submit(pin);
      if (mounted) Navigator.of(context).pop(t);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.field('pin') ?? e.message);
        _pin.clear();
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(widget.title),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(widget.body),
          const SizedBox(height: 12),
          TextField(
            key: const Key('pin-input'),
            controller: _pin,
            autofocus: true,
            keyboardType: TextInputType.number,
            maxLength: 4,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 28, letterSpacing: 12, fontWeight: FontWeight.bold),
            decoration: const InputDecoration(counterText: '', hintText: '••••'),
            onSubmitted: (_) => _go(),
          ),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, key: const Key('pin-error'), style: const TextStyle(color: Brand.red))),
        ]),
        actions: [
          TextButton(key: const Key('pin-cancel'), onPressed: _busy ? null : () => Navigator.of(context).pop(), child: const Text('Back')),
          FilledButton(key: const Key('pin-submit'), onPressed: _busy ? null : _go, child: Text(widget.action)),
        ],
      );
}
