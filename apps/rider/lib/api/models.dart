// Data models, parsed from the API's JSON (shapes are pinned by test/fixtures, captured from the real backend).

import 'package:ridecoop_core/ridecoop_core.dart' show User;

export 'package:ridecoop_core/ridecoop_core.dart' show User;

double _d(dynamic v, [double fallback = 0]) => v == null ? fallback : (v is num ? v.toDouble() : double.tryParse('$v') ?? fallback);
double? _dn(dynamic v) => v == null ? null : (v is num ? v.toDouble() : double.tryParse('$v'));
int? _in(dynamic v) => v == null ? null : (v is num ? v.toInt() : int.tryParse('$v'));
String? _s(dynamic v) => v?.toString();
DateTime? _dt(dynamic v) => v == null ? null : DateTime.tryParse('$v')?.toLocal();
Map<String, dynamic> _m(dynamic v) => v is Map<String, dynamic> ? v : <String, dynamic>{};
List<dynamic> _l(dynamic v) => v is List ? v : const [];

class LatLngPoint {
  const LatLngPoint(this.lat, this.lng);
  final double lat;
  final double lng;
}

/// A chosen location: from search, reverse geocoding, or the device.
class Place {
  const Place({required this.name, this.address, required this.lat, required this.lng, this.placeId});

  final String name;
  final String? address;
  final double lat;
  final double lng;
  final String? placeId;

  factory Place.fromJson(Map<String, dynamic> j) => Place(
        name: _s(j['name']) ?? _s(j['address']) ?? 'Selected location',
        address: _s(j['address']),
        lat: _d(j['lat']),
        lng: _d(j['lng']),
        placeId: _s(j['place_id']),
      );

  /// Single line for the API's pickup_address / dropoff_address.
  String get label => (address == null || address!.isEmpty || address!.startsWith(name)) ? (address ?? name) : '$name, $address';
}

class Suggestion {
  Suggestion({required this.placeId, required this.mainText, this.secondaryText, this.distanceM});

  final String placeId;
  final String mainText;
  final String? secondaryText;
  final int? distanceM;

  factory Suggestion.fromJson(Map<String, dynamic> j) => Suggestion(
        placeId: _s(j['place_id']) ?? '',
        mainText: _s(j['main_text']) ?? '',
        secondaryText: _s(j['secondary_text']),
        distanceM: _in(j['distance_m']),
      );
}

class FareLine {
  FareLine(this.code, this.label, this.amount);
  final String code;
  final String label;
  final double amount;

  factory FareLine.fromJson(Map<String, dynamic> j) => FareLine(_s(j['code']) ?? '', _s(j['label']) ?? '', _d(j['amount']));
}

class Quote {
  Quote({
    required this.vehicleType,
    required this.displayName,
    this.description,
    required this.currency,
    required this.fare,
    required this.holdAmount,
    required this.distanceM,
    required this.durationS,
    required this.surge,
    required this.maxPassengers,
    required this.maxLuggage,
    required this.lines,
  });

  final String vehicleType;
  final String displayName;
  final String? description;
  final String currency;
  final double fare;
  final double holdAmount;
  final int distanceM;
  final int durationS;
  final double surge;
  final int maxPassengers;
  final int maxLuggage;
  final List<FareLine> lines;

  factory Quote.fromJson(Map<String, dynamic> j) => Quote(
        vehicleType: _s(j['vehicle_type']) ?? '',
        displayName: _s(j['display_name']) ?? _s(j['vehicle_type']) ?? '',
        description: _s(j['description']),
        currency: _s(j['currency']) ?? '',
        fare: _d(j['fare']),
        holdAmount: _d(j['hold_amount']),
        distanceM: _in(j['distance_m']) ?? 0,
        durationS: _in(j['duration_s']) ?? 0,
        surge: _d(j['surge_multiplier'], 1),
        maxPassengers: _in(j['max_passengers']) ?? 4,
        maxLuggage: _in(j['max_luggage']) ?? 2,
        lines: _l(_m(j['breakdown'])['lines']).map((e) => FareLine.fromJson(_m(e))).toList(),
      );
}

class RouteInfo {
  RouteInfo({required this.distanceM, required this.durationS, this.polyline});
  final int distanceM;
  final int durationS;
  final List<LatLngPoint>? polyline;

  factory RouteInfo.fromJson(Map<String, dynamic> j) => RouteInfo(
        distanceM: _in(j['distance_m']) ?? 0,
        durationS: _in(j['duration_s']) ?? 0,
        polyline: j['polyline'] is List
            ? _l(j['polyline']).whereType<List>().map((p) => LatLngPoint(_d(p[0]), _d(p[1]))).toList()
            : null,
      );
}

