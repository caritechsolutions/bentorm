import 'dart:async';

import 'package:flutter/material.dart';

import 'api_client.dart';
import 'brand.dart';
import 'realtime.dart';

class ChatMessage {
  ChatMessage({required this.id, required this.body, required this.mine, this.sender, this.at, this.read = false});
  final int id;
  final String body;
  final bool mine;
  final String? sender;
  final DateTime? at;
  final bool read;

  factory ChatMessage.fromJson(Map<String, dynamic> j) => ChatMessage(
        id: (j['id'] as num?)?.toInt() ?? 0,
        body: '${j['body'] ?? ''}',
        mine: j['mine'] == true,
        sender: j['sender']?.toString(),
        at: DateTime.tryParse('${j['at']}')?.toLocal(),
        read: j['read'] == true,
      );
}

/// Messages between rider (or customer) and driver during a trip — no phone numbers needed.
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required this.client, required this.tripId, required this.otherName, this.realtime, this.quickReplies = const [], this.pollEvery = const Duration(seconds: 3)});

  final ApiClient client;
  final String tripId;
  final String otherName;
  final RealtimeClient? realtime;

  /// One-tap messages (e.g. for drivers: "I'm here", "On my way").
  final List<String> quickReplies;
  final Duration pollEvery;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final List<ChatMessage> _messages = [];
  final _text = TextEditingController();
  final _scroll = ScrollController();
  Timer? _poll;
  StreamSubscription<RealtimeEvent>? _live;
  bool _open = true;
  bool _sending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
    // With live updates connected, messages arrive by themselves: poll slowly as a safety net.
    _poll = Timer.periodic(widget.realtime?.connected == true ? widget.pollEvery * 4 : widget.pollEvery, (_) => _load());
    _live = widget.realtime?.events.where((e) => e.name == 'chat.message' && e.data['trip_id'] == widget.tripId).listen((_) => _load());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _live?.cancel();
    _text.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final after = _messages.isEmpty ? 0 : _messages.last.id;
      final r = await widget.client.get('/trips/${widget.tripId}/messages', query: {'after': '$after'}) as Map<String, dynamic>;
      if (!mounted) return;
      final fresh = [for (final m in (r['messages'] as List? ?? const [])) ChatMessage.fromJson(Map<String, dynamic>.from(m as Map))];
      setState(() {
        _open = r['open'] != false;
        _messages.addAll(fresh.where((m) => !_messages.any((x) => x.id == m.id)));
      });
      if (fresh.isNotEmpty) _toBottom();
    } on ApiException {
      // try again next tick
    }
  }

  void _toBottom() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.jumpTo(_scroll.position.maxScrollExtent);
      });

  Future<void> _send([String? quick]) async {
    final body = (quick ?? _text.text).trim();
    if (body.isEmpty || _sending) return;
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final m = ChatMessage.fromJson(await widget.client.post('/trips/${widget.tripId}/messages', {'body': body}) as Map<String, dynamic>);
      if (!mounted) return;
      setState(() {
        if (!_messages.any((x) => x.id == m.id)) _messages.add(m);
        if (quick == null) _text.clear();
      });
      _toBottom();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.otherName)),
        body: SafeArea(
          child: Column(children: [
            Expanded(
              child: _messages.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(24),
                        child: Text('Messages go to ${widget.otherName} in the app. Your phone number stays private.',
                            textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
                      ),
                    )
                  : ListView.builder(
                      controller: _scroll,
                      padding: const EdgeInsets.all(12),
                      itemCount: _messages.length,
                      itemBuilder: (_, i) {
                        final m = _messages[i];
                        return Align(
                          alignment: m.mine ? Alignment.centerRight : Alignment.centerLeft,
                          child: Container(
                            key: Key('msg-${m.id}'),
                            margin: const EdgeInsets.symmetric(vertical: 3),
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.75),
                            decoration: BoxDecoration(color: m.mine ? Brand.blue : const Color(0xFFF1F2F4), borderRadius: BorderRadius.circular(14)),
                            child: Text(m.body, style: TextStyle(color: m.mine ? Colors.white : Brand.charcoal, fontSize: 15)),
                          ),
                        );
                      },
                    ),
            ),
            if (_error != null) Padding(padding: const EdgeInsets.symmetric(horizontal: 12), child: Text(_error!, style: const TextStyle(color: Brand.red))),
            if (!_open)
              const Padding(padding: EdgeInsets.all(12), child: Text('This chat has closed — the trip has ended.', style: TextStyle(color: Colors.black54)))
            else ...[
              if (widget.quickReplies.isNotEmpty)
                SizedBox(
                  height: 44,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    children: [
                      for (final q in widget.quickReplies)
                        Padding(padding: const EdgeInsets.symmetric(horizontal: 4), child: ActionChip(label: Text(q), onPressed: _sending ? null : () => _send(q))),
                    ],
                  ),
                ),
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 4, 8, 8),
                child: Row(children: [
                  Expanded(
                    child: TextField(
                      key: const Key('chat-input'),
                      controller: _text,
                      minLines: 1,
                      maxLines: 4,
                      maxLength: 500,
                      textInputAction: TextInputAction.send,
                      onSubmitted: (_) => _send(),
                      decoration: const InputDecoration(hintText: 'Message', counterText: '', isDense: true),
                    ),
                  ),
                  IconButton(key: const Key('chat-send'), onPressed: _sending ? null : () => _send(), icon: const Icon(Icons.send, color: Brand.blue)),
                ]),
              ),
            ],
          ]),
        ),
      );
}
