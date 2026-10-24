import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:ridecoop_rider/main.dart' show navigatorKey;

import 'booking_test.dart' show signedInServer;
import 'support/fake_server.dart';

/// Fixtures for these come from the local backend (scratchpad features-fixtures.sh): a rider with a saved Home and
/// "Gym", promo SAVE10, 5.00 ride credit, a ride with driver Smoke (chat, video call), and a tip afterwards.

/// Camera and microphone stand-in: no WebRTC in widget tests.
class FakeMedia extends CallMedia {
  final calls = <String>[];
  @override
  Future<void> start({required List<Map<String, dynamic>> iceServers, required bool video}) async => calls.add('start video=$video');
  @override
  Future<Map<String, dynamic>> createOffer() async => {'type': 'offer', 'sdp': 'fake-offer'};
  @override
  Future<Map<String, dynamic>> answerOffer(Map<String, dynamic> offer) async => {'type': 'answer', 'sdp': 'fake-answer'};
  @override
  Future<void> acceptAnswer(Map<String, dynamic> answer) async {
    calls.add('answer ${answer['sdp']}');
    onState?.call('connected');
  }

  @override
  Future<void> addCandidate(Map<String, dynamic> candidate) async => calls.add('candidate');
  @override
  Future<void> setMuted(bool muted) async => calls.add('muted=$muted');
  @override
  Future<void> setSpeaker(bool on) async {}
  @override
  Future<void> setCamera(bool on) async => calls.add('camera=$on');
  @override
  Future<void> switchCamera() async {}
  @override
  Widget localView() => const SizedBox();
  @override
  Widget remoteView() => const SizedBox();
  @override
  Future<void> dispose() async {}
}

Map<String, dynamic> configWith(Map<String, bool> changes) {
  final c = fixture('config_features') as Map<String, dynamic>;
  (c['features'] as Map<String, dynamic>).addAll(changes);
  return c;
}

