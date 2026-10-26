import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';

import 'api_client.dart';
import 'brand.dart';
import 'realtime.dart';

/// A call between rider (or customer) and driver, as the server describes it.
class CallInfo {
  CallInfo({required this.id, required this.tripId, required this.status, required this.video, required this.incoming, required this.otherName,
      required this.iceServers, required this.phoneBridge, this.ringSeconds = 45});

  final String id;
  final String tripId;
  final String status; // ringing | accepted | declined | missed | ended | bridged
  final bool video;
  final bool incoming;
  final String otherName;
  final List<Map<String, dynamic>> iceServers;
  final bool phoneBridge;
  final int ringSeconds;

  bool get live => status == 'ringing' || status == 'accepted';

  factory CallInfo.fromJson(Map<String, dynamic> j) => CallInfo(
        id: '${j['id']}',
        tripId: '${j['trip_id']}',
        status: '${j['status']}',
        video: j['video'] == true,
        incoming: j['incoming'] == true,
        otherName: '${j['other_name'] ?? ''}',
        iceServers: [for (final s in (j['ice_servers'] as List? ?? const [])) Map<String, dynamic>.from(s as Map)],
        phoneBridge: j['phone_bridge'] == true,
        ringSeconds: (j['ring_seconds'] as num?)?.toInt() ?? 45,
      );
}

/// Call endpoints (same for both apps).
class CallApi {
  CallApi(this.client);
  final ApiClient client;

  Future<CallInfo> start(String tripId, {required bool video}) async =>
      CallInfo.fromJson(await client.post('/trips/$tripId/calls', {'video': video}) as Map<String, dynamic>);

  Future<CallInfo?> incoming() async {
    final r = await client.get('/calls/incoming') as Map<String, dynamic>;
    return r['call'] is Map ? CallInfo.fromJson(Map<String, dynamic>.from(r['call'] as Map)) : null;
  }

  Future<CallInfo> get(String id) async => CallInfo.fromJson(await client.get('/calls/$id') as Map<String, dynamic>);

  Future<CallInfo> act(String id, String action) async => CallInfo.fromJson(await client.post('/calls/$id/$action') as Map<String, dynamic>);

  Future<void> signal(String id, String type, Map<String, dynamic> payload) async {
    await client.post('/calls/$id/signal', {'type': type, 'payload': payload});
  }

  Future<({List<Map<String, dynamic>> signals, String status})> signals(String id, int after) async {
    final r = await client.get('/calls/$id/signals', query: {'after': '$after'}) as Map<String, dynamic>;
    return (signals: [for (final s in (r['signals'] as List? ?? const [])) Map<String, dynamic>.from(s as Map)], status: '${r['status']}');
  }
}

/// Camera, microphone and the WebRTC connection. [WebRtcCallMedia] in the apps; a fake in widget tests.
abstract class CallMedia {
  void Function(Map<String, dynamic> candidate)? onCandidate;
  void Function(String state)? onState; // connected | failed | closed
  VoidCallback? onRemoteVideo;

  Future<void> start({required List<Map<String, dynamic>> iceServers, required bool video});
  Future<Map<String, dynamic>> createOffer();
  Future<Map<String, dynamic>> answerOffer(Map<String, dynamic> offer);
  Future<void> acceptAnswer(Map<String, dynamic> answer);
  Future<void> addCandidate(Map<String, dynamic> candidate);
  Future<void> setMuted(bool muted);
  Future<void> setSpeaker(bool on);
  Future<void> setCamera(bool on);
  Future<void> switchCamera();
  Widget localView();
  Widget remoteView();
  Future<void> dispose();
}

class WebRtcCallMedia extends CallMedia {
  RTCPeerConnection? _pc;
  MediaStream? _local;
  final _localR = RTCVideoRenderer();
  final _remoteR = RTCVideoRenderer();
  final List<RTCIceCandidate> _pending = [];
  bool _remoteSet = false;

