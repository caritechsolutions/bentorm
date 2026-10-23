import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

void main() {
  testWidgets('new driver: create an account as a driver, then sees what is blocking them', (tester) async {
    final server = driverServer(profile: 'profile_new')
      ..on('POST /auth/otp/lookup', fixture('lookup_new'))
      ..on('POST /auth/otp/request', fixture('otp_request'))
      ..on('POST /auth/otp/verify', fixture('otp_verify_driver'), status: 201);
    final app = await pumpDriverApp(tester, server, signedIn: false);
    expect(find.text('Ride Coop Driver'), findsOneWidget);

    await tapKey(tester, 'create-account');
    await tester.enterText(find.byKey(const Key('name')), 'Dave Driver');
    await tester.enterText(find.byKey(const Key('email')), 'dave@example.com');
    await tester.enterText(find.byKey(const Key('phone')), '2465556271');
    await tapKey(tester, 'continue');
    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');

    final verify = jsonDecode(server.calls('POST /auth/otp/verify').single.body) as Map;
    expect(verify['role'], 'driver');
    expect(app.session.user?.role, 'driver');
    for (final issue in fixture('profile_new')['issues'] as List) {
      expect(find.text(issue as String), findsOneWidget);
    }
    expect(find.byKey(const Key('finish-setup')), findsOneWidget);
    await unmount(tester, app);
  });

  testWidgets('a rider account cannot sign in to the driver app', (tester) async {
    final riderLogin = fixture('otp_verify_driver') as Map<String, dynamic>;
    riderLogin['user'] = {...riderLogin['user'] as Map<String, dynamic>, 'role': 'rider'};
    final server = FakeServer()
      ..on('POST /auth/otp/lookup', {'phone': '+12465550101', 'registered': true, 'email_hint': 'r•••@example.com'})
      ..on('POST /auth/otp/request', {...fixture('otp_request'), 'registered': true})
      ..on('POST /auth/otp/verify', riderLogin)
      ..on('POST /auth/logout', {'message': 'ok'});
    final app = await pumpDriverApp(tester, server, signedIn: false);
    await tapKey(tester, 'sign-in');
    await tester.enterText(find.byKey(const Key('phone')), '2465550101');
    await tapKey(tester, 'continue');
    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');
    expect(find.textContaining('has a rider account'), findsOneWidget);
    expect(server.calls('POST /auth/logout'), hasLength(1));
    expect(app.session.user, isNull);
    await unmount(tester, app);
  });

  testWidgets('go online is refused with the reasons while documents are under review', (tester) async {
    final server = driverServer(profile: 'profile_approved')..on('POST /driver/online', fixture('online_blocked_documents'), status: 403);
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'go-online');
    for (final issue in (fixture('online_blocked_documents')['issues'] as List).take(3)) {
      expect(find.text(issue as String), findsOneWidget);
    }
    expect(app.engine.online, isFalse);
    await unmount(tester, app);
  });

  testWidgets('account screen: add the vehicle with seats, luggage and features', (tester) async {
    final vid = fixture('vehicle_created')['id'];
    final server = driverServer(profile: 'profile_new')
      ..on('POST /driver/vehicles', fixture('vehicle_created'), status: 201)
      ..on('POST /driver/vehicles/$vid/select', fixture('vehicle_selected'));
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'finish-setup');
    expect(find.byKey(const Key('step-vehicle')), findsOneWidget);
    await tapKey(tester, 'step-vehicle');

    await tester.enterText(find.byKey(const Key('make')), 'Toyota');
    await tester.enterText(find.byKey(const Key('model')), 'Axio');
    await tester.enterText(find.byKey(const Key('year')), '2019');
    await tester.enterText(find.byKey(const Key('colour')), 'Silver');
    await tester.enterText(find.byKey(const Key('plate')), 'z33725');
    await tester.ensureVisible(find.text('Child seat'));
    await tester.tap(find.text('Child seat'));
    await settle(tester, 3);
    await tapKey(tester, 'save');

    final sent = jsonDecode(server.calls('POST /driver/vehicles').single.body) as Map;
    expect(sent['plate'], 'Z33725');
    expect([sent['make'], sent['model'], sent['year'], sent['seats'], sent['luggage_capacity']], ['Toyota', 'Axio', 2019, 4, 2]);
    expect(sent['features'], ['child_seat']);
    expect(find.byKey(const Key('step-vehicle')), findsOneWidget, reason: 'back on the account screen');
    await unmount(tester, app);
  });

  testWidgets('documents: upload a photo with number and expiry for review', (tester) async {
    final server = driverServer(profile: 'profile_pending')..on('POST /driver/documents', fixture('document_uploaded'), status: 201);
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'finish-setup');
    await tapKey(tester, 'step-documents');
    expect(find.byKey(const Key('state-police_certificate')), findsOneWidget);
    expect(find.text('Needed'), findsWidgets);
    expect(find.text('Under review'), findsWidgets);

    await tapKey(tester, 'doc-psv_permit');
    await tapKey(tester, 'upload');
    expect(find.text('Add a photo first.'), findsOneWidget);
    await tapKey(tester, 'choose-photo');
    expect(find.byKey(const Key('picked')), findsOneWidget);
    await tapKey(tester, 'upload');
    expect(find.text('Enter the document number.'), findsOneWidget);
    await tester.enterText(find.byKey(const Key('doc-number')), 'psv-889');
    await tapKey(tester, 'upload');
    expect(find.text('Choose the expiry date.'), findsOneWidget);
    await tapKey(tester, 'doc-expiry');
    await tester.tap(find.text('OK'));
    await settle(tester);
    await tapKey(tester, 'upload');

    final req = server.calls('POST /driver/documents').single;
    final text = bodyText(req);
    expect(req.headers['content-type'], startsWith('multipart/form-data'));
    expect(text, contains('name="document_type"\r\n\r\npsv_permit'));
    expect(text, contains('name="number"\r\n\r\npsv-889'));
    expect(text, contains('name="expires_at"'));
    expect(text, contains('filename="licence.jpg"'));
    expect(text, contains('fake-jpeg-bytes'));
    expect(find.text('PSV / taxi permit sent for review.'), findsOneWidget);
    await unmount(tester, app);
  });
}
