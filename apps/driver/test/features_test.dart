import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:ridecoop_driver/main.dart' show navigatorKey;

import 'support/fake_server.dart';

/// Fixtures from the local backend (scratchpad features-fixtures.sh): Rita's ride with promo SAVE10 and 5.00 credit,
/// a message from her, her video call, and a 5.00 tip after the ride.

class FakeMedia extends CallMedia {
  final calls = <String>[];
  @override
  Future<void> start({required List<Map<String, dynamic>> iceServers, required bool video}) async => calls.add('start video=$video');
  @override
  Future<Map<String, dynamic>> createOffer() async => {'type': 'offer', 'sdp': 'fake-offer'};
  @override
  Future<Map<String, dynamic>> answerOffer(Map<String, dynamic> offer) async {
    calls.add('offer ${offer['sdp']}');
    onState?.call('connected');
    return {'type': 'answer', 'sdp': 'fake-answer'};
  }

  @override
  Future<void> acceptAnswer(Map<String, dynamic> answer) async {}
  @override
  Future<void> addCandidate(Map<String, dynamic> candidate) async {}
  @override
  Future<void> setMuted(bool muted) async {}
  @override
  Future<void> setSpeaker(bool on) async {}
  @override
  Future<void> setCamera(bool on) async {}
  @override
  Future<void> switchCamera() async {}
  @override
  Widget localView() => const SizedBox();
  @override
  Widget remoteView() => const SizedBox();
  @override
  Future<void> dispose() async {}
}