  @override
  Future<void> start({required List<Map<String, dynamic>> iceServers, required bool video}) async {
    await _localR.initialize();
    await _remoteR.initialize();
    _local = await navigator.mediaDevices.getUserMedia({
      'audio': true,
      'video': video ? {'facingMode': 'user', 'width': 640, 'height': 480} : false,
    });
    _localR.srcObject = _local;
    _pc = await createPeerConnection({'iceServers': iceServers, 'sdpSemantics': 'unified-plan'});
    for (final t in _local!.getTracks()) {
      await _pc!.addTrack(t, _local!);
    }
    // Always ready to receive video, so either side can turn the camera on.
    if (!video) {
      await _pc!.addTransceiver(kind: RTCRtpMediaType.RTCRtpMediaTypeVideo, init: RTCRtpTransceiverInit(direction: TransceiverDirection.RecvOnly));
    }
    _pc!.onIceCandidate = (c) {
      if (c.candidate != null) onCandidate?.call({'candidate': c.candidate, 'sdpMid': c.sdpMid, 'sdpMLineIndex': c.sdpMLineIndex});
    };
    _pc!.onTrack = (e) {
      if (e.streams.isNotEmpty) {
        _remoteR.srcObject = e.streams.first;
        if (e.track.kind == 'video') onRemoteVideo?.call();
      }
    };
    _pc!.onConnectionState = (s) {
      if (s == RTCPeerConnectionState.RTCPeerConnectionStateConnected) onState?.call('connected');
      if (s == RTCPeerConnectionState.RTCPeerConnectionStateFailed) onState?.call('failed');
      if (s == RTCPeerConnectionState.RTCPeerConnectionStateClosed) onState?.call('closed');
    };
    await Helper.setSpeakerphoneOn(video);
  }

  @override
  Future<Map<String, dynamic>> createOffer() async {
    final o = await _pc!.createOffer({});
    await _pc!.setLocalDescription(o);
    return {'sdp': o.sdp, 'type': o.type};
  }

  @override
  Future<Map<String, dynamic>> answerOffer(Map<String, dynamic> offer) async {
    await _pc!.setRemoteDescription(RTCSessionDescription('${offer['sdp']}', '${offer['type']}'));
    await _flush();
    final a = await _pc!.createAnswer({});
    await _pc!.setLocalDescription(a);
    return {'sdp': a.sdp, 'type': a.type};
  }

  @override
  Future<void> acceptAnswer(Map<String, dynamic> answer) async {
    await _pc!.setRemoteDescription(RTCSessionDescription('${answer['sdp']}', '${answer['type']}'));
    await _flush();
  }

  @override
  Future<void> addCandidate(Map<String, dynamic> c) async {
    final cand = RTCIceCandidate('${c['candidate']}', c['sdpMid']?.toString(), (c['sdpMLineIndex'] as num?)?.toInt());
    if (_remoteSet) {
      await _pc?.addCandidate(cand);
    } else {
      _pending.add(cand); // too early: wait for the offer/answer
    }
  }

  Future<void> _flush() async {
    _remoteSet = true;
    for (final c in _pending) {
      await _pc?.addCandidate(c);
    }
    _pending.clear();
  }

  @override
  Future<void> setMuted(bool muted) async {
    for (final t in _local?.getAudioTracks() ?? const <MediaStreamTrack>[]) {
      t.enabled = !muted;
    }
  }

  @override
  Future<void> setSpeaker(bool on) => Helper.setSpeakerphoneOn(on);

  @override
  Future<void> setCamera(bool on) async {
    for (final t in _local?.getVideoTracks() ?? const <MediaStreamTrack>[]) {
      t.enabled = on;
    }
  }

  @override
  Future<void> switchCamera() async {
    final v = _local?.getVideoTracks() ?? const <MediaStreamTrack>[];
    if (v.isNotEmpty) await Helper.switchCamera(v.first);
  }

  @override
  Widget localView() => RTCVideoView(_localR, mirror: true, objectFit: RTCVideoViewObjectFit.RTCVideoViewObjectFitCover);

