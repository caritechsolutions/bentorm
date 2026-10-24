import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:ridecoop_core/ridecoop_core.dart' show Safety;

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';

/// Ride credit balance and where it came from. Credit comes off rides and orders when you choose "Use ride credit".
class CreditScreen extends StatefulWidget {
  const CreditScreen({super.key});

  @override
  State<CreditScreen> createState() => _CreditScreenState();
}

class _CreditScreenState extends State<CreditScreen> {
  CreditInfo? _info;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final c = await AppScope.of(context).api.credit();
      if (mounted) setState(() => _info = c);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  String _date(DateTime? d) {
    if (d == null) return '';
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return '${d.day} ${months[d.month - 1]} ${d.year}';
  }

  @override
  Widget build(BuildContext context) {
    final c = _info;
    return Scaffold(
      appBar: AppBar(title: const Text('Ride credit')),
      body: c == null
          ? Center(child: _error != null ? Text(_error!) : const CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(16), children: [
              Text(money(c.balance, c.currency), key: const Key('credit-balance'), style: const TextStyle(fontSize: 36, fontWeight: FontWeight.bold)),
              const Text('Turn on "Use ride credit" when you book a ride or check out.', style: TextStyle(color: Colors.black54)),
              const SizedBox(height: 16),
              if (c.entries.isEmpty)
                const Text('No credit yet. Invite friends to earn some.')
              else
                for (final e in c.entries)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(e.label),
                    subtitle: Text(_date(e.date)),
                    trailing: Text(
                      '${e.amount >= 0 ? '+' : '−'}${money(e.amount.abs(), c.currency)}',
                      style: TextStyle(fontWeight: FontWeight.bold, color: e.amount >= 0 ? const Color(0xFF1E9E5A) : Brand.charcoal),
                    ),
                  ),
            ]),
    );
  }
}

/// Your invite code to share, and (for new customers) a box to enter a friend's code.
class InviteScreen extends StatefulWidget {
  const InviteScreen({super.key});

  @override
  State<InviteScreen> createState() => _InviteScreenState();
}

class _InviteScreenState extends State<InviteScreen> {
  ReferralInfo? _info;
  String? _error;
  final _code = TextEditingController();
  String? _codeError;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final r = await AppScope.of(context).api.referral();
      if (mounted) setState(() => _info = r);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _enter() async {
    if (_code.text.trim().isEmpty) return;
    setState(() {
      _busy = true;
      _codeError = null;
    });
    try {
      final msg = await AppScope.of(context).api.enterReferralCode(_code.text);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
      await _load();
    } on ApiException catch (e) {
      if (mounted) setState(() => _codeError = e.field('code') ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final r = _info;
    return Scaffold(
      appBar: AppBar(title: const Text('Invite friends')),
      body: r == null
          ? Center(child: _error != null ? Text(_error!) : const CircularProgressIndicator())
          : ListView(padding: const EdgeInsets.all(16), children: [
              const Icon(Icons.card_giftcard, size: 56, color: Brand.blue),
              const SizedBox(height: 8),
              Text(
                'Give ${money(r.refereeCredit, 'BBD')}, get ${money(r.referrerCredit, 'BBD')}',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 4),
              const Text('Your friend gets ride credit, and so do you after their first ride or order.',
                  textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(border: Border.all(color: Brand.blue, width: 2), borderRadius: BorderRadius.circular(14)),
                child: Row(children: [
                  Expanded(child: Text(r.code, key: const Key('invite-code'), style: const TextStyle(fontSize: 26, fontWeight: FontWeight.bold, letterSpacing: 3))),
                  IconButton(
                    key: const Key('invite-copy'),
                    icon: const Icon(Icons.copy),
                    tooltip: 'Copy',
                    onPressed: () {
                      Clipboard.setData(ClipboardData(text: r.shareText));
                      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Copied — paste it to a friend')));
                    },
                  ),
                ]),
              ),
              const SizedBox(height: 12),
              FilledButton.icon(
                key: const Key('invite-whatsapp'),
                onPressed: () => Safety.launch(Uri.parse('https://wa.me/?text=${Uri.encodeComponent(r.shareText)}')),
                icon: const Icon(Icons.chat),
                label: const Text('Share on WhatsApp'),
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                key: const Key('invite-sms'),
                onPressed: () => Safety.launch(Uri(scheme: 'sms', queryParameters: {'body': r.shareText})),
                icon: const Icon(Icons.sms_outlined),
                label: const Text('Send a text'),
              ),
              const SizedBox(height: 16),
              Text('${r.invited} invited · ${r.rewarded} rewarded', key: const Key('invite-stats'), textAlign: TextAlign.center),
              if (r.referredBy != null)
                Padding(padding: const EdgeInsets.only(top: 8), child: Text('You were invited by ${r.referredBy}.', textAlign: TextAlign.center)),
              if (r.canEnterCode) ...[
                const Divider(height: 32),
                const Text('Got a friend\'s code?', style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                TextField(
                  key: const Key('friend-code'),
                  controller: _code,
                  textCapitalization: TextCapitalization.characters,
                  decoration: InputDecoration(hintText: 'Enter their code', errorText: _codeError),
                ),
                const SizedBox(height: 8),
                FilledButton(key: const Key('friend-code-apply'), onPressed: _busy ? null : _enter, child: const Text('Add code')),
              ],
            ]),
    );
  }
}
