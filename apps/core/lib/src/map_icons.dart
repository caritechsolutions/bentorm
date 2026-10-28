import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import 'brand.dart';

/// A PNG for a map marker and the point in it (0..1) that sits on the map position.
class MapIcon {
  const MapIcon(this.png, this.width, this.height, {this.anchorX = 0.5, this.anchorY = 0.5});
  final Uint8List png;

  /// Logical size (the PNG is drawn at the device pixel ratio).
  final double width;
  final double height;
  final double anchorX;
  final double anchorY;
}

/// Map markers drawn in code, in Ride Coop colours: top-down cars that turn with the driver's heading, and pickup /
/// drop-off pins with a label ("Pickup · 4 min"). Cached per look, so redrawing a map is free.
class MapIcons {
  MapIcons._();

  static final Map<String, Future<MapIcon>> _cache = {};

  /// Top-down car pointing north (rotate the marker by the heading). Standard yellow, premium black, XL blue,
  /// deliveries orange with a bag on the roof.
  static Future<MapIcon> car(String? vehicleType, double ratio, {bool small = false}) {
    final type = (vehicleType ?? 'standard').toLowerCase();
    return _cache.putIfAbsent('car:$type:$ratio:$small', () => _car(type, ratio, small));
  }

  /// A label bubble above a coloured dot; the dot is the map position.
  static Future<MapIcon> labelPin(String label, Color color, double ratio) =>
      _cache.putIfAbsent('pin:$label:${color.toARGB32()}:$ratio', () => _labelPin(label, color, ratio));

  /// A plain dot (stops).
  static Future<MapIcon> dot(Color color, double ratio, {double size = 20}) =>
      _cache.putIfAbsent('dot:${color.toARGB32()}:$ratio:$size', () => _dot(color, ratio, size));

  static ({Color body, Color roof, double length}) _look(String type) => switch (type) {
        'premium' || 'luxury' || 'executive' => (body: const Color(0xFF1F2328), roof: const Color(0xFF3A3F45), length: 1.0),
        'xl' || 'van' || 'suv' => (body: Brand.blue, roof: const Color(0xFF0A5F8D), length: 1.18),
        'delivery' => (body: const Color(0xFFF28C28), roof: const Color(0xFFC96A10), length: 0.95),
        _ => (body: Brand.yellow, roof: const Color(0xFFE0A100), length: 1.0),
      };

