import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';
import 'calls.dart';
import 'live.dart';
import 'realtime.dart';
import 'session.dart';

/// Opens the call screen when the other person calls. Hears about calls three ways: a live "call.ring" event, a
/// push notification (the app calls [check]), and — while live updates are down — a short poll.
class IncomingCalls {
  IncomingCalls({required this.navigator, required this.client, required this.session, this.live, this.pollEvery = const Duration(seconds: 6)});

  final GlobalKey<NavigatorState> navigator;
  final ApiClient client;
  final Session session;
  final LiveLink? live;
  final Duration pollEvery;

  Timer? _timer;
  StreamSubscription<RealtimeEvent>? _sub;
  RealtimeClient? _subscribedTo;
  String? _showing;
  bool _checking = false;

  void start() {
    _timer = Timer.periodic(pollEvery, (_) {
      // With live updates connected, the ring arrives by itself; poll only as a fallback.
      if (live?.realtime?.connected == true) {
        _listen();
        return;
      }
      check();
    });
    live?.addListener(_listen);
    _listen();
  }

  void _listen() {
    final rt = live?.realtime;
    if (rt == null || identical(rt, _subscribedTo)) return;
    _sub?.cancel();
    _subscribedTo = rt;
    _sub = rt.events.where((e) => e.name == 'call.ring').listen((_) => check());
  }

  /// Ask the server for a ringing call and show it (once).
  Future<void> check() async {
    if (_checking || _showing != null || session.state != SessionState.signedIn) return;
    _checking = true;
    try {
      final call = await CallApi(client).incoming();
      if (call == null || call.status != 'ringing' || call.id == _showing) return;
      final nav = navigator.currentState;
      if (nav == null) return;
      _showing = call.id;
      await nav.push(MaterialPageRoute(builder: (_) => CallScreen(client: client, call: call, realtime: live?.realtime)));
      _showing = null;
    } on ApiException {
      // Offline: the next tick tries again.
    } finally {
      _checking = false;
    }
  }

  void stop() {
    _timer?.cancel();
    _sub?.cancel();
    live?.removeListener(_listen);
  }
}
