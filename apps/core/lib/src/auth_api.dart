import 'api_client.dart';
import 'user.dart';

/// Sign-in calls shared by both apps. Each app's API class extends this.
class AuthApi {
  AuthApi(this.client);
  final ApiClient client;

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

  /// Logs in, or signs up as [role] when [name] and [email] are given. A 422 with body['needs_name'] /
  /// body['needs_email'] means more details are needed — the same code stays valid for the retry.
  Future<({String token, User user})> verifyCode(String phone, String code, {required String role, String? name, String? email}) async {
    final r = await client.post('/auth/otp/verify', {
      'phone': phone,
      'code': code,
      if (name != null && name.trim().isNotEmpty) 'name': name.trim(),
      if (email != null && email.trim().isNotEmpty) 'email': email.trim(),
      'role': role,
      'device_name': '$role-app',
    }) as Map<String, dynamic>;
    return (token: r['token'] as String, user: User.fromJson(r['user'] as Map<String, dynamic>));
  }

  Future<User> me() async => User.fromJson(await client.get('/me') as Map<String, dynamic>);

  Future<void> logout() async {
    await client.post('/auth/logout');
  }
}
