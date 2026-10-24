import 'package:ridecoop_core/ridecoop_core.dart' show AuthApi;

import 'api_client.dart';
import 'models.dart';
import 'shop_models.dart';

/// Every call the rider app makes, typed. Sign-in calls come from [AuthApi] (shared core).
class RiderApi extends AuthApi {
  RiderApi(super.client);

  // ---------------------------------------------------------------- push notifications
  Future<void> registerDevice(String token, {String platform = 'android'}) async {
    await client.post('/devices', {'token': token, 'platform': platform});
  }

  Future<void> forgetDevice(String token) async {
    await client.delete('/devices', {'token': token});
  }

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

  /// Named places within ~250 m of a point, nearest first. Empty when search is unavailable.
  Future<List<Place>> nearbyPlaces(double lat, double lng) async {
    try {
      final r = await client.get('/places/nearby', query: {'lat': '$lat', 'lng': '$lng'});
      return (r as List).map((e) => Place.fromJson(e as Map<String, dynamic>)).toList();
    } on ApiException {
      return const [];
    }
  }

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

  Future<Estimate> estimate(Place pickup, Place dropoff,
          {int passengers = 1, int luggage = 0, List<Place> stops = const [], DateTime? scheduledAt, String? promoCode, bool useCredit = false}) async =>
      Estimate.fromJson(await client.post('/fares/estimate', {
        'pickup_lat': pickup.lat,
        'pickup_lng': pickup.lng,
        'dropoff_lat': dropoff.lat,
        'dropoff_lng': dropoff.lng,
        if (stops.isNotEmpty) 'stops': stops.map(_stop).toList(),
        'passengers': passengers,
        'luggage': luggage,
        if (scheduledAt != null) 'scheduled_at': scheduledAt.toUtc().toIso8601String(),
        if (promoCode != null) 'promo_code': promoCode,
        if (useCredit) 'use_credit': true,
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
    DateTime? scheduledAt,
    String? promoCode,
    bool useCredit = false,
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
        if (scheduledAt != null) 'scheduled_at': scheduledAt.toUtc().toIso8601String(),
        if (promoCode != null) 'promo_code': promoCode,
        if (useCredit) 'use_credit': true,
      }) as Map<String, dynamic>);

  /// Rides booked for later, soonest first.
  Future<List<Trip>> scheduledTrips() async =>
      (await client.get('/trips/scheduled') as List).map((e) => Trip.fromJson(e as Map<String, dynamic>)).toList();

  /// 100% to the driver, charged to [cardId] or the default card.
  Future<Trip> tip(String tripId, double amount, {String? cardId}) async =>
      Trip.fromJson(await client.post('/trips/$tripId/tip', {'amount': amount, if (cardId != null) 'payment_method_id': cardId}) as Map<String, dynamic>);

  Future<Order> tipOrder(String orderId, double amount) async =>
      Order.fromJson(await client.post('/shop/orders/$orderId/tip', {'amount': amount}) as Map<String, dynamic>);

  // ---------------------------------------------------------------- saved places, promos, credit, invites
  Future<List<SavedPlace>> savedPlaces() async =>
      (await client.get('/me/places') as List).map((e) => SavedPlace.fromJson(e as Map<String, dynamic>)).toList();

  Future<SavedPlace> savePlace({required String kind, String? label, required Place place}) async => SavedPlace.fromJson(await client.post('/me/places', {
        'kind': kind,
        if (label != null) 'label': label,
        'address': place.label,
        'lat': place.lat,
        'lng': place.lng,
        if (place.placeId != null) 'place_id': place.placeId,
      }) as Map<String, dynamic>);

  Future<SavedPlace> renamePlace(int id, String label) async =>
      SavedPlace.fromJson(await client.patch('/me/places/$id', {'label': label}) as Map<String, dynamic>);

  Future<List<SavedPlace>> deletePlace(int id) async =>
      (await client.delete('/me/places/$id') as List).map((e) => SavedPlace.fromJson(e as Map<String, dynamic>)).toList();

