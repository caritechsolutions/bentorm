import 'package:flutter/foundation.dart';

import 'api_client.dart';

/// Settings the server sends at start (GET /config): feature switches, emergency numbers, live-update server.
/// Features the admin turns off disappear from the apps on their next start — no app update needed.
class RemoteConfig extends ChangeNotifier {
  RemoteConfig([Map<String, dynamic>? json]) {
    if (json != null) _apply(json);
  }

  Map<String, dynamic> _features = const {};
  List<({String name, String number})> emergencyNumbers = const [
    (name: 'Police', number: '211'),
    (name: 'Ambulance', number: '511'),
    (name: 'Fire', number: '311'),
  ];

  /// Reverb/Pusher settings, or null when the server has none.
  ({String key, String host, int port, bool tls, String authEndpoint})? realtime;
  bool loaded = false;

  /// Unknown or not loaded yet: on (the server still checks every action).
  bool on(String feature) => _features[feature] != false;

  num number(String key, num fallback) {
    final v = _features[key];
    return v is num ? v : fallback;
  }

  Future<void> load(ApiClient client) async {
    try {
      _apply(await client.get('/config') as Map<String, dynamic>);
      loaded = true;
      notifyListeners();
    } on ApiException {
      // Offline: keep defaults; actions still work and the server decides.
    }
  }

  void _apply(Map<String, dynamic> j) {
    if (j['features'] is Map) _features = Map<String, dynamic>.from(j['features'] as Map);
    final nums = j['emergency_numbers'];
    if (nums is List && nums.isNotEmpty) {
      emergencyNumbers = [
        for (final n in nums)
          if (n is Map) (name: '${n['name'] ?? ''}', number: '${n['number'] ?? ''}'),
      ];
    }
    final rt = j['realtime'];
    if (rt is Map && rt['key'] != null && rt['host'] != null) {
      realtime = (
        key: '${rt['key']}',
        host: '${rt['host']}',
        port: (rt['port'] as num?)?.toInt() ?? 443,
        tls: rt['scheme'] == 'https',
        authEndpoint: '${rt['auth_endpoint'] ?? ''}',
      );
    }
  }
}
