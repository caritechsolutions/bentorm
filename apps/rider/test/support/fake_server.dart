import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:ridecoop_rider/api/api_client.dart';
import 'package:ridecoop_rider/api/models.dart';
import 'package:ridecoop_rider/api/rider_api.dart';
import 'package:ridecoop_rider/main.dart';
import 'package:ridecoop_rider/state/location.dart';
import 'package:ridecoop_rider/state/session.dart';
import 'package:ridecoop_rider/widgets/ride_map.dart';

/// Real API responses captured from the backend (see test/fixtures).
dynamic fixture(String name) => jsonDecode(File('test/fixtures/$name.json').readAsStringSync());

typedef Handler = Object? Function(http.Request req);

/// A scripted backend: routes are "METHOD /path" (query ignored). A handler returns either a JSON
/// value (200) or a [Reply] for other statuses.
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

class Reply {
  Reply(this.status, this.body);
  final int status;
  final Object? body;
}

/// Pumps the whole app. [signedIn] starts with a stored token.
Future<({Session session, FakeServer server})> pumpApp(WidgetTester tester, FakeServer server, {bool signedIn = true}) async {
  RideMap.enabled = false;
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);

  final session = Session(RiderApi(ApiClient(client: server.client, baseUrl: 'https://api.test/api/v1')), MemoryTokenStore(signedIn ? 'tok' : null));
  await tester.pumpWidget(RideCoopApp(session: session, location: FixedLocationService(const LatLngPoint(13.0969, -59.6145))));
  // Not awaited: the fake clock (pump) drives it, like the real app's first frames.
  unawaited(session.restore());
  await settle(tester);
  return (session: session, server: server);
}

/// pumpAndSettle never settles with spinners on screen; pump a fixed amount of time instead.
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
Future<void> unmount(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  await tester.pump(const Duration(seconds: 1));
}