  static Future<MapIcon> _car(String type, double ratio, bool small) async {
    final look = _look(type);
    final w = small ? 18.0 : 26.0;
    final h = w * 1.9 * look.length;
    final rec = ui.PictureRecorder();
    final c = Canvas(rec)..scale(ratio);
    final body = RRect.fromRectAndRadius(Rect.fromLTWH(w * 0.08, h * 0.04, w * 0.84, h * 0.92), Radius.circular(w * 0.32));
    // Soft shadow so the car reads on any map colour.
    c.drawRRect(body.shift(Offset(0, h * 0.02)), Paint()
      ..color = Colors.black.withValues(alpha: 0.28)
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 2));
    // Wheels.
    final tyre = Paint()..color = const Color(0xFF111111);
    for (final y in [0.2, 0.72]) {
      c.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(0, h * y, w * 0.14, h * 0.14), const Radius.circular(2)), tyre);
      c.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(w * 0.86, h * y, w * 0.14, h * 0.14), const Radius.circular(2)), tyre);
    }
    c.drawRRect(body, Paint()..color = look.body);
    c.drawRRect(body, Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.2);
    // Windscreen (front = top), roof, rear window.
    final glass = Paint()..color = const Color(0xFF26323B);
    c.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(w * 0.2, h * 0.24, w * 0.6, h * 0.14), Radius.circular(w * 0.12)), glass);
    c.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(w * 0.22, h * 0.4, w * 0.56, h * 0.26), Radius.circular(w * 0.1)), Paint()..color = look.roof);
    c.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(w * 0.24, h * 0.68, w * 0.52, h * 0.1), Radius.circular(w * 0.1)), glass);
    // Headlights.
    final light = Paint()..color = const Color(0xFFFFF4C2);
    c.drawCircle(Offset(w * 0.28, h * 0.08), w * 0.06, light);
    c.drawCircle(Offset(w * 0.72, h * 0.08), w * 0.06, light);
    if (type == 'delivery') {
      // A bag on the roof.
      c.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(w * 0.3, h * 0.44, w * 0.4, h * 0.18), const Radius.circular(2)), Paint()..color = Colors.white);
    }
    return _png(rec, w, h, ratio);
  }

  static Future<MapIcon> _labelPin(String label, Color color, double ratio) async {
    final tp = TextPainter(
      text: TextSpan(text: label, style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w700)),
      textDirection: TextDirection.ltr,
      maxLines: 1,
      ellipsis: '…',
    )..layout(maxWidth: 220);
    final bw = tp.width + 20;
    const bh = 26.0;
    const dot = 16.0;
    const gap = 6.0;
    final w = bw;
    const h = bh + gap + dot + 2;
    final rec = ui.PictureRecorder();
    final c = Canvas(rec)..scale(ratio);
    final bubble = RRect.fromRectAndRadius(Rect.fromLTWH(0, 0, bw, bh), const Radius.circular(13));
    c.drawRRect(bubble.shift(const Offset(0, 1.5)), Paint()
      ..color = Colors.black.withValues(alpha: 0.25)
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 2));
    c.drawRRect(bubble, Paint()..color = color);
    tp.paint(c, Offset(10, (bh - tp.height) / 2));
    // Stem and dot.
    c.drawLine(Offset(w / 2, bh), Offset(w / 2, bh + gap), Paint()
      ..color = color
      ..strokeWidth = 2);
    final center = Offset(w / 2, bh + gap + dot / 2);
    c.drawCircle(center, dot / 2, Paint()..color = Colors.white);
    c.drawCircle(center, dot / 2 - 3, Paint()..color = color);
    return _png(rec, w, h, ratio, anchorY: (bh + gap + dot / 2) / h);
  }

  static Future<MapIcon> _dot(Color color, double ratio, double size) async {
    final rec = ui.PictureRecorder();
    final c = Canvas(rec)..scale(ratio);
    final center = Offset(size / 2, size / 2);
    c.drawCircle(center, size / 2, Paint()..color = Colors.white);
    c.drawCircle(center, size / 2 - 3, Paint()..color = color);
    return _png(rec, size, size, ratio);
  }

  static Future<MapIcon> _png(ui.PictureRecorder rec, double w, double h, double ratio, {double anchorY = 0.5}) async {
    final image = await rec.endRecording().toImage((w * ratio).ceil(), (h * ratio).ceil());
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    return MapIcon(bytes!.buffer.asUint8List(), w, h, anchorY: anchorY);
  }
}

/// Smooth movement for a marker: give it each new position as it arrives and read [at] every frame.
/// Moves in a straight line over [over] (about the time between pings) and turns the shortest way.
///
/// Steady when parked: GPS wanders a few metres and its compass heading is noise when the car isn't moving, so
/// small moves (under [minMoveM] while slower than [minSpeed]) are ignored and the car only turns once it has
/// really moved — then it faces the way it travelled (the phone's heading is trusted only at speed).
/// After a gap (the app was in the background, a lost connection) it jumps to the new position instead of
/// sliding across the map.
class GlidingPoint {
  GlidingPoint({this.over = const Duration(seconds: 4), this.minMoveM = 8, this.minSpeed = 1.5, this.staleAfter = const Duration(seconds: 12)});
  final Duration over;
  final double minMoveM;

  /// m/s (~5 km/h)
  final double minSpeed;
  final Duration staleAfter;

