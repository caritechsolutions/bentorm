import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../config.dart';
import '../state/scope.dart';

/// Saved cards: add (hosted card page at the gateway), make default, remove.
class CardsScreen extends StatefulWidget {
  const CardsScreen({super.key});

  @override
  State<CardsScreen> createState() => _CardsScreenState();
}

class _CardsScreenState extends State<CardsScreen> with WidgetsBindingObserver {
  List<PaymentCard>? _cards;
  String? _error;
  bool _busy = false;
  String? _pendingId; // card being added at the gateway
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _poll?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _pendingId != null) _checkPending();
  }

  Future<void> _load() async {
    try {
      final cards = await AppScope.of(context).api.cards();
      if (mounted) setState(() => _cards = cards.where((c) => c.status == 'active').toList());
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _add() async {
    final scope = AppScope.of(context);
    setState(() => _busy = true);
    try {
      final here = await scope.location.current() ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng);
      final pending = await scope.api.startAddCard(here.lat, here.lng);
      if (pending.redirectUrl == null) throw ApiException(500, 'Card setup is not available right now.');
      _pendingId = pending.id;
      await launchUrl(Uri.parse(pending.redirectUrl!), mode: LaunchMode.externalApplication);
      // Poll while the rider is on the card page (web keeps this tab running; apps also check on resume).
      _poll?.cancel();
      var ticks = 0;
      _poll = Timer.periodic(const Duration(seconds: 3), (t) {
        if (++ticks > 100) t.cancel();
        _checkPending();
      });
    } on ApiException catch (e) {
      _snack(e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _checkPending() async {
    final id = _pendingId;
    if (id == null || !mounted) return;
    try {
      final c = await AppScope.of(context).api.card(id);
      if (c.status == 'pending') return;
      _poll?.cancel();
      _pendingId = null;
      _snack(c.status == 'active' ? '${c.label} added.' : (c.failureReason ?? 'The card could not be added.'));
      await _load();
    } on ApiException {
      // Try again on the next tick.
    }
  }

  Future<void> _makeDefault(PaymentCard c) async {
    try {
      await AppScope.of(context).api.makeDefaultCard(c.id);
      await _load();
    } on ApiException catch (e) {
      _snack(e.message);
    }
  }

  Future<void> _remove(PaymentCard c) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Remove ${c.label}?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Remove', style: TextStyle(color: Brand.red))),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await AppScope.of(context).api.deleteCard(c.id);
      await _load();
    } on ApiException catch (e) {
      _snack(e.message);
    }
  }

  void _snack(String m) {
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));
  }

  @override
  Widget build(BuildContext context) {
    final cards = _cards;
    return Scaffold(
      appBar: AppBar(title: const Text('Payment')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const ListTile(
            contentPadding: EdgeInsets.zero,
            leading: Icon(Icons.payments_outlined),
            title: Text('Cash'),
            subtitle: Text('Always available — pay your driver at the end of the ride'),
          ),
          const Divider(),
          if (_error != null) Text(_error!, style: const TextStyle(color: Brand.red)),
          if (cards == null && _error == null) const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
          if (cards != null && cards.isEmpty)
            const Padding(padding: EdgeInsets.symmetric(vertical: 12), child: Text('No cards yet. Add one to pay in the app.', style: TextStyle(color: Colors.black54))),
          if (cards != null)
            for (final c in cards)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const Icon(Icons.credit_card),
                title: Text(c.label),
                subtitle: Text(c.expired ? 'Expired' : 'Expires ${c.expiry}${c.isDefault ? ' · Default' : ''}'),
                trailing: PopupMenuButton<String>(
                  onSelected: (v) => v == 'default' ? _makeDefault(c) : _remove(c),
                  itemBuilder: (_) => [
                    if (!c.isDefault) const PopupMenuItem(value: 'default', child: Text('Make default')),
                    const PopupMenuItem(value: 'remove', child: Text('Remove')),
                  ],
                ),
              ),
          const SizedBox(height: 16),
          FilledButton.icon(
            key: const Key('add-card'),
            onPressed: _busy ? null : _add,
            icon: const Icon(Icons.add_card),
            label: Text(_pendingId != null ? 'Waiting for the card page…' : 'Add a card'),
          ),
          const SizedBox(height: 8),
          const Text(
            'Card details are entered on our payment provider\'s secure page — Ride Coop never sees your full card number.',
            style: TextStyle(color: Colors.black54, fontSize: 13),
          ),
        ],
      ),
    );
  }
}
