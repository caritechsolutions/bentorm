import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/rider_api.dart';
import '../config.dart';

/// What the app needs from a push provider (Firebase in the app, a fake in tests).
abstract class PushBridge {
  Future<bool> start();
  Future<String?> token();
  Stream<String> get tokenRefresh;

  /// Messages that arrive while the app is open (the system shows them itself when it isn't).
  Stream<Map<String, String>> get foreground;

  /// The customer tapped a notification.
  Stream<Map<String, String>> get opened;
  Future<void> showLocal(String title, String body, {required bool urgent});
  Future<void> deleteToken();
}

/// Registers this phone for push while signed in: driver on the way, order accepted / on the way / delivered,
/// "the store is out of something". Taps and messages that arrive while open are passed to [onMessage].
class PushRegistrar {
  PushRegistrar(this.api, this.session, {PushBridge? bridge, this.onMessage})
      : bridge = bridge ?? (AppConfig.pushConfigured ? FirebasePushBridge() : null);

  final RiderApi api;
  final Session session;
  final PushBridge? bridge;
  final void Function(Map<String, String> data, {required bool tapped})? onMessage;

  final _subs = <StreamSubscription<Object?>>[];
  String? _registered;
  bool _started = false;

  Future<void> attach() async {
    final b = bridge;
    if (b == null) return;
    session.addListener(_sync);
    try {
      _started = await b.start();
    } catch (e) {
      debugPrint('Push unavailable: $e');
      return;
    }
    if (!_started) return;
    _subs
      ..add(b.tokenRefresh.listen((t) => _register(t)))
      ..add(b.foreground.listen((data) {
        // The app is open: Android won't show it, so we do.
        b.showLocal(data['title'] ?? 'Ride Coop', data['body'] ?? '', urgent: data['channel'] == 'ride_offers' || data['urgent'] == 'true');
        onMessage?.call(data, tapped: false);
      }))
      ..add(b.opened.listen((data) => onMessage?.call(data, tapped: true)));
    await _sync();
  }

  Future<void> _sync() async {
    final b = bridge;
    if (b == null || !_started) return;
    if (session.state == SessionState.signedIn) {
      final t = await b.token();
      if (t != null && t != _registered) await _register(t);
    } else if (session.state == SessionState.signedOut && _registered != null) {
      _registered = null;
      await b.deleteToken().catchError((Object _) {});
    }
  }

  Future<void> _register(String token) async {
    if (session.state != SessionState.signedIn) return;
    try {
      await api.registerDevice(token);
      _registered = token;
    } on ApiException {
      // Next start or token refresh tries again.
    }
  }

  /// Before signing out: stop pushes to this phone for this account.
  Future<void> forget() async {
    final t = _registered;
    if (t == null) return;
    try {
      await api.forgetDevice(t);
    } on ApiException {
      // The server forgets it anyway when someone else registers the phone.
    }
    _registered = null;
  }

  void dispose() {
    for (final s in _subs) {
      s.cancel();
    }
    session.removeListener(_sync);
  }
}

class FirebasePushBridge implements PushBridge {
  final _local = FlutterLocalNotificationsPlugin();
  var _id = 0;

  // Same channel ids the server uses for every app; for customers both are ordinary (loud = high importance).
  static const urgent = AndroidNotificationChannel('ride_offers', 'Urgent', description: 'Your driver has arrived, the store needs an answer', importance: Importance.max);
  static const updates = AndroidNotificationChannel('ride_updates', 'Rides & orders', description: 'Driver on the way, order accepted, delivered', importance: Importance.high);

  @override
  Future<bool> start() async {
    await Firebase.initializeApp();
    await _local.initialize(settings: const InitializationSettings(android: AndroidInitializationSettings('@mipmap/ic_launcher')));
    final android = _local.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
    await android?.createNotificationChannel(urgent);
    await android?.createNotificationChannel(updates);
    await android?.requestNotificationsPermission();
    final settings = await FirebaseMessaging.instance.requestPermission(alert: true, sound: true, badge: true);
    return settings.authorizationStatus != AuthorizationStatus.denied;
  }

  @override
  Future<String?> token() => FirebaseMessaging.instance.getToken();

  @override
  Stream<String> get tokenRefresh => FirebaseMessaging.instance.onTokenRefresh;

  static Map<String, String> _flat(RemoteMessage m) => {
        ...m.data.map((k, v) => MapEntry(k, '$v')),
        if (m.notification?.title != null) 'title': m.notification!.title!,
        if (m.notification?.body != null) 'body': m.notification!.body!,
        if (m.notification?.android?.channelId != null) 'channel': m.notification!.android!.channelId!,
      };

  @override
  Stream<Map<String, String>> get foreground => FirebaseMessaging.onMessage.map(_flat);

  @override
  Stream<Map<String, String>> get opened async* {
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) yield _flat(initial);
    yield* FirebaseMessaging.onMessageOpenedApp.map(_flat);
  }

  @override
  Future<void> showLocal(String title, String body, {required bool urgent}) => _local.show(
        id: _id++,
        title: title,
        body: body,
        notificationDetails: NotificationDetails(
          android: urgent
              ? AndroidNotificationDetails(FirebasePushBridge.urgent.id, FirebasePushBridge.urgent.name,
                  channelDescription: FirebasePushBridge.urgent.description, importance: Importance.max, priority: Priority.max)
              : AndroidNotificationDetails(updates.id, updates.name, channelDescription: updates.description, importance: Importance.high, priority: Priority.high),
        ),
      );

  @override
  Future<void> deleteToken() => FirebaseMessaging.instance.deleteToken();
}
