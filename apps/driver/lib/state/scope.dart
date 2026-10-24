import 'package:flutter/widgets.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/driver_api.dart';
import 'engine.dart';
import 'location.dart';
import 'push.dart';

class AppScope extends InheritedWidget {
  const AppScope({super.key, required this.session, required this.location, required this.engine, this.push, required super.child});

  final Session session;
  final LocationService location;
  final DriverEngine engine;
  final PushRegistrar? push;

  DriverApi get api => session.api as DriverApi;

  static AppScope of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope missing');
    return scope!;
  }

  @override
  bool updateShouldNotify(AppScope old) => session != old.session || engine != old.engine || location != old.location;
}
