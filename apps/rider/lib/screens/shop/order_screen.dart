import 'dart:async';

import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart' show AppPages, CallScreen, ChatScreen, RealtimeEvent;

import '../../api/api_client.dart';
import '../../api/models.dart' show LatLngPoint;
import '../../api/shop_models.dart';
import '../../brand.dart';
import '../../state/scope.dart';
import '../../widgets/ride_map.dart';
import 'checkout_screen.dart';

/// Follow an order: progress, the store's questions, what was ordered and what it costs.
class OrderScreen extends StatefulWidget {
  const OrderScreen({super.key, required this.initial});
  final Order initial;

  /// How often an open order is refreshed.
  static Duration pollEvery = const Duration(seconds: 5);

  @override
  State<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends State<OrderScreen> with WidgetsBindingObserver {
  late Order _order = widget.initial;
  Timer? _poll;
  Timer? _clock;
  bool _busy = false;
  StreamSubscription<RealtimeEvent>? _live;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _schedule();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _live = AppScope.of(context).realtime?.events.listen((e) {
        if (e.name.startsWith('order.') || e.name.startsWith('trip.') || e.name == 'chat.message') _refresh();
      });
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    _clock?.cancel();
    _live?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _refresh();
  }

  void _schedule() {
    _poll?.cancel();
    _clock?.cancel();
    if (!_order.isActive) return;
    _poll = Timer.periodic(OrderScreen.pollEvery, (_) => _refresh());
    if (_order.status == 'placed') _clock = Timer.periodic(const Duration(seconds: 1), (_) {
        if (mounted) setState(() {});
      });
  }

  Future<void> _refresh() async {
    try {
      final o = await AppScope.of(context).api.order(_order.id);
      if (!mounted) return;
      final statusChanged = o.status != _order.status;
      setState(() => _order = o);
      if (statusChanged) _schedule();
    } on ApiException {
      // Try again on the next tick.
    }
  }

