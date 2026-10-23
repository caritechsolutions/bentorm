import 'package:ridecoop_core/ridecoop_core.dart' show AuthApi;

import 'api_client.dart';
import 'models.dart';

/// Every call the rider app makes, typed. Sign-in calls come from [AuthApi] (shared core).
class RiderApi extends AuthApi {
  RiderApi(super.client);

  Future<User> updateMe({String? name, String? email}) async =>
      User.fromJson(await client.patch('/me', {if (name != null) 'name': name, if (email != null) 'email': email}) as Map<String, dynamic>);

  // ---------------------------------------------------------------- places
  Future<List<Suggestion>> autocomplete(String q, {double? lat, double? lng, String? session}) async {
    final r = await client.get('/places/autocomplete', query: {
      'q': q,
      if (lat != null) 'lat': '$lat',
      if (lng != null) 'lng': '$lng',
      if (session != null) 'session': session,
    });
    return (r as List).map((e) => Suggestion.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<Place> place(String placeId, {String? session}) async =>
      Place.fromJson(await client.get('/places/${Uri.encodeComponent(placeId)}', query: {if (session != null) 'session': session}) as Map<String, dynamic>);

  /// Address for a point; null when nothing is found or search is unavailable.
  Future<Place?> reverse(double lat, double lng) async {
    try {
      return Place.fromJson(await client.get('/places/reverse', query: {'lat': '$lat', 'lng': '$lng'}) as Map<String, dynamic>);
    } on ApiException {
      return null;
    }
  }

  // ---------------------------------------------------------------- fares + trips
  static Map<String, dynamic> _stop(Place p) => {'lat': p.lat, 'lng': p.lng, 'address': p.label};

  Future<Estimate> estimate(Place pickup, Place dropoff, {int passengers = 1, int luggage = 0, List<Place> stops = const []}) async =>
      Estimate.fromJson(await client.post('/fares/estimate', {
        'pickup_lat': pickup.lat,
        'pickup_lng': pickup.lng,
        'dropoff_lat': dropoff.lat,
        'dropoff_lng': dropoff.lng,
        if (stops.isNotEmpty) 'stops': stops.map(_stop).toList(),
        'passengers': passengers,
        'luggage': luggage,
      }) as Map<String, dynamic>);

  /// Requests a ride. A declined card hold throws ApiException(402).
  Future<Trip> requestTrip({
    required Place pickup,
    required Place dropoff,
    required String vehicleType,
    required String paymentMethod,
    String? cardId,
    int passengers = 1,
    int luggage = 0,
    List<String> features = const [],
    List<Place> stops = const [],
    String? note,
  }) async =>
      Trip.fromJson(await client.post('/trips', {
        'pickup_lat': pickup.lat,
        'pickup_lng': pickup.lng,
        'pickup_address': pickup.label,
        'dropoff_lat': dropoff.lat,
        'dropoff_lng': dropoff.lng,
        'dropoff_address': dropoff.label,
        'vehicle_type': vehicleType,
        'payment_method': paymentMethod,
        if (cardId != null) 'payment_method_id': cardId,
        'passengers': passengers,
        'luggage': luggage,
        if (features.isNotEmpty) 'features': features,
        if (stops.isNotEmpty) 'stops': stops.map(_stop).toList(),
        if (note != null && note.trim().isNotEmpty) 'rider_note': note.trim(),
      }) as Map<String, dynamic>);

  Future<TripSnapshot> activeTrip() async => TripSnapshot.fromJson(await client.get('/trips/active') as Map<String, dynamic>);

  Future<TripSnapshot> trip(String id) async => TripSnapshot.fromJson(await client.get('/trips/$id') as Map<String, dynamic>);

  /// Replace the stops not yet reached. After a driver accepted, the change waits for them (stopChange.pending).
  Future<Trip> changeStops(String id, List<Place> stops) async =>
      Trip.fromJson(await client.post('/trips/$id/stops', {'stops': stops.map(_stop).toList()}) as Map<String, dynamic>);

  Future<Trip> withdrawStopChange(String id) async => Trip.fromJson(await client.delete('/trips/$id/stops/pending') as Map<String, dynamic>);

  Future<Trip> cancelTrip(String id, {String? reason}) async =>
      Trip.fromJson(await client.post('/trips/$id/cancel', {if (reason != null) 'reason': reason}) as Map<String, dynamic>);

  Future<void> rateTrip(String id, int score, {String? comment}) async {
    await client.post('/trips/$id/rate', {'score': score, if (comment != null && comment.trim().isNotEmpty) 'comment': comment.trim()});
  }

  Future<({List<Trip> trips, bool hasMore})> history({int page = 1}) async {
    final r = await client.get('/trips', query: {'page': '$page'}) as Map<String, dynamic>;
    final trips = (r['data'] as List).map((e) => Trip.fromJson(e as Map<String, dynamic>)).toList();
    final links = r['links'];
    return (trips: trips, hasMore: links is Map && links['next'] != null);
  }

  Future<List<Trip>> outstanding() async {
    final r = await client.get('/me/outstanding');
    final list = r is Map ? (r['data'] ?? r['trips'] ?? const []) : r;
    return (list as List).map((e) => Trip.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// Settle an amount owed. With a saved card it's charged directly; otherwise a hosted page URL comes back.
  Future<String?> payOutstanding(String tripId, {String? cardId}) async {
    final r = await client.post('/trips/$tripId/pay', {if (cardId != null) 'payment_method_id': cardId});
    return r is Map ? r['redirect_url']?.toString() : null;
  }

  // ---------------------------------------------------------------- cards
  Future<List<PaymentCard>> cards() async =>
      (await client.get('/me/payment-methods') as List).map((e) => PaymentCard.fromJson(e as Map<String, dynamic>)).toList();

  Future<PaymentCard> card(String id) async => PaymentCard.fromJson(await client.get('/me/payment-methods/$id') as Map<String, dynamic>);

  /// Starts adding a card: returns the pending card with the hosted page URL to open.
  Future<PaymentCard> startAddCard(double lat, double lng) async {
    final r = await client.post('/me/payment-methods', {'lat': lat, 'lng': lng}) as Map<String, dynamic>;
    return PaymentCard.fromJson((r['payment_method'] ?? r) as Map<String, dynamic>);
  }

  Future<void> makeDefaultCard(String id) async {
    await client.post('/me/payment-methods/$id/default');
  }

  Future<void> deleteCard(String id) async {
    await client.delete('/me/payment-methods/$id');
  }
}
