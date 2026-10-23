import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../api/models.dart';
import '../brand.dart';
import '../config.dart';

/// Full-bleed map with pickup, drop-off, route and driver. Refits the camera when the points change.
class RideMap extends StatefulWidget {
  const RideMap({
    super.key,
    this.pickup,
    this.dropoff,
    this.stops = const [],
    this.driver,
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
  final LatLngPoint? driver;
  final List<LatLngPoint>? route;
  final LatLngPoint? center;
  final double bottomPadding;
  final bool showMyLocation;

  @override
  State<RideMap> createState() => _RideMapState();
}

class _RideMapState extends State<RideMap> {
  GoogleMapController? _controller;

  static LatLng _ll(LatLngPoint p) => LatLng(p.lat, p.lng);

  List<LatLngPoint> get _points => [
        if (widget.pickup != null) widget.pickup!,
        ...widget.stops,
        if (widget.dropoff != null) widget.dropoff!,
        if (widget.driver != null) widget.driver!,
      ];

  String _signature(RideMap w) =>
      [w.pickup, w.dropoff, ...w.stops, w.driver != null, w.center].map((p) => p is LatLngPoint ? '${p.lat},${p.lng}' : '$p').join('|');

  @override
  void didUpdateWidget(RideMap old) {
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
    if (!RideMap.enabled) {
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
      for (var i = 0; i < widget.stops.length; i++)
        Marker(
          markerId: MarkerId('stop-$i'),
          position: _ll(widget.stops[i]),
          icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueOrange),
          infoWindow: InfoWindow(title: 'Stop ${i + 1}'),
        ),
      if (widget.driver != null)
        Marker(
          markerId: const MarkerId('driver'),
          position: _ll(widget.driver!),
          icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueYellow),
          infoWindow: const InfoWindow(title: 'Your driver'),
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
