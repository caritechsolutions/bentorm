import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_server.dart';

void main() {
  testWidgets('new rider: phone → code → asked for name with the same code → signed in', (tester) async {
    final server = FakeServer()
      ..on('POST /auth/otp/request', fixture('otp_request'))
      ..handle('POST /auth/otp/verify', (req) {
        final body = req.body;
        return body.contains('"name"') ? fixture('otp_verify') : Reply(422, fixture('otp_verify_needs_name'));
      })
      ..on('GET /trips/active', fixture('active_none'))
      ..on('GET /me/payment-methods', fixture('payment_methods_empty'))
      ..on('GET /places/reverse', {'message': 'No address found here.'}, status: 404);

    final app = await pumpApp(tester, server, signedIn: false);
    expect(find.text('Send code'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('phone')), '2465551234');
    await tapKey(tester, 'continue');
    expect(find.textContaining('Enter the code sent to'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('code')), '123456');
    await tapKey(tester, 'continue');
    expect(find.byKey(const Key('name')), findsOneWidget, reason: 'first-time phone is asked for a name');
    expect(find.text('Create account'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('name')), 'Rita Rider');
    await tapKey(tester, 'continue');

    final verifies = server.calls('POST /auth/otp/verify');
    expect(verifies, hasLength(2));
    expect(verifies.last.body, contains('"code":"123456"'), reason: 'same code reused for the name step');
    expect(app.session.user?.name, 'Rita Rider');
    expect(find.text('Hi Rita, where to?'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('wrong code shows the server message', (tester) async {
    final server = FakeServer()
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
