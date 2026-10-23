import 'package:geolocator/geolocator.dart';

import '../api/models.dart';

typedef Fix = ({double lat, double lng, double? heading, double? speed});

/// Device location. Returns null when unavailable or refused.
class LocationService {
  Future<Fix?> current() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) return null;
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 10)),
      );
      return (lat: p.latitude, lng: p.longitude, heading: p.heading, speed: p.speed);
    } catch (_) {
      return null;
    }
  }

  /// Is location permission granted (asking if needed)? Online drivers must share location.
  Future<bool> ensurePermission() async => (await current()) != null;
}

/// Fixed location, for tests.
class FixedLocationService extends LocationService {
  FixedLocationService(this.point);
  LatLngPoint? point;
  @override
  Future<Fix?> current() async => point == null ? null : (lat: point!.lat, lng: point!.lng, heading: 90.0, speed: 0.0);
}
