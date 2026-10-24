import 'dart:async';

import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/models.dart';
import '../../api/shop_models.dart';
import '../../brand.dart';
import '../../config.dart';
import '../../state/scope.dart';
import 'shop_widgets.dart';
import 'store_screen.dart';

/// Shop home: categories, search, and the stores that deliver to the customer.
class ShopScreen extends StatefulWidget {
  const ShopScreen({super.key});

  @override
  State<ShopScreen> createState() => _ShopScreenState();
}

class _ShopScreenState extends State<ShopScreen> {
  LatLngPoint? _here;
  List<ShopCategory> _categories = const [];
  List<StoreSummary>? _stores;
  String? _category;
  String? _error;
  final _search = TextEditingController();
  Timer? _debounce;
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _init());
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  Future<void> _init() async {
    final scope = AppScope.of(context);
    _here = await scope.location.current() ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng);
    if (!mounted) return;
    try {
      final cats = await scope.api.shopCategories(lat: _here!.lat, lng: _here!.lng);
      if (mounted) setState(() => _categories = cats.where((c) => c.stores > 0).toList());
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
    await _load();
  }

  Future<void> _load() async {
    final seq = ++_seq;
    setState(() => _error = null);
    try {
      final stores = await AppScope.of(context).api.shopStores(category: _category, q: _search.text, lat: _here?.lat, lng: _here?.lng);
      if (mounted && seq == _seq) setState(() => _stores = stores);
    } on ApiException catch (e) {
      if (mounted && seq == _seq) setState(() => _error = e.message);
    }
  }

  void _searchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), _load);
  }

  void _pick(String? slug) {
    setState(() {
      _category = slug;
      _stores = null;
    });
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final stores = _stores;
    return Scaffold(
      appBar: AppBar(title: const Text('Shop')),
      bottomNavigationBar: const CartBar(),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.only(bottom: 24),
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
              child: TextField(
                key: const Key('shop-search'),
                controller: _search,
                onChanged: _searchChanged,
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(hintText: 'Search stores and items', prefixIcon: Icon(Icons.search), isDense: true),
              ),
            ),
            if (_categories.isNotEmpty)
              SizedBox(
                height: 48,
                child: ListView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  children: [
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: ChoiceChip(key: const Key('category-all'), label: const Text('All'), selected: _category == null, onSelected: (_) => _pick(null)),
                    ),
                    for (final c in _categories)
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 4),
                        child: ChoiceChip(
                          key: Key('category-${c.slug}'),
                          label: Text('${c.icon ?? ''} ${c.name}'.trim()),
                          selected: _category == c.slug,
                          onSelected: (_) => _pick(c.slug),
                        ),
                      ),
                  ],
                ),
              ),
            if (_error != null) Padding(padding: const EdgeInsets.all(24), child: Text(_error!, style: const TextStyle(color: Brand.red))),
            if (stores == null && _error == null) const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
            if (stores != null && stores.isEmpty)
              Padding(
                padding: const EdgeInsets.all(32),
                child: Column(children: [
                  const Icon(Icons.storefront_outlined, size: 56, color: Colors.black26),
                  const SizedBox(height: 12),
                  Text(_search.text.trim().isNotEmpty ? 'Nothing matches "${_search.text.trim()}".' : 'No stores deliver to you yet.',
                      textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
                ]),
              ),
            if (stores != null)
              for (final s in stores) _StoreTile(store: s),
          ],
        ),
      ),
    );
  }
}

class _StoreTile extends StatelessWidget {
  const _StoreTile({required this.store});
  final StoreSummary store;

  @override
  Widget build(BuildContext context) {
    final closed = !store.openNow;
    final tooFar = store.deliversHere == false;
    return InkWell(
      key: Key('store-${store.slug}'),
      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => StoreScreen(slug: store.slug, summary: store))),
      child: Opacity(
        opacity: closed || tooFar ? 0.55 : 1,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          child: Row(children: [
            ShopImage(url: store.logoUrl, size: 64),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(store.name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                if (store.description != null)
                  Text(store.description!, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.black54)),
                const SizedBox(height: 2),
                Text(tooFar ? 'Doesn\'t deliver to you' : store.facts, style: TextStyle(fontSize: 13, color: closed || tooFar ? Brand.red : Colors.black54)),
              ]),
            ),
            const Icon(Icons.chevron_right, color: Colors.black26),
          ]),
        ),
      ),
    );
  }
}