class Estimate {
  Estimate(this.quotes, this.route);
  final List<Quote> quotes;
  final RouteInfo? route;

  factory Estimate.fromJson(Map<String, dynamic> j) => Estimate(
        _l(j['quotes']).map((e) => Quote.fromJson(_m(e))).toList(),
        j['route'] is Map ? RouteInfo.fromJson(_m(j['route'])) : null,
      );
}

class Vehicle {
  Vehicle({required this.make, required this.model, this.colour, required this.plate, this.photoUrl, this.year});
  final String make;
  final String model;
  final String? colour;
  final String plate;
  final String? photoUrl;
  final int? year;

  factory Vehicle.fromJson(Map<String, dynamic> j) => Vehicle(
        make: _s(j['make']) ?? '',
        model: _s(j['model']) ?? '',
        colour: _s(j['colour']),
        plate: _s(j['plate']) ?? '',
        photoUrl: _s(j['photo_url']),
        year: _in(j['year']),
      );

  String get description => [if (colour != null && colour!.isNotEmpty) colour, make, model].join(' ');
}

class TripStop {
  TripStop(this.lat, this.lng, this.address, [this.reachedAt]);
  final double lat;
  final double lng;
  final String? address;

  /// Extra stops only: when the driver got there.
  final DateTime? reachedAt;

  factory TripStop.fromJson(Map<String, dynamic> j) => TripStop(_d(j['lat']), _d(j['lng']), _s(j['address']), _dt(j['reached_at']));

  bool get reached => reachedAt != null;
  LatLngPoint get point => LatLngPoint(lat, lng);
  Place toPlace() => Place(name: address ?? 'Stop', address: address, lat: lat, lng: lng);
}

/// A rider's change of stops during a ride: pending (the driver must confirm), accepted or declined.
class StopChange {
  StopChange({required this.status, required this.stops, this.estimatedFare, this.respondedAt});
  final String status;
  final List<TripStop> stops;
  final double? estimatedFare;
  final DateTime? respondedAt;

  factory StopChange.fromJson(Map<String, dynamic> j) => StopChange(
        status: _s(j['status']) ?? '',
        stops: _l(j['stops']).map((e) => TripStop.fromJson(_m(e))).toList(),
        estimatedFare: _dn(j['estimated_fare']),
        respondedAt: _dt(j['responded_at']),
      );

  bool get pending => status == 'pending';
}

class Trip {
  Trip({
    required this.id,
    required this.status,
    required this.vehicleType,
    required this.pickup,
    required this.dropoff,
    this.estimatedFare,
    this.finalFare,
    required this.currency,
    required this.paymentMethod,
    required this.paymentStatus,
    required this.amountDue,
    this.driver,
    this.vehicle,
    this.paymentActionUrl,
    this.cancelledBy,
    this.cancellationReason,
    required this.cancellationFee,
    this.cardLabel,
    required this.fareLines,
    this.requestedAt,
    this.completedAt,
    required this.rated,
    this.stops = const [],
    this.stopChange,
    this.pin,
    this.pinVerified = false,
  });

  final String id;
  final String status;
  final String vehicleType;
  final TripStop pickup;
  final TripStop dropoff;
  final double? estimatedFare;
  final double? finalFare;
  final String currency;
  final String paymentMethod;
  final String paymentStatus;
  final double amountDue;
  final User? driver;
  final Vehicle? vehicle;
  final String? paymentActionUrl;
  final String? cancelledBy;
  final String? cancellationReason;
  final double cancellationFee;
  final String? cardLabel;
  final List<FareLine> fareLines;
  final DateTime? requestedAt;
  final DateTime? completedAt;
  final bool rated;

  /// Extra stops in order (reached ones have reachedAt).
  final List<TripStop> stops;
  final StopChange? stopChange;

  /// The rider tells the driver this; the driver must enter it to start the trip.
  final String? pin;
  final bool pinVerified;

