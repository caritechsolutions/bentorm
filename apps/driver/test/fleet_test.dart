import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_driver/api/models.dart';

import 'support/fake_server.dart';

/// Fleets in the driver app, on responses captured from the local backend (scratchpad fleet-fixtures.sh):
/// Island Taxis invites the driver to FL1 and FL2 (FL2 is in use by Mark).
void main() {
  test('fleet fields parse from the server', () {
    final cars = (fixture('vehicles_fleet') as List).map((e) => Vehicle.fromJson(e as Map<String, dynamic>)).toList();
    final fl2 = cars.firstWhere((c) => c.plate == 'FL2');
    expect([fl2.isFleetCar, fl2.fleetName, fl2.inUseBy], [true, 'Island Taxis', 'Mark']);
    expect(cars.firstWhere((c) => c.plate == 'ZR101').isFleetCar, isFalse);
    final invite = DriverStatus.fromJson(fixture('profile_fleet_invite')).invites.single;
    expect([invite.fleetName, invite.cars], ['Island Taxis', ['FL1 · Toyota Axio', 'FL2 · Nissan Note']]);
    expect(DriverTrip.fromJson(fixture('trip_fleet_completed')).earningsPaidTo, 'Island Taxis');
    expect(Earnings.fromJson(fixture('earnings_fleet')).fleets.single.today.earnings, 17.6);
    expect(DriverStatus.fromJson(fixture('profile_offline_reason')).profile.offlineReason, 'Island Taxis removed you from their fleet.');
  });

  testWidgets('accept a fleet invitation, see the fleet cars, drive FL1 (FL2 is in use)', (tester) async {
    var profile = 'profile_fleet_invite';
    final inviteId = fixture('profile_fleet_invite')['fleet_invites'][0]['id'];
    final server = driverServer(profile: 'profile_fleet_invite')
      ..handle('GET /driver/profile', (_) => fixture(profile))
      ..handle('POST /driver/fleet-invites/$inviteId/accept', (_) {
        profile = 'profile_fleet_joined';
        return fixture('fleet_invite_accepted');
      })
      ..on('GET /driver/vehicles', fixture('vehicles_fleet'));
    final cars = (fixture('vehicles_fleet') as List).cast<Map<String, dynamic>>();
    final fl1 = cars.firstWhere((c) => c['plate'] == 'FL1')['id'];
    server.handle('POST /driver/vehicles/$fl1/select', (_) {
      profile = 'profile_fleet_car';
      return fixture('select_fleet_car');
    });
    final app = await pumpDriverApp(tester, server);

    expect(find.text('Island Taxis invited you to drive their cars'), findsOneWidget);
    expect(find.textContaining('FL1 · Toyota Axio'), findsOneWidget);
    await tapKey(tester, 'invite-accept');
    expect(server.calls('POST /driver/fleet-invites/$inviteId/accept'), hasLength(1));
    expect(find.text('Island Taxis invited you to drive their cars'), findsNothing);
    expect(find.textContaining('Choose one under My vehicles'), findsOneWidget);

    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tapKey(tester, 'drawer-vehicles');
    expect(find.text('My vehicles'), findsOneWidget);
    expect(find.byKey(const Key('fleet-FL1')), findsOneWidget);
    expect(find.byKey(const Key('in-use-FL2')), findsOneWidget);
    expect(find.text('In use by Mark'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('use-FL2'))).onPressed, isNull, reason: 'one driver per car');

    await tapKey(tester, 'use-FL1');
    expect(server.calls('POST /driver/vehicles/$fl1/select'), hasLength(1));
    expect(find.text('You\'re driving FL1 (Island Taxis).'), findsOneWidget);
    await tester.pageBack();
    await settle(tester);
    expect(find.byKey(const Key('current-car-line')), findsOneWidget);
    expect(find.text('Island Taxis\'s car · earnings paid to Island Taxis'), findsOneWidget);
    await unmount(tester, app);
  });

  testWidgets('the fleet takes the car away: the app goes offline with the reason and does not sign back on', (tester) async {
    var removed = false;
    final server = driverServer(profile: 'profile_fleet_car')
      ..handle('GET /driver/profile', (_) => fixture(removed ? 'profile_offline_reason' : 'profile_fleet_car'))
      ..on('POST /driver/online', fixture('online_fleet'))
      ..handle('POST /driver/location', (_) => fixture(removed ? 'location_fleet_removed' : 'location_fleet_online'));
    final app = await pumpDriverApp(tester, server);
    await tapKey(tester, 'go-online');
    expect(find.text('ONLINE'), findsOneWidget);
    final onlineCalls = server.calls('POST /driver/online').length;

    removed = true;
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('OFFLINE'), findsOneWidget);
    expect(server.calls('POST /driver/online').length, onlineCalls, reason: 'no automatic sign-on');
    expect(find.byKey(const Key('offline-reason')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('offline-reason')), matching: find.text('Island Taxis removed you from their fleet.')), findsOneWidget);
    await unmount(tester, app);
  });

  testWidgets('earnings in fleet cars are shown as paid to the fleet', (tester) async {
    final server = driverServer(profile: 'profile_fleet_car')..on('GET /driver/earnings', fixture('earnings_fleet'));
    final app = await pumpDriverApp(tester, server);
    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tester.tap(find.text('Earnings'));
    await settle(tester);
    expect(find.byKey(const Key('fleet-earnings-Island Taxis')), findsOneWidget);
    expect(find.text('Earnings paid to Island Taxis'), findsOneWidget);
    expect(find.textContaining('hand to Island Taxis'), findsWidgets);
    await unmount(tester, app);
  });
}
