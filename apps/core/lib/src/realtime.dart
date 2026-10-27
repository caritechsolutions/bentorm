import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:web_socket_channel/web_socket_channel.dart';

/// One event from the server, e.g. name "trip.updated", "chat.message", "call.ring".
class RealtimeEvent {
  const RealtimeEvent(this.name, this.data, [this.channel]);
  final String name;
  final Map<String, dynamic> data;

  /// e.g. "private-user.12" or "private-trip.01abc…".
  final String? channel;
}

typedef SocketFactory = WebSocketChannel Function(Uri uri);

/// Live updates from the server (Laravel Reverb speaks the Pusher protocol) on this user's private channel, so the
/// apps hear about trips, messages and calls at once instead of asking every few seconds. Reconnects by itself;
/// screens keep a slow poll as a safety net, so nothing is lost while it's down.
class RealtimeClient extends ChangeNotifier {
  RealtimeClient({required this.key, required this.host, required this.port, required this.tls, required this.authEndpoint, SocketFactory? connect, http.Client? httpClient})
      : _connect = connect ?? ((uri) => WebSocketChannel.connect(uri)),
        _http = httpClient ?? http.Client();

  final String key;
  final String host;
  final int port;
  final bool tls;
  final String authEndpoint;
  final SocketFactory _connect;
  final http.Client _http;

  final _events = StreamController<RealtimeEvent>.broadcast();
  Stream<RealtimeEvent> get events => _events.stream;

  /// True while subscribed to the user's channel.
  bool connected = false;

  WebSocketChannel? _ws;
  StreamSubscription<dynamic>? _sub;
  Timer? _retry;
  int _attempt = 0;
  String? _token;
  int? _userId;
  bool _stopped = true;
  String? _socketId;
  final Set<String> _extra = {};

  /// Listen to another private channel too, e.g. "private-trip.{id}" for the driver's live position during a ride.
  /// Kept across reconnects until [leave].
  void join(String channel) {
    if (!_extra.add(channel)) return;
    final id = _socketId;
    if (id != null) _subscribe(id, channel);
  }

  void leave(String channel) {
    if (_extra.remove(channel) && _socketId != null) _send({'event': 'pusher:unsubscribe', 'data': {'channel': channel}});
  }

  /// Connect as [userId] (API [token] authorises the private channel). Safe to call again (e.g. new sign-in).
  void start({required String token, required int userId}) {
    _token = token;
    _userId = userId;
    _stopped = false;
    _attempt = 0;
    _open();
  }

  void stop() {
    _stopped = true;
    _retry?.cancel();
    _close();
    if (connected) {
      connected = false;
      notifyListeners();
    }
  }

  Uri get _uri => Uri(scheme: tls ? 'wss' : 'ws', host: host, port: port, path: '/app/$key', queryParameters: {'protocol': '7', 'client': 'ridecoop', 'version': '1.0'});

  void _open() {
    _close();
    try {
      _ws = _connect(_uri);
      _sub = _ws!.stream.listen(_onMessage, onDone: _lost, onError: (_) => _lost(), cancelOnError: true);
    } catch (_) {
      _lost();
    }
  }

  void _close() {
    _socketId = null;
    _sub?.cancel();
    _sub = null;
    try {
      _ws?.sink.close();
    } catch (_) {}
    _ws = null;
  }

  void _lost() {
    if (connected) {
      connected = false;
      notifyListeners();
    }
    if (_stopped) return;
    // 1, 2, 4 … up to 30 s between tries.
    final wait = Duration(seconds: math.min(30, 1 << math.min(_attempt, 5)));
    _attempt++;
    _retry?.cancel();
    _retry = Timer(wait, _open);
  }

  Future<void> _onMessage(dynamic raw) async {
    Map<String, dynamic> msg;
    try {
      msg = jsonDecode('$raw') as Map<String, dynamic>;
    } catch (_) {
      return;
    }
    final event = '${msg['event']}';
    final data = msg['data'] is String ? _decode(msg['data'] as String) : (msg['data'] is Map ? Map<String, dynamic>.from(msg['data'] as Map) : <String, dynamic>{});
    switch (event) {
      case 'pusher:connection_established':
        _socketId = '${data['socket_id']}';
        await _subscribe(_socketId!, 'private-user.$_userId');
        for (final c in _extra.toList()) {
          await _subscribe(_socketId!, c);
        }
      case 'pusher:ping':
        _send({'event': 'pusher:pong', 'data': {}});
      case 'pusher_internal:subscription_succeeded':
        _attempt = 0;
        connected = true;
        notifyListeners();
      case 'pusher:error':
        break; // the server closes the socket; _lost reconnects
      default:
        if (!event.startsWith('pusher')) _events.add(RealtimeEvent(event, data, msg['channel']?.toString()));
    }
  }

  Future<void> _subscribe(String socketId, String channel) async {
    // Only the user's own channel is essential; a refused extra channel (trip over) is just skipped.
    final essential = channel == 'private-user.$_userId';
    try {
      final res = await _http.post(Uri.parse(authEndpoint),
          headers: {'Authorization': 'Bearer $_token', 'Accept': 'application/json', 'Content-Type': 'application/json'},
          body: jsonEncode({'socket_id': socketId, 'channel_name': channel}));
      if (res.statusCode != 200) {
        if (essential) _lost();
        return;
      }
      final auth = (jsonDecode(res.body) as Map<String, dynamic>)['auth'];
      _send({'event': 'pusher:subscribe', 'data': {'channel': channel, 'auth': auth}});
    } catch (_) {
      if (essential) _lost();
    }
  }

  void _send(Map<String, dynamic> msg) {
    try {
      _ws?.sink.add(jsonEncode(msg));
    } catch (_) {}
  }

  static Map<String, dynamic> _decode(String s) {
    try {
      final v = jsonDecode(s);
      return v is Map<String, dynamic> ? v : <String, dynamic>{};
    } catch (_) {
      return <String, dynamic>{};
    }
  }

  @override
  void dispose() {
    stop();
    _events.close();
    super.dispose();
  }
}
