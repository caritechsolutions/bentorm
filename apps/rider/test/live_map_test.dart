import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:web_socket_channel/web_socket_channel.dart';

import 'booking_test.dart' show signedInServer;
import 'support/fake_server.dart';

/// A websocket stand-in: the test pushes server frames in and reads what the app sent.
class FakeSocket implements WebSocketChannel {
  final incoming = StreamController<dynamic>();
  final sent = <Map<String, dynamic>>[];
  late final _sink = _FakeSink(this);

  void serverSends(String event, Map<String, dynamic> data, {String? channel}) =>
      incoming.add(jsonEncode({'event': event, if (channel != null) 'channel': channel, 'data': jsonEncode(data)}));

  List<String> subscribed() => [for (final m in sent) if (m['event'] == 'pusher:subscribe') '${(m['data'] as Map)['channel']}'];
  List<String> unsubscribed() => [for (final m in sent) if (m['event'] == 'pusher:unsubscribe') '${(m['data'] as Map)['channel']}'];

  @override
  Stream get stream => incoming.stream;
  @override
  WebSocketSink get sink => _sink;
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _FakeSink implements WebSocketSink {
  _FakeSink(this.s);
  final FakeSocket s;
  @override
  void add(dynamic data) => s.sent.add(jsonDecode('$data') as Map<String, dynamic>);
  @override
  Future close([int? closeCode, String? closeReason]) async {}
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

({RealtimeClient client, FakeSocket socket}) liveClient() {
  final socket = FakeSocket();
  final auth = MockClient((r) async => http.Response(jsonEncode({'auth': 'key:sig'}), 200));
  final rt = RealtimeClient(key: 'k', host: 'ws.test', port: 443, tls: true, authEndpoint: 'https://api.test/api/broadcasting/auth', connect: (_) => socket, httpClient: auth);
  return (client: rt, socket: socket);
}

void main() {
  test('the live client joins extra channels and tags events with their channel', () async {
    final live = liveClient();
    final events = <RealtimeEvent>[];
    live.client.events.listen(events.add);
    live.client.start(token: 'tok', userId: 25);
    live.socket.serverSends('pusher:connection_established', {'socket_id': '1.2'});
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(live.socket.subscribed(), ['private-user.25']);

    live.client.join('private-trip.T1');
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(live.socket.subscribed(), ['private-user.25', 'private-trip.T1']);

    live.socket.serverSends('driver.location', {'lat': 13.1, 'lng': -59.6, 'heading': 90}, channel: 'private-trip.T1');
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(events.single.name, 'driver.location');
    expect(events.single.channel, 'private-trip.T1');
    expect(events.single.data['heading'], 90);

    // After a reconnect both channels are joined again; leave stops the trip one.
    live.socket.serverSends('pusher:connection_established', {'socket_id': '1.3'});
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(live.socket.subscribed().where((c) => c == 'private-trip.T1'), hasLength(2));
    live.client.leave('private-trip.T1');
    expect(live.socket.unsubscribed(), ['private-trip.T1']);
    live.client.stop();
  });

  test('a gliding car moves smoothly to each new position and turns the short way', () {
    final g = GlidingPoint(over: const Duration(seconds: 4));
    final t0 = DateTime(2026, 10, 27, 12);
    g.moveTo(13.10, -59.60, 350, speed: 12, now: t0);
    expect(g.at(t0)!.lat, 13.10);
    g.moveTo(13.11, -59.60, 10, speed: 12, now: t0); // at speed the phone's heading is used
    final half = g.at(t0.add(const Duration(seconds: 2)))!;
    expect(half.lat, closeTo(13.105, 0.0001), reason: 'half way after half the time');
    expect(half.heading < 5 || half.heading > 355, isTrue, reason: '350° → 10° turns through north, not round the back');
    expect(g.at(t0.add(const Duration(seconds: 5)))!.lat, closeTo(13.11, 0.00001));
    expect(g.moving(t0.add(const Duration(seconds: 5))), isFalse);
    // No heading given: face the direction of travel (east here).
    g.moveTo(13.11, -59.59, null, now: t0.add(const Duration(seconds: 6)));
    expect(g.at(t0.add(const Duration(seconds: 10)))!.heading, closeTo(90, 1));
  });

  test('a parked car stays put and keeps facing the same way; it turns only once it really moves', () {
    final g = GlidingPoint();
    final t0 = DateTime(2026, 10, 28, 12);
    g.moveTo(13.10, -59.60, 90, speed: 10, now: t0); // driving east
    // Stopped: GPS wanders 3 m with a random compass heading at 0 m/s.
    for (var i = 1; i <= 5; i++) {
      g.moveTo(13.10 + 0.00002 * (i.isEven ? 1 : -1), -59.60, i * 70.0, speed: 0, now: t0.add(Duration(seconds: 4 * i)));
      final p = g.at(t0.add(Duration(seconds: 4 * i + 2)))!;
      expect(p.heading, closeTo(90, 0.001), reason: 'no spinning while parked');
      expect(p.lat, 13.10, reason: 'no wandering while parked');
    }
    // Pulls away north: faces the way it actually went, not the noisy compass at low speed.
    g.moveTo(13.1002, -59.60, 250, speed: 2, now: t0.add(const Duration(seconds: 24)));
    expect(g.at(t0.add(const Duration(seconds: 30)))!.heading, closeTo(0, 1));
  });

  test('after a gap (app in the background) the car jumps to where it is instead of sliding across the map', () {
    final g = GlidingPoint();
    final t0 = DateTime(2026, 10, 28, 12);
    g.moveTo(13.10, -59.60, null, now: t0);
    g.moveTo(13.105, -59.60, null, now: t0.add(const Duration(seconds: 60)));
    final p = g.at(t0.add(const Duration(seconds: 60)))!;
    expect(p.lat, 13.105, reason: 'already there, no straight-line slide');
    expect(g.moving(t0.add(const Duration(seconds: 60))), isFalse);
  });

  testWidgets('map icons draw: cars by type and labelled pins', (tester) async {
    await tester.runAsync(() async {
      final std = await MapIcons.car('standard', 2);
      final prem = await MapIcons.car('premium', 2);
      final pin = await MapIcons.labelPin('Pickup · 4 min', Brand.blue, 2);
      expect(std.png.length, greaterThan(100));
      expect(prem.png, isNot(equals(std.png)), reason: 'premium looks different');
      expect(pin.width, greaterThan(60));
      expect(pin.anchorY, greaterThan(0.6), reason: 'the dot under the label is the map point');
      expect(identical(await MapIcons.car('standard', 2), std), isTrue, reason: 'cached');
    });
  });

  testWidgets('during a ride the trip screen listens to the trip channel and leaves it when done', (tester) async {
    final live = liveClient();
    final snap = fixture('active_accepted_features') as Map<String, dynamic>;
    final id = snap['trip']['id'] as String;
    final server = signedInServer()
      ..on('GET /trips/active', snap)
      ..on('GET /trips/$id', snap);
    live.client.start(token: 'tok', userId: 25);
    live.socket.serverSends('pusher:connection_established', {'socket_id': '1.2'});
    await pumpApp(tester, server, realtime: live.client);
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
    await settle(tester);
    expect(find.text('Your driver is on the way'), findsOneWidget);
    expect(live.socket.subscribed(), contains('private-trip.$id'));

    // A live position: no request needed for it.
    final before = server.calls('GET /trips/$id').length;
    live.socket.serverSends('driver.location', {'lat': 13.0985, 'lng': -59.6125, 'heading': 180}, channel: 'private-trip.$id');
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
    await tester.pump();
    expect(server.calls('GET /trips/$id').length, before);

    // While the driver comes, the rider's phone shares where it is (they may not be at the pin).
    server.on('POST /trips/$id/rider-location', {'ok': true, 'shared': true});
    await tester.pump(const Duration(seconds: 11));
    await settle(tester);
    expect(server.calls('POST /trips/$id/rider-location'), isNotEmpty);

    // The map follows the driver; the recenter button brings it back after the rider moves the map.
    expect(find.byKey(const Key('recenter')), findsOneWidget);
    // Fold the trip panel down to see the map, then open it again.
    expect(find.byKey(const Key('driver-name')), findsOneWidget);
    await tester.tap(find.byKey(const Key('panel-toggle')));
    await settle(tester);
    expect(find.byKey(const Key('trip-compact')), findsOneWidget);
    expect(find.byKey(const Key('driver-name')), findsNothing);
    await tester.tap(find.byKey(const Key('trip-compact')));
    await settle(tester);
    expect(find.byKey(const Key('driver-name')), findsOneWidget);

    await unmount(tester);
    expect(live.socket.unsubscribed(), contains('private-trip.$id'));
    live.client.stop();
  });
}
