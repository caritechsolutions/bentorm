import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart' show AppPages, IncomingCalls, LiveLink;

import 'api/api_client.dart';
import 'api/rider_api.dart';
import 'brand.dart';
import 'config.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'screens/shop/order_screen.dart';
import 'screens/trip_screen.dart';
import 'state/cart.dart';
import 'state/location.dart';
import 'state/push.dart';
import 'state/scope.dart';
import 'state/session.dart';

/// Lets a tapped notification open the right screen from outside the widget tree.
final navigatorKey = GlobalKey<NavigatorState>();

void main() {
  final api = RiderApi(ApiClient(baseUrl: AppConfig.apiBase));
  final session = Session(api, SecureTokenStore(), role: 'rider');
  final live = LiveLink(session);
  final calls = IncomingCalls(navigator: navigatorKey, client: api.client, session: session, live: live);
  // Notifications: a call rings at once; a tapped one opens its order, ride or help page.
  // (Open screens refresh themselves, so nothing else is needed while the app is in front.)
  final push = PushRegistrar(api, session, onMessage: (data, {required tapped}) async {
    if (data['type'] == 'call') {
      calls.check();
      return;
    }
    if (!tapped) return;
    try {
      final page = data['page'];
      final orderId = data['order_id'];
      final tripId = data['trip_id'];
      if (page != null && page.isNotEmpty) {
        await AppPages.open(api.client, page);
      } else if (orderId != null && orderId.isNotEmpty) {
        final order = await api.order(orderId);
        navigatorKey.currentState?.push(MaterialPageRoute(builder: (_) => OrderScreen(initial: order)));
      } else if (tripId != null && tripId.isNotEmpty && data['type'] == 'chat') {
        final snap = await api.trip(tripId);
        if (snap.trip != null) navigatorKey.currentState?.push(MaterialPageRoute(builder: (_) => TripScreen(initial: snap.trip!)));
      }
    } on ApiException {
      // Offline: the order or ride is on the home screen when the app connects.
    }
  });
  runApp(RideCoopApp(session: session, location: LocationService(), push: push, live: live));
  session.restore();
  live.start();
  calls.start();
  push.attach();
}

class RideCoopApp extends StatelessWidget {
  RideCoopApp({super.key, required this.session, required this.location, Cart? cart, this.push, this.live}) : cart = cart ?? Cart();

  final Session session;
  final LocationService location;
  final Cart cart;
  final PushRegistrar? push;
  final LiveLink? live;

  @override
  Widget build(BuildContext context) {
    return AppScope(
      session: session,
      location: location,
      cart: cart,
      push: push,
      live: live,
      child: MaterialApp(
        navigatorKey: navigatorKey,
        title: Brand.name,
        debugShowCheckedModeBanner: false,
        theme: Brand.theme(),
        home: ListenableBuilder(
          listenable: session,
          builder: (context, _) => switch (session.state) {
            SessionState.loading => const _Splash(),
            SessionState.signedOut => const LoginScreen(),
            SessionState.signedIn => const HomeScreen(),
            SessionState.offline => _Offline(onRetry: session.restore),
          },
        ),
      ),
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(Icons.local_taxi_rounded, size: 72, color: Brand.yellow),
            SizedBox(height: 12),
            Text(Brand.name, style: TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: Brand.charcoal)),
            SizedBox(height: 24),
            CircularProgressIndicator(),
          ]),
        ),
      );
}

class _Offline extends StatelessWidget {
  const _Offline({required this.onRetry});
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) => Scaffold(
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const Icon(Icons.wifi_off, size: 56, color: Colors.black38),
              const SizedBox(height: 12),
              const Text('Can\'t reach Ride Coop', style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              const Text('Check your internet connection and try again.', textAlign: TextAlign.center),
              const SizedBox(height: 20),
              FilledButton(onPressed: onRetry, child: const Text('Try again')),
            ]),
          ),
        ),
      );
}
