import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import 'api/driver_api.dart';
import 'config.dart';
import 'screens/home_screen.dart';
import 'state/engine.dart';
import 'state/location.dart';
import 'state/push.dart';
import 'state/scope.dart';

void main() {
  final api = DriverApi(ApiClient(baseUrl: AppConfig.apiBase));
  final session = Session(api, SecureTokenStore('ridecoop_driver_token'), role: 'driver');
  final location = LocationService();
  final engine = DriverEngine(api, location);
  // Signed out (by the driver, or because the token was revoked): stop pinging and polling.
  session.addListener(() {
    if (session.state == SessionState.signedOut) engine.reset();
  });
  final live = LiveLink(session);
  final calls = IncomingCalls(navigator: navigatorKey, client: api.client, session: session, live: live);
  // Push: a tapped or arriving notification (new ride, stop change, cancellation) refreshes at once; a call rings;
  // a tapped help reply opens its page.
  final push = PushRegistrar(
    api,
    session,
    onOpened: (data) {
      if (data['type'] == 'call') {
        calls.check();
      } else {
        engine.refreshNow();
      }
    },
    onTapped: (data) {
      final page = data['page'];
      if (page != null && page.isNotEmpty) AppPages.open(api.client, page).catchError((Object _) {});
    },
  );
  // Live updates: a new offer or a change to the trip refreshes at once instead of waiting for the next poll.
  live.addListener(() => _listenLive(live, engine));
  runApp(DriverApp(session: session, location: location, engine: engine, push: push, live: live));
  session.restore();
  push.attach();
  live.start();
  calls.start();
}

/// Lets a call or a tapped notification open a screen from outside the widget tree.
final navigatorKey = GlobalKey<NavigatorState>();

RealtimeClient? _liveFor;
void _listenLive(LiveLink live, DriverEngine engine) {
  final rt = live.realtime;
  if (rt == null || identical(rt, _liveFor)) return;
  _liveFor = rt;
  rt.events.where((e) => e.name.startsWith('trip.') || e.name.startsWith('offer')).listen((_) => engine.refreshNow());
}

class DriverApp extends StatelessWidget {
  const DriverApp({super.key, required this.session, required this.location, required this.engine, this.push, this.live});

  final Session session;
  final LocationService location;
  final DriverEngine engine;
  final PushRegistrar? push;
  final LiveLink? live;

  @override
  Widget build(BuildContext context) {
    return AppScope(
      session: session,
      location: location,
      engine: engine,
      push: push,
      live: live,
      child: MaterialApp(
        navigatorKey: navigatorKey,
        title: 'Ride Coop Driver',
        debugShowCheckedModeBanner: false,
        theme: Brand.theme(primary: Brand.charcoal),
        home: ListenableBuilder(
          listenable: session,
          builder: (context, _) => switch (session.state) {
            SessionState.loading => const _Splash(),
            SessionState.signedOut => PhoneLoginScreen(
                session: session,
                title: 'Ride Coop Driver',
                tagline: 'Drive local. Earn fair.',
                icon: Icons.local_taxi_rounded,
                iconColor: Brand.charcoal,
              ),
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
            Icon(Icons.local_taxi_rounded, size: 72, color: Brand.charcoal),
            SizedBox(height: 12),
            Text('Ride Coop Driver', style: TextStyle(fontSize: 26, fontWeight: FontWeight.bold, color: Brand.charcoal)),
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
