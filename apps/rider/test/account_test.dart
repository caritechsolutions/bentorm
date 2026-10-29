import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import 'booking_test.dart' show signedInServer;
import 'support/fake_server.dart';

/// Delete my account (store requirement) and the Terms / Privacy links.
void main() {
  final opened = <Uri>[];
  setUp(() {
    opened.clear();
    AppPages.launch = (u) async {
      opened.add(u);
      return true;
    };
  });

  Future<void> openMenu(WidgetTester tester) async {
    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
  }

  testWidgets('a ride under way stops the deletion and says why', (tester) async {
    final server = signedInServer()..on('GET /me/deletion', {'blockers': ['Finish or cancel your current ride first.'], 'web_url': 'https://api.test/account/delete'});
    await pumpApp(tester, server);
    await openMenu(tester);
    await tapKey(tester, 'delete-account');
    expect(find.byKey(const Key('delete-blocked')), findsOneWidget);
    expect(find.text('• Finish or cancel your current ride first.'), findsOneWidget);
    expect(server.calls('DELETE /me'), isEmpty);
    await unmount(tester);
  });

  testWidgets('delete the account: confirm, the server deletes it, the app signs out', (tester) async {
    final server = signedInServer()
      ..on('GET /me/deletion', {'blockers': [], 'web_url': 'https://api.test/account/delete'})
      ..on('DELETE /me', {'ok': true, 'message': 'Your account has been deleted.'});
    final app = await pumpApp(tester, server);
    await openMenu(tester);

    await tapKey(tester, 'legal-privacy');
    expect(opened.single.toString(), 'https://api.test/privacy');

    await tapKey(tester, 'delete-account'); // the menu is still open
    await tapKey(tester, 'confirm-delete-account');
    expect(jsonDecode(server.calls('DELETE /me').single.body), {'confirm': true});
    expect(app.session.state, SessionState.signedOut);
    expect(find.byKey(const Key('create-account')), findsOneWidget, reason: 'back at the sign-in screen');
    await unmount(tester);
  });
}
