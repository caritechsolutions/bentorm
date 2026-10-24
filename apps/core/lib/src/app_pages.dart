import 'package:url_launcher/url_launcher.dart';

import 'api_client.dart';

/// Web pages inside the apps (help, support, statements…): the server gives a one-time sign-in link and the page
/// opens in an in-app browser. The pages live on the server, so they change without an app update.
class AppPages {
  AppPages._();

  /// Tests replace this.
  static Future<bool> Function(Uri url) launch = (url) => launchUrl(url, mode: LaunchMode.inAppBrowserView);

  /// [page] e.g. "help", "support/new?category=lost_item&trip=…", "statement". Throws ApiException when offline.
  static Future<void> open(ApiClient client, String page) async {
    final r = await client.post('/app-links', {'page': page}) as Map<String, dynamic>;
    await launch(Uri.parse('${r['url']}'));
  }
}
