// Driver-side models, parsed from the API (shapes pinned by test/fixtures, captured from the real backend).

double _d(dynamic v, [double f = 0]) => v == null ? f : (v is num ? v.toDouble() : double.tryParse('$v') ?? f);
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

class ServiceAreaOption {
  ServiceAreaOption(this.id, this.name, this.currency);
  final int id;
  final String name;
  final String currency;
  factory ServiceAreaOption.fromJson(Map<String, dynamic> j) => ServiceAreaOption(_in(j['id']) ?? 0, _s(j['name']) ?? '', _s(j['currency']) ?? '');
}

class VehicleTypeOption {
  VehicleTypeOption(this.key, this.name, this.maxPassengers, this.maxLuggage);
  final String key;
  final String name;
  final int maxPassengers;
  final int maxLuggage;
  factory VehicleTypeOption.fromJson(Map<String, dynamic> j) =>
      VehicleTypeOption(_s(j['key']) ?? '', _s(j['name']) ?? '', _in(j['max_passengers']) ?? 4, _in(j['max_luggage']) ?? 2);
}

class FeatureOption {
  FeatureOption(this.key, this.label);
  final String key;
  final String label;
  factory FeatureOption.fromJson(Map<String, dynamic> j) => FeatureOption(_s(j['key']) ?? '', _s(j['label']) ?? '');
}

class Vehicle {
  Vehicle({
    required this.id,
    required this.make,
    required this.model,
    this.year,
    this.colour,
    required this.plate,
    required this.seats,
    required this.luggage,
    required this.features,
    required this.vehicleType,
    required this.status,
    this.statusNote,
  });

  final int id;
  final String make;
  final String model;
  final int? year;
  final String? colour;
  final String plate;
  final int seats;
  final int luggage;
  final List<String> features;
  final String vehicleType;
  final String status; // pending | approved | rejected | suspended
  final String? statusNote;

  factory Vehicle.fromJson(Map<String, dynamic> j) => Vehicle(
        id: _in(j['id']) ?? 0,
        make: _s(j['make']) ?? '',
        model: _s(j['model']) ?? '',
        year: _in(j['year']),
        colour: _s(j['colour']),
        plate: _s(j['plate']) ?? '',
        seats: _in(j['seats']) ?? 4,
        luggage: _in(j['luggage_capacity']) ?? 2,
        features: _l(j['features']).map((e) => '$e').toList(),
        vehicleType: _s(j['vehicle_type']) ?? 'standard',
        status: _s(j['status']) ?? 'pending',
        statusNote: _s(j['status_note']),
      );

  String get title => [if (year != null) '$year', make, model].join(' ');
}

class DriverProfile {
  DriverProfile({
    required this.status,
    this.statusNote,
    this.serviceAreaId,
    required this.isOnline,
    required this.rating,
    required this.ratingCount,
    required this.completedTrips,
    this.licenceNumber,
    this.licenceExpiry,
    this.psvPermit,
    this.currentVehicle,
  });

  final String status; // pending | approved | suspended | rejected
  final String? statusNote;
  final int? serviceAreaId;
  final bool isOnline;
  final double rating;
  final int ratingCount;
  final int completedTrips;
  final String? licenceNumber;
  final String? licenceExpiry; // yyyy-mm-dd
  final String? psvPermit;
  final Vehicle? currentVehicle;

  factory DriverProfile.fromJson(Map<String, dynamic> j) => DriverProfile(
        status: _s(j['status']) ?? 'pending',
        statusNote: _s(j['status_note']),
        serviceAreaId: _in(j['service_area_id']),
        isOnline: j['is_online'] == true,
        rating: _d(j['rating_avg'], 5),
        ratingCount: _in(j['rating_count']) ?? 0,
        completedTrips: _in(j['completed_trips']) ?? 0,
        licenceNumber: _s(j['licence_number']),
        licenceExpiry: _s(j['licence_expiry']),
        psvPermit: _s(j['psv_permit_number']),
        currentVehicle: j['current_vehicle'] is Map<String, dynamic> ? Vehicle.fromJson(j['current_vehicle']) : null,
      );
}

/// GET /driver/profile: the profile plus whether they can go online, and why not.
class DriverStatus {
  DriverStatus(this.profile, this.canGoOnline, this.issues);
  final DriverProfile profile;
  final bool canGoOnline;
  final List<String> issues;

