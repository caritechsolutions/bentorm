import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';
import 'trip_screen.dart';

class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  final List<Trip> _trips = [];
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
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
    return '${d.day} ${months[d.month - 1]} ${d.year}, $h:${d.minute.toString().padLeft(2, '0')} ${d.hour < 12 ? 'am' : 'pm'}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Your trips')),
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
            return ListTile(
              title: Text(t.dropoff.address ?? 'Trip', maxLines: 1, overflow: TextOverflow.ellipsis),
              subtitle: Text('${_date(t.requestedAt)} · ${t.statusTitle}'),
              trailing: Text(t.status == 'completed' ? money(t.fare, t.currency) : (t.cancellationFee > 0 ? money(t.cancellationFee, t.currency) : '')),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => TripScreen(initial: t))),
            );
          },
        ),
      ),
    );
  }
}