void main() {
  final launched = <Uri>[];
  late FakeMedia media;
  setUp(() {
    launched.clear();
    Future<bool> record(Uri u) async {
      launched.add(u);
      return true;
    }

    Safety.launch = record;
    AppPages.launch = record;
    media = FakeMedia();
    CallScreen.makeMedia = () => media;
    CallScreen.pollEvery = const Duration(milliseconds: 300);
  });
  tearDown(() => CallScreen.makeMedia = WebRtcCallMedia.new);

  final trip = fixture('trip_unread') as Map<String, dynamic>;
  final id = trip['id'] as String;

  testWidgets('on a ride: message the rider, share the trip, SOS; then collect what the rider owes and see the tip', (tester) async {
    dynamic current = trip;
    final server = driverServer()
      ..on('GET /driver/trips/active', {'trip': trip})
      ..handle('GET /driver/trips/$id', (_) => current)
      ..on('GET /trips/$id/messages', fixture('messages'))
      ..on('POST /trips/$id/messages', {'id': 9, 'body': 'On my way', 'mine': true, 'sender': 'Smoke', 'read': false}, status: 201)
      ..on('POST /trips/$id/share', {'url': 'https://ridecoop.test/t/abc', 'text': 'Follow my ride live on Ride Coop: https://ridecoop.test/t/abc'})
      ..on('POST /sos', {'alert_id': 1, 'message': 'Ride Coop has been alerted and can see where you are.'});
    final app = await pumpDriverApp(tester, server);
    expect(find.text('Pick up Rita'), findsOneWidget);
    expect(find.text('1'), findsOneWidget, reason: 'unread message badge');
    expect(find.byKey(const Key('call-rider')), findsOneWidget);
    expect(find.byKey(const Key('video-call-rider')), findsOneWidget);

    await tapKey(tester, 'open-chat');
    expect(find.text('I am by the red door'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('chat-input')), 'On my way');
    await tapKey(tester, 'chat-send');
    expect(jsonDecode(server.calls('POST /trips/$id/messages').single.body), {'body': 'On my way'});
    await tester.pageBack();
    await settle(tester);

    await tapKey(tester, 'share-trip');
    await tapKey(tester, 'share-sms');
    expect(launched.single.scheme, 'sms');

    await tapKey(tester, 'sos');
    expect(find.byKey(const Key('sos-call-211')), findsOneWidget);
    await tapKey(tester, 'sos-alert');
    expect(jsonDecode(server.calls('POST /sos').single.body)['trip_id'], id);
    expect(find.byKey(const Key('sos-sent')), findsOneWidget);
    await tester.tapAt(const Offset(10, 10)); // close the sheet
    await settle(tester);

    // Completed: the rider used promo + credit, so collect the payable amount (Ride Coop pays the rest).
    current = fixture('trip_completed_payable');
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    final done = fixture('trip_completed_payable') as Map<String, dynamic>;
    expect(find.text('BBD ${(done['payable'] as num).toStringAsFixed(2)}'), findsOneWidget);
    expect(find.byKey(const Key('promo-note')), findsOneWidget);
    expect(find.byKey(const Key('tip-received')), findsNothing);
    await unmount(tester, app);
  });

  testWidgets('the rider video-calls: it rings, the driver answers on video, then hangs up', (tester) async {
    final ring = fixture('call_incoming')['call'] as Map<String, dynamic>;
    final cid = ring['id'] as String;
    var accepted = false;
    final server = driverServer()
      ..on('GET /calls/incoming', fixture('call_incoming'))
      ..handle('POST /calls/$cid/accept', (_) {
        accepted = true;
        return fixture('call_accepted');
      })
      // Like the server: only signals after the one asked for, and the status as it is now.
      ..handle('GET /calls/$cid/signals', (req) => {
            'signals': int.parse(req.url.queryParameters['after'] ?? '0') >= 1 ? <dynamic>[] : fixture('call_signals')['signals'],
            'status': accepted ? 'accepted' : 'ringing',
          })
      ..handle('GET /calls/$cid', (_) => accepted ? fixture('call_accepted') : ring)
      ..on('POST /calls/$cid/signal', {'ok': true})
      ..on('POST /calls/$cid/end', {...fixture('call_accepted') as Map<String, dynamic>, 'status': 'ended'});
    final app = await pumpDriverApp(tester, server);
    final watcher = IncomingCalls(navigator: navigatorKey, client: app.session.api.client, session: app.session);
    unawaited(watcher.check()); // completes when the call screen closes
    await settle(tester);
    expect(find.text('Rita'), findsOneWidget);
    expect(find.text('Video call'), findsOneWidget);

    await tapKey(tester, 'call-accept-video');
    await settle(tester, 6);
    expect(server.calls('POST /calls/$cid/accept'), hasLength(1));
    expect(media.calls, ['start video=true', 'offer v=0']);
    final answer = jsonDecode(server.calls('POST /calls/$cid/signal').first.body) as Map;
    expect(answer['type'], 'answer');
    expect(find.byKey(const Key('call-flip')), findsOneWidget, reason: 'camera on');

    await tapKey(tester, 'call-end');
    expect(server.calls('POST /calls/$cid/end'), hasLength(1));
    expect(find.byKey(const Key('call-name')), findsNothing);
    watcher.stop();
    await unmount(tester, app);
  });

  testWidgets('a tipped ride shows the tip; the statement opens from the menu', (tester) async {
    final server = driverServer()..on('POST /app-links', fixture('app_link'));
    final app = await pumpDriverApp(tester, server);
    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tapKey(tester, 'drawer-statement');
    expect(jsonDecode(server.calls('POST /app-links').single.body), {'page': 'statement'});
    expect(launched.single.toString(), fixture('app_link')['url']);
    await unmount(tester, app);

    final tipped = fixture('trip_tipped') as Map<String, dynamic>;
    final server2 = driverServer()
      ..on('GET /driver/trips/active', {'trip': trip})
      ..on('GET /driver/trips/$id', tipped);
    final app2 = await pumpDriverApp(tester, server2);
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.byKey(const Key('tip-received')), findsOneWidget);
    expect(find.textContaining('BBD ${(tipped['tip'] as num).toStringAsFixed(2)} tip from Rita'), findsOneWidget);
    await unmount(tester, app2);
  });
}
