import 'package:ridecoop_core/ridecoop_core.dart';

import 'models.dart';

/// Every call the driver app makes. Sign-in calls come from [AuthApi] (shared core).
class DriverApi extends AuthApi {
  DriverApi(super.client);

  Map<String, dynamic> _map(dynamic r) => r as Map<String, dynamic>;

  // ---------------------------------------------------------------- push notifications
  Future<void> registerDevice(String token, {String platform = 'android'}) async {
    await client.post('/devices', {'token': token, 'platform': platform});
  }

  Future<void> forgetDevice(String token) async {
    await client.delete('/devices', {'token': token});
  }

  // ---------------------------------------------------------------- reference data
  Future<List<ServiceAreaOption>> serviceAreas() async =>
      (await client.get('/service-areas') as List).map((e) => ServiceAreaOption.fromJson(_map(e))).toList();

  Future<List<VehicleTypeOption>> vehicleTypes(int? serviceAreaId) async => (await client.get('/vehicle-types',
          query: {if (serviceAreaId != null) 'service_area_id': '$serviceAreaId'}) as List)
      .map((e) => VehicleTypeOption.fromJson(_map(e)))
      .toList();

  Future<List<FeatureOption>> features() async =>
      (await client.get('/vehicle-features') as List).map((e) => FeatureOption.fromJson(_map(e))).toList();

  // ---------------------------------------------------------------- profile
  Future<DriverStatus> status() async => DriverStatus.fromJson(_map(await client.get('/driver/profile')));

  Future<DriverProfile> updateProfile({int? serviceAreaId, String? licenceNumber, String? licenceExpiry, String? psvPermit, bool? acceptsDeliveries}) async =>
      DriverProfile.fromJson(_map(await client.patch('/driver/profile', {
        if (acceptsDeliveries != null) 'accepts_deliveries': acceptsDeliveries,
        if (serviceAreaId != null) 'service_area_id': serviceAreaId,
        if (licenceNumber != null) 'licence_number': licenceNumber,
        if (licenceExpiry != null) 'licence_expiry': licenceExpiry,
        if (psvPermit != null) 'psv_permit_number': psvPermit,
      })));

  // ---------------------------------------------------------------- vehicles
  Future<List<Vehicle>> vehicles() async => (await client.get('/driver/vehicles') as List).map((e) => Vehicle.fromJson(_map(e))).toList();

  Future<Vehicle> saveVehicle(Map<String, dynamic> fields, {int? id}) async => Vehicle.fromJson(
      _map(id == null ? await client.post('/driver/vehicles', fields) : await client.patch('/driver/vehicles/$id', fields)));

  Future<void> selectVehicle(int id) async {
    await client.post('/driver/vehicles/$id/select');
  }

  // ---------------------------------------------------------------- documents
  Future<List<DocItem>> documents({int? vehicleId}) async {
    final r = _map(await client.get(vehicleId == null ? '/driver/documents' : '/driver/vehicles/$vehicleId/documents'));
    return (r['checklist'] as List).map((e) => DocItem.fromJson(_map(e))).toList();
  }

  Future<void> uploadDocument({
    int? vehicleId,
    required String typeKey,
    required List<int> bytes,
    required String filename,
    String? contentType,
    String? number,
    String? expiresAt,
  }) async {
    await client.upload(vehicleId == null ? '/driver/documents' : '/driver/vehicles/$vehicleId/documents',
        field: 'file',
        bytes: bytes,
        filename: filename,
        contentType: contentType,
        fields: {
          'document_type': typeKey,
          if (number != null && number.trim().isNotEmpty) 'number': number.trim(),
          if (expiresAt != null && expiresAt.isNotEmpty) 'expires_at': expiresAt,
        });
  }

  // ---------------------------------------------------------------- online + location
  /// Throws ApiException(403) with body['issues'] when the driver can't go online yet.
  Future<void> goOnline() async {
    await client.post('/driver/online');
  }

