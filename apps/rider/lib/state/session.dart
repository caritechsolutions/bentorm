import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../api/rider_api.dart';

/// Where the token lives between launches.
abstract class TokenStore {
  Future<String?> read();
  Future<void> write(String token);
  Future<void> clear();
}

class SecureTokenStore implements TokenStore {
  static const _key = 'ridecoop_token';
  final _storage = const FlutterSecureStorage();

  @override
  Future<String?> read() async {
    try {
      return await _storage.read(key: _key);
    } catch (_) {
      return null;
    }
  }

  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

class MemoryTokenStore implements TokenStore {
  MemoryTokenStore([this.token]);
  String? token;
  @override
  Future<String?> read() async => token;
  @override
  Future<void> write(String t) async => token = t;
  @override
  Future<void> clear() async => token = null;
}

enum SessionState { loading, signedOut, signedIn, offline }

class Session extends ChangeNotifier {
  Session(this.api, this.store) {
    api.client.onUnauthorized = () => _signedOut();
  }

  final RiderApi api;
  final TokenStore store;
  SessionState state = SessionState.loading;
  User? user;

  Future<void> restore() async {
    state = SessionState.loading;
    notifyListeners();
    final token = await store.read();
    if (token == null) {
      state = SessionState.signedOut;
      notifyListeners();
      return;
    }
    api.client.token = token;
    try {
      user = await api.me();
      state = SessionState.signedIn;
    } on ApiException catch (e) {
      if (e.status == 401 || e.status == 403) {
        await store.clear();
        api.client.token = null;
        state = SessionState.signedOut;
      } else {
        state = SessionState.offline;
      }
    }
    notifyListeners();
  }

  Future<void> signedIn(String token, User u) async {
    api.client.token = token;
    await store.write(token);
    user = u;
    state = SessionState.signedIn;
    notifyListeners();
  }

  void updateUser(User u) {
    user = u;
    notifyListeners();
  }

  Future<void> signOut() async {
    try {
      await api.logout();
    } catch (_) {
      // Token may already be gone; signing out locally is what matters.
    }
    await _signedOut();
  }

  Future<void> _signedOut() async {
    await store.clear();
    api.client.token = null;
    user = null;
    state = SessionState.signedOut;
    notifyListeners();
  }
}
