import 'package:geolocator/geolocator.dart';

import '../api/models.dart';

/// Device location. Returns null when unavailable or refused — the app then falls back to a default.
class LocationService {
  Future<LatLngPoint?> current() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return null;
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) return null;
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 12)),
      );
      return LatLngPoint(p.latitude, p.longitude);
    } catch (_) {
      return null;
    }
  }
}

/// Fixed location, for tests.
class FixedLocationService extends LocationService {
  FixedLocationService(this.point);
  final LatLngPoint? point;
  @override
  Future<LatLngPoint?> current() async => point;
}
