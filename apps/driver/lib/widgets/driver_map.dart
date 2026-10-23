import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import 'package:ridecoop_core/ridecoop_core.dart' show Brand;

import '../api/models.dart';
import '../config.dart';

/// Full-bleed map with pickup and drop-off. [me] (the driver's position, shown by the blue dot) is used to
/// frame the camera; it refits when the points change.
class DriverMap extends StatefulWidget {
  const DriverMap({
    super.key,
    this.pickup,
    this.dropoff,
    this.me,
    this.route,
    this.center,
    this.bottomPadding = 0,
    this.showMyLocation = true,
  });

  /// Tests switch this off: Google Maps needs a real platform view.
  static bool enabled = AppConfig.mapsEnabled;

  final LatLngPoint? pickup;
  final LatLngPoint? dropoff;
  final LatLngPoint? me;
  final List<LatLngPoint>? route;
  final LatLngPoint? center;
  final double bottomPadding;
  final bool showMyLocation;

  @override
  State<DriverMap> createState() => _DriverMapState();
}

class _DriverMapState extends State<DriverMap> {
  GoogleMapController? _controller;

  static LatLng _ll(LatLngPoint p) => LatLng(p.lat, p.lng);

  List<LatLngPoint> get _points => [
        if (widget.pickup != null) widget.pickup!,
        if (widget.dropoff != null) widget.dropoff!,
        if (widget.me != null) widget.me!,
      ];

  String _signature(DriverMap w) =>
      [w.pickup, w.dropoff, w.me, w.center].map((p) => p is LatLngPoint ? '${p.lat},${p.lng}' : '$p').join('|');

  @override
  void didUpdateWidget(DriverMap old) {
    super.didUpdateWidget(old);
    if (_signature(old) != _signature(widget) || old.bottomPadding != widget.bottomPadding) _fit();
  }

  Future<void> _fit() async {
    final c = _controller;
    if (c == null) return;
    final pts = _points;
    try {
      if (pts.length >= 2) {
        final lats = pts.map((p) => p.lat);
        final lngs = pts.map((p) => p.lng);
        final bounds = LatLngBounds(
          southwest: LatLng(lats.reduce(math.min), lngs.reduce(math.min)),
          northeast: LatLng(lats.reduce(math.max), lngs.reduce(math.max)),
        );
        await c.animateCamera(CameraUpdate.newLatLngBounds(bounds, 70));
      } else {
        final p = pts.isNotEmpty ? pts.first : widget.center;
        if (p != null) await c.animateCamera(CameraUpdate.newLatLngZoom(_ll(p), 15));
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
    final markers = <Marker>{
      if (widget.pickup != null)
        Marker(
          markerId: const MarkerId('pickup'),
          position: _ll(widget.pickup!),
          icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueAzure),
          infoWindow: const InfoWindow(title: 'Pickup'),
        ),
      if (widget.dropoff != null)
        Marker(
          markerId: const MarkerId('dropoff'),
          position: _ll(widget.dropoff!),
          icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueRed),
          infoWindow: const InfoWindow(title: 'Drop-off'),
        ),
    };
    final route = widget.route;
    final polylines = <Polyline>{
      if (route != null && route.length >= 2)
        Polyline(polylineId: const PolylineId('route'), points: route.map(_ll).toList(), color: Brand.blue, width: 5),
    };

    return GoogleMap(
      initialCameraPosition: CameraPosition(target: _ll(start), zoom: 14),
      markers: markers,
      polylines: polylines,
      myLocationEnabled: widget.showMyLocation,
      myLocationButtonEnabled: false,
      zoomControlsEnabled: false,
      mapToolbarEnabled: false,
      compassEnabled: false,
      padding: EdgeInsets.only(bottom: widget.bottomPadding, top: 80),
      onMapCreated: (c) {
        _controller = c;
        _fit();
      },
    );
  }
}
