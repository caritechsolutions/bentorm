import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:ridecoop_core/ridecoop_core.dart' show GlidingPoint, MapIcon, MapIcons;

import '../api/models.dart';
import '../brand.dart';
import '../config.dart';

/// Full-bleed map: labelled pickup / drop-off, stops, the route, the driver's car (gliding between updates and
/// turned to face the way it's going) and free cars nearby. Refits the camera when the points change.
class RideMap extends StatefulWidget {
  const RideMap({
    super.key,
    this.pickup,
    this.dropoff,
    this.stops = const [],
    this.driver,
    this.driverHeading,
    this.driverVehicleType,
    this.driverSpeed,
    this.followDriver = false,
    this.followWith,
    this.route,
    this.splitRouteAtDriver = false,
    this.pickupLabel = 'Pickup',
    this.dropoffLabel = 'Drop-off',
    this.cars = const [],
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
  final double? driverHeading;
  final String? driverVehicleType;

  /// m/s: the car only turns when it's really moving (GPS heading is noise when parked).
  final double? driverSpeed;

  /// Keep the camera on the driver (and [followWith], e.g. the pickup) as they move, until the rider moves the map;
  /// the recenter button turns it back on.
  final bool followDriver;
  final LatLngPoint? followWith;
  final List<LatLngPoint>? route;

  /// During the ride: the part of [route] already driven is drawn grey.
  final bool splitRouteAtDriver;
  final String pickupLabel;
  final String dropoffLabel;
  final List<NearbyCar> cars;
  final LatLngPoint? center;
  final double bottomPadding;
  final bool showMyLocation;

  @override
  State<RideMap> createState() => _RideMapState();
}

class _RideMapState extends State<RideMap> with SingleTickerProviderStateMixin {
  GoogleMapController? _controller;
  final _car = GlidingPoint();
  // ~15 updates a second is smooth on a map and keeps the platform channel quiet.
  Duration _lastFrame = Duration.zero;
  late final Ticker _ticker = createTicker((elapsed) {
    if (!mounted) return;
    final done = !_car.moving();
    if (done || elapsed - _lastFrame >= const Duration(milliseconds: 66)) {
      _lastFrame = elapsed;
      setState(() {});
    }
    if (done) {
      _ticker.stop();
      _lastFrame = Duration.zero;
    }
  });
  final Map<String, BitmapDescriptor> _icons = {};
  bool _following = true;
  bool _cameraByUs = false; // a camera move we started (not the rider's finger)
  DateTime _lastFollow = DateTime.fromMillisecondsSinceEpoch(0);
  double _ratio = 2;

  static LatLng _ll(LatLngPoint p) => LatLng(p.lat, p.lng);

  @override
  void initState() {
    super.initState();
    _feedDriver();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final r = MediaQuery.maybeOf(context)?.devicePixelRatio ?? 2;
    if (r != _ratio || _icons.isEmpty) {
      _ratio = r;
      _loadIcons();
    }
  }

  @override
  void dispose() {
    _ticker.dispose();
    super.dispose();
  }

  void _feedDriver() {
    final d = widget.driver;
    if (d == null) return;
    _car.moveTo(d.lat, d.lng, widget.driverHeading, speed: widget.driverSpeed);
    if (RideMap.enabled && !_ticker.isActive) _ticker.start();
  }

  Future<void> _loadIcons() async {
    if (!RideMap.enabled) return;
    final wanted = <String, Future<MapIcon>>{
      'pickup': MapIcons.labelPin(widget.pickupLabel, Brand.blue, _ratio),
      'dropoff': MapIcons.labelPin(widget.dropoffLabel, Brand.red, _ratio),
      'stop': MapIcons.dot(Colors.orange, _ratio),
      'driver': MapIcons.car(widget.driverVehicleType, _ratio),
      for (final t in {for (final c in widget.cars) c.vehicleType ?? 'standard'}) 'car:$t': MapIcons.car(t, _ratio, small: true),
    };
    for (final e in wanted.entries) {
      final icon = await e.value;
      _icons[e.key] = BitmapDescriptor.bytes(icon.png, imagePixelRatio: _ratio);
      _anchors[e.key] = Offset(icon.anchorX, icon.anchorY);
    }
    if (mounted) setState(() {});
  }

  final Map<String, Offset> _anchors = {};

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
    if (widget.driver != null &&
        (old.driver == null || old.driver!.lat != widget.driver!.lat || old.driver!.lng != widget.driver!.lng || old.driverHeading != widget.driverHeading)) {
      _feedDriver();
    }
    if (old.pickupLabel != widget.pickupLabel ||
        old.dropoffLabel != widget.dropoffLabel ||
        old.driverVehicleType != widget.driverVehicleType ||
        widget.cars.any((c) => !_icons.containsKey('car:${c.vehicleType ?? 'standard'}'))) {
      _loadIcons();
    }
    if (_signature(old) != _signature(widget) || old.bottomPadding != widget.bottomPadding) {
      _following && widget.followDriver && widget.driver != null ? _follow(force: true) : _fit();
    } else if (widget.followDriver && _following && widget.driver != null && widget.driver != old.driver) {
      _follow();
    }
  }

  /// Keep the car (and the point it's heading for) in view. At most every 3 s so the map doesn't keep jumping.
  Future<void> _follow({bool force = false}) async {
    final c = _controller, d = widget.driver;
    if (c == null || d == null) return;
    final now = DateTime.now();
    if (!force && now.difference(_lastFollow) < const Duration(seconds: 3)) return;
    _lastFollow = now;
    final w = widget.followWith;
    _cameraByUs = true;
    try {
      if (w == null || (w.lat - d.lat).abs() + (w.lng - d.lng).abs() < 0.003) {
        await c.animateCamera(CameraUpdate.newLatLngZoom(_ll(d), 16));
      } else {
        await c.animateCamera(CameraUpdate.newLatLngBounds(
          LatLngBounds(
            southwest: LatLng(math.min(d.lat, w.lat), math.min(d.lng, w.lng)),
            northeast: LatLng(math.max(d.lat, w.lat), math.max(d.lng, w.lng)),
          ),
          80,
        ));
      }
    } catch (_) {
      // The map may still be laying out; the next position tries again.
    }
  }

  void _recenter() {
    setState(() => _following = true);
    widget.driver != null && widget.followDriver ? _follow(force: true) : _fit();
  }

  Future<void> _fit() async {
    final c = _controller;
    if (c == null) return;
    final pts = _points;
    _cameraByUs = true;
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

  /// [route] split where the car is: (driven, still to go).
  (List<LatLng>, List<LatLng>) _split(List<LatLngPoint> route, ({double lat, double lng})? car) {
    final all = route.map(_ll).toList();
    if (car == null || !widget.splitRouteAtDriver) return (const [], all);
    var best = 0;
    var bestD = double.infinity;
    for (var i = 0; i < route.length; i++) {
      final d = math.pow(route[i].lat - car.lat, 2) + math.pow(route[i].lng - car.lng, 2);
      if (d < bestD) {
        bestD = d.toDouble();
        best = i;
      }
    }
    final here = LatLng(car.lat, car.lng);
    return ([...all.sublist(0, best + 1), here], [here, ...all.sublist(best + 1)]);
  }

  BitmapDescriptor _icon(String key, double hue) => _icons[key] ?? BitmapDescriptor.defaultMarkerWithHue(hue);
  Offset _anchor(String key) => _anchors[key] ?? const Offset(0.5, 1);

  @override
  Widget build(BuildContext context) {
    if (!RideMap.enabled) {
      return Stack(children: [
        Positioned.fill(
          child: Container(
            color: const Color(0xFFE8EEF2),
            alignment: Alignment.center,
            child: const Icon(Icons.map_outlined, size: 64, color: Colors.black26),
          ),
        ),
        _recenterButton(),
      ]);
    }

    final start = widget.pickup ?? widget.center ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng);
    final car = widget.driver == null ? null : _car.at();
    final markers = <Marker>{
      for (var i = 0; i < widget.cars.length; i++)
        Marker(
          markerId: MarkerId('car-$i'),
          position: LatLng(widget.cars[i].lat, widget.cars[i].lng),
          icon: _icon('car:${widget.cars[i].vehicleType ?? 'standard'}', BitmapDescriptor.hueYellow),
          anchor: const Offset(0.5, 0.5),
          rotation: widget.cars[i].heading ?? 0,
          flat: true,
          zIndex: 1.0,
        ),
      if (widget.pickup != null)
        Marker(
          markerId: const MarkerId('pickup'),
          position: _ll(widget.pickup!),
          icon: _icon('pickup', BitmapDescriptor.hueAzure),
          anchor: _anchor('pickup'),
          zIndex: 3.0,
        ),
      if (widget.dropoff != null)
        Marker(
          markerId: const MarkerId('dropoff'),
          position: _ll(widget.dropoff!),
          icon: _icon('dropoff', BitmapDescriptor.hueRed),
          anchor: _anchor('dropoff'),
          zIndex: 3.0,
        ),
      for (var i = 0; i < widget.stops.length; i++)
        Marker(
          markerId: MarkerId('stop-$i'),
          position: _ll(widget.stops[i]),
          icon: _icon('stop', BitmapDescriptor.hueOrange),
          anchor: _icons.containsKey('stop') ? const Offset(0.5, 0.5) : const Offset(0.5, 1),
          infoWindow: InfoWindow(title: 'Stop ${i + 1}'),
          zIndex: 2.0,
        ),
      if (car != null)
        Marker(
          markerId: const MarkerId('driver'),
          position: LatLng(car.lat, car.lng),
          icon: _icon('driver', BitmapDescriptor.hueYellow),
          anchor: const Offset(0.5, 0.5),
          rotation: car.heading,
          flat: true,
          zIndex: 4.0,
          infoWindow: const InfoWindow(title: 'Your driver'),
        ),
    };
    final route = widget.route;
    final polylines = <Polyline>{};
    if (route != null && route.length >= 2) {
      final (done, ahead) = _split(route, car == null ? null : (lat: car.lat, lng: car.lng));
      if (done.length >= 2) polylines.add(Polyline(polylineId: const PolylineId('done'), points: done, color: const Color(0xFFB0B7BF), width: 5));
      polylines.add(Polyline(polylineId: const PolylineId('route'), points: ahead, color: Brand.blue, width: 5));
    }

    return Stack(children: [
      Positioned.fill(child: _map(start, markers, polylines)),
      _recenterButton(),
    ]);
  }

  /// Back to the car (and the pickup / drop-off), following again.
  Widget _recenterButton() {
    if (!widget.followDriver || widget.driver == null) return const SizedBox.shrink();
    return Positioned(
      right: 12,
      bottom: widget.bottomPadding + 12,
      child: FloatingActionButton.small(
        key: const Key('recenter'),
        heroTag: 'recenter',
        backgroundColor: Colors.white,
        foregroundColor: _following ? Brand.blue : Brand.charcoal,
        tooltip: 'Show my driver',
        onPressed: _recenter,
        child: Icon(_following ? Icons.navigation : Icons.my_location),
      ),
    );
  }

  Widget _map(LatLngPoint start, Set<Marker> markers, Set<Polyline> polylines) {
    return GoogleMap(
      initialCameraPosition: CameraPosition(target: _ll(start), zoom: 14),
      // The rider dragging or zooming the map stops following until they tap recenter.
      onCameraMoveStarted: () {
        if (!_cameraByUs && _following && widget.followDriver) setState(() => _following = false);
      },
      onCameraIdle: () => _cameraByUs = false,
      markers: markers,
      polylines: polylines,
      style: mapStyle,
      myLocationEnabled: widget.showMyLocation,
      myLocationButtonEnabled: false,
      zoomControlsEnabled: false,
      mapToolbarEnabled: false,
      compassEnabled: false,
      padding: EdgeInsets.only(bottom: widget.bottomPadding, top: 80),
      onMapCreated: (c) {
        _controller = c;
        _following && widget.followDriver && widget.driver != null ? _follow(force: true) : _fit();
      },
    );
  }
}

/// A calmer map: businesses and transit icons hidden, softer colours, so pickup, drop-off and cars stand out.
const mapStyle = '''
[
  {"featureType":"poi","elementType":"labels.icon","stylers":[{"visibility":"off"}]},
  {"featureType":"poi.business","stylers":[{"visibility":"off"}]},
  {"featureType":"poi.park","elementType":"geometry","stylers":[{"color":"#dcefd9"}]},
  {"featureType":"transit","stylers":[{"visibility":"off"}]},
  {"featureType":"road","elementType":"labels.icon","stylers":[{"visibility":"off"}]},
  {"featureType":"road.highway","elementType":"geometry","stylers":[{"color":"#f6e7b0"}]},
  {"featureType":"water","elementType":"geometry","stylers":[{"color":"#b9dcef"}]},
  {"featureType":"landscape.man_made","elementType":"geometry","stylers":[{"color":"#f2f2f0"}]}
]
''';