  /// Throws ApiException (422) with the reason when the code can't be used.
  Future<PromoCheck> checkPromo(String code, {required String forWhat, double? amount}) async => PromoCheck.fromJson(
      await client.post('/promo-codes/check', {'code': code.trim(), 'for': forWhat, if (amount != null) 'amount': amount}) as Map<String, dynamic>);

  Future<CreditInfo> credit() async => CreditInfo.fromJson(await client.get('/me/credit') as Map<String, dynamic>);

  Future<ReferralInfo> referral() async => ReferralInfo.fromJson(await client.get('/me/referral') as Map<String, dynamic>);

  /// Returns the server's thank-you message.
  Future<String> enterReferralCode(String code) async =>
      '${(await client.post('/me/referral', {'code': code.trim()}) as Map<String, dynamic>)['message']}';

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

  // ---------------------------------------------------------------- shop
  static Map<String, String> _near(double? lat, double? lng) => {if (lat != null) 'lat': '$lat', if (lng != null) 'lng': '$lng'};

  Future<List<ShopCategory>> shopCategories({double? lat, double? lng}) async =>
      (await client.get('/shop/categories', query: _near(lat, lng)) as List).map((e) => ShopCategory.fromJson(e as Map<String, dynamic>)).toList();

  Future<List<StoreSummary>> shopStores({String? category, String? q, double? lat, double? lng}) async =>
      (await client.get('/shop/stores', query: {
        ..._near(lat, lng),
        if (category != null) 'category': category,
        if (q != null && q.trim().isNotEmpty) 'q': q.trim(),
      }) as List)
          .map((e) => StoreSummary.fromJson(e as Map<String, dynamic>))
          .toList();

  Future<StoreMenu> shopStore(String slug, {double? lat, double? lng}) async =>
      StoreMenu.fromJson(await client.get('/shop/stores/${Uri.encodeComponent(slug)}', query: _near(lat, lng)) as Map<String, dynamic>);

  Future<ShopQuote> shopQuote(String store, List<Map<String, dynamic>> items, double lat, double lng, {double tip = 0, String? promoCode, bool useCredit = false}) async =>
      ShopQuote.fromJson(await client.post('/shop/quote', {
        'store': store,
        'lat': lat,
        'lng': lng,
        'items': items,
        if (tip > 0) 'tip': tip,
        if (promoCode != null) 'promo_code': promoCode,
        if (useCredit) 'use_credit': true,
      }) as Map<String, dynamic>);

  /// Places the order and holds the card. A card that needs 3-D Secure comes back pending_payment with a redirect_url.
  Future<Order> placeOrder({
    required String store,
    required List<Map<String, dynamic>> items,
    required Place dropoff,
    required String cardId,
    String? note,
    double tip = 0,
    String? promoCode,
    bool useCredit = false,
  }) async =>
      Order.fromJson(await client.post('/shop/orders', {
        'store': store,
        'lat': dropoff.lat,
        'lng': dropoff.lng,
        'address': dropoff.label,
        'items': items,
        'payment_method_id': cardId,
        if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
        if (tip > 0) 'tip': tip,
        if (promoCode != null) 'promo_code': promoCode,
        if (useCredit) 'use_credit': true,
      }) as Map<String, dynamic>);

  Future<Order> order(String id) async => Order.fromJson(await client.get('/shop/orders/$id') as Map<String, dynamic>);

  Future<List<Order>> activeOrders() async =>
      (await client.get('/shop/orders/active') as List).map((e) => Order.fromJson(e as Map<String, dynamic>)).toList();

  Future<({List<Order> orders, bool hasMore})> orders({int page = 1}) async {
    final r = await client.get('/shop/orders', query: {'page': '$page'}) as Map<String, dynamic>;
    final links = r['links'];
    return (orders: (r['data'] as List).map((e) => Order.fromJson(e as Map<String, dynamic>)).toList(), hasMore: links is Map && links['next'] != null);
  }

  Future<Order> cancelOrder(String id) async => Order.fromJson(await client.post('/shop/orders/$id/cancel') as Map<String, dynamic>);

  /// Answer the store's "we're out of something" request. No cancels the order.
  Future<Order> answerOrderChange(String id, bool accept) async =>
      Order.fromJson(await client.post('/shop/orders/$id/change', {'accept': accept}) as Map<String, dynamic>);
}