  Future<void> goOffline() async {
    await client.post('/driver/offline');
  }

  /// Location ping (also the presence heartbeat). Returns the active trip, if any, for resync.
  Future<DriverTrip?> ping(double lat, double lng, {double? heading, double? speed}) async {
    final r = _map(await client.post('/driver/location', {
      'lat': lat,
      'lng': lng,
      if (heading != null && heading >= 0) 'heading': heading,
      if (speed != null && speed >= 0) 'speed': speed,
    }));
    return r['active_trip'] is Map<String, dynamic> ? DriverTrip.fromJson(r['active_trip']) : null;
  }

  Future<List<Offer>> offers() async =>
      (_map(await client.get('/driver/offers'))['offers'] as List).map((e) => Offer.fromJson(_map(e))).toList();

  // ---------------------------------------------------------------- trips
  Future<DriverTrip> accept(String tripId) async => DriverTrip.fromJson(_map(await client.post('/driver/trips/$tripId/accept')));

  Future<void> decline(String tripId) async {
    await client.post('/driver/trips/$tripId/decline');
  }

  Future<DriverTrip> arrive(String tripId) async => DriverTrip.fromJson(_map(await client.post('/driver/trips/$tripId/arrive')));

  /// [pin] is the 4-digit code the rider reads out. Wrong/missing → ApiException 422 (errors.pin); too many tries → 429.
  Future<DriverTrip> start(String tripId, String pin) async =>
      DriverTrip.fromJson(_map(await client.post('/driver/trips/$tripId/start', {'pin': pin})));

  /// Can this destination use in-app navigation? [check] only asks; without it an allowed start is counted
  /// (billed per destination). False → open the Google Maps app instead.
  Future<bool> navigation(String tripId, String destination, {bool check = false}) async =>
      _map(await client.post('/driver/trips/$tripId/navigation', {'destination': destination, if (check) 'check': true}))['in_app'] == true;

  Future<DriverTrip> acceptStops(String tripId) async => DriverTrip.fromJson(_map(await client.post('/driver/trips/$tripId/stops/accept')));

  Future<DriverTrip> declineStops(String tripId) async => DriverTrip.fromJson(_map(await client.post('/driver/trips/$tripId/stops/decline')));

  Future<DriverTrip> reachStop(String tripId, int index) async =>
      DriverTrip.fromJson(_map(await client.post('/driver/trips/$tripId/stops/$index/reached')));

  /// Deliveries need the customer's [pin] (wrong/missing → 422 errors.pin; too many tries → 429).
  Future<DriverTrip> complete(String tripId, {double? lat, double? lng, String? pin}) async => DriverTrip.fromJson(_map(await client
      .post('/driver/trips/$tripId/complete', {if (lat != null) 'lat': lat, if (lng != null) 'lng': lng, if (pin != null) 'pin': pin})));

  Future<DriverTrip> cancel(String tripId, String reason) async =>
      DriverTrip.fromJson(_map(await client.post('/driver/trips/$tripId/cancel', {'reason': reason})));

  Future<DriverTrip?> activeTrip() async {
    final r = _map(await client.get('/driver/trips/active'));
    return r['trip'] is Map<String, dynamic> ? DriverTrip.fromJson(r['trip']) : null;
  }

  Future<DriverTrip> trip(String tripId) async => DriverTrip.fromJson(_map(await client.get('/driver/trips/$tripId')));

  Future<({List<DriverTrip> trips, bool hasMore})> history({int page = 1}) async {
    final r = _map(await client.get('/driver/trips', query: {'page': '$page'}));
    return (
      trips: (r['data'] as List).map((e) => DriverTrip.fromJson(_map(e))).toList(),
      hasMore: r['links'] is Map && (r['links'] as Map)['next'] != null,
    );
  }

  Future<Earnings> earnings() async => Earnings.fromJson(_map(await client.get('/driver/earnings')));
}
