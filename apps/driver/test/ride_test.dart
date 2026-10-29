import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

void main() {
  testWidgets('online → offer with earnings → accept → arrive → start → complete → collect cash', (tester) async {
    final tripId = fixture('trip_accepted')['id'] as String;
    var offers = fixture('offers_none');
    // Like the real server: every action moves the trip on, and GET returns where it is now.
    dynamic current = fixture('trip_accepted');
    Handler step(String name) => (_) => current = fixture(name);
    final server = driverServer()
      ..on('POST /driver/online', fixture('online'))
      ..handle('GET /driver/offers', (_) => offers)
      ..handle('POST /driver/trips/$tripId/accept', step('trip_accepted'))
      ..handle('GET /driver/trips/$tripId', (_) => current)
      ..handle('POST /driver/trips/$tripId/arrive', step('trip_arrived'))
      ..handle('POST /driver/trips/$tripId/start', step('trip_started'))
      ..handle('POST /driver/trips/$tripId/complete', step('trip_completed'));
    final app = await pumpDriverApp(tester, server);

    expect(find.text('OFFLINE'), findsOneWidget);
    await tapKey(tester, 'go-online');
    expect(find.text('ONLINE'), findsOneWidget);
    expect(find.byKey(const Key('online-title')), findsOneWidget);
    expect(server.calls('POST /driver/location'), isNotEmpty, reason: 'position reported as soon as we go online');
    final ping = jsonDecode(server.calls('POST /driver/location').first.body) as Map;
    expect([ping['lat'], ping['lng']], [13.0975, -59.613]);

    offers = freshOffers();
    await tester.pump(const Duration(seconds: 4));
    await settle(tester);
    final offer = fixture('offers')['offers'][0];
    expect(find.text('New ride request'), findsOneWidget);
    expect(find.text('You earn about BBD ${(offer['estimated_earnings'] as num).toStringAsFixed(2)}'), findsOneWidget);
    expect(find.text('Note: By the blue gate'), findsOneWidget);
    expect(find.byKey(const Key('offer-countdown')), findsOneWidget);

    offers = fixture('offers_none'); // like the server: an accepted ride is no longer offered
    await tapKey(tester, 'accept');
    expect(server.calls('POST /driver/trips/$tripId/accept'), hasLength(1));
    expect(find.text('Pick up Rita'), findsOneWidget);
    expect(find.byKey(const Key('rider-note')), findsOneWidget);

    await tapKey(tester, 'navigate');
    expect(openedUrls.single.queryParameters['destination'], '13.0969,-59.6145');

    await tapKey(tester, 'arrived');
    expect(find.text('Waiting for Rita'), findsOneWidget);
    await tapKey(tester, 'start');
    expect(find.text('PIN from Rita'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('pin-input')), '4821');
    await tapKey(tester, 'pin-submit');
    expect(jsonDecode(server.calls('POST /driver/trips/$tripId/start').single.body), {'pin': '4821'});
    expect(find.text('Taking Rita'), findsOneWidget);
    await tapKey(tester, 'complete');
    await tapKey(tester, 'confirm-complete');
    final done = fixture('trip_completed');
    expect(find.text('Trip complete'), findsOneWidget);
    expect(find.text('BBD ${(done['actual']['fare'] as num).toStringAsFixed(2)}'), findsOneWidget);
    expect(find.text('You earned BBD ${(done['driver_earnings'] as num).toStringAsFixed(2)}'), findsOneWidget);
    expect(jsonDecode(server.calls('POST /driver/trips/$tripId/complete').single.body), containsPair('lat', 13.0975));

    // Rate the rider: a low score asks what went wrong.
    server.on('POST /driver/trips/$tripId/rate', {'id': 1, 'score': 3}, status: 201);
    await tapKey(tester, 'rider-star-3');
    await tester.enterText(find.byKey(const Key('rider-comment')), 'Late to the pickup');
    await tapKey(tester, 'rate-rider');
    expect(jsonDecode(server.calls('POST /driver/trips/$tripId/rate').single.body), {'score': 3, 'comment': 'Late to the pickup'});
    expect(find.byKey(const Key('rider-rated')), findsOneWidget);

    await tapKey(tester, 'done');
    expect(find.byKey(const Key('online-title')), findsOneWidget, reason: 'back online, ready for the next ride');
    expect(app.engine.activeTrip, isNull);
    await unmount(tester, app);
  });

  testWidgets('decline removes the offer and tells the server', (tester) async {
    final tripId = fixture('offers')['offers'][0]['trip']['id'] as String;
    var offers = fixture('offers_none');
    final server = driverServer()
      ..on('POST /driver/online', fixture('online'))
      ..handle('GET /driver/offers', (_) => offers)
      ..on('POST /driver/trips/$tripId/decline', fixture('trip_declined'));
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'go-online');
    offers = freshOffers();
    await tester.pump(const Duration(seconds: 4));
    await settle(tester);
    offers = fixture('offers_none');
    await tapKey(tester, 'decline');
    expect(server.calls('POST /driver/trips/$tripId/decline'), hasLength(1));
    expect(find.text('New ride request'), findsNothing);
    expect(find.byKey(const Key('online-title')), findsOneWidget);
    await unmount(tester, app);
  });

  testWidgets('a ride already taken shows a message', (tester) async {
    final tripId = fixture('offers')['offers'][0]['trip']['id'] as String;
    var offers = fixture('offers_none');
    final server = driverServer()
      ..on('POST /driver/online', fixture('online'))
      ..handle('GET /driver/offers', (_) => offers)
      ..on('POST /driver/trips/$tripId/accept', fixture('trip_accept_conflict'), status: 409);
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'go-online');
    offers = freshOffers();
    await tester.pump(const Duration(seconds: 4));
    await settle(tester);
    offers = fixture('offers_none');
    await tapKey(tester, 'accept');
    expect(find.text('That ride was taken by another driver.'), findsOneWidget);
    expect(app.engine.activeTrip, isNull);
    await unmount(tester, app);
  });

  testWidgets('active ride resumes on launch and shows when the rider cancels', (tester) async {
    final tripId = fixture('driver_active_accepted')['trip']['id'] as String;
    var trip = fixture('driver_active_accepted')['trip'];
    final server = driverServer()
      ..on('GET /driver/trips/active', fixture('driver_active_accepted'))
      ..handle('GET /driver/trips/$tripId', (_) => trip);
    final app = await pumpDriverApp(tester, server);
    expect(find.text('Pick up Rita'), findsOneWidget, reason: 'trip screen opens by itself');

    trip = {...fixture('driver_trip_rider_cancelled') as Map<String, dynamic>, 'id': tripId};
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('Ride cancelled'), findsOneWidget);
    expect(find.text('Rita cancelled the ride.'), findsOneWidget);
    await tapKey(tester, 'done');
    expect(app.engine.activeTrip, isNull);
    await unmount(tester, app);
  });

  testWidgets('driver cancels with a reason', (tester) async {
    final tripId = fixture('driver_active_accepted')['trip']['id'] as String;
    final server = driverServer()
      ..on('GET /driver/trips/active', fixture('driver_active_accepted'))
      ..on('GET /driver/trips/$tripId', fixture('driver_active_accepted')['trip'])
      ..on('POST /driver/trips/$tripId/cancel', {...fixture('trip_cancelled_by_driver') as Map<String, dynamic>, 'id': tripId});
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'cancel-ride');
    await tester.tap(find.text('Problem with my vehicle'));
    await settle(tester);
    expect(jsonDecode(server.calls('POST /driver/trips/$tripId/cancel').single.body), {'reason': 'Problem with my vehicle'});
    expect(find.text('You cancelled this ride.'), findsOneWidget);
    await unmount(tester, app);
  });

  testWidgets('earnings screen shows today, week and month', (tester) async {
    final server = driverServer()..on('GET /driver/earnings', fixture('earnings'));
    final app = await pumpDriverApp(tester, server);
    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tester.tap(find.text('Earnings'));
    await settle(tester);
    final today = fixture('earnings')['periods']['today'];
    expect(find.byKey(const Key('earnings-Today')), findsOneWidget);
    expect(find.text('BBD ${(today['earnings'] as num).toStringAsFixed(2)}'), findsWidgets);
    expect(find.textContaining('cash collected BBD ${(today['cash_collected'] as num).toStringAsFixed(2)}'), findsWidgets);
    await unmount(tester, app);
  });
}
