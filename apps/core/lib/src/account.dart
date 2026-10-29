import 'package:flutter/material.dart';

import 'api_client.dart';
import 'app_pages.dart';
import 'brand.dart';
import 'session.dart';

/// Terms of Use and Privacy Policy: public pages on the Ride Coop server (edited in the admin panel).
class LegalLinks {
  LegalLinks._();

  /// e.g. https://api.ridecoop.net/terms (the API base without /api/v1).
  static Uri url(ApiClient client, String page) => Uri.parse(client.baseUrl).replace(path: '/$page', query: null);

  static Future<void> open(ApiClient client, String page) => AppPages.launch(url(client, page));

  /// Menu rows: Terms, Privacy.
  static List<Widget> tiles(BuildContext context, ApiClient client) => [
        ListTile(
          key: const Key('legal-terms'),
          dense: true,
          leading: const Icon(Icons.description_outlined),
          title: const Text('Terms of Use'),
          onTap: () => open(client, 'terms'),
        ),
        ListTile(
          key: const Key('legal-privacy'),
          dense: true,
          leading: const Icon(Icons.privacy_tip_outlined),
          title: const Text('Privacy Policy'),
          onTap: () => open(client, 'privacy'),
        ),
      ];
}

/// "Delete my account": says what stops it (a ride under way, money owed), otherwise asks once and deletes.
class AccountDeletion {
  AccountDeletion._();

  static Future<void> start(BuildContext context, Session session) async {
    final client = session.api.client;
    final messenger = ScaffoldMessenger.of(context);
    List<String> blockers;
    try {
      final r = await client.get('/me/deletion') as Map<String, dynamic>;
      blockers = [for (final b in (r['blockers'] as List? ?? const [])) '$b'];
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
      return;
    }
    if (!context.mounted) return;
    if (blockers.isNotEmpty) {
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          key: const Key('delete-blocked'),
          title: const Text('You can\'t delete your account yet'),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [for (final b in blockers) Text('• $b')]),
          actions: [TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('OK'))],
        ),
      );
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete your account?'),
        content: const Text('Your name, number, email, saved places and cards are removed and you are signed out. '
            'Past trips are kept without your details for our records. This can\'t be undone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep my account')),
          FilledButton(
            key: const Key('confirm-delete-account'),
            style: FilledButton.styleFrom(backgroundColor: Brand.red, minimumSize: const Size(0, 44)),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await client.delete('/me', {'confirm': true});
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
      return;
    }
    messenger.showSnackBar(const SnackBar(content: Text('Your account has been deleted.')));
    await session.signOut();
  }

  static Widget tile(BuildContext context, Session session) => ListTile(
        key: const Key('delete-account'),
        dense: true,
        leading: const Icon(Icons.delete_outline, color: Brand.red),
        title: const Text('Delete account', style: TextStyle(color: Brand.red)),
        onTap: () => start(context, session),
      );
}
