import 'package:flutter/widgets.dart';
import 'package:ridecoop_core/ridecoop_core.dart' show LiveLink, RealtimeClient, RemoteConfig;

import '../api/rider_api.dart';
import 'cart.dart';
import 'location.dart';
import 'push.dart';
import 'session.dart';

/// Gives every screen the session, API and location service.
class AppScope extends InheritedWidget {
  const AppScope({super.key, required this.session, required this.location, required this.cart, this.push, this.live, required super.child});

  final Session session;
  final LocationService location;
  final Cart cart;

  /// Null in tests and builds without Firebase.
  final PushRegistrar? push;

  /// Server settings and live updates. Null in tests: every feature counts as on and screens poll.
  final LiveLink? live;
  static final _defaults = RemoteConfig();
  RemoteConfig get config => live?.config ?? _defaults;
  RealtimeClient? get realtime => live?.realtime;

  RiderApi get api => session.api as RiderApi;

  static AppScope of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope missing');
    return scope!;
  }

  @override
  bool updateShouldNotify(AppScope oldWidget) => session != oldWidget.session || location != oldWidget.location || cart != oldWidget.cart || live != oldWidget.live;
}