void main() {
  final launched = <Uri>[];
  late Future<bool> Function(Uri) realSafety;
  late Future<bool> Function(Uri) realPages;
  late FakeMedia media;
  setUp(() {
    launched.clear();
    realSafety = Safety.launch;
    realPages = AppPages.launch;
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
  tearDown(() {
    Safety.launch = realSafety;
    AppPages.launch = realPages;
    CallScreen.makeMedia = WebRtcCallMedia.new;
  });

  testWidgets('book later from a saved place, with a promo code and ride credit', (tester) async {
    final booked = fixture('trip_scheduled') as Map<String, dynamic>;
    final server = signedInServer()
      ..on('GET /me/places', fixture('places'))
      ..on('GET /me/credit', fixture('credit'))
      ..on('POST /fares/estimate', fixture('estimate_promo'))
      ..on('POST /promo-codes/check', fixture('promo_ok'))
      ..on('POST /trips', booked, status: 201)
      ..on('GET /trips/${booked['id']}', {'trip': booked, 'driver_position': null});
    await pumpApp(tester, server, config: fixture('config_features') as Map<String, dynamic>);

    // One tap on "Home" (saved place) prices the ride there.
    expect(find.text('Home'), findsOneWidget);
    expect(find.text('Gym'), findsOneWidget);
    await tapKey(tester, 'saved-1');
    expect(server.calls('POST /fares/estimate').last.body, contains('"dropoff_lat":13.0745'));

    // Later: tomorrow, the first time offered.
    await tapKey(tester, 'when');
    await tapKey(tester, 'when-day-1');
    final firstTime = find.byWidgetPredicate((w) => w.key is ValueKey<String> && (w.key as ValueKey<String>).value.startsWith('when-time-')).first;
    await tester.tap(firstTime);
    await settle(tester);
    expect(server.calls('POST /fares/estimate').last.body, contains('scheduled_at'));
    expect(find.textContaining('tomorrow at'), findsWidgets);

    // Promo code: checked, then the prices show the discount.
    await tapKey(tester, 'promo');
    await tester.enterText(find.byKey(const Key('promo-input')), 'save10');
    await tapKey(tester, 'promo-apply');
    expect(server.calls('POST /promo-codes/check').single.body, contains('"code":"save10"'));
    expect(server.calls('POST /fares/estimate').last.body, contains('"promo_code":"SAVE10"'));
    final q = (fixture('estimate_promo')['quotes'] as List).first as Map<String, dynamic>;
    final after = (q['fare'] as num) - (q['discount'] as num);
    expect(find.textContaining('BBD ${after.toStringAsFixed(2)}'), findsWidgets);

    // Ride credit (5.00 on the account).
    expect(find.textContaining('Use ride credit (BBD 5.00)'), findsOneWidget);
    await tapKey(tester, 'use-credit');

    await tapKey(tester, 'request');
    final body = server.calls('POST /trips').single.body;
    expect(body, contains('"scheduled_at"'));
    expect(body, contains('"promo_code":"SAVE10"'));
    expect(body, contains('"use_credit":true'));
    expect(find.text('Ride booked'), findsOneWidget);
    expect(find.byKey(const Key('scheduled-time')), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('during a ride: message the driver, share the trip, SOS', (tester) async {
    final snap = fixture('active_unread') as Map<String, dynamic>;
    final id = snap['trip']['id'] as String;
    final server = signedInServer()
      ..on('GET /trips/active', snap)
      ..on('GET /trips/$id', snap)
      ..on('GET /trips/$id/messages', fixture('messages'))
      ..on('POST /trips/$id/messages', fixture('message_sent'), status: 201)
      ..on('POST /trips/$id/share', fixture('share'))
      ..on('POST /sos', fixture('sos'));
    await pumpApp(tester, server, config: fixture('config_features') as Map<String, dynamic>);
    expect(find.text('Your driver is on the way'), findsOneWidget);
    expect(find.text('1'), findsOneWidget, reason: 'unread message badge');

    await tapKey(tester, 'open-chat');
    expect(find.text('On my way'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('chat-input')), 'Coming out now');
    await tapKey(tester, 'chat-send');
    expect(server.calls('POST /trips/$id/messages').single.body, contains('Coming out now'));
    await tester.pageBack();
    await settle(tester);

    await tapKey(tester, 'share-trip');
    await tapKey(tester, 'share-whatsapp');
    expect(launched.single.toString(), startsWith('https://wa.me/?text='));
    expect(Uri.decodeComponent(launched.single.query), contains(fixture('share')['url'] as String));

    await tapKey(tester, 'sos');
    await tapKey(tester, 'sos-call-211');
    expect(launched.last.toString(), 'tel:211');
    await tapKey(tester, 'sos-alert');
    final sos = server.calls('POST /sos').single.body;
    expect(sos, contains('"trip_id":"$id"'));
    expect(sos, contains('"lat":13.0969'));
    expect(find.byKey(const Key('sos-sent')), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('video call the driver in the app; it connects and ends', (tester) async {
    final snap = fixture('active_unread') as Map<String, dynamic>;
    final id = snap['trip']['id'] as String;
    final call = fixture('call_outgoing') as Map<String, dynamic>;
    final cid = call['id'] as String;
    var answered = false;
    final server = signedInServer()
      ..on('GET /trips/active', snap)
      ..on('GET /trips/$id', snap)
      ..on('POST /trips/$id/calls', call, status: 201)
      ..on('POST /calls/$cid/signal', {'ok': true})
      ..handle('GET /calls/$cid/signals', (_) => answered
          ? {
              'signals': [
                {'seq': 1, 'type': 'answer', 'payload': {'type': 'answer', 'sdp': 'driver-answer'}},
              ],
              'status': 'accepted',
            }
          : {'signals': <dynamic>[], 'status': 'ringing'})
      ..on('GET /calls/$cid', fixture('call_accepted'))
      ..on('POST /calls/$cid/end', fixture('call_ended'));
    await pumpApp(tester, server, config: fixture('config_features') as Map<String, dynamic>);

    await tapKey(tester, 'video-call-driver');
    expect(server.calls('POST /trips/$id/calls').single.body, contains('"video":true'));
    expect(find.byKey(const Key('call-name')), findsOneWidget);
    expect(find.text('Ringing…'), findsOneWidget);
    expect(media.calls.first, 'start video=true');
    expect(server.calls('POST /calls/$cid/signal').first.body, contains('"type":"offer"'));

    answered = true;
    await settle(tester, 6);
    expect(media.calls, contains('answer driver-answer'));
    expect(find.text('Ringing…'), findsNothing);
    expect(find.textContaining('0:0'), findsOneWidget, reason: 'call timer once connected');

    await tapKey(tester, 'call-mute');
    expect(media.calls, contains('muted=true'));
    await tapKey(tester, 'call-end');
    expect(server.calls('POST /calls/$cid/end'), hasLength(1));
    expect(find.byKey(const Key('call-name')), findsNothing);
    expect(find.text('Your driver is on the way'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('after the ride: promo and credit shown, tip the driver, report a lost item', (tester) async {
    final snap = fixture('trip_completed_tip') as Map<String, dynamic>;
    final t = snap['trip'] as Map<String, dynamic>;
    final id = t['id'] as String;
    final server = signedInServer()
      ..on('GET /trips/active', snap)
      ..on('GET /trips/$id', snap)
      ..on('POST /trips/$id/tip', fixture('trip_tipped'))
      ..on('POST /app-links', fixture('app_link'));
    await pumpApp(tester, server, config: fixture('config_features') as Map<String, dynamic>);

    expect(find.text('BBD ${(t['payable'] as num).toStringAsFixed(2)}'), findsOneWidget, reason: 'what the rider paid');
    expect(find.textContaining('(SAVE10)'), findsOneWidget);
    expect(find.textContaining('ride credit −BBD 5.00'), findsOneWidget);

    // Fare 22.00 → 10/15/20% rounded: 2, 3, 4.
    expect(find.byKey(const Key('tip-box')), findsOneWidget);
    await tapKey(tester, 'tip-4');
    await tapKey(tester, 'send-tip');
    expect(server.calls('POST /trips/$id/tip').single.body, contains('"amount":4'));
    expect(find.byKey(const Key('tip-box')), findsNothing);
    expect(find.byKey(const Key('tip-done')), findsOneWidget);

    await tapKey(tester, 'lost-item');
    expect(server.calls('POST /app-links').single.body, contains('support/new?category=lost_item&trip=$id'));
    expect(launched.single.toString(), fixture('app_link')['url']);
    await unmount(tester);
  });

  testWidgets('features the admin turned off are hidden', (tester) async {
    final snap = fixture('active_unread') as Map<String, dynamic>;
    final id = snap['trip']['id'] as String;
    final server = signedInServer()
      ..on('GET /trips/active', snap)
      ..on('GET /trips/$id', snap);
    await pumpApp(tester, server, config: configWith({'chat': false, 'sos': false, 'share_trip': false}));
    expect(find.text('Your driver is on the way'), findsOneWidget);
    expect(find.byKey(const Key('open-chat')), findsNothing);
    expect(find.byKey(const Key('sos')), findsNothing);
    expect(find.byKey(const Key('share-trip')), findsNothing);
    expect(find.byKey(const Key('call-driver')), findsOneWidget, reason: 'calls are still on');
    await unmount(tester);
  });

  testWidgets('invite friends, ride credit and saved places from the menu', (tester) async {
    final server = signedInServer()
      ..on('GET /me/referral', fixture('referral'))
      ..on('POST /me/referral', fixture('referral_bad'), status: 422)
      ..on('GET /me/credit', fixture('credit'))
      ..on('GET /me/places', fixture('places'))
      ..on('POST /app-links', fixture('app_link'));
    await pumpApp(tester, server, config: fixture('config_features') as Map<String, dynamic>);

    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tapKey(tester, 'drawer-invite');
    expect(find.text(fixture('referral')['code'] as String), findsOneWidget);
    await tapKey(tester, 'invite-whatsapp');
    expect(Uri.decodeComponent(launched.single.query), contains(fixture('referral')['code'] as String));
    await tester.enterText(find.byKey(const Key('friend-code')), 'zzzzzz');
    await tapKey(tester, 'friend-code-apply');
    expect(find.text(fixture('referral_bad')['errors']['code'][0] as String), findsOneWidget);
    await tester.pageBack();
    await settle(tester);

    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tapKey(tester, 'drawer-credit');
    expect(find.text('BBD 5.00'), findsOneWidget);
    expect(find.text('Welcome gift'), findsOneWidget);
    await tester.pageBack();
    await settle(tester);

    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tapKey(tester, 'drawer-places');
    expect(find.text('Rockley, Christ Church'), findsWidgets);
    expect(find.text('Gym'), findsOneWidget);
    await tester.pageBack();
    await settle(tester);

    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tapKey(tester, 'drawer-help');
    expect(server.calls('POST /app-links').single.body, contains('"page":"help"'));
    await unmount(tester);
  });

  testWidgets('an incoming call rings and can be declined', (tester) async {
    // The driver's view of a call is the same shape; here the driver calls the rider.
    final ring = fixture('call_outgoing') as Map<String, dynamic>;
    final incoming = {...ring, 'incoming': true, 'other_name': 'Smoke'};
    final cid = ring['id'] as String;
    final server = signedInServer()
      ..on('GET /calls/incoming', {'call': incoming})
      ..on('GET /calls/$cid/signals', {'signals': <dynamic>[], 'status': 'ringing'})
      ..on('POST /calls/$cid/decline', {...incoming, 'status': 'declined'});
    final app = await pumpApp(tester, server);
    final watcher = IncomingCalls(navigator: navigatorKey, client: app.session.api.client, session: app.session);
    unawaited(watcher.check()); // completes when the call screen closes
    await settle(tester);
    expect(find.text('Smoke'), findsOneWidget);
    expect(find.byKey(const Key('call-accept-video')), findsOneWidget);
    await tapKey(tester, 'call-decline');
    expect(server.calls('POST /calls/$cid/decline'), hasLength(1));
    expect(find.byKey(const Key('call-name')), findsNothing);
    watcher.stop();
    await unmount(tester);
  });
}
