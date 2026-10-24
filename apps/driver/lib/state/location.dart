import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import '../api/models.dart';

typedef Fix = ({double lat, double lng, double? heading, double? speed});

/// Device location. Returns null when unavailable or refused.
class LocationService {
  StreamSubscription<Position>? _stream;
  Position? _last;

  /// While online: a continuous location stream run as an Android foreground service (with its own
  /// "You're online" notification), so pings keep going when the driver switches to another app or locks
  /// the screen. Started while the app is in front, it needs only the usual "while using the app" permission.
  Future<void> startBackground() async {
    if (_stream != null) return;
    try {
      final settings = defaultTargetPlatform == TargetPlatform.android
          ? AndroidSettings(
              accuracy: LocationAccuracy.high,
              distanceFilter: 0,
              intervalDuration: const Duration(seconds: 4),
              foregroundNotificationConfig: const ForegroundNotificationConfig(
                notificationTitle: 'You\'re online',
                notificationText: 'Ride Coop Driver is sharing your location to find you rides.',
                notificationChannelName: 'Online status',
                enableWakeLock: true,
                setOngoing: true,
              ),
            )
          : const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 0);
      _stream = Geolocator.getPositionStream(locationSettings: settings).listen((p) => _last = p, onError: (Object _) {});
    } catch (_) {
      _stream = null;
    }
  }

  Future<void> stopBackground() async {
    await _stream?.cancel();
    _stream = null;
    _last = null;
  }

  Future<Fix?> current() async {
    final recent = _last;
    if (recent != null && DateTime.now().difference(recent.timestamp).inSeconds < 15) {
      return (lat: recent.latitude, lng: recent.longitude, heading: recent.heading, speed: recent.speed);
    }
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
  Future<void> startBackground() async {}

  @override
  Future<void> stopBackground() async {}

  @override
  Future<Fix?> current() async => point == null ? null : (lat: point!.lat, lng: point!.lng, heading: 90.0, speed: 0.0);
}
