import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_driver/api/models.dart';

import 'support/fake_server.dart';

void main() {
  test('profile states parse with blocking issues', () {
    final fresh = DriverStatus.fromJson(fixture('profile_new'));
    expect(fresh.canGoOnline, isFalse);
    expect(fresh.issues, isNotEmpty);
    expect(fresh.profile.currentVehicle, isNull);

    final ready = DriverStatus.fromJson(fixture('profile_approved'));
    expect(ready.canGoOnline, isTrue);
    expect(ready.profile.status, 'approved');
    expect(ready.profile.currentVehicle!.plate, isNotEmpty);
    expect(ready.profile.currentVehicle!.features, contains('child_seat'));
  });

  test('document checklists parse', () {
    final docs = (fixture('documents_partial')['checklist'] as List).map((e) => DocItem.fromJson(e)).toList();
    expect(docs.map((d) => d.typeKey), containsAll(['driver_photo', 'drivers_licence', 'police_certificate', 'psv_permit']));
    final licence = docs.firstWhere((d) => d.typeKey == 'drivers_licence');
    expect([licence.required, licence.requiresNumber, licence.requiresExpiry, licence.state], [true, true, true, 'pending']);
    expect(licence.number, 'L123456');
    expect(docs.firstWhere((d) => d.typeKey == 'police_certificate').needsAction, isTrue);
    final approved = (fixture('documents_approved')['checklist'] as List).map((e) => DocItem.fromJson(e));
    expect(approved.every((d) => d.state == 'approved' && !d.needsAction), isTrue);
  });

  test('offer shows estimated earnings and trip details', () {
    final o = Offer.fromJson(fixture('offers')['offers'][0]);
    expect(o.estimatedEarnings, greaterThan(0));
    expect(o.estimatedEarnings, lessThan(o.trip.estimatedFare!));
    expect(o.trip.riderName, 'Rita Rider');
    expect(o.trip.riderNote, 'By the blue gate');
    expect([o.trip.passengers, o.trip.luggage], [2, 1]);
    expect(o.trip.isCash, isTrue);
  });

  test('trip steps, earnings and history parse', () {
    expect(DriverTrip.fromJson(fixture('trip_accepted')).status, 'accepted');
    final withRider = DriverTrip.fromJson({...fixture('trip_accepted') as Map<String, dynamic>, 'rider_position': {'lat': 13.07, 'lng': -59.53, 'at': 1}});
    expect([withRider.riderPosition?.lat, withRider.riderPosition?.lng], [13.07, -59.53]);
    expect(DriverTrip.fromJson(fixture('trip_accepted')).riderPosition, isNull);
    expect(DriverTrip.fromJson(fixture('trip_arrived')).status, 'arrived');
    expect(DriverTrip.fromJson(fixture('trip_started')).status, 'in_progress');
    final done = DriverTrip.fromJson(fixture('trip_completed'));
    expect(done.status, 'completed');
    expect(done.finalFare, greaterThan(0));
    expect(done.earnings, greaterThan(0));
    expect(DriverTrip.fromJson(fixture('trip_cancelled_by_driver')).cancelledBy, 'driver');
    expect(DriverTrip.fromJson(fixture('driver_trip_rider_cancelled')).cancelledBy, 'rider');

    final e = Earnings.fromJson(fixture('earnings'));
    expect(e.today.trips, 1);
    expect(e.today.earnings, done.earnings);
    expect(e.today.cash, done.finalFare);
  });
}
