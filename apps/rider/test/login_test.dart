import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

FakeServer homeRoutes(FakeServer s) => s
  ..on('GET /trips/active', fixture('active_none'))
  ..on('GET /me/payment-methods', fixture('payment_methods_empty'))
  ..on('GET /places/reverse', {'message': 'No address found here.'}, status: 404);

void main() {
  testWidgets('new rider: phone → name + email → code to SMS and email → signed in', (tester) async {
    final server = homeRoutes(FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_new'))
      ..on('POST /auth/otp/request', fixture('otp_request'))
      ..on('POST /auth/otp/verify', fixture('otp_verify'), status: 201));

    final app = await pumpApp(tester, server, signedIn: false);
    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    expect(server.calls('POST /auth/otp/request'), isEmpty, reason: 'no code until we have the email');
    expect(find.byKey(const Key('email')), findsOneWidget);

    await tester.enterText(find.byKey(const Key('name')), 'Rita Rider');
    await tester.enterText(find.byKey(const Key('email')), 'not-an-email');
    await tapKey(tester, 'continue');
    expect(find.text('Enter a valid email address.'), findsOneWidget);
    expect(server.calls('POST /auth/otp/request'), isEmpty);

    await tester.enterText(find.byKey(const Key('email')), 'rita@example.com');
    await tapKey(tester, 'continue');
    final sent = jsonDecode(server.calls('POST /auth/otp/request').single.body) as Map;
    expect(sent['email'], 'rita@example.com');
    final hint = fixture('otp_request')['email_hint'] as String;
    expect(find.textContaining('by text to '), findsOneWidget);
    expect(find.textContaining('and to $hint'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');
    final verify = jsonDecode(server.calls('POST /auth/otp/verify').single.body) as Map;
    expect(verify['name'], 'Rita Rider');
    expect(verify['email'], 'rita@example.com');
    expect(verify['code'], '123456');
    expect(app.session.user?.name, 'Rita Rider');
    expect(find.text('Hi Rita, where to?'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('registered rider: code sent straight away to SMS + email on file', (tester) async {
    final server = homeRoutes(FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_registered'))
      ..on('POST /auth/otp/request', {...fixture('otp_request'), 'registered': true})
      ..on('POST /auth/otp/verify', fixture('otp_verify')));
    await pumpApp(tester, server, signedIn: false);

    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    expect(find.byKey(const Key('name')), findsNothing);
    final sent = jsonDecode(server.calls('POST /auth/otp/request').single.body) as Map;
    expect(sent.containsKey('email'), isFalse, reason: 'server uses the email on file');
    expect(find.byKey(const Key('code')), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');
    final verify = jsonDecode(server.calls('POST /auth/otp/verify').single.body) as Map;
    expect(verify.containsKey('name'), isFalse);
    expect(find.text('Hi Rita, where to?'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('email already used shows the server message', (tester) async {
    final server = FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_new'))
      ..on('POST /auth/otp/request', fixture('otp_request_email_taken'), status: 422);
    await pumpApp(tester, server, signedIn: false);
    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    await tester.enterText(find.byKey(const Key('name')), 'Rita Rider');
    await tester.enterText(find.byKey(const Key('email')), 'taken@example.com');
    await tapKey(tester, 'continue');
    expect(find.text('That email is already used by another account.'), findsOneWidget);
    expect(find.byKey(const Key('code')), findsNothing);
    await unmount(tester);
  });

  testWidgets('wrong code shows the server message', (tester) async {
    final server = FakeServer()
      ..on('POST /auth/otp/lookup', fixture('lookup_registered'))
      ..on('POST /auth/otp/request', fixture('otp_request'))
      ..on('POST /auth/otp/verify', fixture('otp_verify_wrong_code'), status: 422);
    await pumpApp(tester, server, signedIn: false);

    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    await tester.enterText(find.byKey(const Key('code')), '000000');
    await tapKey(tester, 'continue');

    expect(find.byKey(const Key('error')), findsOneWidget);
    expect(find.text(fixture('otp_verify_wrong_code')['errors']['code'][0] as String), findsOneWidget);
    await unmount(tester);
  });
}
