import 'package:flutter/widgets.dart';

import '../api/rider_api.dart';
import 'cart.dart';
import 'location.dart';
import 'session.dart';

/// Gives every screen the session, API and location service.
class AppScope extends InheritedWidget {
  const AppScope({super.key, required this.session, required this.location, required this.cart, required super.child});

  final Session session;
  final LocationService location;
  final Cart cart;

  RiderApi get api => session.api as RiderApi;

  static AppScope of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope missing');
    return scope!;
  }

  @override
  bool updateShouldNotify(AppScope oldWidget) => session != oldWidget.session || location != oldWidget.location || cart != oldWidget.cart;
}
