import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:ridecoop_core/ridecoop_core.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

import '../api/driver_api.dart';
import '../api/models.dart';
import '../config.dart';
import 'location.dart';

/// Everything that runs while a driver is online: location pings, offer polling, the active trip.
class DriverEngine extends ChangeNotifier {
  DriverEngine(this.api, this.location, {this.locationEvery = AppConfig.locationEvery, this.offersEvery = AppConfig.offersEvery});

  final DriverApi api;
  final LocationService location;
  final Duration locationEvery;
  final Duration offersEvery;

  DriverStatus? status;
  bool online = false;
  bool toggling = false;
  List<String> blockers = const [];
  List<Offer> offers = const [];
  DriverTrip? activeTrip;
  LatLngPoint? position;
  String? message;

  Timer? _locTimer;
  Timer? _offerTimer;
  bool _disposed = false;

  /// Pings in a row that didn't reach the server (no signal, no location). From 3 (~12 s) the app warns the driver.
  int failedPings = 0;
  bool get connectionProblem => online && failedPings >= 3;

  /// Why the last pings failed: 'location' or 'network'.
  String? pingProblem;
  bool _rejoining = false;

  /// Loads the profile, resumes an active trip and an online session after a restart.
  Future<void> load() async {
    await refreshStatus();
    try {
      activeTrip = await api.activeTrip();
    } on ApiException {
      // Offline at launch: the next ping resyncs.
    }
    online = status?.profile.isOnline ?? false;
    if (online || activeTrip != null) _startTimers();
    final fix = await location.current();
    if (fix != null) position = LatLngPoint(fix.lat, fix.lng);
    _notify();
  }

  Future<void> refreshStatus() async {
    try {
      status = await api.status();
      blockers = status!.canGoOnline ? const [] : status!.issues;
    } on ApiException catch (e) {
      message = e.message;
    }
    _notify();
  }

  /// Accept or decline a fleet's invitation to drive its cars.
  Future<void> answerInvite(FleetInvite invite, {required bool accept}) async {
    try {
      await api.respondToInvite(invite.id, accept: accept);
      message = accept ? 'You can now drive ${invite.fleetName}\'s cars. Choose one under My vehicles.' : 'Invitation declined.';
      await refreshStatus();
    } on ApiException catch (e) {
      message = e.message;
    }
    _notify();
  }

  /// Shop deliveries on/off. Takes effect for the next job offered.
  Future<void> setDeliveries(bool on) async {
    try {
      await api.updateProfile(acceptsDeliveries: on);
      await refreshStatus();
      message = on ? 'You\'ll get shop deliveries too.' : 'Deliveries off — rides only.';
    } on ApiException catch (e) {
      message = e.message;
    }
    _notify();
  }

  Future<void> goOnline() async {
    toggling = true;
    message = null;
    _notify();
    try {
      final fix = await location.current();
      if (fix == null) {
        message = 'Turn on location for Ride Coop Driver — riders need to find you.';
        return;
      }
      position = LatLngPoint(fix.lat, fix.lng);
      await api.goOnline();
      // Report position straight away so dispatch can see us before the first timer tick.
      await api.ping(fix.lat, fix.lng, heading: fix.heading, speed: fix.speed);
      online = true;
      blockers = const [];
      _startTimers();
    } on ApiException catch (e) {
      final issues = e.body['issues'];
      blockers = issues is List ? issues.map((i) => '$i').toList() : const [];
      message = e.message;
    } finally {
      toggling = false;
      _notify();
    }
  }

  Future<void> goOffline() async {
    toggling = true;
    _notify();
    try {
      await api.goOffline();
      online = false;
      offers = const [];
      if (activeTrip == null) _stopTimers();
    } on ApiException catch (e) {
      message = e.message;
    } finally {
      toggling = false;
      _notify();
    }
  }

