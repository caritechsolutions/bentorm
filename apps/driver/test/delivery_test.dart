import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

/// Shop deliveries (fixtures captured from the local backend: order for Rita from Ann's Fish Pot).
Map<String, dynamic> f(String name, [Map<String, dynamic> changes = const {}]) => {...fixture(name) as Map<String, dynamic>, ...changes};

void main() {
  final tripId = fixture('delivery_accepted')['id'] as String;
  final order = fixture('delivery_accepted')['delivery']['order_number'] as String;

  testWidgets('switch deliveries on, get a delivery offer, accept it', (tester) async {
    var profile = 'profile_approved';
    var offers = fixture('offers_none');
    final server = driverServer()
      ..handle('GET /driver/profile', (_) => fixture(profile))
      ..handle('PATCH /driver/profile', (_) {
        profile = 'profile_approved_deliveries';
        return fixture('profile_deliveries_on');
      })
      ..on('POST /driver/online', fixture('online'))
      ..handle('GET /driver/offers', (_) => offers)
      ..on('POST /driver/trips/$tripId/accept', fixture('delivery_accepted'))
      ..handle('GET /driver/trips/$tripId', (_) => fixture('delivery_accepted'));
    final app = await pumpDriverApp(tester, server);

    expect(tester.widget<SwitchListTile>(find.byKey(const Key('deliveries-switch'))).value, isFalse);
    await tapKey(tester, 'deliveries-switch');
    expect(jsonDecode(server.calls('PATCH /driver/profile').single.body), {'accepts_deliveries': true});
    expect(tester.widget<SwitchListTile>(find.byKey(const Key('deliveries-switch'))).value, isTrue);
    expect(find.text('You\'ll get shop deliveries too.'), findsOneWidget);
    await tester.pump(const Duration(seconds: 5)); // let the message go before tapping below it
    await settle(tester);

    await tapKey(tester, 'go-online');
    expect(find.text('Looking for rides and deliveries near you.'), findsOneWidget);
    offers = freshOffers('offers_delivery');
    await tester.pump(const Duration(seconds: 4));
    await settle(tester);
    expect(find.text('New delivery'), findsOneWidget);
    expect(find.text('You earn about BBD ${(fixture('offers_delivery')['offers'][0]['estimated_earnings'] as num).toStringAsFixed(2)}'), findsOneWidget);
    expect(find.text('Order $order · 2 items · paid by card'), findsOneWidget);
    expect(find.text("Collect at Ann's Fish Pot, Oistins Bay Gardens"), findsOneWidget);
    expect(find.textContaining('PIN'), findsNothing, reason: 'drivers never see the PIN');

    await tapKey(tester, 'accept');
    expect(find.text('Collect order $order'), findsOneWidget);
    expect(find.text('2× Fried flying fish (Side: Breadfruit)'), findsOneWidget);
    expect(find.text('Note: Blue gate'), findsOneWidget);
    await unmount(tester, app);
  });

  testWidgets('at the store → store hands over → customer PIN (missing, wrong, right) → delivered', (tester) async {
    dynamic current = f('delivery_accepted');
    final server = driverServer()
      ..on('GET /driver/trips/active', {'trip': fixture('delivery_accepted')})
      ..handle('GET /driver/trips/$tripId', (_) => current)
      ..handle('POST /driver/trips/$tripId/arrive', (_) => current = f('delivery_arrived'));
    var tries = 0;
    server.handle('POST /driver/trips/$tripId/complete', (_) {
      tries++;
      if (tries == 1) return Reply(422, fixture('delivery_complete_wrong_pin'));
      return current = f('delivery_completed');
    });
    final app = await pumpDriverApp(tester, server);
    expect(find.text('Collect order $order'), findsOneWidget);

    await tapKey(tester, 'navigate');
    expect(openedUrls.last.queryParameters['destination'], startsWith('13.06'), reason: 'to the store');
    await tapKey(tester, 'call-store');
    expect(openedUrls.last.toString(), 'tel:2464281234');

    await tapKey(tester, 'arrived');
    expect(find.text("At Ann's Fish Pot"), findsOneWidget);
    expect(find.byKey(const Key('waiting-store')), findsOneWidget);
    expect(find.byKey(const Key('start')), findsNothing, reason: 'the store moves it on, not the driver');

    // The store taps "Picked up" in their portal.
    current = f('delivery_picked_up');
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(tester.widget<Text>(find.byKey(const Key('trip-title'))).data, 'Deliver to Rita');

    await tapKey(tester, 'deliver');
    await tester.enterText(find.byKey(const Key('pin-input')), '12');
    await tapKey(tester, 'pin-submit');
    expect(find.text('The PIN is 4 digits.'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('pin-input')), '0000');
    await tapKey(tester, 'pin-submit');
    expect(find.text('That PIN is not right. 4 tries left.'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('pin-input')), '8582');
    await tapKey(tester, 'pin-submit');
    expect(jsonDecode(server.calls('POST /driver/trips/$tripId/complete').last.body)['pin'], '8582');
    expect(find.text('Order delivered'), findsOneWidget);
    expect(find.text('You earned BBD ${(fixture('delivery_completed')['driver_earnings'] as num).toStringAsFixed(2)}'), findsOneWidget);
    await unmount(tester, app);
  });

  testWidgets('the store cancels the order: the job is called off', (tester) async {
    final off = fixture('delivery_called_off') as Map<String, dynamic>;
    dynamic current = f('delivery_accepted', {'id': off['id']});
    final server = driverServer()
      ..on('GET /driver/trips/active', {'trip': current})
      ..handle('GET /driver/trips/${off['id']}', (_) => current);
    final app = await pumpDriverApp(tester, server);
    expect(find.text('Collect order $order'), findsOneWidget);
    current = off;
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('Delivery cancelled'), findsOneWidget);
    expect(find.textContaining('called off'), findsOneWidget);
    await unmount(tester, app);
  });
}
