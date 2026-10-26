import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_rider/screens/shop/checkout_screen.dart';

import 'booking_test.dart' show signedInServer;
import 'support/fake_server.dart';

/// Fixtures from the local backend (scratchpad owed-fixtures.sh): a cash ride cancelled with a BBD 5.88 fee.
void main() {
  final opened = <Uri>[];
  late Future<void> Function(Uri) realOpen;
  setUp(() {
    opened.clear();
    realOpen = CheckoutScreen.openUrl;
    CheckoutScreen.openUrl = (u) async => opened.add(u);
  });
  tearDown(() => CheckoutScreen.openUrl = realOpen);

  final owed = (fixture('outstanding') as List).first as Map<String, dynamic>;
  final tripId = owed['id'] as String;

  testWidgets('a cancellation fee shows on the home screen and is paid with a saved card', (tester) async {
    var paid = false;
    final card = (fixture('payment_methods_owed') as List).first as Map<String, dynamic>;
    final server = signedInServer()
      ..on('GET /me/payment-methods', fixture('payment_methods_owed'))
      ..handle('GET /me/outstanding', (_) => paid ? fixture('outstanding_none') : fixture('outstanding'))
      ..handle('POST /trips/$tripId/pay', (_) {
        paid = true;
        return fixture('pay_owed_card');
      });
    await pumpApp(tester, server);

    expect(find.byKey(const Key('owed-banner')), findsOneWidget);
    expect(find.text('You owe BBD 5.88'), findsOneWidget);
    expect(find.textContaining('Cancellation fee for the ride you cancelled'), findsOneWidget);

    await tapKey(tester, 'pay-owed');
    expect(find.text('Pay BBD 5.88'), findsOneWidget);
    await tapKey(tester, 'pay-owed-card-${card['id']}');
    expect(server.calls('POST /trips/$tripId/pay').single.body, contains('"payment_method_id":"${card['id']}"'));
    expect(find.text('Paid — thank you. You can book again.'), findsOneWidget);
    expect(find.byKey(const Key('owed-banner')), findsNothing);
    expect(opened, isEmpty);
    await unmount(tester);
  });

  testWidgets('no saved card: the secure card page opens', (tester) async {
    final server = signedInServer()
      ..on('GET /me/outstanding', fixture('outstanding'))
      ..on('POST /trips/$tripId/pay', fixture('pay_owed_page'));
    await pumpApp(tester, server);
    await tapKey(tester, 'pay-owed');
    expect(find.text('Pay by card'), findsOneWidget);
    await tapKey(tester, 'pay-owed-page');
    expect(server.calls('POST /trips/$tripId/pay').single.body, isNot(contains('payment_method_id')));
    expect(opened.single.toString(), fixture('pay_owed_page')['redirect_url']);
    expect(find.byKey(const Key('owed-banner')), findsOneWidget, reason: 'still owed until the bank page completes');
    await unmount(tester);
  });

  testWidgets('booking while owing says why and shows the banner', (tester) async {
    final server = signedInServer()
      ..handle('GET /me/outstanding', (_) => fixture('outstanding'))
      ..on('POST /fares/estimate', fixture('estimate'))
      ..on('POST /trips', fixture('trip_blocked_owed'), status: 422);
    await pumpApp(tester, server);
    expect(find.byKey(const Key('owed-banner')), findsOneWidget);
    await tapKey(tester, 'where-to');
    await tester.enterText(find.byKey(const Key('search')), 'oistins');
    await settle(tester, 6);
    await tester.tap(find.text('Oistins Fish Fry'));
    await settle(tester);
    await tapKey(tester, 'request');
    expect(find.text('Please settle BBD 5.88 owed on a previous trip first.'), findsOneWidget);
    await unmount(tester);
  });
}