  Future<DriverTrip?> accept(Offer offer) async {
    try {
      final trip = await api.accept(offer.trip.id);
      activeTrip = trip;
      offers = const [];
      _notify();
      return trip;
    } on ApiException catch (e) {
      offers = offers.where((o) => o.id != offer.id).toList();
      message = e.status == 409 ? 'That ride was taken by another driver.' : e.message;
      _notify();
      return null;
    }
  }

  Future<void> decline(Offer offer) async {
    offers = offers.where((o) => o.id != offer.id).toList();
    _notify();
    try {
      await api.decline(offer.trip.id);
    } on ApiException {
      // It expires on its own anyway.
    }
  }

  /// The trip screen reports every change; a finished trip clears the slot.
  void tripChanged(DriverTrip trip) {
    activeTrip = trip.isActive ? trip : null;
    if (activeTrip == null && !online) _stopTimers();
    _notify();
  }

  void clearMessage() {
    message = null;
    _notify();
  }

  /// Signed out: stop everything and forget this driver's state.
  void reset() {
    _stopTimers();
    status = null;
    online = false;
    toggling = false;
    failedPings = 0;
    pingProblem = null;
    blockers = const [];
    offers = const [];
    activeTrip = null;
    message = null;
    _notify();
  }

  void _startTimers() {
    _locTimer ??= Timer.periodic(locationEvery, (_) => _ping());
    _offerTimer ??= Timer.periodic(offersEvery, (_) => _pollOffers());
    location.startBackground();
    _wake(true);
  }

  void _stopTimers() {
    _locTimer?.cancel();
    _offerTimer?.cancel();
    _locTimer = null;
    _offerTimer = null;
    location.stopBackground();
    _wake(false);
  }

  Future<void> _ping() async {
    final fix = await location.current();
    if (fix == null) {
      failedPings++;
      pingProblem = 'location';
      _notify();
      return;
    }
    position = LatLngPoint(fix.lat, fix.lng);
    try {
      final r = await api.ping(fix.lat, fix.lng, heading: fix.heading, speed: fix.speed);
      failedPings = 0;
      pingProblem = null;
      if (r.trip != null && activeTrip == null) activeTrip = r.trip; // resync
      if (r.online == false && online && activeTrip == null) {
        if (r.offlineReason != null) {
          // Set offline on purpose (a fleet took its car back): say why, and don't sign back on.
          online = false;
          offers = const [];
          message = r.offlineReason;
          _stopTimers();
          await refreshStatus();
        } else {
          // The server set us offline (the phone was silent for a while) but the driver still wants rides: sign back on.
          await _rejoin();
        }
      }
    } on ApiException {
      failedPings++;
      pingProblem = 'network';
    }
    _notify();
  }

  Future<void> _rejoin() async {
    if (_rejoining) return;
    _rejoining = true;
    try {
      await api.goOnline();
      message = 'Connection restored — you\'re online again.';
    } on ApiException catch (e) {
      // Can't go online any more (documents expired, account changed): show the truth.
      online = false;
      offers = const [];
      final issues = e.body['issues'];
      blockers = issues is List ? issues.map((i) => '$i').toList() : const [];
      message = e.message;
      if (activeTrip == null) _stopTimers();
    } finally {
      _rejoining = false;
    }
  }

  /// A push arrived (or was tapped): don't wait for the next tick.
  Future<void> refreshNow() async {
    if (online && activeTrip == null) await _pollOffers();
  }

  Future<void> _pollOffers() async {
    if (!online || activeTrip != null) return;
    try {
      final now = DateTime.now();
      offers = (await api.offers()).where((o) => o.expiresAt.isAfter(now)).toList();
    } on ApiException {
      return;
    }
    _notify();
  }

  /// Tests switch this off (no wakelock plugin there).
  static bool keepAwake = true;

  void _wake(bool on) {
    if (!keepAwake) return;
    // Keep the screen on while online so location keeps flowing; ignore platforms without it.
    (on ? WakelockPlus.enable() : WakelockPlus.disable()).catchError((Object _) {});
  }

  void _notify() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    _stopTimers();
    super.dispose();
  }
}