  factory DriverStatus.fromJson(Map<String, dynamic> j) =>
      DriverStatus(DriverProfile.fromJson(_m(j['profile'])), j['can_go_online'] == true, _l(j['issues']).map((e) => '$e').toList());
}

/// One row of a document checklist.
class DocItem {
  DocItem({
    required this.typeKey,
    required this.name,
    this.description,
    required this.required,
    required this.requiresNumber,
    required this.requiresExpiry,
    required this.imageOnly,
    required this.state,
    this.number,
    this.expiresAt,
    this.reviewNote,
  });

  final String typeKey;
  final String name;
  final String? description;
  final bool required;
  final bool requiresNumber;
  final bool requiresExpiry;
  final bool imageOnly;

  /// missing | pending | approved | expiring_soon | expired | rejected
  final String state;
  final String? number;
  final String? expiresAt;
  final String? reviewNote;

  factory DocItem.fromJson(Map<String, dynamic> j) {
    final t = _m(j['type']);
    final doc = _m(j['pending_replacement']).isNotEmpty ? _m(j['pending_replacement']) : _m(j['document']);
    return DocItem(
      typeKey: _s(t['key']) ?? '',
      name: _s(t['name']) ?? '',
      description: _s(t['description']),
      required: t['is_required'] == true,
      requiresNumber: t['requires_number'] == true,
      requiresExpiry: t['requires_expiry'] == true,
      imageOnly: t['is_image_only'] == true,
      state: _s(j['state']) ?? 'missing',
      number: _s(doc['number']),
      expiresAt: _s(doc['expires_at']),
      reviewNote: _s(doc['review_note']),
    );
  }

  bool get needsAction => const ['missing', 'rejected', 'expired'].contains(state) && required;

  String get stateLabel => switch (state) {
        'missing' => required ? 'Needed' : 'Optional',
        'pending' => 'Under review',
        'approved' => 'Approved',
        'expiring_soon' => 'Expiring soon',
        'expired' => 'Expired',
        'rejected' => 'Rejected',
        _ => state,
      };
}

class TripStop {
  TripStop(this.lat, this.lng, this.address, [this.reachedAt]);
  final double lat;
  final double lng;
  final String? address;
  final DateTime? reachedAt;
  factory TripStop.fromJson(Map<String, dynamic> j) => TripStop(_d(j['lat']), _d(j['lng']), _s(j['address']), _dt(j['reached_at']));
  LatLngPoint get point => LatLngPoint(lat, lng);
  bool get reached => reachedAt != null;
}

/// A rider's mid-ride request to change the stops; the driver accepts or declines it.
class StopChange {
  StopChange({required this.status, required this.stops, this.estimatedFare});
  final String status; // pending | accepted | declined | withdrawn
  final List<TripStop> stops;
  final double? estimatedFare;
  bool get pending => status == 'pending';
  static StopChange? fromJson(dynamic v) {
    if (v is! Map<String, dynamic>) return null;
    return StopChange(
      status: _s(v['status']) ?? '',
      stops: _l(v['stops']).map((e) => TripStop.fromJson(_m(e))).toList(),
      estimatedFare: _dn(v['estimated_fare']),
    );
  }
}

/// A trip as the driver sees it.
class DriverTrip {
  DriverTrip({
    required this.id,
    required this.status,
    required this.pickup,
    required this.dropoff,
    this.estimatedFare,
    this.estimatedDistanceM,
    this.estimatedDurationS,
    this.finalFare,
    this.earnings,
    required this.currency,
    required this.paymentMethod,
    required this.paymentStatus,
    required this.passengers,
    required this.luggage,
    required this.features,
    this.riderNote,
    this.riderName,
    this.cancelledBy,
    this.cancellationReason,
    this.requestedAt,
    this.completedAt,
    this.stops = const [],
    this.stopChange,
    this.pinVerified = false,
  });

  final String id;
  final String status;
  final TripStop pickup;
  final TripStop dropoff;
  final double? estimatedFare;
  final int? estimatedDistanceM;
  final int? estimatedDurationS;
  final double? finalFare;
  final double? earnings;
  final String currency;
  final String paymentMethod; // cash | card
  final String paymentStatus;
  final int passengers;
  final int luggage;
  final List<String> features;
  final String? riderNote;
  final String? riderName;
  final String? cancelledBy;
  final String? cancellationReason;
  final DateTime? requestedAt;
  final DateTime? completedAt;
  final List<TripStop> stops;
  final StopChange? stopChange;
  final bool pinVerified;

