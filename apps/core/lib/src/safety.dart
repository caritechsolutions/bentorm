import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api_client.dart';
import 'brand.dart';
import 'remote_config.dart';

/// SOS and share-my-trip, used by both apps.
class Safety {
  Safety._();

  /// Tests replace this (tel:, sms:, WhatsApp links).
  static Future<bool> Function(Uri url) launch = (url) => launchUrl(url, mode: LaunchMode.externalApplication);

  /// The SOS sheet: emergency numbers to call, and "Alert Ride Coop" (sends where you are and the trip to the team).
  static Future<void> sos(BuildContext context, {required ApiClient client, required RemoteConfig config, String? tripId, Future<({double lat, double lng})?> Function()? where}) {
    return showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (_) => _SosSheet(client: client, config: config, tripId: tripId, where: where),
    );
  }

  /// Gets a live link for the trip and offers WhatsApp, text message or copy.
  static Future<void> shareTrip(BuildContext context, {required ApiClient client, required String tripId}) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final r = await client.post('/trips/$tripId/share') as Map<String, dynamic>;
      if (!context.mounted) return;
      final text = '${r['text']}';
      await showModalBottomSheet<void>(
        context: context,
        showDragHandle: true,
        builder: (ctx) => SafeArea(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Text('Share your trip', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            ),
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
              child: Text('They can follow the car on a map until an hour after the trip — no app needed.', style: TextStyle(color: Colors.black54)),
            ),
            ListTile(
              key: const Key('share-whatsapp'),
              leading: const Icon(Icons.chat),
              title: const Text('WhatsApp'),
              onTap: () {
                Navigator.pop(ctx);
                launch(Uri.parse('https://wa.me/?text=${Uri.encodeComponent(text)}'));
              },
            ),
            ListTile(
              key: const Key('share-sms'),
              leading: const Icon(Icons.sms_outlined),
              title: const Text('Text message'),
              onTap: () {
                Navigator.pop(ctx);
                launch(Uri(scheme: 'sms', queryParameters: {'body': text}));
              },
            ),
            ListTile(
              key: const Key('share-copy'),
              leading: const Icon(Icons.copy),
              title: const Text('Copy link'),
              onTap: () {
                Clipboard.setData(ClipboardData(text: '${r['url']}'));
                Navigator.pop(ctx);
                messenger.showSnackBar(const SnackBar(content: Text('Link copied')));
              },
            ),
          ]),
        ),
      );
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }
}

class _SosSheet extends StatefulWidget {
  const _SosSheet({required this.client, required this.config, this.tripId, this.where});
  final ApiClient client;
  final RemoteConfig config;
  final String? tripId;
  final Future<({double lat, double lng})?> Function()? where;

  @override
  State<_SosSheet> createState() => _SosSheetState();
}

class _SosSheetState extends State<_SosSheet> {
  bool _sending = false;
  String? _sent;
  String? _error;

  Future<void> _alert() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final at = await widget.where?.call();
      final r = await widget.client.post('/sos', {
        if (widget.tripId != null) 'trip_id': widget.tripId,
        if (at != null) 'lat': at.lat,
        if (at != null) 'lng': at.lng,
      }) as Map<String, dynamic>;
      if (mounted) setState(() => _sent = '${r['message']}');
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = '${e.message} Call the police on the number above.');
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('Emergency', style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            const Text('If you are in danger, call now.', style: TextStyle(color: Colors.black54)),
            const SizedBox(height: 12),
            for (final n in widget.config.emergencyNumbers)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: FilledButton.icon(
                  key: Key('sos-call-${n.number}'),
                  style: FilledButton.styleFrom(backgroundColor: Brand.red),
                  onPressed: () => Safety.launch(Uri(scheme: 'tel', path: n.number)),
                  icon: const Icon(Icons.call),
                  label: Text('${n.name} · ${n.number}'),
                ),
              ),
            const Divider(height: 24),
            if (_sent != null)
              Container(
                key: const Key('sos-sent'),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: const Color(0xFFE8F5E9), borderRadius: BorderRadius.circular(12)),
                child: Text(_sent!),
              )
            else
              OutlinedButton.icon(
                key: const Key('sos-alert'),
                style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), foregroundColor: Brand.red, side: const BorderSide(color: Brand.red)),
                onPressed: _sending ? null : _alert,
                icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.shield_outlined),
                label: const Text('Alert the Ride Coop team'),
              ),
            if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: Brand.red))),
            const SizedBox(height: 4),
            const Text('The team sees where you are and the trip details straight away.', style: TextStyle(color: Colors.black54, fontSize: 13)),
          ]),
        ),
      );
}
