/// Build-time settings, passed with --dart-define.
class AppConfig {
  /// API base, e.g. https://api.ridecoop.net/api/v1
  static const apiBase = String.fromEnvironment('API_BASE', defaultValue: 'https://api.ridecoop.net/api/v1');

  /// Off in widget tests (Google Maps needs a real platform view).
  static const mapsEnabled = bool.fromEnvironment('MAPS_ENABLED', defaultValue: true);

  /// Where riders land when nothing else is known: Bridgetown, Barbados.
  static const defaultLat = 13.0969;
  static const defaultLng = -59.6145;

  /// Push notifications: CI sets PUSH=true when it packaged google-services.json (Firebase starts natively from it).
  static const pushConfigured = bool.fromEnvironment('PUSH');
}
