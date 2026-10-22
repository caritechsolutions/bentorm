import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_rider/api/models.dart';

import 'support/fake_server.dart';

void main() {
  test('estimate: quotes with fare, hold, breakdown and route', () {
    final e = Estimate.fromJson(fixture('estimate'));
    expect(e.quotes, isNotEmpty);
    final q = e.quotes.first;
    expect(q.vehicleType, 'standard');
    expect(q.displayName, 'Standard');
    expect(q.currency, 'BBD');
    expect(q.fare, 57.5);
    expect(q.holdAmount, greaterThan(q.fare));
    expect(q.lines.map((l) => l.code), containsAll(['base', 'distance', 'tax']));
    expect(e.route!.distanceM, greaterThan(1000));
  });

  test('trip lifecycle snapshots parse', () {
    expect(Trip.fromJson(fixture('trip_created')).status, 'requested');
    expect(TripSnapshot.fromJson(fixture('active_none')).trip, isNull);

    final accepted = TripSnapshot.fromJson(fixture('active_accepted'));
    expect(accepted.trip!.status, 'accepted');
    expect(accepted.trip!.hasDriver, isTrue);
    expect(accepted.trip!.driver!.name, fixture('active_accepted')['trip']['driver']['name']);
    expect(accepted.trip!.driver!.rating, isNotNull);
    expect(accepted.trip!.vehicle!.plate, fixture('active_accepted')['trip']['vehicle']['plate']);
    expect(accepted.driverPosition!.lat, closeTo(13.0975, 0.0001));

    final done = TripSnapshot.fromJson(fixture('trip_completed')).trip!;
    expect(done.status, 'completed');
    expect(done.isActive, isFalse);
    expect(done.finalFare, greaterThan(0));
    expect(done.paymentStatus, 'paid');
    expect(done.fareLines, isNotEmpty);

    final cancelled = Trip.fromJson(fixture('trip_cancelled'));
    expect(cancelled.status, 'cancelled');
    expect(cancelled.cancelledBy, 'rider');
  });

  test('user, card setup and history parse', () {
    final me = User.fromJson(fixture('me'));
    expect(me.name, 'Rita Rider');
    expect(me.phone, startsWith('+1246'));

    final card = PaymentCard.fromJson(fixture('card_setup')['payment_method']);
    expect(card.status, 'pending');
    expect(card.redirectUrl, isNotNull);

    final page = fixture('trips_history');
    expect((page['data'] as List).map((e) => Trip.fromJson(e)).first.status, 'completed');
  });
}
