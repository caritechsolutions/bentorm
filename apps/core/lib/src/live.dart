import 'package:flutter/foundation.dart';

import 'realtime.dart';
import 'remote_config.dart';
import 'session.dart';

/// Joins the server settings and live updates to the signed-in user: loads /config at start, and keeps a realtime
/// connection open while someone is signed in. Both apps make one of these in main(); tests pass none.
class LiveLink extends ChangeNotifier {
  LiveLink(this.session, {RemoteConfig? config, this.makeRealtime}) : config = config ?? RemoteConfig();

  final Session session;
  final RemoteConfig config;

  /// Tests inject a fake; the default builds a [RealtimeClient] from the server's settings.
  final RealtimeClient Function(RemoteConfig c)? makeRealtime;

  RealtimeClient? realtime;
  int? _userId;

  Future<void> start() async {
    session.addListener(_sync);
    await config.load(session.api.client);
    _sync();
  }

  void _sync() {
    final u = session.user;
    final token = session.api.client.token;
    if (session.state == SessionState.signedIn && u != null && token != null) {
      if (!config.loaded) {
        // Signed in before /config arrived (offline at start): try again now.
        config.load(session.api.client).then((_) => _connect(u.id, token));
        return;
      }
      _connect(u.id, token);
    } else if (_userId != null) {
      realtime?.stop();
      _userId = null;
    }
  }

  void _connect(int userId, String token) {
    if (_userId == userId && realtime != null) return;
    final rt = config.realtime;
    if (rt == null) return;
    realtime ??= makeRealtime?.call(config) ??
        RealtimeClient(key: rt.key, host: rt.host, port: rt.port, tls: rt.tls, authEndpoint: rt.authEndpoint);
    _userId = userId;
    realtime!.start(token: token, userId: userId);
    notifyListeners();
  }

  @override
  void dispose() {
    session.removeListener(_sync);
    realtime?.dispose();
    super.dispose();
  }
}
