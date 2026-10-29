import 'package:flutter/material.dart';

import 'account.dart';
import 'api_client.dart';
import 'brand.dart';
import 'session.dart';

enum LoginStep { welcome, phone, details, code }

/// Welcome → Sign in (phone) or Create an account (name, email, phone) → one code by SMS + email.
/// Used by both apps; [Session.role] decides the account type created.
class PhoneLoginScreen extends StatefulWidget {
  const PhoneLoginScreen({super.key, required this.session, required this.tagline, this.icon = Icons.local_taxi_rounded, this.iconColor = Brand.yellow, this.title = Brand.name});

  final Session session;
  final String title;
  final String tagline;
  final IconData icon;
  final Color iconColor;

  @override
  State<PhoneLoginScreen> createState() => _PhoneLoginScreenState();
}

class _PhoneLoginScreenState extends State<PhoneLoginScreen> {
  final _phone = TextEditingController();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _code = TextEditingController();
  LoginStep _step = LoginStep.welcome;
  String? _normalised;
  bool _isNew = false;
  String _sentTo = '';
  bool _busy = false;
  String? _error;
  String? _info;

  static final _emailPattern = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  Session get _session => widget.session;

  @override
  void initState() {
    super.initState();
    _error = _session.notice;
  }

  @override
  void dispose() {
    _phone.dispose();
    _name.dispose();
    _email.dispose();
    _code.dispose();
    super.dispose();
  }

  bool _phoneOk() {
    if (_phone.text.replaceAll(RegExp(r'\D'), '').length < 7) {
      setState(() => _error = 'Enter your mobile number.');
      return false;
    }
    return true;
  }

  /// Sign in: registered → send the code; unknown → offer to create the account.
  Future<void> _continueSignIn() async {
    if (!_phoneOk()) return;
    await _run(() async {
      final r = await _session.api.lookup(_phone.text.trim());
      _normalised = r.phone;
      if (r.registered) {
        _isNew = false;
        await _send();
      } else {
        setState(() {
          _step = LoginStep.details;
          _info = 'There\'s no account for ${r.phone} yet. Add your name and email to create one.';
        });
      }
    });
  }

  /// Create account: unknown → send to phone + email; already registered → just sign them in.
  Future<void> _continueCreate() async {
    if (_name.text.trim().length < 2) {
      setState(() => _error = 'Tell us your name.');
      return;
    }
    if (!_emailPattern.hasMatch(_email.text.trim())) {
      setState(() => _error = 'Enter a valid email address.');
      return;
    }
    if (!_phoneOk()) return;
    await _run(() async {
      final r = await _session.api.lookup(_phone.text.trim());
      _normalised = r.phone;
      _isNew = !r.registered;
      await _send();
      if (!_isNew) {
        setState(() => _info = '${r.phone} already has an account, so we\'ve sent you a sign-in code instead.');
      }
    });
  }

  Future<void> _send() async {
    final r = await _session.api.requestCode(_normalised!, email: _isNew ? _email.text : null);
    final parts = [
      if (r.channels.contains('sms')) 'by text to ${r.phone}',
      if (r.channels.contains('email') && r.emailHint != null) 'to ${r.emailHint}',
    ];
    setState(() {
      _sentTo = parts.join(' and ');
      _code.clear();
      _info = null;
      _step = LoginStep.code;
    });
  }