  factory DriverTrip.fromJson(Map<String, dynamic> j) {
    final est = _m(j['estimate']);
    final act = _m(j['actual']);
    final ts = _m(j['timestamps']);
    return DriverTrip(
      id: _s(j['id']) ?? '',
      status: _s(j['status']) ?? '',
      pickup: TripStop.fromJson(_m(j['pickup'])),
      dropoff: TripStop.fromJson(_m(j['dropoff'])),
      estimatedFare: _dn(est['fare']),
      estimatedDistanceM: _in(est['distance_m']),
      estimatedDurationS: _in(est['duration_s']),
      finalFare: _dn(act['fare']),
      earnings: _dn(j['driver_earnings']),
      currency: _s(j['currency']) ?? '',
      paymentMethod: _s(j['payment_method']) ?? 'cash',
      paymentStatus: _s(j['payment_status']) ?? '',
      passengers: _in(j['passengers']) ?? 1,
      luggage: _in(j['luggage']) ?? 0,
      features: _l(j['required_features']).map((e) => '$e').toList(),
      riderNote: _s(j['rider_note']),
      riderName: _s(_m(j['rider'])['name']),
      cancelledBy: _s(j['cancelled_by']),
      cancellationReason: _s(j['cancellation_reason']),
      requestedAt: _dt(ts['requested_at']),
      completedAt: _dt(ts['completed_at']),
      stops: _l(j['stops']).map((e) => TripStop.fromJson(_m(e))).toList(),
      stopChange: StopChange.fromJson(j['stop_change']),
      pinVerified: j['pin_verified'] == true,
    );
  }

  /// Index (in [stops]) of the next stop to drive to, or null when only the drop-off is left.
  int? get nextStopIndex {
    for (var i = 0; i < stops.length; i++) {
      if (!stops[i].reached) return i;
    }
    return null;
  }

  bool get hasPendingStopChange => stopChange?.pending ?? false;

  /// Changes when anything the trip screen shows changes (used to decide whether a poll result is new).
  String get signature => [status, for (final s in stops) '${s.lat},${s.lng},${s.reached}', stopChange?.status, stopChange?.stops.length].join('|');

  bool get isActive => const ['accepted', 'arrived', 'in_progress'].contains(status);
  bool get canCancel => const ['accepted', 'arrived'].contains(status);
  bool get isCash => paymentMethod == 'cash';
  String get riderFirstName {
    final n = (riderName ?? '').trim();
    return n.isEmpty ? 'Rider' : n.split(' ').first;
  }
}

class Offer {
  Offer({required this.id, required this.distanceM, required this.expiresAt, this.estimatedEarnings, required this.trip});
  final int id;
  final int distanceM;
  final DateTime expiresAt;
  final double? estimatedEarnings;
  final DriverTrip trip;

  factory Offer.fromJson(Map<String, dynamic> j) => Offer(
        id: _in(j['id']) ?? 0,
        distanceM: _in(j['distance_m']) ?? 0,
        expiresAt: _dt(j['expires_at']) ?? DateTime.now().add(const Duration(seconds: 20)),
        estimatedEarnings: _dn(j['estimated_earnings']),
        trip: DriverTrip.fromJson(_m(j['trip'])),
      );
}

class EarningsPeriod {
  EarningsPeriod(this.trips, this.earnings, this.cash);
  final int trips;
  final double earnings;
  final double cash;
  factory EarningsPeriod.fromJson(Map<String, dynamic> j) => EarningsPeriod(_in(j['trips']) ?? 0, _d(j['earnings']), _d(j['cash_collected']));
}

class Earnings {
  Earnings(this.currency, this.today, this.week, this.month);
  final String currency;
  final EarningsPeriod today;
  final EarningsPeriod week;
  final EarningsPeriod month;
  factory Earnings.fromJson(Map<String, dynamic> j) {
    final p = _m(j['periods']);
    return Earnings(_s(j['currency']) ?? '', EarningsPeriod.fromJson(_m(p['today'])), EarningsPeriod.fromJson(_m(p['week'])), EarningsPeriod.fromJson(_m(p['month'])));
  }
}
