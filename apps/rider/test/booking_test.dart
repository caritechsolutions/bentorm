import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:ridecoop_rider/api/models.dart' show LatLngPoint;
import 'package:ridecoop_rider/state/location.dart';

import 'support/fake_server.dart';

/// The rider's phone, which moves between trips.
class MovingLocation extends LocationService {
  LatLngPoint point = const LatLngPoint(13.0969, -59.6145);
  @override
  Future<LatLngPoint?> current() async => point;
}

FakeServer signedInServer() => FakeServer()
  ..on('GET /me', fixture('me'))
  ..on('GET /trips/active', fixture('active_none'))
  ..on('GET /me/payment-methods', fixture('payment_methods_empty'))
  ..on('GET /places/reverse', {'place_id': 'here', 'name': 'Bay Street', 'address': 'Bay Street, Bridgetown, Barbados', 'lat': 13.0969, 'lng': -59.6145})
  ..on('GET /places/autocomplete', [
    {'place_id': 'oistins-1', 'main_text': 'Oistins Fish Fry', 'secondary_text': 'Christ Church, Barbados', 'distance_m': 9100},
  ])
  ..on('GET /places/oistins-1', {'place_id': 'oistins-1', 'name': 'Oistins Fish Fry', 'address': 'Oistins, Christ Church, Barbados', 'lat': 13.0667, 'lng': -59.5333});

Future<void> chooseOistins(WidgetTester tester) async {
  await tapKey(tester, 'where-to');
  await tester.enterText(find.byKey(const Key('search')), 'oistins');
  await settle(tester, 6); // debounce + request
  await tester.tap(find.text('Oistins Fish Fry'));
  await settle(tester);
}

void main() {
  testWidgets('book a cash ride: search → quotes → request → driver → complete → rate', (tester) async {
    final server = signedInServer()
      ..on('POST /fares/estimate', fixture('estimate'))
      ..on('POST /trips', fixture('trip_created'), status: 201);
    final tripId = fixture('trip_created')['id'] as String;
    var stage = 'active_requested';
    server.handle('GET /trips/$tripId', (_) => fixture(stage));

    final phone = MovingLocation();
    await pumpApp(tester, server, location: phone);
    expect(find.text('Hi Rita, where to?'), findsOneWidget);
    expect(find.textContaining('Bay Street'), findsOneWidget, reason: 'pickup from reverse geocoding');

    await chooseOistins(tester);
    expect(server.calls('GET /places/autocomplete').single.url.queryParameters['session'], isNotEmpty);
    expect(find.text('Standard'), findsOneWidget);
    expect(find.textContaining('BBD 57.50'), findsWidgets);
    expect(find.text('Cash'), findsOneWidget);

    await tapKey(tester, 'request');
    final req = server.calls('POST /trips').single.body;
    expect(req, contains('"vehicle_type":"standard"'));
    expect(req, contains('"payment_method":"cash"'));
    expect(req, contains('"dropoff_address":"Oistins Fish Fry, Oistins, Christ Church, Barbados"'));
    expect(find.text('Finding your driver'), findsOneWidget);

    stage = 'active_accepted';
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('Your driver is on the way'), findsOneWidget);
    expect(find.text(fixture('active_accepted')['trip']['driver']['name'] as String), findsOneWidget);
    expect(find.text(fixture('active_accepted')['trip']['vehicle']['plate'] as String), findsOneWidget);

    stage = 'trip_completed';
    server.on('POST /trips/$tripId/rate', fixture('rate'), status: 201);
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('You have arrived'), findsOneWidget);
    final fare = (fixture('trip_completed')['trip']['actual']['fare'] as num).toDouble();
    expect(find.text('BBD ${fare.toStringAsFixed(2)}'), findsOneWidget);
    expect(find.text('Paid in cash to your driver'), findsOneWidget);

    await tapKey(tester, 'star-5');
    await tapKey(tester, 'submit-rating');
    expect(server.calls('POST /trips/$tripId/rate').single.body, contains('"score":5'));
    expect(find.text('Thanks for rating your driver!'), findsOneWidget);

    // Back home at the drop-off: the next pickup is where the rider is now, not this trip's pickup.
    phone.point = const LatLngPoint(13.0667, -59.5333);
    server.on('GET /places/reverse', {'place_id': 'there', 'name': 'Oistins Bay Gardens', 'address': 'Oistins, Christ Church, Barbados', 'lat': 13.0667, 'lng': -59.5333});
    await tester.tap(find.text('Done'));
    await settle(tester);
    expect(find.text('Hi Rita, where to?'), findsOneWidget);
    expect(find.textContaining('Oistins Bay Gardens'), findsOneWidget, reason: 'pickup follows the rider');
    expect(find.textContaining('Bay Street'), findsNothing);
    await unmount(tester);
  });

  testWidgets('drop-off outside the service area shows the reason', (tester) async {
    final server = signedInServer()..on('POST /fares/estimate', fixture('estimate_outside'), status: 422);
    await pumpApp(tester, server);
    await chooseOistins(tester);
    expect(find.byKey(const Key('estimate-error')), findsOneWidget);
    expect(find.text(fixture('estimate_outside')['errors']['pickup'][0] as String), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('cancel while searching is free and confirmed', (tester) async {
    final server = signedInServer()
      ..on('POST /fares/estimate', fixture('estimate'))
      ..on('POST /trips', fixture('trip_created'), status: 201);
    final tripId = fixture('trip_created')['id'] as String;
    server
      ..on('GET /trips/$tripId', fixture('active_requested'))
      ..on('POST /trips/$tripId/cancel', fixture('trip_cancelled'));

    await pumpApp(tester, server);
    await chooseOistins(tester);
    await tapKey(tester, 'request');
    await tapKey(tester, 'cancel-ride');
    expect(find.textContaining('No charge'), findsOneWidget);
    await tapKey(tester, 'confirm-cancel');
    expect(server.calls('POST /trips/$tripId/cancel'), hasLength(1));
    expect(find.text('Ride cancelled'), findsOneWidget);
    expect(find.text('You cancelled this ride.'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('an active trip is resumed on launch', (tester) async {
    final server = signedInServer()..on('GET /trips/active', fixture('active_accepted'));
    final tripId = fixture('active_accepted')['trip']['id'] as String;
    server.on('GET /trips/$tripId', fixture('active_accepted'));
    await pumpApp(tester, server);
    expect(find.text('Your driver is on the way'), findsOneWidget);
    expect(find.text(fixture('active_accepted')['trip']['vehicle']['plate'] as String), findsOneWidget);
    await unmount(tester);
  });
}
