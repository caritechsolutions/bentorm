import 'package:flutter/material.dart';

import 'api/api_client.dart';
import 'api/rider_api.dart';
import 'brand.dart';
import 'config.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'state/location.dart';
import 'state/scope.dart';
import 'state/session.dart';

void main() {
  final session = Session(RiderApi(ApiClient(baseUrl: AppConfig.apiBase)), SecureTokenStore(), role: 'rider');
  runApp(RideCoopApp(session: session, location: LocationService()));
  session.restore();
}

class RideCoopApp extends StatelessWidget {
  const RideCoopApp({super.key, required this.session, required this.location});

  final Session session;
  final LocationService location;

  @override
  Widget build(BuildContext context) {
    return AppScope(
      session: session,
      location: location,
      child: MaterialApp(
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
