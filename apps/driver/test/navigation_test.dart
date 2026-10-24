import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_driver/navigation/navigation_screen.dart';
import 'package:ridecoop_driver/screens/trip_screen.dart';

import 'support/fake_server.dart';

void main() {
  final trip = fixture('driver_active_accepted')['trip'] as Map<String, dynamic>;
  final tripId = trip['id'] as String;

  late List<NavTarget> inApp;
  late int termsShown;

  setUp(() {
    inApp = [];
    termsShown = 0;
    NavigationScreen.acceptTerms = () async {
      termsShown++;
      return true;
    };
    TripScreen.openInApp = (context, target) async {
      inApp.add(target);
      return NavOutcome.closed;
    };
  });

  FakeServer server({required bool allowed}) => driverServer()
    ..on('GET /driver/trips/active', fixture('driver_active_accepted'))
    ..on('GET /driver/trips/$tripId', trip)
    ..handle('POST /driver/trips/$tripId/navigation', (_) => {'in_app': allowed, 'reason': allowed ? null : 'off'});

  testWidgets('admin switched it on: navigation opens inside the app and the start is counted', (tester) async {
    final s = server(allowed: true);
    final app = await pumpDriverApp(tester, s);
    await tapKey(tester, 'navigate');

    final calls = s.calls('POST /driver/trips/$tripId/navigation').map((r) => jsonDecode(r.body)).toList();
    expect(calls, [
      {'destination': 'pickup', 'check': true},
      {'destination': 'pickup'},
    ], reason: 'ask first, then the counted start');
    expect(termsShown, 1);
    expect(inApp.single.kind, 'pickup');
    expect(inApp.single.lat, 13.0969);
    expect(inApp.single.title, startsWith('Pickup:'));
    expect(openedUrls, isEmpty, reason: 'Google Maps app not opened');
    await unmount(tester, app);
  });

  testWidgets('switched off (or limit reached): Google Maps app, nothing counted, no terms', (tester) async {
    final s = server(allowed: false);
    final app = await pumpDriverApp(tester, s);
    await tapKey(tester, 'navigate');

    expect(s.calls('POST /driver/trips/$tripId/navigation').map((r) => jsonDecode(r.body)).toList(), [
      {'destination': 'pickup', 'check': true},
    ]);
    expect(termsShown, 0);
    expect(inApp, isEmpty);
    expect(openedUrls.single.queryParameters['destination'], '13.0969,-59.6145');
    await unmount(tester, app);
  });

  testWidgets('terms declined: Google Maps app, nothing counted', (tester) async {
    NavigationScreen.acceptTerms = () async => false;
    final s = server(allowed: true);
    final app = await pumpDriverApp(tester, s);
    await tapKey(tester, 'navigate');
    expect(s.calls('POST /driver/trips/$tripId/navigation'), hasLength(1));
    expect(inApp, isEmpty);
    expect(openedUrls, hasLength(1));
    await unmount(tester, app);
  });

  testWidgets('in-app navigation fails to route: falls back to Google Maps', (tester) async {
    TripScreen.openInApp = (context, target) async => NavOutcome.useExternal;
    final app = await pumpDriverApp(tester, server(allowed: true));
    await tapKey(tester, 'navigate');
    expect(openedUrls, hasLength(1));
    await unmount(tester, app);
  });
}
