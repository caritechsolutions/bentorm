import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/shop_models.dart';
import '../../brand.dart';
import '../../state/scope.dart';
import 'order_screen.dart';

/// Shop order history.
class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key});

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  final List<Order> _orders = [];
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
      final r = await AppScope.of(context).api.orders(page: _page + 1);
      if (!mounted) return;
      setState(() {
        _page++;
        _orders.addAll(r.orders);
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
    return '${d.day} ${months[d.month - 1]} ${d.year}';
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Your orders')),
        body: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            if (n.metrics.pixels > n.metrics.maxScrollExtent - 200) _more();
            return false;
          },
          child: ListView.separated(
            itemCount: _orders.length + 1,
            separatorBuilder: (_, __) => const Divider(height: 1),
            itemBuilder: (context, i) {
              if (i == _orders.length) {
                if (_error != null) return Padding(padding: const EdgeInsets.all(24), child: Text(_error!));
                if (_loading) return const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator()));
                if (_orders.isEmpty) return const Padding(padding: EdgeInsets.all(24), child: Text('No orders yet.'));
                return const SizedBox.shrink();
              }
              final o = _orders[i];
              return ListTile(
                key: Key('order-${o.id}'),
                title: Text(o.storeName),
                subtitle: Text('${_date(o.createdAt)} · ${o.statusLabel}'),
                trailing: Text(money(o.totals.total, o.currency)),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(initial: o))),
              );
            },
          ),
        ),
      );
}