  @override
  Widget remoteView() => RTCVideoView(_remoteR, objectFit: RTCVideoViewObjectFit.RTCVideoViewObjectFitCover);

  @override
  Future<void> dispose() async {
    try {
      for (final t in _local?.getTracks() ?? const <MediaStreamTrack>[]) {
        await t.stop();
      }
      await _local?.dispose();
      await _pc?.close();
    } catch (_) {}
    _localR.srcObject = null;
    _remoteR.srcObject = null;
    await _localR.dispose();
    await _remoteR.dispose();
  }
}

/// Full-screen call: rings (outgoing) or offers Accept/Decline (incoming), then connects audio — and video when either
/// side turns the camera on. Signalling goes through the server; live updates make it quick, polling is the fallback.
/// If the call can't connect, "Call by phone instead" has Ride Coop ring both phones (numbers stay private).
class CallScreen extends StatefulWidget {
  const CallScreen({super.key, required this.client, required this.call, this.realtime, this.media});

  final ApiClient client;
  final CallInfo call;
  final RealtimeClient? realtime;

  /// Tests pass a fake.
  final CallMedia? media;

  /// Tests make this faster.
  static Duration pollEvery = const Duration(milliseconds: 800);

  /// Camera/microphone for calls with no [media] given. Tests swap in a fake (no WebRTC in widget tests).
  static CallMedia Function() makeMedia = WebRtcCallMedia.new;

  /// Starts a call from a trip screen. Returns when the call screen closes.
  static Future<void> start(BuildContext context, {required ApiClient client, required String tripId, required bool video, RealtimeClient? realtime, CallMedia? media}) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final call = await CallApi(client).start(tripId, video: video);
      if (!context.mounted) return;
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => CallScreen(client: client, call: call, realtime: realtime, media: media)));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  State<CallScreen> createState() => _CallScreenState();
}

class _CallScreenState extends State<CallScreen> {
  late final CallApi _api = CallApi(widget.client);
  late final CallMedia _media = widget.media ?? CallScreen.makeMedia();
  late CallInfo _call = widget.call;
  late bool _camera = widget.call.video;
  Timer? _poll;
  Timer? _clock;
  StreamSubscription<RealtimeEvent>? _live;
  int _after = 0;
  bool _started = false;
  bool _connected = false;
  bool _muted = false;
  bool _speaker = false;
  bool _remoteVideo = false;
  bool _busy = false;
  String? _note;
  final DateTime _openedAt = DateTime.now();
  DateTime? _connectedAt;

  bool get _incomingRinging => _call.incoming && _call.status == 'ringing';

  @override
  void initState() {
    super.initState();
    _speaker = widget.call.video;
    _media.onCandidate = (c) => _api.signal(_call.id, 'candidate', c).catchError((Object _) {});
    _media.onState = (s) {
      _report(s);
      if (!mounted) return;
      setState(() {
        if (s == 'connected') {
          _connected = true;
          _connectedAt ??= DateTime.now();
          _note = null;
        } else if (s == 'failed') {
          _note = 'The connection dropped.';
        }
      });
    };
    _media.onRemoteVideo = () {
      if (mounted) setState(() => _remoteVideo = true);
    };
    if (!_call.incoming) _startMedia(asCaller: true);
    // Live updates bring signalling at once; then a slower poll is only a safety net (keeps under the API rate limit).
    _poll = Timer.periodic(widget.realtime?.connected == true ? CallScreen.pollEvery * 3 : CallScreen.pollEvery, (_) => _tick());
    _clock = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
    _live = widget.realtime?.events.where((e) => e.data['call_id'] == _call.id).listen((_) => _tick());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _clock?.cancel();
    _live?.cancel();
    _media.dispose();
    super.dispose();
  }

