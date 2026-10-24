import 'dart:async';

import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/shop_models.dart';
import '../../brand.dart';
import '../../state/scope.dart';
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

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _schedule();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    _clock?.cancel();
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

  @override
  Widget build(BuildContext context) {
    final o = _order;
    final change = o.changeRequest;
    final left = o.status == 'placed' && o.expiresAt != null ? o.expiresAt!.difference(DateTime.now()) : null;
    return Scaffold(
      appBar: AppBar(title: Text('Order ${o.number}')),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
          children: [
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
            if (o.status == 'ready')
              const Text('A driver will bring it to you. We\'ll let you know when it\'s on the way.', style: TextStyle(color: Colors.black54)),
            if ((o.status == 'declined' || o.status == 'cancelled' || o.status == 'expired'))
              Text(
                [
                  if (o.declineReason != null) o.declineReason!,
                  if (o.paymentStatus == 'released' || o.status == 'expired') 'Nothing was charged — the hold on your card is released.',
                ].join(' '),
                key: const Key('order-ended'),
                style: const TextStyle(color: Colors.black54),
              ),
            if (o.isActive && o.status != 'pending_payment') ...[const SizedBox(height: 16), _Progress(step: o.step)],
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
            if (o.cardLabel != null)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text(
                  '${o.cardLabel} · ${switch (o.paymentStatus) { 'paid' => 'charged', 'authorized' => 'held, charged when ready', 'released' => 'hold released', 'failed' => 'not accepted', _ => 'waiting' }}',
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
            if (o.canCancel) ...[
              const SizedBox(height: 24),
              OutlinedButton(key: const Key('cancel-order'), onPressed: _busy ? null : _cancel, child: const Text('Cancel order')),
            ],
          ],
        ),
      ),
    );
  }
}

class _Progress extends StatelessWidget {
  const _Progress({required this.step});
  final int step;

  static const labels = ['Sent', 'Preparing', 'Ready', 'On the way'];

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
