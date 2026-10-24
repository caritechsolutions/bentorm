import 'dart:async';

import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../config.dart';
import '../state/engine.dart';
import '../state/scope.dart';
import '../widgets/driver_map.dart';
import 'account_screen.dart';
import 'earnings_screen.dart';
import 'history_screen.dart';
import 'trip_screen.dart';

/// Online / offline, ride offers, and the way into the active trip.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  bool _started = false;
  bool _tripOpen = false;
  late DriverEngine _engine;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (!_started) {
      _started = true;
      _engine = AppScope.of(context).engine;
      _engine.addListener(_onEngine);
      WidgetsBinding.instance.addPostFrameCallback((_) => _engine.load());
    }
  }

  @override
  void dispose() {
    _engine.removeListener(_onEngine);
    super.dispose();
  }

  void _onEngine() {
    if (!mounted) return;
    final msg = _engine.message;
    if (msg != null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
      _engine.clearMessage();
    }
    final trip = _engine.activeTrip;
    if (trip != null && !_tripOpen) _openTrip(trip);
  }

  Future<void> _openTrip(DriverTrip trip) async {
    _tripOpen = true;
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TripScreen(initial: trip)));
    _tripOpen = false;
    if (mounted) _engine.refreshStatus();
  }

  Future<void> _openAccount() async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const AccountScreen()));
    if (mounted) _engine.refreshStatus();
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: _engine,
      builder: (context, _) {
        final e = _engine;
        return Scaffold(
          drawer: _Menu(onAccount: _openAccount),
          body: Stack(children: [
            Positioned.fill(
              child: DriverMap(me: e.position, center: e.position ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng), bottomPadding: 260),
            ),
            SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Row(children: [
                  Builder(
                    builder: (ctx) => FloatingActionButton.small(
                      heroTag: 'menu',
                      backgroundColor: Colors.white,
                      foregroundColor: Brand.charcoal,
                      onPressed: () => Scaffold.of(ctx).openDrawer(),
                      child: const Icon(Icons.menu),
                    ),
                  ),
                  const Spacer(),
                  _StatusChip(online: e.online),
                ]),
              ),
            ),
            Align(
              alignment: Alignment.bottomCenter,
              child: ConstrainedBox(
                constraints: BoxConstraints(maxWidth: 560, maxHeight: MediaQuery.of(context).size.height * 0.75),
                child: Material(
                  elevation: 12,
                  color: Colors.white,
                  borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
                  child: SafeArea(
                    top: false,
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.fromLTRB(16, 16, 16, 12),
                      child: _panel(e),
                    ),
                  ),
                ),
              ),
            ),
          ]),
        );
      },
    );
  }

  Widget _panel(DriverEngine e) {
    if (e.status == null) {
      return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
    }
    if (e.activeTrip != null) {
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('You have a ride in progress', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
        const SizedBox(height: 12),
        FilledButton(onPressed: () => _openTrip(e.activeTrip!), child: const Text('Open ride')),
      ]);
    }
    if (e.online && e.offers.isNotEmpty) {
      final o = e.offers.first;
      return OfferCard(
        key: ValueKey(o.id),
        offer: o,
        onAccept: () async {
          final trip = await e.accept(o);
          if (trip != null && mounted && !_tripOpen) _openTrip(trip);
        },
        onDecline: () => e.decline(o),
      );
    }
    if (e.online) {
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('You\'re online', key: Key('online-title'), style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        const Text('Looking for rides near you. Keep the app open.', style: TextStyle(color: Colors.black54)),
        const SizedBox(height: 12),
        const LinearProgressIndicator(),
        const SizedBox(height: 16),
        OutlinedButton(
          key: const Key('go-offline'),
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
          onPressed: e.toggling ? null : e.goOffline,
          child: const Text('Go offline'),
        ),
      ]);
    }

    final p = e.status!.profile;
    final blockers = e.blockers;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text('Hi ${AppScope.of(context).session.user?.firstName ?? 'there'}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
      const SizedBox(height: 4),
      Text(p.status == 'approved' ? 'You\'re offline.' : _statusLine(p.status), style: const TextStyle(color: Colors.black54)),
      if (blockers.isNotEmpty) ...[
        const SizedBox(height: 12),
        const Text('Before you can drive:', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 4),
        for (final b in blockers)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Padding(padding: EdgeInsets.only(top: 2), child: Icon(Icons.error_outline, size: 18, color: Brand.red)),
              const SizedBox(width: 8),
              Expanded(child: Text(b, key: const Key('blocker'))),
            ]),
          ),
        const SizedBox(height: 12),
        OutlinedButton(
          key: const Key('finish-setup'),
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(48)),
          onPressed: _openAccount,
          child: const Text('Finish setting up'),
        ),
      ],
      const SizedBox(height: 12),
      FilledButton(
        key: const Key('go-online'),
        onPressed: e.toggling ? null : e.goOnline,
        child: e.toggling
            ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
            : const Text('Go online'),
      ),
    ]);
  }

  String _statusLine(String status) => switch (status) {
        'pending' => 'Your application is being reviewed.',
        'suspended' => 'Your account is suspended. Please contact Ride Coop.',
        'rejected' => 'Your application was not approved. Please contact Ride Coop.',
        _ => status,
      };
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.online});
  final bool online;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: online ? const Color(0xFF1E9E5A) : Colors.white,
          borderRadius: BorderRadius.circular(20),
          boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 6)],
        ),
        child: Text(online ? 'ONLINE' : 'OFFLINE', style: TextStyle(fontWeight: FontWeight.bold, color: online ? Colors.white : Brand.charcoal)),
      );
}

