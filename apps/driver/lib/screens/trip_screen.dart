import 'dart:async';

import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/models.dart';
import '../state/scope.dart';
import '../widgets/driver_map.dart';

/// Pick up → arrive → start → complete (or cancel). Checks every few seconds in case the rider cancels.
class TripScreen extends StatefulWidget {
  const TripScreen({super.key, required this.initial, this.pollEvery = const Duration(seconds: 4)});
  final DriverTrip initial;
  final Duration pollEvery;

  /// Tests replace this (no browser in tests).
  static Future<bool> Function(Uri url) openUrl = (url) => launchUrl(url, mode: LaunchMode.externalApplication);

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
      if (mounted && (t.status != _trip.status)) _set(t);
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

  Future<void> _navigate(TripStop to) async {
    final url = Uri.parse('https://www.google.com/maps/dir/?api=1&destination=${to.lat},${to.lng}&travelmode=driving');
    await TripScreen.openUrl(url);
  }

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
    const reasons = ['Rider didn\'t show up', 'Rider asked me to cancel', 'Problem with my vehicle', 'Other'];
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

  String _title(DriverTrip t) => switch (t.status) {
        'accepted' => 'Pick up ${t.riderFirstName}',
        'arrived' => 'Waiting for ${t.riderFirstName}',
        'in_progress' => 'Taking ${t.riderFirstName}',
        'completed' => 'Trip complete',
        'cancelled' => 'Ride cancelled',
        _ => t.status,
      };

  List<Widget> _panel(DriverTrip t) {
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

    switch (t.status) {
      case 'accepted':
        return [
          ...riderInfo,
          _stop(Icons.trip_origin, Brand.blue, 'Pickup', t.pickup.address),
          const SizedBox(height: 12),
          OutlinedButton.icon(key: const Key('navigate'), onPressed: () => _navigate(t.pickup), icon: const Icon(Icons.navigation), label: const Text('Navigate to pickup')),
          const SizedBox(height: 8),
          FilledButton(key: const Key('arrived'), onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.arrive(t.id)), child: const Text('I\'ve arrived')),
          _cancelButton(),
        ];
      case 'arrived':
        return [
          ...riderInfo,
          _stop(Icons.place, Brand.red, 'Drop-off', t.dropoff.address),
          const SizedBox(height: 12),
          FilledButton(key: const Key('start'), onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.start(t.id)), child: Text('Start trip with ${t.riderFirstName}')),
          _cancelButton(),
        ];
      case 'in_progress':
        return [
          ...riderInfo,
          _stop(Icons.place, Brand.red, 'Drop-off', t.dropoff.address),
          const SizedBox(height: 12),
          OutlinedButton.icon(key: const Key('navigate'), onPressed: () => _navigate(t.dropoff), icon: const Icon(Icons.navigation), label: const Text('Navigate to drop-off')),
          const SizedBox(height: 8),
          FilledButton(key: const Key('complete'), onPressed: _busy ? null : _complete, child: const Text('Complete trip')),
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

  Widget _stop(IconData icon, Color color, String label, String? address) => Row(children: [
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
        child: TextButton(key: const Key('cancel-ride'), onPressed: _busy ? null : _cancel, style: TextButton.styleFrom(foregroundColor: Brand.red), child: const Text('Cancel ride')),
      );
}
