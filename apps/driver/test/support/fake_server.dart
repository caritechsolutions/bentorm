import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:ridecoop_driver/api/driver_api.dart';
import 'package:ridecoop_driver/api/models.dart';
import 'package:ridecoop_driver/main.dart';
import 'package:ridecoop_driver/screens/documents_screen.dart';
import 'package:ridecoop_driver/screens/trip_screen.dart';
import 'package:ridecoop_driver/state/engine.dart';
import 'package:ridecoop_driver/state/location.dart';
import 'package:ridecoop_driver/widgets/driver_map.dart';

/// Real API responses captured from the backend (see test/fixtures).
dynamic fixture(String name) => jsonDecode(File('test/fixtures/$name.json').readAsStringSync());

typedef Handler = Object? Function(http.Request req);

class Reply {
  Reply(this.status, this.body);
  final int status;
  final Object? body;
}

/// Scripted backend: "METHOD /path" (query ignored) → JSON (200) or a [Reply].
class FakeServer {
  final Map<String, Handler> routes = {};
  final List<http.Request> log = [];

  void on(String route, Object? body, {int status = 200}) => routes[route] = (_) => Reply(status, body);
  void handle(String route, Handler h) => routes[route] = h;

  List<http.Request> calls(String route) {
    final parts = route.split(' ');
    return log.where((r) => r.method == parts[0] && r.url.path.endsWith(parts[1])).toList();
  }

  http.Client get client => MockClient((req) async {
        log.add(req);
        final path = req.url.path.replaceFirst('/api/v1', '');
        final h = routes['${req.method} $path'];
        if (h == null) return http.Response(jsonEncode({'message': 'No fake for ${req.method} $path'}), 404);
        final out = h(req);
        final reply = out is Reply ? out : Reply(200, out);
        return http.Response(jsonEncode(reply.body), reply.status, headers: {'content-type': 'application/json'});
      });
}

/// Offers captured earlier have expired; push their deadline into the future.
Map<String, dynamic> freshOffers() {
  final o = fixture('offers') as Map<String, dynamic>;
  for (final offer in o['offers'] as List) {
    (offer as Map<String, dynamic>)['expires_at'] = DateTime.now().toUtc().add(const Duration(seconds: 20)).toIso8601String();
  }
  return o;
}

/// The routes every signed-in driver screen touches.
FakeServer driverServer({String profile = 'profile_approved'}) {
  final vehicleId = (fixture(profile)['profile']['current_vehicle'] as Map?)?['id'];
  final s = FakeServer();
  if (vehicleId != null) s.on('GET /driver/vehicles/$vehicleId/documents', fixture('vehicle_documents_new'));
  return s
  ..on('GET /me', fixture('me_driver'))
  ..on('GET /driver/profile', fixture(profile))
  ..on('GET /driver/trips/active', fixture('driver_active_none'))
  ..on('POST /driver/location', fixture('location_idle'))
  ..on('GET /driver/offers', fixture('offers_none'))
  ..on('GET /service-areas', fixture('service_areas'))
  ..on('GET /vehicle-types', fixture('vehicle_types'))
  ..on('GET /vehicle-features', fixture('vehicle_features'))
  ..on('GET /driver/documents', fixture('documents_partial'));
}

class TestApp {
  TestApp(this.session, this.engine, this.server, this.location);
  final Session session;
  final DriverEngine engine;
  final FakeServer server;
  final FixedLocationService location;
}

final List<Uri> openedUrls = [];

Future<TestApp> pumpDriverApp(WidgetTester tester, FakeServer server, {bool signedIn = true}) async {
  DriverMap.enabled = false;
  DriverEngine.keepAwake = false;
  DocumentsScreen.pick = (_) async => (bytes: utf8.encode('fake-jpeg-bytes'), name: 'licence.jpg', mime: 'image/jpeg');
  openedUrls.clear();
  TripScreen.openUrl = (url) async {
    openedUrls.add(url);
    return true;
  };
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final api = DriverApi(ApiClient(client: server.client, baseUrl: 'https://api.test/api/v1'));
  final session = Session(api, MemoryTokenStore(signedIn ? 'tok' : null), role: 'driver');
  final location = FixedLocationService(const LatLngPoint(13.0975, -59.6130));
  final engine = DriverEngine(api, location);
  session.addListener(() {
    if (session.state == SessionState.signedOut) engine.reset();
  });
  await tester.pumpWidget(DriverApp(session: session, location: location, engine: engine));
  unawaited(session.restore());
  await settle(tester);
  return TestApp(session, engine, server, location);
}

/// pumpAndSettle never settles with spinners/progress bars on screen; pump fixed time instead.
Future<void> settle(WidgetTester tester, [int frames = 12]) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

Future<void> tapKey(WidgetTester tester, String key) async {
  final f = find.byKey(Key(key));
  await tester.ensureVisible(f);
  await tester.pump();
  await tester.tap(f);
  await settle(tester);
}

/// Disposes the app so periodic timers stop before the test ends.
Future<void> unmount(WidgetTester tester, TestApp app) async {
  app.engine.reset();
  await tester.pumpWidget(const SizedBox());
  await tester.pump(const Duration(seconds: 1));
}

/// Multipart bodies hold binary; decode leniently to look for form fields.
String bodyText(http.Request r) => latin1.decode(r.bodyBytes);
