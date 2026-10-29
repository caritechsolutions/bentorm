import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:google_navigation_flutter/google_navigation_flutter.dart' as nav;

import 'package:ridecoop_core/ridecoop_core.dart' show Brand, MapIcon, MapIcons;

import '../api/models.dart';
import '../config.dart';

/// Full-bleed map with pickup, stops and drop-off. [me] (the driver's position, shown by the blue dot) is used to
/// frame the camera; it refits when the points change.
///
/// Drawn with the Navigation SDK's map view (google_navigation_flutter), not google_maps_flutter: the Navigation
/// SDK bundles its own Maps SDK and the two can't be linked into one app.
class DriverMap extends StatefulWidget {
  const DriverMap({
    super.key,
    this.pickup,
    this.dropoff,
    this.stops = const [],
    this.me,
    this.rider,
    this.route,
    this.center,
    this.bottomPadding = 0,
    this.showMyLocation = true,
  });

  /// Tests switch this off: Google Maps needs a real platform view.
  static bool enabled = AppConfig.mapsEnabled;

  final LatLngPoint? pickup;
  final LatLngPoint? dropoff;
  final List<LatLngPoint> stops;
  final LatLngPoint? me;

  /// The rider's phone while you come to them.
  final LatLngPoint? rider;
  final List<LatLngPoint>? route;
  final LatLngPoint? center;
  final double bottomPadding;
  final bool showMyLocation;

  @override
  State<DriverMap> createState() => _DriverMapState();
}

class _DriverMapState extends State<DriverMap> {
  nav.GoogleMapViewController? _controller;

  static nav.LatLng _ll(LatLngPoint p) => nav.LatLng(latitude: p.lat, longitude: p.lng);

  List<LatLngPoint> get _points => [
        if (widget.pickup != null) widget.pickup!,
        if (widget.dropoff != null) widget.dropoff!,
        ...widget.stops,
        if (widget.me != null) widget.me!,
        if (widget.rider != null) widget.rider!,
      ];

  String _pins(DriverMap w) =>
      [w.pickup, w.dropoff, ...w.stops, w.rider, w.route?.length].map((p) => p is LatLngPoint ? '${p.lat},${p.lng}' : '$p').join('|');

  String _camera(DriverMap w) => '${_pins(w)}|${w.me?.lat},${w.me?.lng}|${w.center?.lat},${w.center?.lng}';

  @override
  void didUpdateWidget(DriverMap old) {
    super.didUpdateWidget(old);
    if (old.bottomPadding != widget.bottomPadding) {
      _controller?.setPadding(EdgeInsets.only(bottom: widget.bottomPadding, top: 80)).catchError((_) {});
    }
    if (_pins(old) != _pins(widget)) _drawPins();
    if (_camera(old) != _camera(widget) || old.bottomPadding != widget.bottomPadding) _fit();
  }

  Future<void> _onCreated(nav.GoogleMapViewController c) async {
    _controller = c;
    try {
      await c.setMyLocationEnabled(widget.showMyLocation);
      await c.settings.setMyLocationButtonEnabled(false);
    } catch (_) {
      // Location permission not granted yet; the blue dot appears once it is.
    }
    await _drawPins();
    await _fit();
  }

  Future<void> _drawPins() async {
    final c = _controller;
    if (c == null) return;
    try {
      final icons = await _PinIcons.load(MediaQuery.maybeOf(context)?.devicePixelRatio ?? 2);
      await c.clearMarkers();
      await c.clearPolylines();
      await c.addMarkers([
        if (widget.pickup != null)
          nav.MarkerOptions(position: _ll(widget.pickup!), icon: icons.pickup, anchor: nav.MarkerAnchor(u: 0.5, v: icons.pickupAnchor), infoWindow: const nav.InfoWindow(title: 'Pickup')),
        for (var i = 0; i < widget.stops.length; i++)
          nav.MarkerOptions(position: _ll(widget.stops[i]), icon: icons.stop, anchor: const nav.MarkerAnchor(u: 0.5, v: 0.5), infoWindow: nav.InfoWindow(title: 'Stop ${i + 1}')),
        if (widget.dropoff != null)
          nav.MarkerOptions(position: _ll(widget.dropoff!), icon: icons.dropoff, anchor: nav.MarkerAnchor(u: 0.5, v: icons.dropoffAnchor), infoWindow: const nav.InfoWindow(title: 'Drop-off')),
        if (widget.rider != null)
          nav.MarkerOptions(position: _ll(widget.rider!), icon: icons.rider, anchor: nav.MarkerAnchor(u: 0.5, v: icons.riderAnchor), infoWindow: const nav.InfoWindow(title: 'Your rider is here')),
      ]);
      final route = widget.route;
      if (route != null && route.length >= 2) {
        await c.addPolylines([nav.PolylineOptions(points: route.map(_ll).toList(), strokeColor: Brand.blue, strokeWidth: 5)]);
      }
    } catch (_) {
      // The view can be torn down mid-update; the next change redraws.
    }
  }