  ({double lat, double lng})? _from;
  ({double lat, double lng})? _to;
  double _fromHeading = 0;
  double _toHeading = 0;
  DateTime _start = DateTime.fromMillisecondsSinceEpoch(0);
  DateTime _lastFix = DateTime.fromMillisecondsSinceEpoch(0);

  bool get hasPosition => _to != null;

  /// A new reported position, with the phone's [heading] (degrees) and [speed] (m/s) when known.
  void moveTo(double lat, double lng, double? heading, {double? speed, DateTime? now}) {
    final t = now ?? DateTime.now();
    final cur = at(t);
    final last = _to;
    final stale = t.difference(_lastFix) > staleAfter;
    _lastFix = t;
    if (cur == null || last == null || stale) {
      // First fix, or the first after a gap: jump here. Keep the way it was facing unless the phone knows better at speed.
      final atSpeed = heading != null && speed != null && speed >= minSpeed * 2;
      _from = (lat: lat, lng: lng);
      _to = _from;
      _fromHeading = _toHeading = cur == null ? (heading ?? 0) : (atSpeed ? (heading ?? cur.heading) : cur.heading);
      _start = t;
      return;
    }
    final moved = _metres(last.lat, last.lng, lat, lng);
    final fast = speed != null && speed >= minSpeed;
    if (moved < minMoveM && !fast) return; // parked or crawling: GPS noise, don't move or turn
    _from = (lat: cur.lat, lng: cur.lng);
    _fromHeading = cur.heading;
    _to = (lat: lat, lng: lng);
    // Face the way it went; the phone's heading only when clearly driving (it's noise when slow).
    _toHeading = heading != null && speed != null && speed >= minSpeed * 2
        ? heading
        : (moved >= minMoveM ? _bearing(last.lat, last.lng, lat, lng) : cur.heading);
    // A jump of more than ~2 km (first fix after a gap): don't glide across the map.
    if (moved > 2000) {
      _from = _to;
      _fromHeading = _toHeading;
    }
    _start = t;
  }

  static double _metres(double lat1, double lng1, double lat2, double lng2) {
    const r = 6371000.0, rad = math.pi / 180;
    final dLat = (lat2 - lat1) * rad, dLng = (lng2 - lng1) * rad;
    final a = math.sin(dLat / 2) * math.sin(dLat / 2) + math.cos(lat1 * rad) * math.cos(lat2 * rad) * math.sin(dLng / 2) * math.sin(dLng / 2);
    return 2 * r * math.asin(math.min(1, math.sqrt(a)));
  }

  /// Where the marker is now, or null before the first position.
  ({double lat, double lng, double heading})? at([DateTime? now]) {
    final a = _from, b = _to;
    if (a == null || b == null) return null;
    final f = ((now ?? DateTime.now()).difference(_start).inMilliseconds / over.inMilliseconds).clamp(0.0, 1.0);
    final e = f * f * (3 - 2 * f); // ease in/out
    var d = (_toHeading - _fromHeading) % 360;
    if (d > 180) d -= 360;
    if (d < -180) d += 360;
    return (lat: a.lat + (b.lat - a.lat) * e, lng: a.lng + (b.lng - a.lng) * e, heading: (_fromHeading + d * e) % 360);
  }

  /// True while still moving towards the last position.
  bool moving([DateTime? now]) => (now ?? DateTime.now()).difference(_start) < over && _from != _to;

  static double _bearing(double lat1, double lng1, double lat2, double lng2) {
    const rad = math.pi / 180;
    final y = math.sin((lng2 - lng1) * rad) * math.cos(lat2 * rad);
    final x = math.cos(lat1 * rad) * math.sin(lat2 * rad) - math.sin(lat1 * rad) * math.cos(lat2 * rad) * math.cos((lng2 - lng1) * rad);
    return (math.atan2(y, x) / rad + 360) % 360;
  }
}
