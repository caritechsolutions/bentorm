import 'api_client.dart';
import 'models.dart';

/// Every call the rider app makes, typed.
class RiderApi {
  RiderApi(this.client);
  final ApiClient client;

  // ---------------------------------------------------------------- auth
  /// Is this phone registered? New phones give name + email before a code is sent.
  Future<({String phone, bool registered, String? emailHint})> lookup(String phone) async {
    final r = await client.post('/auth/otp/lookup', {'phone': phone}) as Map<String, dynamic>;
    return (phone: r['phone']?.toString() ?? phone, registered: r['registered'] == true, emailHint: r['email_hint']?.toString());
  }

  /// Sends a code by SMS, and by email (the account's, or [email] for a new sign-up).
  Future<({String phone, List<String> channels, String? emailHint})> requestCode(String phone, {String? email}) async {
    final r = await client.post('/auth/otp/request', {
      'phone': phone,
      if (email != null && email.trim().isNotEmpty) 'email': email.trim(),
    }) as Map<String, dynamic>;
    return (
      phone: r['phone']?.toString() ?? phone,
      channels: (r['channels'] as List?)?.map((e) => e.toString()).toList() ?? const ['sms'],
      emailHint: r['email_hint']?.toString(),
    );
  }

  /// Logs in, or signs up when [name] and [email] are given. A 422 with body['needs_name'] /
  /// body['needs_email'] means more details are needed — the same code stays valid for the retry.
  Future<({String token, User user})> verifyCode(String phone, String code, {String? name, String? email}) async {
    final r = await client.post('/auth/otp/verify', {
      'phone': phone,
      'code': code,
      if (name != null && name.trim().isNotEmpty) 'name': name.trim(),
      if (email != null && email.trim().isNotEmpty) 'email': email.trim(),
      'role': 'rider',
      'device_name': 'rider-app',
    }) as Map<String, dynamic>;
    return (token: r['token'] as String, user: User.fromJson(r['user'] as Map<String, dynamic>));
  }

  Future<User> me() async => User.fromJson(await client.get('/me') as Map<String, dynamic>);

  Future<User> updateMe({String? name, String? email}) async =>
      User.fromJson(await client.patch('/me', {if (name != null) 'name': name, if (email != null) 'email': email}) as Map<String, dynamic>);

  Future<void> logout() async {
    await client.post('/auth/logout');
  }

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
  Future<Estimate> estimate(Place pickup, Place dropoff, {int passengers = 1, int luggage = 0}) async =>
      Estimate.fromJson(await client.post('/fares/estimate', {
        'pickup_lat': pickup.lat,
        'pickup_lng': pickup.lng,
        'dropoff_lat': dropoff.lat,
        'dropoff_lng': dropoff.lng,
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
        if (note != null && note.trim().isNotEmpty) 'rider_note': note.trim(),
      }) as Map<String, dynamic>);

  Future<TripSnapshot> activeTrip() async => TripSnapshot.fromJson(await client.get('/trips/active') as Map<String, dynamic>);

  Future<TripSnapshot> trip(String id) async => TripSnapshot.fromJson(await client.get('/trips/$id') as Map<String, dynamic>);

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
