import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

Map<String, dynamic> f(String name, [Map<String, dynamic> changes = const {}]) => {...fixture(name) as Map<String, dynamic>, ...changes};

void main() {
  final tripId = fixture('trip_accepted_stops')['id'] as String;

  testWidgets('an offer lists the rider\'s stops', (tester) async {
    var offers = fixture('offers_none');
    final server = driverServer()
      ..on('POST /driver/online', fixture('online'))
      ..handle('GET /driver/offers', (_) => offers);
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'go-online');
    offers = freshOffers('offers_with_stops');
    await tester.pump(const Duration(seconds: 4));
    await settle(tester);
    expect(find.text('New ride request'), findsOneWidget);
    expect(find.text('Garrison Savannah'), findsOneWidget);
    expect(find.text('Stop 1'), findsOneWidget);
    expect(find.textContaining('PIN'), findsNothing, reason: 'drivers never see the PIN');
    await unmount(tester, app);
  });

  testWidgets('stop change → accept → arrive → PIN (wrong, then right) → each stop → complete', (tester) async {
    dynamic current = f('trip_accepted_stops');
    Handler step(Map<String, dynamic> next) => (_) => current = next;
    final server = driverServer()
      ..on('GET /driver/trips/active', {'trip': fixture('trip_accepted_stops')})
      ..handle('GET /driver/trips/$tripId', (_) => current)
      ..handle('POST /driver/trips/$tripId/stops/accept', step(f('trip_stops_accepted')))
      ..handle('POST /driver/trips/$tripId/arrive', step(f('trip_stops_accepted', {'status': 'arrived'})))
      ..handle('POST /driver/trips/$tripId/complete', step(f('trip_completed_stops')));
    var pinTries = 0;
    server.handle('POST /driver/trips/$tripId/start', (_) {
      pinTries++;
      if (pinTries == 1) return Reply(422, fixture('trip_start_wrong_pin'));
      return current = f('trip_started_stops');
    });
    server.handle('POST /driver/trips/$tripId/stops/0/reached', step(f('trip_stop_reached')));
    final allDone = f('trip_completed_stops', {'status': 'in_progress'});
    server.handle('POST /driver/trips/$tripId/stops/1/reached', step(allDone));

    final app = await pumpDriverApp(tester, server);
    expect(find.text('Pick up Rita'), findsOneWidget);
    expect(find.text('Garrison Savannah'), findsOneWidget, reason: 'stops listed before pickup');
    expect(find.byKey(const Key('stop-change')), findsNothing);

    // The rider adds a second stop.
    current = f('driver_trip_stop_change_pending');
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.byKey(const Key('stop-change')), findsOneWidget);
    expect(find.text('Stop 2: Welches'), findsOneWidget);
    expect(find.text('New fare estimate BBD 77.50'), findsOneWidget);
    expect(find.text('Rita wants to change the stops.'), findsOneWidget);

    await tapKey(tester, 'accept-stops');
    expect(server.calls('POST /driver/trips/$tripId/stops/accept'), hasLength(1));
    expect(find.byKey(const Key('stop-change')), findsNothing);
    expect(find.text('Welches'), findsOneWidget);

    await tapKey(tester, 'arrived');
    expect(find.text('Waiting for Rita'), findsOneWidget);
    await tapKey(tester, 'start');

    // Not 4 digits: caught before calling the server.
    await tester.enterText(find.byKey(const Key('pin-input')), '12');
    await tapKey(tester, 'pin-submit');
    expect(find.text('The PIN is 4 digits.'), findsOneWidget);
    expect(server.calls('POST /driver/trips/$tripId/start'), isEmpty);

    await tester.enterText(find.byKey(const Key('pin-input')), '0000');
    await tapKey(tester, 'pin-submit');
    expect(find.text(fixture('trip_start_wrong_pin')['errors']['pin'][0] as String), findsOneWidget);
    expect(find.text('Waiting for Rita'), findsOneWidget, reason: 'still not started');

    await tester.enterText(find.byKey(const Key('pin-input')), '3197');
    await tapKey(tester, 'pin-submit');
    expect(jsonDecode(server.calls('POST /driver/trips/$tripId/start').last.body), {'pin': '3197'});
    expect(find.text('Taking Rita'), findsOneWidget);

    expect(find.text('Stop 1 of 2'), findsOneWidget);
    await tapKey(tester, 'navigate');
    expect(openedUrls.last.queryParameters['destination'], '13.079,-59.608');
    await tapKey(tester, 'reached-stop');
    expect(server.calls('POST /driver/trips/$tripId/stops/0/reached'), hasLength(1));
    expect(find.text('Stop 2 of 2'), findsOneWidget);
    await tapKey(tester, 'reached-stop');
    expect(server.calls('POST /driver/trips/$tripId/stops/1/reached'), hasLength(1));
    expect(find.byKey(const Key('reached-stop')), findsNothing);
    expect(find.text('Complete trip'), findsOneWidget);

    await tapKey(tester, 'complete');
    await tapKey(tester, 'confirm-complete');
    expect(find.text('Trip complete'), findsOneWidget);
    expect(find.text('BBD ${(fixture('trip_completed_stops')['actual']['fare'] as num).toStringAsFixed(2)}'), findsOneWidget);
    await tapKey(tester, 'done');
    await unmount(tester, app);
  });

  testWidgets('declining a stop change keeps the route', (tester) async {
    dynamic current = f('driver_trip_stop_change_pending');
    final declined = f('trip_accepted_stops', {
      'stop_change': {...(fixture('driver_trip_stop_change_pending')['stop_change'] as Map<String, dynamic>), 'status': 'declined'},
    });
    final server = driverServer()
      ..on('GET /driver/trips/active', {'trip': fixture('driver_trip_stop_change_pending')})
      ..handle('GET /driver/trips/$tripId', (_) => current)
      ..handle('POST /driver/trips/$tripId/stops/decline', (_) => current = declined);
    final app = await pumpDriverApp(tester, server);
    expect(find.byKey(const Key('stop-change')), findsOneWidget);
    await tapKey(tester, 'decline-stops');
    expect(server.calls('POST /driver/trips/$tripId/stops/decline'), hasLength(1));
    expect(find.byKey(const Key('stop-change')), findsNothing);
    expect(find.text('Welches'), findsNothing);
    expect(find.text('Garrison Savannah'), findsOneWidget);
    await unmount(tester, app);
  });
}