/// A ride offer with a countdown; disappears when it expires.
class OfferCard extends StatefulWidget {
  const OfferCard({super.key, required this.offer, required this.onAccept, required this.onDecline});
  final Offer offer;
  final Future<void> Function() onAccept;
  final VoidCallback onDecline;

  @override
  State<OfferCard> createState() => _OfferCardState();
}

class _OfferCardState extends State<OfferCard> {
  Timer? _tick;
  bool _busy = false;

  int get _secondsLeft => widget.offer.expiresAt.difference(DateTime.now()).inSeconds.clamp(0, 999);

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      if (_secondsLeft <= 0) {
        _tick?.cancel();
        widget.onDecline();
      } else {
        setState(() {});
      }
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final o = widget.offer;
    final t = o.trip;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        const Expanded(child: Text('New ride request', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold))),
        CircleAvatar(
          radius: 20,
          backgroundColor: Brand.yellow,
          child: Text('$_secondsLeft', key: const Key('offer-countdown'), style: const TextStyle(fontWeight: FontWeight.bold, color: Brand.charcoal)),
        ),
      ]),
      const SizedBox(height: 8),
      if (o.estimatedEarnings != null)
        Text('You earn about ${money(o.estimatedEarnings, t.currency.isEmpty ? null : t.currency)}',
            key: const Key('offer-earnings'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: Color(0xFF1E9E5A))),
      Text('Fare ${money(t.estimatedFare, t.currency.isEmpty ? null : t.currency)} · ${t.isCash ? 'Cash' : 'Card'}', style: const TextStyle(color: Colors.black54)),
      const Divider(height: 20),
      _line(Icons.trip_origin, Brand.blue, t.pickup.address ?? 'Pickup', '${distanceText(o.distanceM)} away'),
      for (var i = 0; i < t.stops.length; i++) _line(Icons.adjust, Colors.orange, t.stops[i].address ?? 'Stop', 'Stop ${i + 1}'),
      _line(Icons.place, Brand.red, t.dropoff.address ?? 'Drop-off',
          [if (t.estimatedDistanceM != null) distanceText(t.estimatedDistanceM), if (t.estimatedDurationS != null) durationText(t.estimatedDurationS)].join(' · ')),
      const SizedBox(height: 6),
      Wrap(spacing: 8, runSpacing: 4, children: [
        Chip(avatar: const Icon(Icons.person, size: 18), label: Text('${t.passengers}')),
        if (t.luggage > 0) Chip(avatar: const Icon(Icons.luggage, size: 18), label: Text('${t.luggage}')),
        for (final f in t.features) Chip(label: Text(f.replaceAll('_', ' '))),
      ]),
      if (t.riderNote != null && t.riderNote!.isNotEmpty) Text('Note: ${t.riderNote}', style: const TextStyle(fontStyle: FontStyle.italic)),
      const SizedBox(height: 12),
      Row(children: [
        Expanded(
          child: OutlinedButton(
            key: const Key('decline'),
            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            onPressed: _busy ? null : widget.onDecline,
            child: const Text('Decline'),
          ),
        ),
        const SizedBox(width: 12),
        Expanded(
          flex: 2,
          child: FilledButton(
            key: const Key('accept'),
            style: FilledButton.styleFrom(backgroundColor: const Color(0xFF1E9E5A)),
            onPressed: _busy
                ? null
                : () async {
                    setState(() => _busy = true);
                    await widget.onAccept();
                    if (mounted) setState(() => _busy = false);
                  },
            child: const Text('Accept'),
          ),
        ),
      ]),
    ]);
  }

  Widget _line(IconData icon, Color color, String text, String trailing) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(child: Text(text, maxLines: 2, overflow: TextOverflow.ellipsis)),
          if (trailing.isNotEmpty) Text(trailing, style: const TextStyle(color: Colors.black54, fontSize: 13)),
        ]),
      );
}

class _Menu extends StatelessWidget {
  const _Menu({required this.onAccount});
  final VoidCallback onAccount;

  @override
  Widget build(BuildContext context) {
    final scope = AppScope.of(context);
    final user = scope.session.user;
    final profile = scope.engine.status?.profile;
    return Drawer(
      child: SafeArea(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Padding(
            padding: const EdgeInsets.all(20),
            child: Row(children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: Brand.charcoal,
                child: Text((user?.name.isNotEmpty ?? false) ? user!.name[0].toUpperCase() : '?', style: const TextStyle(fontSize: 24, color: Colors.white)),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(user?.name ?? '', overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  if (profile != null)
                    Row(children: [
                      const Icon(Icons.star, size: 16, color: Brand.yellow),
                      Flexible(child: Text(' ${profile.rating.toStringAsFixed(1)} · ${profile.completedTrips} trips', overflow: TextOverflow.ellipsis)),
                    ]),
                ]),
              ),
            ]),
          ),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.badge_outlined),
            title: const Text('Account, vehicle & documents'),
            onTap: () {
              Navigator.pop(context);
              onAccount();
            },
          ),
          ListTile(
            leading: const Icon(Icons.payments_outlined),
            title: const Text('Earnings'),
            onTap: () {
              Navigator.pop(context);
              Navigator.of(context).push(MaterialPageRoute(builder: (_) => const EarningsScreen()));
            },
          ),
          ListTile(
            leading: const Icon(Icons.history),
            title: const Text('Trip history'),
            onTap: () {
              Navigator.pop(context);
              Navigator.of(context).push(MaterialPageRoute(builder: (_) => const HistoryScreen()));
            },
          ),
          const Spacer(),
          ListTile(
            leading: const Icon(Icons.logout),
            title: const Text('Sign out'),
            onTap: () async {
              Navigator.pop(context);
              if (scope.engine.online) await scope.engine.goOffline();
              await scope.push?.forget();
              await scope.session.signOut();
            },
          ),
        ]),
      ),
    );
  }
}
