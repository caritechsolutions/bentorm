import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../state/scope.dart';

class EarningsScreen extends StatefulWidget {
  const EarningsScreen({super.key});

  @override
  State<EarningsScreen> createState() => _EarningsScreenState();
}

class _EarningsScreenState extends State<EarningsScreen> {
  Earnings? _e;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      try {
        final e = await AppScope.of(context).api.earnings();
        if (mounted) setState(() => _e = e);
      } on ApiException catch (err) {
        if (mounted) setState(() => _error = err.message);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final e = _e;
    return Scaffold(
      appBar: AppBar(title: const Text('Earnings')),
      body: SingleChildScrollView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (_error != null) Text(_error!, style: const TextStyle(color: Brand.red)),
        if (e == null && _error == null) const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
        if (e != null) ...[
          for (final (label, p) in [('Today', e.today), ('This week', e.week), ('This month', e.month)])
            Card(
              key: Key('earnings-$label'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(label, style: const TextStyle(color: Colors.black54)),
                  Text(money(p.earnings, e.currency), style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold)),
                  Text('${p.trips} ${p.trips == 1 ? 'trip' : 'trips'} · cash collected ${money(p.cash, e.currency)}'),
                ]),
              ),
            ),
          const SizedBox(height: 8),
          const Text('Earnings are your share after the Ride Coop fee, before tax. Cash you collected is already in your pocket.',
              style: TextStyle(color: Colors.black54, fontSize: 13)),
        ],
      ])),
    );
  }
}
