import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../state/scope.dart';

/// Welcome → create an account / sign in (shared with the driver app).
class LoginScreen extends StatelessWidget {
  const LoginScreen({super.key});

  @override
  Widget build(BuildContext context) => PhoneLoginScreen(session: AppScope.of(context).session, tagline: 'Ride local. Ride fair.');
}
