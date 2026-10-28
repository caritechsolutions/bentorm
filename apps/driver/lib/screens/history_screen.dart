import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../state/scope.dart';

class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  final List<DriverTrip> _trips = [];
  int _page = 0;
  bool _hasMore = true;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _more());
  }

  Future<void> _more() async {
    if (_loading || !_hasMore) return;
    setState(() => _loading = true);
    try {
      final r = await AppScope.of(context).api.history(page: _page + 1);
      if (!mounted) return;
      setState(() {
        _page++;
        _trips.addAll(r.trips);
        _hasMore = r.hasMore;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _date(DateTime? d) {
    if (d == null) return '';
    const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
    return '${d.day} ${m[d.month - 1]}, $h:${d.minute.toString().padLeft(2, '0')} ${d.hour < 12 ? 'am' : 'pm'}';
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Trip history')),
        body: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            if (n.metrics.pixels > n.metrics.maxScrollExtent - 200) _more();
            return false;
          },
          child: ListView.separated(
            itemCount: _trips.length + 1,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              if (i == _trips.length) {
                if (_error != null) return Padding(padding: const EdgeInsets.all(24), child: Text(_error!));
                if (_loading) return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
                if (_trips.isEmpty) return const Padding(padding: EdgeInsets.all(24), child: Text('No trips yet.'));
                return const SizedBox.shrink();
              }
              final t = _trips[i];
              final cur = t.currency.isEmpty ? null : t.currency;
              return ListTile(
                title: Text(t.dropoff.address ?? 'Trip', maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text('${_date(t.requestedAt)} · ${t.riderName ?? ''} · ${t.status == 'completed' ? (t.isCash ? 'cash' : 'card') : t.status}'
                    '${t.earningsPaidTo != null ? ' · paid to ${t.earningsPaidTo}' : ''}'),
                trailing: Text(t.earnings != null ? money(t.earnings, cur) : '', style: const TextStyle(fontWeight: FontWeight.bold)),
              );
            },
          ),
        ),
      );
}
