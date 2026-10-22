import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../brand.dart';
import '../state/scope.dart';

enum _Step { phone, details, code }

/// Phone → (new riders: name + email) → one code sent by SMS and email → signed in.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phone = TextEditingController();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _code = TextEditingController();
  _Step _step = _Step.phone;
  String? _normalised; // +1246… from the server
  bool _isNew = false;
  String _sentTo = '';
  bool _busy = false;
  String? _error;

  static final _emailPattern = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  @override
  void dispose() {
    _phone.dispose();
    _name.dispose();
    _email.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _continuePhone() async {
    final phone = _phone.text.trim();
    if (phone.replaceAll(RegExp(r'\D'), '').length < 7) {
      setState(() => _error = 'Enter your mobile number.');
      return;
    }
    final api = AppScope.of(context).api;
    await _run(() async {
      final r = await api.lookup(phone);
      _normalised = r.phone;
      _isNew = !r.registered;
      if (_isNew) {
        setState(() => _step = _Step.details);
      } else {
        await _send();
      }
    });
  }

  Future<void> _continueDetails() async {
    if (_name.text.trim().length < 2) {
      setState(() => _error = 'Tell us your name.');
      return;
    }
    if (!_emailPattern.hasMatch(_email.text.trim())) {
      setState(() => _error = 'Enter a valid email address.');
      return;
    }
    await _run(_send);
  }

  Future<void> _send() async {
    final r = await AppScope.of(context).api.requestCode(_normalised!, email: _isNew ? _email.text : null);
    final parts = [
      if (r.channels.contains('sms')) 'by text to ${r.phone}',
      if (r.channels.contains('email') && r.emailHint != null) 'to ${r.emailHint}',
    ];
    setState(() {
      _sentTo = parts.join(' and ');
      _code.clear();
      _step = _Step.code;
    });
  }

  Future<void> _verify() async {
    final code = _code.text.trim();
    if (code.length < 4) {
      setState(() => _error = 'Enter the code we sent you.');
      return;
    }
    final scope = AppScope.of(context);
    await _run(() async {
      try {
        final r = await scope.api.verifyCode(_normalised!, code, name: _isNew ? _name.text : null, email: _isNew ? _email.text : null);
        await scope.session.signedIn(r.token, r.user);
      } on ApiException catch (e) {
        if (e.body['needs_name'] == true || e.body['needs_email'] == true) {
          // Server wants details we didn't collect (e.g. the number was registered meanwhile).
          setState(() {
            _isNew = true;
            _step = _Step.details;
          });
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
      if (mounted) setState(() => _error = e.field('code') ?? e.field('email') ?? e.field('phone') ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _back() => setState(() {
        _step = _Step.phone;
        _error = null;
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Icon(Icons.local_taxi_rounded, size: 72, color: Brand.yellow),
                    const SizedBox(height: 8),
                    Text(Brand.name, textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.bold, color: Brand.charcoal)),
                    const SizedBox(height: 4),
                    const Text('Ride local. Ride fair.', textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
                    const SizedBox(height: 32),
                    ...switch (_step) {
                      _Step.phone => _phoneStep(),
                      _Step.details => _detailsStep(),
                      _Step.code => _codeStep(),
                    },
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(_error!, key: const Key('error'), style: const TextStyle(color: Brand.red)),
                    ],
                    const SizedBox(height: 24),
                    FilledButton(
                      key: const Key('continue'),
                      onPressed: _busy ? null : switch (_step) { _Step.phone => _continuePhone, _Step.details => _continueDetails, _Step.code => _verify },
                      child: _busy
                          ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                          : Text(switch (_step) { _Step.phone => 'Continue', _Step.details => 'Send code', _Step.code => _isNew ? 'Create account' : 'Sign in' }),
                    ),
                    if (_step != _Step.phone)
                      TextButton(onPressed: _busy ? null : _back, child: const Text('Use a different number or resend')),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _phoneStep() => [
        const Text('Your mobile number', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 8),
        TextField(
          key: const Key('phone'),
          controller: _phone,
          keyboardType: TextInputType.phone,
          autofillHints: const [AutofillHints.telephoneNumber],
          decoration: const InputDecoration(hintText: '246 555 1234', prefixIcon: Icon(Icons.phone)),
          onSubmitted: (_) => _continuePhone(),
        ),
        const SizedBox(height: 8),
        const Text('We\'ll send you a code to sign in.', style: TextStyle(color: Colors.black54, fontSize: 13)),
      ];

  List<Widget> _detailsStep() => [
        Text('Welcome! Create your account for $_normalised', style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 12),
        TextField(
          key: const Key('name'),
          controller: _name,
          textCapitalization: TextCapitalization.words,
          autofillHints: const [AutofillHints.name],
          textInputAction: TextInputAction.next,
          decoration: const InputDecoration(hintText: 'First and last name', prefixIcon: Icon(Icons.person)),
        ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('email'),
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          autofillHints: const [AutofillHints.email],
          autocorrect: false,
          decoration: const InputDecoration(hintText: 'Email address', prefixIcon: Icon(Icons.email_outlined)),
          onSubmitted: (_) => _continueDetails(),
        ),
        const SizedBox(height: 8),
        const Text('We\'ll send your code by text and email, and email your receipts.', style: TextStyle(color: Colors.black54, fontSize: 13)),
      ];

  List<Widget> _codeStep() => [
        Text('Enter the code we sent $_sentTo', key: const Key('sent-to'), style: const TextStyle(fontWeight: FontWeight.w600)),
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
      ];
}
