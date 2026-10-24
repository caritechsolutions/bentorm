import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:ridecoop_driver/api/driver_api.dart';
import 'package:ridecoop_driver/state/push.dart';

import 'support/fake_server.dart';

class FakeBridge implements PushBridge {
  String? current = 'fcm-token-1';
  final refresh = StreamController<String>.broadcast();
  final fg = StreamController<Map<String, String>>.broadcast();
  final tapped = StreamController<Map<String, String>>.broadcast();
  final shown = <({String title, bool loud})>[];
  var deleted = 0;
  var allow = true;

  @override
  Future<bool> start() async => allow;
  @override
  Future<String?> token() async => current;
  @override
  Stream<String> get tokenRefresh => refresh.stream;
  @override
  Stream<Map<String, String>> get foreground => fg.stream;
  @override
  Stream<Map<String, String>> get opened => tapped.stream;
  @override
  Future<void> showLocal(String title, String body, {required bool loud}) async => shown.add((title: title, loud: loud));
  @override
  Future<void> deleteToken() async => deleted++;
}

void main() {
  late FakeServer server;
  late DriverApi api;
  late Session session;
  late FakeBridge bridge;
  late List<Map<String, String>> opened;

  setUp(() async {
    server = driverServer()
      ..on('POST /devices', {'message': 'Device registered.'})
      ..on('DELETE /devices', {'message': 'Device removed.'});
    api = DriverApi(ApiClient(client: server.client, baseUrl: 'https://api.test/api/v1'));
    session = Session(api, MemoryTokenStore('tok'), role: 'driver');
    await session.restore();
    bridge = FakeBridge();
    opened = [];
  });

  Map<String, dynamic> body(int i) => jsonDecode(server.calls('POST /devices')[i].body) as Map<String, dynamic>;

  test('registers the phone when signed in, and again when Firebase rotates the token', () async {
    final push = PushRegistrar(api, session, bridge: bridge, onOpened: opened.add);
    await push.attach();
    expect(server.calls('POST /devices'), hasLength(1));
    expect(body(0), {'token': 'fcm-token-1', 'platform': 'android'});

    bridge.refresh.add('fcm-token-2');
    await pumpEventQueue();
    expect(body(1)['token'], 'fcm-token-2');
    push.dispose();
  });

  test('an offer arriving while the app is open rings (loud) and checks for offers at once', () async {
    final push = PushRegistrar(api, session, bridge: bridge, onOpened: opened.add);
    await push.attach();
    bridge.fg.add({'type': 'offer', 'channel': 'ride_offers', 'title': 'New ride request', 'body': 'Pickup: Bay Street'});
    bridge.fg.add({'type': 'trip', 'channel': 'ride_updates', 'title': 'Ride cancelled', 'body': 'Rita cancelled the ride.'});
    bridge.tapped.add({'type': 'offer', 'trip_id': 'abc'});
    await pumpEventQueue();
    expect(bridge.shown, [(title: 'New ride request', loud: true), (title: 'Ride cancelled', loud: false)]);
    expect(opened.map((d) => d['type']), ['offer', 'trip', 'offer']);
    push.dispose();
  });

  test('signing out tells the server to stop pushing to this phone', () async {
    final push = PushRegistrar(api, session, bridge: bridge);
    await push.attach();
    await push.forget();
    expect(jsonDecode(server.calls('DELETE /devices').single.body), {'token': 'fcm-token-1'});
    await session.signOut();
    await pumpEventQueue();
    push.dispose();
  });

  test('notifications refused or Firebase not configured: nothing registered, nothing breaks', () async {
    bridge.allow = false;
    await PushRegistrar(api, session, bridge: bridge).attach();
    expect(server.calls('POST /devices'), isEmpty);

    final off = PushRegistrar(api, session); // no Firebase options in test builds
    expect(off.bridge, isNull);
    await off.attach();
    await off.forget();
    expect(server.calls('POST /devices'), isEmpty);
  });
}
