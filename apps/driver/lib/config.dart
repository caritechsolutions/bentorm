/// Build-time settings, passed with --dart-define.
class AppConfig {
  static const apiBase = String.fromEnvironment('API_BASE', defaultValue: 'https://api.ridecoop.net/api/v1');
  static const mapsEnabled = bool.fromEnvironment('MAPS_ENABLED', defaultValue: true);
  static const defaultLat = 13.0969; // Bridgetown
  static const defaultLng = -59.6145;

  /// Push notifications: CI sets PUSH=true when it packaged google-services.json (Firebase starts natively from it).
  static const pushConfigured = bool.fromEnvironment('PUSH');

  /// How often an online driver reports their position (doubles as the 90 s presence heartbeat).
  static const locationEvery = Duration(seconds: 4);

  /// How often an online, idle driver checks for ride offers.
  static const offersEvery = Duration(seconds: 3);
}