  /// Tells the server how this phone is doing (shown by `artisan bentorm:calls`). Best effort.
  void _report(String state, [String? detail]) {
    _api.signal(_call.id, 'state', {'state': state, if (detail != null) 'detail': detail.length > 300 ? detail.substring(0, 300) : detail}).catchError((Object _) {});
  }

  Future<void> _startMedia({required bool asCaller}) async {
    try {
      await _media.start(iceServers: _call.iceServers, video: _camera);
      _started = true;
      _report('media_ok', _camera ? 'video' : 'audio');
    } catch (e) {
      _report('media_error', '$e');
      if (mounted) setState(() => _note = 'Allow the microphone${_camera ? ' and camera' : ''} for Ride Coop to talk in the app.');
      return;
    }
    if (asCaller) {
      try {
        await _api.signal(_call.id, 'offer', await _media.createOffer());
      } catch (e) {
        _report('error', 'offer: $e');
      }
    }
  }

  /// Fetch new signalling messages and the call status.
  Future<void> _tick() async {
    if (_busy) return;
    _busy = true;
    try {
      final r = await _api.signals(_call.id, _after);
      for (final s in r.signals) {
        // Not accepted yet (or the camera/mic isn't ready): leave the offer unread; it's read once we're started.
        if (!_started) break;
        final payload = Map<String, dynamic>.from(s['payload'] as Map);
        try {
          switch (s['type']) {
            case 'offer':
              await _api.signal(_call.id, 'answer', await _media.answerOffer(payload));
            case 'answer':
              await _media.acceptAnswer(payload);
            case 'candidate':
              await _media.addCandidate(payload);
          }
        } catch (e) {
          _report('error', '${s['type']}: $e');
        }
        _after = (s['seq'] as num).toInt();
      }
      if (r.status != _call.status) {
        _call = await _api.get(_call.id);
        if (!_call.live) _finish(_endText(_call.status));
      }
      if (mounted) setState(() {});
    } on ApiException {
      // keep trying
    } finally {
      _busy = false;
    }
  }

  String _endText(String status) => switch (status) {
        'declined' => '${_call.otherName} can\'t talk right now.',
        'missed' => _call.incoming ? 'Missed call.' : 'No answer.',
        'bridged' => 'Ride Coop is ringing your phone now.',
        _ => 'Call ended.',
      };

  Future<void> _accept({required bool video}) async {
    _camera = video;
    _speaker = video;
    try {
      _call = await _api.act(_call.id, 'accept');
      await _startMedia(asCaller: false);
      await _tick(); // the offer and early candidates were left unread until now
    } on ApiException catch (e) {
      _finish(e.message);
    }
  }

  Future<void> _end(String action) async {
    try {
      await _api.act(_call.id, action);
    } on ApiException {
      // closing anyway
    }
    _finish(null);
  }

  Future<void> _phoneInstead() async {
    try {
      _call = await _api.act(_call.id, 'phone');
      _finish('Ride Coop is ringing your phone now — answer it to be connected.');
    } on ApiException catch (e) {
      if (mounted) setState(() => _note = e.message);
    }
  }

