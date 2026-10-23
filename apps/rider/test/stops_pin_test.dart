import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'booking_test.dart' show signedInServer, chooseOistins;
import 'support/fake_server.dart';

/// Search results keyed by what was typed, so the stop and the drop-off can differ.
void placesFor(FakeServer server) {
  server
    ..handle('GET /places/autocomplete', (req) => (req.url.queryParameters['q'] ?? req.url.queryParameters['input'] ?? '').contains('wel')
        ? [
            {'place_id': 'welches-1', 'main_text': 'Welches', 'secondary_text': 'Christ Church, Barbados', 'distance_m': 4100},
          ]
        : [
            {'place_id': 'oistins-1', 'main_text': 'Oistins Fish Fry', 'secondary_text': 'Christ Church, Barbados', 'distance_m': 9100},
          ])
    ..on('GET /places/welches-1', {'place_id': 'welches-1', 'name': 'Welches', 'address': 'Welches', 'lat': 13.1, 'lng': -59.57});
}

Future<void> chooseWelches(WidgetTester tester) async {
  await tester.enterText(find.byKey(const Key('search')), 'welches');
  await settle(tester, 6);
  await tester.tap(find.text('Welches'));
  await settle(tester);
}

void main() {
  final tripId = fixture('trip_created_stops')['id'] as String;
  final pin = fixture('trip_created_stops')['pin'] as String;

  testWidgets('book with a stop: the fare includes it, and the PIN shows once a driver accepts', (tester) async {
    final server = signedInServer()..on('POST /trips', fixture('trip_created_stops'), status: 201);
    placesFor(server);
    server.handle('POST /fares/estimate', (req) => req.body.contains('"stops"') && req.body.contains('Welches') ? fixture('estimate_stops') : fixture('estimate'));
    var stage = 'active_requested_stops';
    server.handle('GET /trips/$tripId', (_) => stage == 'active_requested_stops' ? {'trip': fixture('trip_created_stops'), 'driver_position': null} : fixture(stage));

    await pumpApp(tester, server);
    await chooseOistins(tester);
    expect(find.textContaining('BBD 57.50'), findsWidgets);

    await tapKey(tester, 'add-stop');
    await chooseWelches(tester);
    expect(find.byKey(const Key('stop-0')), findsOneWidget);
    expect(find.textContaining('BBD 65.00'), findsWidgets, reason: 'estimate re-run with the stop');
    expect(server.calls('POST /fares/estimate').last.body, contains('"stops"'));

    await tapKey(tester, 'request');
    final req = server.calls('POST /trips').single.body;
    expect(req, contains('"stops"'));
    expect(req, contains('Welches'));
    expect(find.text('Garrison Savannah'), findsOneWidget, reason: 'stop listed on the trip screen');
    expect(find.byKey(const Key('pin-card')), findsNothing, reason: 'no PIN until a driver is coming');

    stage = 'active_accepted_stops';
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('Your driver is on the way'), findsOneWidget);
    expect(find.byKey(const Key('pin-card')), findsOneWidget);
    expect(find.text(pin.split('').join(' ')), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('remove a stop before booking', (tester) async {
    final server = signedInServer()..on('POST /fares/estimate', fixture('estimate'));
    placesFor(server);
    await pumpApp(tester, server);
    await chooseOistins(tester);
    await tapKey(tester, 'add-stop');
    await chooseWelches(tester);
    expect(find.byKey(const Key('stop-0')), findsOneWidget);
    await tapKey(tester, 'remove-stop-0');
    expect(find.byKey(const Key('stop-0')), findsNothing);
    expect(server.calls('POST /fares/estimate').last.body, isNot(contains('Welches')));
    await unmount(tester);
  });

  testWidgets('add a stop mid-ride: waits for the driver, who declines', (tester) async {
    final server = signedInServer()..on('GET /trips/active', fixture('active_accepted_stops'));
    placesFor(server);
    var current = fixture('active_accepted_stops') as Map<String, dynamic>;
    server
      ..handle('GET /trips/$tripId', (_) => current)
      ..on('POST /trips/$tripId/stops', fixture('stop_change_pending'));

    await pumpApp(tester, server);
    expect(find.byKey(const Key('change-stops')), findsOneWidget);
    await tapKey(tester, 'change-stops');
    expect(find.text('Garrison Savannah'), findsOneWidget, reason: 'existing stop pre-filled');
    await tapKey(tester, 'edit-add-stop');
    await chooseWelches(tester);
    expect(find.byKey(const Key('edit-stop-1')), findsOneWidget);
    expect(find.byKey(const Key('edit-add-stop')), findsNothing, reason: 'max 2 stops');
    expect(find.text('Send to driver'), findsOneWidget);

    current = {'trip': fixture('stop_change_pending'), 'driver_position': null};
    await tapKey(tester, 'save-stops');
    final body = server.calls('POST /trips/$tripId/stops').single.body;
    expect(body, contains('Garrison Savannah'));
    expect(body, contains('Welches'));
    expect(find.byKey(const Key('stop-change-pending')), findsOneWidget);
    expect(find.textContaining('BBD 77.50'), findsOneWidget);
    expect(find.byKey(const Key('change-stops')), findsNothing);

    current = {'trip': fixture('stop_change_declined'), 'driver_position': null};
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.byKey(const Key('stop-change-pending')), findsNothing);
    expect(find.textContaining("couldn't change the stops"), findsOneWidget);
    expect(find.text('Welches'), findsNothing, reason: 'declined: route unchanged');
    await unmount(tester);
  });

  testWidgets('driver accepts the new stop', (tester) async {
    final server = signedInServer()..on('GET /trips/active', {'trip': fixture('stop_change_pending'), 'driver_position': null});
    var current = {'trip': fixture('stop_change_pending'), 'driver_position': null};
    server.handle('GET /trips/$tripId', (_) => current);
    await pumpApp(tester, server);
    expect(find.byKey(const Key('stop-change-pending')), findsOneWidget);

    current = {'trip': fixture('stop_change_accepted'), 'driver_position': null};
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.textContaining('accepted the change'), findsOneWidget);
    expect(find.text('Welches'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('withdraw a pending change', (tester) async {
    final server = signedInServer()..on('GET /trips/active', {'trip': fixture('stop_change_pending'), 'driver_position': null});
    server
      ..on('GET /trips/$tripId', {'trip': fixture('stop_change_pending'), 'driver_position': null})
      ..on('DELETE /trips/$tripId/stops/pending', fixture('active_accepted_stops')['trip']);
    await pumpApp(tester, server);
    server.on('GET /trips/$tripId', fixture('active_accepted_stops'));
    await tapKey(tester, 'withdraw-stops');
    expect(server.calls('DELETE /trips/$tripId/stops/pending'), hasLength(1));
    expect(find.byKey(const Key('stop-change-pending')), findsNothing);
    expect(find.byKey(const Key('change-stops')), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('a stop outside the area shows the server reason', (tester) async {
    final server = signedInServer()..on('GET /trips/active', fixture('active_accepted_stops'));
    placesFor(server);
    server
      ..on('GET /trips/$tripId', fixture('active_accepted_stops'))
      ..on('POST /trips/$tripId/stops', fixture('stop_change_invalid'), status: 422);
    await pumpApp(tester, server);
    await tapKey(tester, 'change-stops');
    await tapKey(tester, 'save-stops');
    expect(find.byKey(const Key('stops-error')), findsOneWidget);
    expect(find.text('Stop 1 must be within Barbados.'), findsOneWidget);
    await unmount(tester);
  });
}
