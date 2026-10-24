import 'dart:async';

import 'package:flutter/material.dart';
import 'package:google_navigation_flutter/google_navigation_flutter.dart' as nav;
import 'package:ridecoop_core/ridecoop_core.dart' show Brand;

/// Where the in-app navigation goes.
class NavTarget {
  const NavTarget({required this.lat, required this.lng, required this.title, required this.kind});
  final double lat;
  final double lng;
  final String title; // e.g. "Pickup: Bay Street"
  final String kind; // pickup | stop | dropoff (what the server counts)
}

/// How a navigation screen ended.
enum NavOutcome { closed, arrived, useExternal }

/// Turn-by-turn guidance inside the app (Google Navigation SDK). Billed per destination, so the trip screen only
/// opens this after the server said yes (admin switch + monthly limit).
class NavigationScreen extends StatefulWidget {
  const NavigationScreen({super.key, required this.target});
  final NavTarget target;

  /// Google's terms must be accepted once on each phone before the SDK can be used. Tests replace this.
  static Future<bool> Function() acceptTerms = () =>
      nav.GoogleMapsNavigator.showTermsAndConditionsDialog('Ride Coop navigation', 'Ride Coop');

  @override
  State<NavigationScreen> createState() => _NavigationScreenState();
}

class _NavigationScreenState extends State<NavigationScreen> {
  bool _sessionReady = false;
  bool _arrived = false;
  String? _error;
  StreamSubscription<nav.OnArrivalEvent>? _arrival;

  @override
  void initState() {
    super.initState();
    _startSession();
  }

  Future<void> _startSession() async {
    try {
      await nav.GoogleMapsNavigator.initializeNavigationSession(
        taskRemovedBehavior: nav.TaskRemovedBehavior.quitService,
        notificationOptions: const nav.NavigationNotificationOptions(resumeAppOnTap: true),
      );
      _arrival = nav.GoogleMapsNavigator.setOnArrivalListener((_) {
        if (mounted) setState(() => _arrived = true);
        nav.GoogleMapsNavigator.stopGuidance().catchError((_) {});
      });
      if (mounted) setState(() => _sessionReady = true);
    } catch (e) {
      if (mounted) setState(() => _error = 'Navigation could not start ($e).');
    }
  }

  Future<void> _onViewCreated(nav.GoogleNavigationViewController controller) async {
    try {
      await controller.setMyLocationEnabled(true);
    } catch (_) {}
    final t = widget.target;
    final destinations = nav.Destinations(
      waypoints: [nav.NavigationWaypoint.withLatLngTarget(title: t.title, target: nav.LatLng(latitude: t.lat, longitude: t.lng))],
      displayOptions: nav.NavigationDisplayOptions(showDestinationMarkers: true, showStopSigns: true, showTrafficLights: true),
    );
    // The SDK needs a GPS fix before it can route; retry for a few seconds while it gets one.
    for (var attempt = 0; attempt < 20 && mounted; attempt++) {
      try {
        final status = await nav.GoogleMapsNavigator.setDestinations(destinations);
        if (status == nav.NavigationRouteStatus.statusOk) {
          await controller.setNavigationUIEnabled(true);
          await nav.GoogleMapsNavigator.startGuidance();
          return;
        }
        if (status == nav.NavigationRouteStatus.locationUnavailable || status == nav.NavigationRouteStatus.locationUnknown) {
          await Future<void>.delayed(const Duration(seconds: 1));
          continue;
        }
        if (mounted) setState(() => _error = _describe(status));
        return;
      } catch (e) {
        if (mounted) setState(() => _error = 'Navigation could not start ($e).');
        return;
      }
    }
    if (mounted) setState(() => _error = 'Waiting for your GPS location. Check location is on, then try again.');
  }

  String _describe(nav.NavigationRouteStatus s) => switch (s) {
        nav.NavigationRouteStatus.routeNotFound => 'No driving route found to this place.',
        nav.NavigationRouteStatus.networkError => 'No internet connection for navigation.',
        nav.NavigationRouteStatus.quotaExceeded || nav.NavigationRouteStatus.quotaCheckFailed => 'Navigation is unavailable right now (Google quota).',
        nav.NavigationRouteStatus.apiKeyNotAuthorized => 'Navigation is not enabled for this app\'s Google key.',
        _ => 'Navigation could not start (${s.name}).',
      };

  @override
  void dispose() {
    _arrival?.cancel();
    if (_sessionReady) {
      nav.GoogleMapsNavigator.cleanup().catchError((_) {});
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.target;
    return Scaffold(
      body: Stack(children: [
        if (_sessionReady && _error == null)
          Positioned.fill(
            child: nav.GoogleMapsNavigationView(
              onViewCreated: _onViewCreated,
              initialCameraPosition: nav.CameraPosition(target: nav.LatLng(latitude: t.lat, longitude: t.lng), zoom: 15),
              initialNavigationUIEnabledPreference: nav.NavigationUIEnabledPreference.automatic,
            ),
          )
        else if (_error == null)
          const Center(child: CircularProgressIndicator()),
        if (_error != null)
          Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                const Icon(Icons.wrong_location_outlined, size: 48, color: Brand.red),
                const SizedBox(height: 12),
                Text(_error!, key: const Key('nav-error'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 16)),
                const SizedBox(height: 16),
                FilledButton(
                  key: const Key('nav-use-external'),
                  onPressed: () => Navigator.of(context).pop(NavOutcome.useExternal),
                  child: const Text('Open in Google Maps'),
                ),
              ]),
            ),
          ),
        Align(
          alignment: Alignment.bottomCenter,
          child: SafeArea(
            child: Container(
              margin: const EdgeInsets.all(12),
              padding: const EdgeInsets.fromLTRB(16, 10, 10, 10),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14), boxShadow: const [BoxShadow(blurRadius: 8, color: Colors.black26)]),
              child: Row(children: [
                Expanded(
                  child: Text(_arrived ? 'You have arrived — ${t.title}' : t.title,
                      key: const Key('nav-title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
                const SizedBox(width: 8),
                FilledButton(
                  key: const Key('nav-back'),
                  onPressed: () => Navigator.of(context).pop(_arrived ? NavOutcome.arrived : NavOutcome.closed),
                  child: const Text('Back to ride'),
                ),
              ]),
            ),
          ),
        ),
      ]),
    );
  }
}
