import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_rider/api/api_client.dart';
import 'package:ridecoop_rider/api/rider_api.dart';
import 'package:ridecoop_rider/state/push.dart';
import 'package:ridecoop_rider/state/session.dart';

import 'booking_test.dart' show signedInServer;

class FakeBridge implements PushBridge {
  String? current = 'fcm-token-1';
  final refresh = StreamController<String>.broadcast();
  final fg = StreamController<Map<String, String>>.broadcast();
  final tapped = StreamController<Map<String, String>>.broadcast();
  final shown = <({String title, bool urgent})>[];
  var deleted = 0;

  @override
  Future<bool> start() async => true;
  @override
  Future<String?> token() async => current;
  @override
  Stream<String> get tokenRefresh => refresh.stream;
  @override
  Stream<Map<String, String>> get foreground => fg.stream;
  @override
  Stream<Map<String, String>> get opened => tapped.stream;
  @override
  Future<void> showLocal(String title, String body, {required bool urgent}) async => shown.add((title: title, urgent: urgent));
  @override
  Future<void> deleteToken() async => deleted++;
}

void main() {
  test('registers the phone, shows messages while open, passes taps on, forgets on sign-out', () async {
    final server = signedInServer()
      ..on('POST /devices', {'message': 'Device registered.'})
      ..on('DELETE /devices', {'message': 'Device removed.'})
      ..on('POST /auth/logout', {'message': 'Signed out.'});
    final api = RiderApi(ApiClient(client: server.client, baseUrl: 'https://api.test/api/v1'));
    final session = Session(api, MemoryTokenStore('tok'), role: 'rider');
    await session.restore();
    final bridge = FakeBridge();
    final got = <(Map<String, String>, bool)>[];
    final push = PushRegistrar(api, session, bridge: bridge, onMessage: (d, {required tapped}) => got.add((d, tapped)));
    await push.attach();
    expect(jsonDecode(server.calls('POST /devices').single.body), {'token': 'fcm-token-1', 'platform': 'android'});

    bridge.fg.add({'title': 'Your order is on the way', 'body': 'Smoke is bringing order 1010.', 'type': 'order', 'order_id': 'abc', 'channel': 'ride_updates'});
    bridge.tapped.add({'type': 'order', 'order_id': 'abc'});
    await Future<void>.delayed(Duration.zero);
    expect(bridge.shown.single.title, 'Your order is on the way');
    expect(bridge.shown.single.urgent, isFalse);
    expect(got.map((g) => g.$2), [false, true]);
    expect(got.last.$1['order_id'], 'abc');

    await push.forget();
    expect(jsonDecode(server.calls('DELETE /devices').single.body), {'token': 'fcm-token-1'});
    push.dispose();
  });
}
