import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../brand.dart';
import '../state/scope.dart';

/// Phone → SMS code → (first time only) name.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  String? _sentTo; // normalised phone once a code was sent
  bool _needsName = false;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  Future<void> _sendCode() async {
    final phone = _phone.text.trim();
    if (phone.replaceAll(RegExp(r'\D'), '').length < 7) {
      setState(() => _error = 'Enter your mobile number.');
      return;
    }
    await _run(() async {
      final normalised = await AppScope.of(context).api.requestCode(phone);
      setState(() {
        _sentTo = normalised;
        _code.clear();
      });
    });
  }

  Future<void> _verify() async {
    final code = _code.text.trim();
    if (code.length < 4) {
      setState(() => _error = 'Enter the code we sent you.');
      return;
    }
    if (_needsName && _name.text.trim().length < 2) {
      setState(() => _error = 'Tell us your name.');
      return;
    }
    final scope = AppScope.of(context);
    await _run(() async {
      try {
        final r = await scope.api.verifyCode(_sentTo!, code, name: _needsName ? _name.text : null);
        await scope.session.signedIn(r.token, r.user);
      } on ApiException catch (e) {
        if (e.body['needs_name'] == true) {
          setState(() => _needsName = true);
          return;
        }
        rethrow;
      }
    });
  }

  Future<void> _run(Future<void> Function() task) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await task();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.field('code') ?? e.field('phone') ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final codeStep = _sentTo != null;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(Icons.local_taxi_rounded, size: 72, color: Brand.yellow),
                  const SizedBox(height: 8),
                  Text(Brand.name, textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.bold, color: Brand.charcoal)),
                  const SizedBox(height: 4),
                  const Text('Ride local. Ride fair.', textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
                  const SizedBox(height: 32),
                  if (!codeStep) ...[
                    const Text('Your mobile number', style: TextStyle(fontWeight: FontWeight.w600)),
                    const SizedBox(height: 8),
                    TextField(
                      key: const Key('phone'),
                      controller: _phone,
                      keyboardType: TextInputType.phone,
                      autofillHints: const [AutofillHints.telephoneNumber],
                      decoration: const InputDecoration(hintText: '246 555 1234', prefixIcon: Icon(Icons.phone)),
                      onSubmitted: (_) => _sendCode(),
                    ),
                    const SizedBox(height: 8),
                    const Text('We\'ll text you a code to sign in.', style: TextStyle(color: Colors.black54, fontSize: 13)),
                  ] else ...[
                    Text('Enter the code sent to $_sentTo', style: const TextStyle(fontWeight: FontWeight.w600)),
                    const SizedBox(height: 8),
                    TextField(
                      key: const Key('code'),
                      controller: _code,
                      keyboardType: TextInputType.number,
                      autofillHints: const [AutofillHints.oneTimeCode],
                      maxLength: 6,
                      textAlign: TextAlign.center,
                      style: const TextStyle(fontSize: 24, letterSpacing: 8),
                      decoration: const InputDecoration(counterText: '', hintText: '••••••'),
                      onSubmitted: (_) => _verify(),
                    ),
                    if (_needsName) ...[
                      const SizedBox(height: 16),
                      const Text('Welcome! What\'s your name?', style: TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 8),
                      TextField(
                        key: const Key('name'),
                        controller: _name,
                        textCapitalization: TextCapitalization.words,
                        autofillHints: const [AutofillHints.name],
                        decoration: const InputDecoration(hintText: 'First and last name', prefixIcon: Icon(Icons.person)),
                        onSubmitted: (_) => _verify(),
                      ),
                    ],
                  ],
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(_error!, key: const Key('error'), style: const TextStyle(color: Brand.red)),
                  ],
                  const SizedBox(height: 24),
                  FilledButton(
                    key: const Key('continue'),
                    onPressed: _busy ? null : (codeStep ? _verify : _sendCode),
                    child: _busy
                        ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                        : Text(codeStep ? (_needsName ? 'Create account' : 'Sign in') : 'Send code'),
                  ),
                  if (codeStep)
                    TextButton(
                      onPressed: _busy
                          ? null
                          : () => setState(() {
                                _sentTo = null;
                                _needsName = false;
                                _error = null;
                              }),
                      child: const Text('Use a different number or resend'),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