  Future<void> _fit() async {
    final c = _controller;
    if (c == null) return;
    final pts = _points;
    try {
      if (pts.length >= 2) {
        final lats = pts.map((p) => p.lat);
        final lngs = pts.map((p) => p.lng);
        final bounds = nav.LatLngBounds(
          southwest: nav.LatLng(latitude: lats.reduce(math.min), longitude: lngs.reduce(math.min)),
          northeast: nav.LatLng(latitude: lats.reduce(math.max), longitude: lngs.reduce(math.max)),
        );
        await c.animateCamera(nav.CameraUpdate.newLatLngBounds(bounds, padding: 70));
      } else {
        final p = pts.isNotEmpty ? pts.first : widget.center;
        if (p != null) await c.animateCamera(nav.CameraUpdate.newLatLngZoom(_ll(p), 15));
      }
    } catch (_) {
      // Camera updates can fail while the map is still laying out; the next change refits.
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!DriverMap.enabled) {
      return Container(
        color: const Color(0xFFE8EEF2),
        alignment: Alignment.center,
        child: const Icon(Icons.map_outlined, size: 64, color: Colors.black26),
      );
    }
    final start = widget.pickup ?? widget.center ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng);
    return nav.GoogleMapsMapView(
      onViewCreated: _onCreated,
      initialCameraPosition: nav.CameraPosition(target: _ll(start), zoom: 14),
      initialZoomControlsEnabled: false,
      initialMapToolbarEnabled: false,
      initialCompassEnabled: false,
      initialPadding: EdgeInsets.only(bottom: widget.bottomPadding, top: 80),
    );
  }
}

/// Coloured dots for the pins (the Navigation SDK map has no tinted default markers), drawn once per screen density.
/// Pickup / drop-off pins with a label and stop dots (shared drawing in ridecoop_core), registered with the
/// Navigation SDK once per pixel ratio.
class _PinIcons {
  _PinIcons(this.pickup, this.stop, this.dropoff, this.pickupAnchor, this.dropoffAnchor, this.rider, this.riderAnchor);
  final nav.ImageDescriptor rider;
  final double riderAnchor;
  final nav.ImageDescriptor pickup;
  final nav.ImageDescriptor stop;
  final nav.ImageDescriptor dropoff;
  final double pickupAnchor;
  final double dropoffAnchor;

  static Future<_PinIcons>? _loading;
  static double? _ratio;

  static Future<_PinIcons> load(double ratio) {
    if (_loading != null && _ratio == ratio) return _loading!;
    _ratio = ratio;
    return _loading = _build(ratio);
  }

  static Future<_PinIcons> _build(double ratio) async {
    final p = await MapIcons.labelPin('Pickup', Brand.blue, ratio);
    final d = await MapIcons.labelPin('Drop-off', Brand.red, ratio);
    final s = await MapIcons.dot(Colors.orange, ratio, size: 22);
    final r = await MapIcons.labelPin('Rider', const Color(0xFF1E9E5A), ratio);
    return _PinIcons(await _register(p, ratio), await _register(s, ratio), await _register(d, ratio), p.anchorY, d.anchorY, await _register(r, ratio), r.anchorY);
  }

  static Future<nav.ImageDescriptor> _register(MapIcon icon, double ratio) =>
      nav.registerBitmapImage(bitmap: ByteData.sublistView(icon.png), imagePixelRatio: ratio, width: icon.width, height: icon.height);
}