  factory Trip.fromJson(Map<String, dynamic> j) {
    final card = j['card'];
    final driver = j['driver'];
    final vehicle = j['vehicle'];
    final action = j['payment_action'];
    final ts = _m(j['timestamps']);
    return Trip(
      id: _s(j['id']) ?? '',
      status: _s(j['status']) ?? '',
      vehicleType: _s(j['vehicle_type']) ?? '',
      pickup: TripStop.fromJson(_m(j['pickup'])),
      dropoff: TripStop.fromJson(_m(j['dropoff'])),
      estimatedFare: _dn(_m(j['estimate'])['fare']),
      finalFare: _dn(_m(j['actual'])['fare']),
      currency: _s(j['currency']) ?? '',
      paymentMethod: _s(j['payment_method']) ?? 'cash',
      paymentStatus: _s(j['payment_status']) ?? '',
      amountDue: _d(j['amount_due']),
      driver: driver is Map<String, dynamic> && driver['id'] != null ? User.fromJson(driver) : null,
      vehicle: vehicle is Map<String, dynamic> && vehicle['plate'] != null ? Vehicle.fromJson(vehicle) : null,
      paymentActionUrl: action is Map ? _s(action['url']) : null,
      cancelledBy: _s(j['cancelled_by']),
      cancellationReason: _s(j['cancellation_reason']),
      cancellationFee: _d(j['cancellation_fee']),
      cardLabel: card is Map ? '${_s(card['brand']) ?? 'Card'} •••• ${_s(card['last4']) ?? ''}' : null,
      fareLines: _l(_m(j['fare_breakdown'])['lines']).map((e) => FareLine.fromJson(_m(e))).toList(),
      requestedAt: _dt(ts['requested_at']),
      completedAt: _dt(ts['completed_at']),
      rated: _l(j['ratings']).isNotEmpty,
      stops: _l(j['stops']).map((e) => TripStop.fromJson(_m(e))).toList(),
      stopChange: j['stop_change'] is Map<String, dynamic> ? StopChange.fromJson(j['stop_change']) : null,
      pin: _s(j['pin']),
      pinVerified: j['pin_verified'] == true,
    );
  }

  bool get isActive => const ['pending_payment', 'requested', 'accepted', 'arrived', 'in_progress'].contains(status);

  /// Stops can be changed until the ride ends.
  bool get canChangeStops => isActive;

  List<TripStop> get remainingStops => stops.where((s) => !s.reached).toList();
  bool get hasDriver => const ['accepted', 'arrived', 'in_progress'].contains(status);
  bool get canCancel => const ['pending_payment', 'requested', 'accepted', 'arrived'].contains(status);

  /// Cancelling now costs a fee (a driver has accepted).
  bool get cancelHasFee => const ['accepted', 'arrived'].contains(status);

  double? get fare => finalFare ?? estimatedFare;

  String get statusTitle => switch (status) {
        'pending_payment' => 'Confirm your card',
        'requested' => 'Finding your driver',
        'accepted' => 'Your driver is on the way',
        'arrived' => 'Your driver has arrived',
        'in_progress' => 'On your way',
        'completed' => 'You have arrived',
        'cancelled' => 'Ride cancelled',
        'no_drivers' => 'No drivers available',
        _ => status,
      };
}

class DriverPosition {
  DriverPosition(this.lat, this.lng, this.heading);
  final double lat;
  final double lng;
  final double? heading;

  factory DriverPosition.fromJson(Map<String, dynamic> j) => DriverPosition(_d(j['lat']), _d(j['lng']), _dn(j['heading']));
}

/// GET /trips/active and GET /trips/{id}.
class TripSnapshot {
  TripSnapshot(this.trip, this.driverPosition);
  final Trip? trip;
  final DriverPosition? driverPosition;

  factory TripSnapshot.fromJson(Map<String, dynamic> j) => TripSnapshot(
        j['trip'] is Map<String, dynamic> ? Trip.fromJson(j['trip']) : null,
        j['driver_position'] is Map<String, dynamic> ? DriverPosition.fromJson(j['driver_position']) : null,
      );
}

class PaymentCard {
  PaymentCard({
    required this.id,
    required this.status,
    this.brand,
    this.last4,
    this.expMonth,
    this.expYear,
    required this.expired,
    required this.isDefault,
    this.redirectUrl,
    this.failureReason,
  });

  final String id;
  final String status; // pending | active | failed | removed
  final String? brand;
  final String? last4;
  final int? expMonth;
  final int? expYear;
  final bool expired;
  final bool isDefault;
  final String? redirectUrl;
  final String? failureReason;

  factory PaymentCard.fromJson(Map<String, dynamic> j) => PaymentCard(
        id: _s(j['id']) ?? '',
        status: _s(j['status']) ?? '',
        brand: _s(j['brand']),
        last4: _s(j['last4']),
        expMonth: _in(j['exp_month']),
        expYear: _in(j['exp_year']),
        expired: j['expired'] == true,
        isDefault: j['is_default'] == true,
        redirectUrl: _s(j['redirect_url']),
        failureReason: _s(j['failure_reason']),
      );

  bool get usable => status == 'active' && !expired;
  String get label => '${brand ?? 'Card'} •••• ${last4 ?? '????'}';
  String get expiry => expMonth == null ? '' : '${expMonth.toString().padLeft(2, '0')}/${(expYear ?? 0) % 100}';
}