  void _finish(String? message) {
    _poll?.cancel();
    if (!mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    Navigator.of(context).pop(); // (the screen blocks the back button while a call is on)
    if (message != null) messenger.showSnackBar(SnackBar(content: Text(message)));
  }

  String get _statusLine {
    if (_incomingRinging) return _call.video ? 'Video call' : 'Calling you…';
    if (_connected && _connectedAt != null) {
      final s = DateTime.now().difference(_connectedAt!).inSeconds;
      return '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';
    }
    return _call.status == 'ringing' ? 'Ringing…' : 'Connecting…';
  }

  bool get _offerPhone => _call.phoneBridge && !_connected && DateTime.now().difference(_openedAt).inSeconds >= 15 && !_incomingRinging;

  @override
  Widget build(BuildContext context) {
    final showVideo = _started && (_remoteVideo || _camera);
    return PopScope(
      canPop: false,
      child: Scaffold(
        backgroundColor: const Color(0xFF111418),
        body: Stack(children: [
          if (showVideo && _remoteVideo) Positioned.fill(child: _media.remoteView()),
          if (showVideo && _camera)
            Positioned(
              right: 16,
              top: 48,
              width: 110,
              height: 150,
              child: ClipRRect(borderRadius: BorderRadius.circular(12), child: _media.localView()),
            ),
          SafeArea(
            child: Column(children: [
              const SizedBox(height: 48),
              CircleAvatar(
                radius: 44,
                backgroundColor: Brand.yellow,
                child: Text(_call.otherName.isEmpty ? '?' : _call.otherName[0].toUpperCase(), style: const TextStyle(fontSize: 36, color: Brand.charcoal)),
              ),
              const SizedBox(height: 16),
              Text(_call.otherName, key: const Key('call-name'), style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.bold)),
              const SizedBox(height: 6),
              Text(_statusLine, key: const Key('call-status'), style: const TextStyle(color: Colors.white70, fontSize: 16)),
              if (_note != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 12, 24, 0),
                  child: Text(_note!, textAlign: TextAlign.center, style: const TextStyle(color: Colors.amberAccent)),
                ),
              const Spacer(),
              if (_offerPhone)
                Padding(
                  padding: const EdgeInsets.only(bottom: 16),
                  child: TextButton.icon(
                    key: const Key('call-phone-instead'),
                    onPressed: _phoneInstead,
                    icon: const Icon(Icons.phone_forwarded, color: Colors.white),
                    label: const Text('Not connecting? Call by phone instead', style: TextStyle(color: Colors.white)),
                  ),
                ),
              if (_incomingRinging)
                Row(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
                  _round(const Key('call-decline'), Icons.call_end, Brand.red, 'Decline', () => _end('decline')),
                  if (_call.video) _round(const Key('call-accept-video'), Icons.videocam, const Color(0xFF1E9E5A), 'Video', () => _accept(video: true)),
                  _round(const Key('call-accept'), Icons.call, const Color(0xFF1E9E5A), 'Accept', () => _accept(video: false)),
                ])
              else
                Wrap(alignment: WrapAlignment.center, spacing: 18, runSpacing: 18, children: [
                  _round(const Key('call-mute'), _muted ? Icons.mic_off : Icons.mic, _muted ? Colors.white : Colors.white24, _muted ? 'Unmute' : 'Mute', () {
                    setState(() => _muted = !_muted);
                    _media.setMuted(_muted);
                  }, dark: _muted),
                  _round(const Key('call-speaker'), _speaker ? Icons.volume_up : Icons.hearing, _speaker ? Colors.white : Colors.white24, 'Speaker', () {
                    setState(() => _speaker = !_speaker);
                    _media.setSpeaker(_speaker);
                  }, dark: _speaker),
                  if (_call.video || _camera)
                    _round(const Key('call-camera'), _camera ? Icons.videocam : Icons.videocam_off, _camera ? Colors.white : Colors.white24, 'Camera', () {
                      setState(() => _camera = !_camera);
                      _media.setCamera(_camera);
                    }, dark: _camera),
                  if (_camera) _round(const Key('call-flip'), Icons.cameraswitch, Colors.white24, 'Flip', _media.switchCamera),
                  _round(const Key('call-end'), Icons.call_end, Brand.red, 'End', () => _end('end')),
                ]),
              const SizedBox(height: 40),
            ]),
          ),
        ]),
      ),
    );
  }

  Widget _round(Key key, IconData icon, Color color, String label, VoidCallback onTap, {bool dark = false}) => Column(mainAxisSize: MainAxisSize.min, children: [
        Material(
          color: color,
          shape: const CircleBorder(),
          child: InkWell(
            key: key,
            customBorder: const CircleBorder(),
            onTap: onTap,
            child: Padding(padding: const EdgeInsets.all(18), child: Icon(icon, color: dark ? Brand.charcoal : Colors.white, size: 30)),
          ),
        ),
        const SizedBox(height: 6),
        Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
      ]);
}