  Future<void> _verify() async {
    final code = _code.text.trim();
    if (code.length < 4) {
      setState(() => _error = 'Enter the code we sent you.');
      return;
    }
    await _run(() async {
      try {
        final r = await _session.api.verifyCode(_normalised!, code,
            role: _session.role, name: _isNew ? _name.text : null, email: _isNew ? _email.text : null);
        final problem = _session.roleProblem(r.user);
        if (problem != null) {
          // Wrong account type for this app: revoke the token we were just given.
          _session.api.client.token = r.token;
          try {
            await _session.api.logout();
          } catch (_) {}
          _session.api.client.token = null;
          setState(() {
            _error = problem;
            _step = LoginStep.welcome;
          });
          return;
        }
        await _session.signedIn(r.token, r.user);
      } on ApiException catch (e) {
        if (e.body['needs_name'] == true || e.body['needs_email'] == true) {
          setState(() {
            _isNew = true;
            _step = LoginStep.details;
            _info = 'Add your name and email to finish creating your account.';
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

  void _go(LoginStep step) => setState(() {
        _step = step;
        _error = null;
        _info = null;
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
                    Icon(widget.icon, size: 72, color: widget.iconColor),
                    const SizedBox(height: 8),
                    Text(widget.title, textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.bold, color: Brand.charcoal)),
                    const SizedBox(height: 4),
                    Text(widget.tagline, textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
                    const SizedBox(height: 32),
                    if (_info != null) ...[
                      Container(
                        key: const Key('info'),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(color: Brand.blue.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10)),
                        child: Text(_info!),
                      ),
                      const SizedBox(height: 16),
                    ],
                    ...switch (_step) {
                      LoginStep.welcome => _welcome(),
                      LoginStep.phone => _phoneStep(),
                      LoginStep.details => _detailsStep(),
                      LoginStep.code => _codeStep(),
                    },
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(_error!, key: const Key('error'), style: const TextStyle(color: Brand.red)),
                    ],
                    if (_step != LoginStep.welcome) ...[
                      const SizedBox(height: 24),
                      FilledButton(
                        key: const Key('continue'),
                        onPressed: _busy
                            ? null
                            : switch (_step) {
                                LoginStep.phone => _continueSignIn,
                                LoginStep.details => _continueCreate,
                                _ => _verify,
                              },
                        child: _busy
                            ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                            : Text(switch (_step) {
                                LoginStep.phone => 'Continue',
                                LoginStep.details => 'Create account',
                                _ => _isNew ? 'Verify and finish' : 'Sign in',
                              }),
                      ),
                      TextButton(
                        key: const Key('back'),
                        onPressed: _busy ? null : () => _go(_step == LoginStep.code ? (_isNew ? LoginStep.details : LoginStep.phone) : LoginStep.welcome),
                        child: Text(_step == LoginStep.code ? 'Change number or resend code' : 'Back'),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _welcome() => [
        FilledButton(key: const Key('create-account'), onPressed: () => _go(LoginStep.details), child: const Text('Create an account')),
        const SizedBox(height: 12),
        OutlinedButton(
          key: const Key('sign-in'),
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
          onPressed: () => _go(LoginStep.phone),
          child: const Text('I already have an account — sign in', style: TextStyle(fontSize: 16)),
        ),
      ];

  Widget _phoneField({VoidCallback? onSubmitted}) => TextField(
        key: const Key('phone'),
        controller: _phone,
        keyboardType: TextInputType.phone,
        autofillHints: const [AutofillHints.telephoneNumber],
        decoration: const InputDecoration(hintText: 'Mobile number, e.g. 246 555 1234', prefixIcon: Icon(Icons.phone)),
        onSubmitted: onSubmitted == null ? null : (_) => onSubmitted(),
      );

  List<Widget> _phoneStep() => [
        const Text('Sign in with your mobile number', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 8),
        _phoneField(onSubmitted: _continueSignIn),
        const SizedBox(height: 8),
        const Text('We\'ll send you a code by text and email.', style: TextStyle(color: Colors.black54, fontSize: 13)),
      ];

  List<Widget> _detailsStep() => [
        const Text('Create your account', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 16)),
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
          textInputAction: TextInputAction.next,
          decoration: const InputDecoration(hintText: 'Email address', prefixIcon: Icon(Icons.email_outlined)),
        ),
        const SizedBox(height: 12),
        _phoneField(onSubmitted: _continueCreate),
        const SizedBox(height: 8),
        const Text('We\'ll send a code to your phone and email to confirm them.', style: TextStyle(color: Colors.black54, fontSize: 13)),
        const SizedBox(height: 6),
        Wrap(key: const Key('signup-legal'), crossAxisAlignment: WrapCrossAlignment.center, children: [
          const Text('By creating an account you agree to the ', style: TextStyle(color: Colors.black54, fontSize: 13)),
          InkWell(onTap: () => LegalLinks.open(_session.api.client, 'terms'), child: const Text('Terms of Use', style: TextStyle(color: Brand.blue, fontSize: 13))),
          const Text(' and ', style: TextStyle(color: Colors.black54, fontSize: 13)),
          InkWell(onTap: () => LegalLinks.open(_session.api.client, 'privacy'), child: const Text('Privacy Policy', style: TextStyle(color: Brand.blue, fontSize: 13))),
          const Text('.', style: TextStyle(color: Colors.black54, fontSize: 13)),
        ]),
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
