import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';
import '../widgets/ride_map.dart';

/// A ride from request to rating. Refreshes every few seconds while the ride is live.
class TripScreen extends StatefulWidget {
  const TripScreen({super.key, required this.initial, this.pollEvery = const Duration(seconds: 4)});

  final Trip initial;
  final Duration pollEvery;

  @override
  State<TripScreen> createState() => _TripScreenState();
}

class _TripScreenState extends State<TripScreen> with WidgetsBindingObserver {
  late Trip _trip = widget.initial;
  DriverPosition? _driver;
  Timer? _timer;
  bool _busy = false;
  bool _openedPayment = false;
  int _stars = 0;
  final _comment = TextEditingController();
  bool _rated = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _rated = widget.initial.rated;
    _timer = Timer.periodic(widget.pollEvery, (_) => _refresh());
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _refresh();
      if (_trip.status == 'pending_payment') _openPaymentAction();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _comment.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _refresh();
  }

  Future<void> _refresh() async {
    if (!mounted) return;
    try {
      final snap = await AppScope.of(context).api.trip(_trip.id);
      if (!mounted || snap.trip == null) return;
      setState(() {
        _trip = snap.trip!;
        _driver = snap.driverPosition;
      });
      if (!_trip.isActive) _timer?.cancel();
    } on ApiException {
      // Keep showing the last known state; the next tick retries.
    }
  }

  Future<void> _openPaymentAction() async {
    final url = _trip.paymentActionUrl;
    if (url == null) return;
    _openedPayment = true;
    await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  }

  Future<void> _cancel() async {
    final fee = _trip.cancelHasFee;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Cancel this ride?'),
        content: Text(fee
            ? 'Your driver is already on the way, so a cancellation fee applies.'
            : 'No charge — no driver has accepted yet.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep ride')),
          FilledButton(
            key: const Key('confirm-cancel'),
            style: FilledButton.styleFrom(backgroundColor: Brand.red, minimumSize: const Size(0, 44)),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Cancel ride'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() => _busy = true);
    try {
      final t = await AppScope.of(context).api.cancelTrip(_trip.id, reason: 'Cancelled by rider in app');
      if (!mounted) return;
      _timer?.cancel();
      setState(() => _trip = t);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _rate() async {
    if (_stars == 0) return;
    setState(() => _busy = true);
    try {
      await AppScope.of(context).api.rateTrip(_trip.id, _stars, comment: _comment.text);
      if (!mounted) return;
      setState(() => _rated = true);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Thanks for rating your driver!')));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = _trip;
    final driverPt = t.hasDriver && _driver != null ? LatLngPoint(_driver!.lat, _driver!.lng) : null;
    return PopScope(
      canPop: !t.isActive,
      child: Scaffold(
        appBar: AppBar(
          automaticallyImplyLeading: !t.isActive,
          title: Text(t.statusTitle, key: const Key('trip-status')),
        ),
        body: Stack(
          children: [
            Positioned.fill(
              child: RideMap(
                pickup: t.status == 'in_progress' ? null : LatLngPoint(t.pickup.lat, t.pickup.lng),
                dropoff: LatLngPoint(t.dropoff.lat, t.dropoff.lng),
                driver: driverPt,
                bottomPadding: 320,
                showMyLocation: t.isActive,
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
          ],
        ),
      ),
    );
  }

  List<Widget> _panel(Trip t) {
    switch (t.status) {
      case 'pending_payment':
        return [
          const Text('Your bank needs you to confirm this card payment.', style: TextStyle(fontSize: 16)),
          const SizedBox(height: 12),
          FilledButton(onPressed: _openPaymentAction, child: Text(_openedPayment ? 'Open confirmation again' : 'Confirm payment')),
          const SizedBox(height: 4),
          const Text('We\'ll start finding your driver as soon as it\'s confirmed.', style: TextStyle(color: Colors.black54)),
          _cancelButton(t),
        ];
      case 'requested':
        return [
          const LinearProgressIndicator(),
          const SizedBox(height: 16),
          const Text('Matching you with a nearby driver…', style: TextStyle(fontSize: 16)),
          const SizedBox(height: 8),
          _routeSummary(t),
          _cancelButton(t),
        ];
      case 'accepted':
      case 'arrived':
      case 'in_progress':
        return [
          if (t.status == 'arrived')
            Container(
              padding: const EdgeInsets.all(12),
              margin: const EdgeInsets.only(bottom: 12),
              decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.25), borderRadius: BorderRadius.circular(12)),
              child: const Text('Your driver is waiting at the pickup point.', style: TextStyle(fontWeight: FontWeight.w600)),
            ),
          _driverCard(t),
          const SizedBox(height: 12),
          _routeSummary(t),
          if (t.canCancel) _cancelButton(t),
        ];
      case 'completed':
        return _completed(t);
      default: // cancelled, no_drivers
        return [
          Text(
            t.status == 'no_drivers'
                ? 'Sorry — no drivers were free nearby. Please try again in a few minutes.'
                : (t.cancelledBy == 'rider' ? 'You cancelled this ride.' : 'This ride was cancelled.'),
            style: const TextStyle(fontSize: 16),
          ),
          if (t.cancellationFee > 0) ...[
            const SizedBox(height: 8),
            Text('Cancellation fee: ${money(t.cancellationFee, t.currency)}'),
          ],
          if (t.paymentMethod == 'card' && t.cancellationFee == 0) ...[
            const SizedBox(height: 8),
            const Text('Any hold on your card has been released.', style: TextStyle(color: Colors.black54)),
          ],
          const SizedBox(height: 16),
          FilledButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Done')),
        ];
    }
  }

  Widget _driverCard(Trip t) {
    final d = t.driver;
    final v = t.vehicle;
    return Row(children: [
      CircleAvatar(
        radius: 28,
        backgroundColor: Brand.yellow,
        child: Text((d?.name.isNotEmpty ?? false) ? d!.name[0].toUpperCase() : '?', style: const TextStyle(fontSize: 22, color: Brand.charcoal)),
      ),
      const SizedBox(width: 12),
      Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(d?.name ?? 'Your driver', key: const Key('driver-name'), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
          if (d?.rating != null)
            Row(children: [
              const Icon(Icons.star, size: 16, color: Brand.yellow),
              Text(' ${d!.rating!.toStringAsFixed(1)}'),
            ]),
          if (v != null) Text(v.description, style: const TextStyle(color: Colors.black54)),
        ]),
      ),
      if (v != null)
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(border: Border.all(color: Brand.charcoal, width: 1.5), borderRadius: BorderRadius.circular(6)),
          child: Text(v.plate, key: const Key('plate'), style: const TextStyle(fontWeight: FontWeight.bold, letterSpacing: 1)),
        ),
    ]);
  }

  Widget _routeSummary(Trip t) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        _line(Icons.trip_origin, Brand.blue, t.pickup.address ?? 'Pickup'),
        _line(Icons.place, Brand.red, t.dropoff.address ?? 'Drop-off'),
        const SizedBox(height: 6),
        Text(
          '${t.paymentMethod == 'cash' ? 'Cash' : (t.cardLabel ?? 'Card')} · estimate ${money(t.estimatedFare, t.currency)}',
          style: const TextStyle(color: Colors.black54),
        ),
      ]);

  Widget _line(IconData icon, Color color, String text) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis)),
        ]),
      );

  Widget _cancelButton(Trip t) => Padding(
        padding: const EdgeInsets.only(top: 12),
        child: OutlinedButton(
          key: const Key('cancel-ride'),
          style: OutlinedButton.styleFrom(foregroundColor: Brand.red, minimumSize: const Size.fromHeight(48)),
          onPressed: _busy ? null : _cancel,
          child: const Text('Cancel ride'),
        ),
      );

  List<Widget> _completed(Trip t) => [
        Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          const Text('Total', style: TextStyle(fontSize: 18)),
          Text(money(t.fare, t.currency), key: const Key('final-fare'), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
        ]),
        const SizedBox(height: 4),
        Text(
          t.paymentMethod == 'cash'
              ? 'Paid in cash to your driver'
              : (t.paymentStatus == 'paid' ? 'Charged to ${t.cardLabel ?? 'your card'}' : 'Payment pending'),
          style: const TextStyle(color: Colors.black54),
        ),
        if (t.amountDue > 0)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text('Still owed: ${money(t.amountDue, t.currency)}', style: const TextStyle(color: Brand.red, fontWeight: FontWeight.w600)),
          ),
        if (t.fareLines.isNotEmpty)
          ExpansionTile(
            tilePadding: EdgeInsets.zero,
            title: const Text('Fare breakdown'),
            children: [
              for (final l in t.fareLines)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [Text(l.label), Text(money(l.amount))]),
                ),
            ],
          ),
        const Divider(height: 24),
        if (!_rated) ...[
          Text('How was your ride with ${t.driver?.firstName ?? 'your driver'}?', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (var i = 1; i <= 5; i++)
                IconButton(
                  key: Key('star-$i'),
                  iconSize: 40,
                  icon: Icon(i <= _stars ? Icons.star : Icons.star_border, color: Brand.yellow),
                  onPressed: () => setState(() => _stars = i),
                ),
            ],
          ),
          TextField(controller: _comment, maxLength: 500, decoration: const InputDecoration(hintText: 'Add a comment (optional)', counterText: '')),
          const SizedBox(height: 8),
          FilledButton(key: const Key('submit-rating'), onPressed: _stars == 0 || _busy ? null : _rate, child: const Text('Submit rating')),
        ],
        TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(_rated ? 'Done' : 'Skip')),
      ];
}