  Future<void> _act(Future<Order> Function() call) async {
    setState(() => _busy = true);
    try {
      final o = await call();
      if (!mounted) return;
      setState(() => _order = o);
      _schedule();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      _refresh();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _cancel() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Cancel this order?'),
        content: const Text('The hold on your card is released.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep it')),
          TextButton(key: const Key('confirm-cancel-order'), onPressed: () => Navigator.pop(ctx, true), child: const Text('Cancel order')),
        ],
      ),
    );
    if (ok == true && mounted) await _act(() => AppScope.of(context).api.cancelOrder(_order.id));
  }

  String _clockText(DateTime? at) {
    if (at == null) return '';
    final h = at.hour % 12 == 0 ? 12 : at.hour % 12;
    return '$h:${at.minute.toString().padLeft(2, '0')} ${at.hour < 12 ? 'am' : 'pm'}';
  }

  /// The driver, their car, a call button, and where they are now.
  List<Widget> _driverSection(Order o) {
    final d = o.delivery!;
    final driverPt = d.driverLat != null && d.driverLng != null ? LatLngPoint(d.driverLat!, d.driverLng!) : null;
    return [
      const SizedBox(height: 16),
      Row(key: const Key('order-driver'), children: [
        CircleAvatar(backgroundColor: Brand.yellow, child: Text(d.driverName!.isEmpty ? '?' : d.driverName![0].toUpperCase(), style: const TextStyle(color: Brand.charcoal))),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(d.driverName!, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            if (d.vehicle != null) Text(d.vehicle!, style: const TextStyle(color: Colors.black54)),
          ]),
        ),
        if (d.canChat && d.tripId != null)
          Padding(
            padding: const EdgeInsets.only(right: 6),
            child: Badge(
              isLabelVisible: d.unreadMessages > 0,
              label: Text('${d.unreadMessages}'),
              child: IconButton.filledTonal(
                key: const Key('order-chat'),
                tooltip: 'Message ${d.driverName}',
                onPressed: () => _chat(d),
                icon: const Icon(Icons.chat_bubble_outline),
              ),
            ),
          ),
        if (d.canCall && d.tripId != null)
          IconButton.filledTonal(
            key: const Key('order-call'),
            tooltip: 'Call ${d.driverName}',
            onPressed: () {
              final scope = AppScope.of(context);
              CallScreen.start(context, client: scope.api.client, tripId: d.tripId!, video: false, realtime: scope.realtime);
            },
            icon: const Icon(Icons.call),
          )
        else if (d.driverPhone != null)
          IconButton.filledTonal(
            key: const Key('call-driver'),
            tooltip: 'Call ${d.driverName}',
            onPressed: () => CheckoutScreen.openUrl(Uri.parse('tel:${d.driverPhone!.replaceAll(RegExp(r'[^0-9+]'), '')}')),
            icon: const Icon(Icons.phone),
          ),
      ]),
      if (RideMap.enabled && driverPt != null)
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(14),
            child: SizedBox(
              height: 220,
              child: RideMap(
                pickup: o.status == 'picked_up' || o.storeLat == null ? null : LatLngPoint(o.storeLat!, o.storeLng!),
                dropoff: o.dropoffLat == null ? null : LatLngPoint(o.dropoffLat!, o.dropoffLng!),
                driver: driverPt,
              ),
            ),
          ),
        ),
    ];
  }

  Future<void> _chat(DeliveryState d) async {
    final scope = AppScope.of(context);
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => ChatScreen(
        client: scope.api.client,
        tripId: d.tripId!,
        otherName: d.driverName ?? 'Your driver',
        realtime: scope.realtime,
        quickReplies: const ['Please call when you arrive', 'Leave it at the door', 'I\'m coming down', 'Thank you!'],
      ),
    ));
    _refresh();
  }

  double? _tip;

  Widget _tipBox(Order o) {
    final options = <double>{for (final a in const [2.0, 5.0, 10.0]) a}.toList();
    return Container(
      key: const Key('order-tip-box'),
      margin: const EdgeInsets.only(top: 16),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(12)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Tip your driver?', style: TextStyle(fontWeight: FontWeight.w600)),
        const Text('100% goes to the driver, on the same card.', style: TextStyle(color: Colors.black54, fontSize: 13)),
        const SizedBox(height: 8),
        Wrap(spacing: 8, children: [
          for (final a in options)
            ChoiceChip(
              key: Key('order-tip-${a.toStringAsFixed(0)}'),
              label: Text(money(a, o.currency)),
              selected: _tip == a,
              onSelected: (_) => setState(() => _tip = a),
            ),
        ]),
        if (_tip != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: FilledButton(
              key: const Key('order-send-tip'),
              onPressed: _busy
                  ? null
                  : () async {
                      final amount = _tip!;
                      await _act(() => AppScope.of(context).api.tipOrder(o.id, amount));
                      if (mounted && !_order.canTip) {
                        setState(() => _tip = null);
                        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Thanks! Your tip goes straight to your driver.')));
                      }
                    },
              child: Text('Tip ${money(_tip!, o.currency)}'),
            ),
          ),
      ]),
    );
  }

  /// The customer reads this to the driver at the door; the driver can't finish without it.
  Widget _pinCard(String pin) => Container(
        key: const Key('delivery-pin-card'),
        margin: const EdgeInsets.only(top: 12),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: Brand.blue.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(14), border: Border.all(color: Brand.blue)),
        child: Row(children: [
          const Icon(Icons.lock_outline, color: Brand.blue),
          const SizedBox(width: 12),
          const Expanded(child: Text('Your delivery PIN — tell it to the driver when they hand you the order. Don\'t share it before.')),
          const SizedBox(width: 8),
          Text(pin.split('').join(' '), key: const Key('delivery-pin'), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold, letterSpacing: 2, color: Brand.blue)),
        ]),
      );

  @override
  Widget build(BuildContext context) {
    final o = _order;
    final change = o.changeRequest;
    final left = o.status == 'placed' && o.expiresAt != null ? o.expiresAt!.difference(DateTime.now()) : null;
    return Scaffold(
      appBar: AppBar(title: Text('Order ${o.number}')),
      body: RefreshIndicator(
        onRefresh: _refresh,
        // Not a lazy ListView: the whole order is built, so nothing (the PIN, the cancel button) depends on scrolling.
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(o.headline, key: const Key('order-headline'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
            const SizedBox(height: 6),
            if (o.status == 'placed')
              Text(
                left != null && left.inSeconds > 0
                    ? 'They have ${left.inMinutes}:${(left.inSeconds % 60).toString().padLeft(2, '0')} to answer. If they don\'t, your card hold is released.'
                    : 'Checking with the store…',
                style: const TextStyle(color: Colors.black54),
              ),
            if (o.status == 'accepted' && o.readyBy != null)
              Text('Ready around ${_clockText(o.readyBy)}', style: const TextStyle(color: Colors.black54)),
            if (o.status == 'ready' && !(o.delivery?.hasDriver ?? false))
              const Text('A driver will bring it to you. We\'ll let you know when it\'s on the way.', style: TextStyle(color: Colors.black54)),
            if (o.delivery?.noDriverAlert == true && o.isActive)
              const Padding(
                padding: EdgeInsets.only(top: 6),
                child: Text('Drivers are busy right now and we keep trying. You can also cancel — nothing is charged.',
                    key: Key('no-driver-note'), style: TextStyle(color: Brand.red)),
              ),
            if ((o.status == 'declined' || o.status == 'cancelled' || o.status == 'expired'))
              Text(
                [
                  if (o.declineReason != null) o.declineReason!,
                  if (o.paymentStatus == 'released' || o.status == 'expired') 'Nothing was charged — the hold on your card is released.',
                ].join(' '),
                key: const Key('order-ended'),
                style: const TextStyle(color: Colors.black54),
              ),
            if ((o.isActive && o.status != 'pending_payment') || o.status == 'delivered') ...[const SizedBox(height: 16), _Progress(step: o.step)],
            if (o.isActive && (o.delivery?.hasDriver ?? false)) ..._driverSection(o),
            if (o.isActive && o.delivery?.pin != null && o.status != 'placed') _pinCard(o.delivery!.pin!),
            if (o.canTip && AppScope.of(context).config.on('tips')) _tipBox(o),
            if (o.needsBank) ...[
              const SizedBox(height: 16),
              FilledButton.icon(
                key: const Key('open-bank'),
                onPressed: () => CheckoutScreen.openUrl(Uri.parse(o.redirectUrl!)),
                icon: const Icon(Icons.lock_outline),
                label: const Text('Open the bank page'),
              ),
            ],
            if (change != null && change.pending) ...[
              const SizedBox(height: 16),
              Container(
                key: const Key('order-change'),
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.18), borderRadius: BorderRadius.circular(12), border: Border.all(color: Brand.yellow)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text('${o.storeName} is out of ${change.items.join(', ')}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  if (change.note != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text('“${change.note}”')),
                  const SizedBox(height: 6),
                  Text('Continue without ${change.items.length == 1 ? 'it' : 'them'}? Your new total is ${money(change.newTotal, o.currency)}.'),
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(
                      child: OutlinedButton(
                        key: const Key('change-decline'),
                        onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.answerOrderChange(o.id, false)),
                        child: const Text('Cancel order'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: FilledButton(
                        key: const Key('change-accept'),
                        style: FilledButton.styleFrom(minimumSize: const Size(0, 44)),
                        onPressed: _busy ? null : () => _act(() => AppScope.of(context).api.answerOrderChange(o.id, true)),
                        child: const Text('Continue'),
                      ),
                    ),
                  ]),
                ]),
              ),
            ],
            const Divider(height: 32),
            Text(o.storeName, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
            const SizedBox(height: 6),
            for (final i in o.items)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('${i.quantity}× ${i.name}',
                          style: TextStyle(fontWeight: FontWeight.w600, decoration: i.removed ? TextDecoration.lineThrough : null, color: i.removed ? Colors.black38 : null)),
                      if (i.optionsText.isNotEmpty) Text(i.optionsText, style: const TextStyle(fontSize: 13, color: Colors.black54)),
                      if (i.removed) const Text('Out of stock — removed', style: TextStyle(fontSize: 12, color: Brand.red)),
                    ]),
                  ),
                  Text(money(i.lineTotal, o.currency), style: TextStyle(color: i.removed ? Colors.black38 : null)),
                ]),
              ),
            const Divider(height: 24),
            for (final l in o.lines)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 3),
                child: Row(children: [Expanded(child: Text(l.label)), Text(money(l.amount, o.currency))]),
              ),
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Row(children: [
                const Expanded(child: Text('Total', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17))),
                Text(money(o.totals.total, o.currency), key: const Key('order-screen-total'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17)),
              ]),
            ),
            if (o.totals.tip > 0 || o.totals.discount > 0 || o.totals.credit > 0)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  [
                    if (o.totals.discount > 0) 'Promo −${money(o.totals.discount, o.currency)}',
                    if (o.totals.credit > 0) 'Ride credit −${money(o.totals.credit, o.currency)}',
                    if (o.totals.tip > 0) 'Tip ${money(o.totals.tip, o.currency)} (thank you!)',
                  ].join(' · '),
                  key: const Key('order-extras'),
                  style: const TextStyle(color: Colors.black54),
                ),
              ),
            if (o.cardLabel != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  '${o.cardLabel} · ${switch (o.paymentStatus) { 'paid' => 'charged', 'authorized' => 'held, charged on delivery', 'released' => 'hold released', 'failed' => 'not accepted', _ => 'waiting' }}',
                  style: const TextStyle(color: Colors.black54),
                ),
              ),
            if (o.address != null) ...[
              const SizedBox(height: 16),
              Row(children: [
                const Icon(Icons.place, color: Brand.red, size: 20),
                const SizedBox(width: 8),
                Expanded(child: Text(o.address!)),
              ]),
            ],
            if (o.note != null) Padding(padding: const EdgeInsets.only(top: 6, left: 28), child: Text('“${o.note}”', style: const TextStyle(color: Colors.black54))),
            if (!o.isActive && AppScope.of(context).config.on('support'))
              Padding(
                padding: const EdgeInsets.only(top: 16),
                child: TextButton.icon(
                  key: const Key('order-help'),
                  onPressed: () async {
                    final messenger = ScaffoldMessenger.of(context);
                    try {
                      await AppPages.open(AppScope.of(context).api.client, 'support/new?category=delivery&order=${o.id}');
                    } on ApiException catch (e) {
                      messenger.showSnackBar(SnackBar(content: Text(e.message)));
                    }
                  },
                  icon: const Icon(Icons.help_outline),
                  label: const Text('Problem with this order?'),
                ),
              ),
            if (o.canCancel) ...[
              const SizedBox(height: 24),
              OutlinedButton(key: const Key('cancel-order'), onPressed: _busy ? null : _cancel, child: const Text('Cancel order')),
            ],
          ]),
        ),
      ),
    );
  }
}

class _Progress extends StatelessWidget {
  const _Progress({required this.step});
  final int step;

  static const labels = ['Sent', 'Preparing', 'Ready', 'On the way', 'Delivered'];

  @override
  Widget build(BuildContext context) => Row(children: [
        for (var i = 0; i < labels.length; i++)
          Expanded(
            child: Column(children: [
              Container(
                height: 6,
                margin: const EdgeInsets.symmetric(horizontal: 2),
                decoration: BoxDecoration(color: i <= step ? Brand.blue : const Color(0xFFE3E5E8), borderRadius: BorderRadius.circular(3)),
              ),
              const SizedBox(height: 6),
              Text(labels[i], style: TextStyle(fontSize: 12, fontWeight: i == step ? FontWeight.bold : FontWeight.normal, color: i <= step ? Brand.charcoal : Colors.black38)),
            ]),
          ),
      ]);
}
