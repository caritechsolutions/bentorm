import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

FakeServer homeRoutes(FakeServer s) => s
  ..on('GET /trips/active', fixture('active_none'))
  ..on('GET /me/payment-methods', fixture('payment_methods_empty'))
  ..on('GET /places/reverse', {'message': 'No address found here.'}, status: 404);

Map<String, dynamic> body(FakeServer s, String route, [int i = 0]) => jsonDecode(s.calls(route)[i].body) as Map<String, dynamic>;

Future<void> fillDetails(WidgetTester tester, {String email = 'rita@example.com'}) async {
  await tester.enterText(find.byKey(const Key('name')), 'Rita Rider');
  await tester.enterText(find.byKey(const Key('email')), email);
  await tester.enterText(find.byKey(const Key('phone')), '2465551234');
}

void main() {
  testWidgets('first screen offers Create an account and Sign in', (tester) async {
    await pumpApp(tester, FakeServer(), signedIn: false);
    expect(find.text('Create an account'), findsOneWidget);
    expect(find.textContaining('sign in'), findsOneWidget);
    expect(find.byKey(const Key('phone')), findsNothing);
    await unmount(tester);
  });

  testWidgets('create an account: name + email + phone → code by SMS and email → signed in', (tester) async {
    final server = homeRoutes(FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_new'))
      ..on('POST /auth/otp/request', fixture('otp_request'))
      ..on('POST /auth/otp/verify', fixture('otp_verify'), status: 201));
    final app = await pumpApp(tester, server, signedIn: false);

    await tapKey(tester, 'create-account');
    await fillDetails(tester, email: 'not-an-email');
    await tapKey(tester, 'continue');
    expect(find.text('Enter a valid email address.'), findsOneWidget);
    expect(server.calls('POST /auth/otp/request'), isEmpty);

    await tester.enterText(find.byKey(const Key('email')), 'rita@example.com');
    await tapKey(tester, 'continue');
    expect(body(server, 'POST /auth/otp/request')['email'], 'rita@example.com');
    expect(find.textContaining('by text to '), findsOneWidget);
    expect(find.textContaining('and to ${fixture('otp_request')['email_hint']}'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');
    final v = body(server, 'POST /auth/otp/verify');
    expect([v['name'], v['email'], v['code'], v['role']], ['Rita Rider', 'rita@example.com', '123456', 'rider']);
    expect(app.session.user?.name, 'Rita Rider');
    expect(find.text('Hi Rita, where to?'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('sign in: registered number gets a code straight away', (tester) async {
    final server = homeRoutes(FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_registered'))
      ..on('POST /auth/otp/request', {...fixture('otp_request'), 'registered': true})
      ..on('POST /auth/otp/verify', fixture('otp_verify')));
    await pumpApp(tester, server, signedIn: false);

    await tapKey(tester, 'sign-in');
    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    expect(body(server, 'POST /auth/otp/request').containsKey('email'), isFalse, reason: 'server uses the email on file');
    expect(find.text('Sign in'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');
    expect(body(server, 'POST /auth/otp/verify').containsKey('name'), isFalse);
    expect(find.text('Hi Rita, where to?'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('sign in with an unknown number moves to create an account, keeping the number', (tester) async {
    final server = homeRoutes(FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_new'))
      ..on('POST /auth/otp/request', fixture('otp_request'))
      ..on('POST /auth/otp/verify', fixture('otp_verify'), status: 201));
    await pumpApp(tester, server, signedIn: false);

    await tapKey(tester, 'sign-in');
    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    expect(server.calls('POST /auth/otp/request'), isEmpty);
    expect(find.byKey(const Key('info')), findsOneWidget);
    expect(find.textContaining('no account for'), findsOneWidget);
    expect(find.text('2465551234'), findsOneWidget, reason: 'number carried over');

    await tester.enterText(find.byKey(const Key('name')), 'Rita Rider');
    await tester.enterText(find.byKey(const Key('email')), 'rita@example.com');
    await tapKey(tester, 'continue');
    expect(body(server, 'POST /auth/otp/request')['email'], 'rita@example.com');
    await unmount(tester);
  });

  testWidgets('create an account for a number that already has one just signs in', (tester) async {
    final server = homeRoutes(FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_registered'))
      ..on('POST /auth/otp/request', {...fixture('otp_request'), 'registered': true})
      ..on('POST /auth/otp/verify', fixture('otp_verify')));
    await pumpApp(tester, server, signedIn: false);

    await tapKey(tester, 'create-account');
    await fillDetails(tester);
    await tapKey(tester, 'continue');
    expect(body(server, 'POST /auth/otp/request').containsKey('email'), isFalse, reason: 'never send a code to a new email for an existing account');
    expect(find.textContaining('already has an account'), findsOneWidget);
    expect(find.byKey(const Key('code')), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');
    expect(body(server, 'POST /auth/otp/verify').containsKey('email'), isFalse);
    await unmount(tester);
  });

  testWidgets('email already used shows the server message', (tester) async {
    final server = FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_new'))
      ..on('POST /auth/otp/request', fixture('otp_request_email_taken'), status: 422);
    await pumpApp(tester, server, signedIn: false);
    await tapKey(tester, 'create-account');
    await fillDetails(tester, email: 'taken@example.com');
    await tapKey(tester, 'continue');
    expect(find.text('That email is already used by another account.'), findsOneWidget);
    expect(find.byKey(const Key('code')), findsNothing);
    await unmount(tester);
  });

  testWidgets('a driver account is refused in the rider app and its token revoked', (tester) async {
    final driverLogin = fixture('otp_verify') as Map<String, dynamic>;
    driverLogin['user'] = {...driverLogin['user'] as Map<String, dynamic>, 'role': 'driver'};
    final server = FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_registered'))
      ..on('POST /auth/otp/request', {...fixture('otp_request'), 'registered': true})
      ..on('POST /auth/otp/verify', driverLogin)
      ..on('POST /auth/logout', {'message': 'Signed out.'});
    final app = await pumpApp(tester, server, signedIn: false);

    await tapKey(tester, 'sign-in');
    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');

    expect(find.textContaining('has a driver account'), findsOneWidget);
    expect(server.calls('POST /auth/logout').single.headers['Authorization'], 'Bearer ${driverLogin['token']}');
    expect(app.session.user, isNull);
    await unmount(tester);
  });

  testWidgets('wrong code shows the server message', (tester) async {
    final server = FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_registered'))
      ..on('POST /auth/otp/request', fixture('otp_request'))
      ..on('POST /auth/otp/verify', fixture('otp_verify_wrong_code'), status: 422);
    await pumpApp(tester, server, signedIn: false);

    await tapKey(tester, 'sign-in');
    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    await tester.enterText(find.byKey(const Key('code')), '000000');
    await tapKey(tester, 'continue');
    expect(find.text(fixture('otp_verify_wrong_code')['errors']['code'][0] as String), findsOneWidget);
    await unmount(tester);
  });
}
