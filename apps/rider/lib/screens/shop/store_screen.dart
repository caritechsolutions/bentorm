import 'package:flutter/material.dart';

import '../../api/api_client.dart';
import '../../api/shop_models.dart';
import '../../brand.dart';
import '../../state/cart.dart';
import '../../state/scope.dart';
import 'shop_widgets.dart';

/// One store's menu. Tap a product to choose options and add it to the cart.
class StoreScreen extends StatefulWidget {
  const StoreScreen({super.key, required this.slug, this.summary});
  final String slug;
  final StoreSummary? summary;

  @override
  State<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends State<StoreScreen> {
  StoreMenu? _menu;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final scope = AppScope.of(context);
    final here = await scope.location.current();
    try {
      final m = await scope.api.shopStore(widget.slug, lat: here?.lat, lng: here?.lng);
      if (mounted) setState(() => _menu = m);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.status == 404 ? 'This store isn\'t available right now.' : e.message);
    }
  }

  Future<void> _open(Product p) async {
    final menu = _menu!;
    if (!p.available) return;
    final cart = AppScope.of(context).cart;
    final line = await showModalBottomSheet<CartLine>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => ProductSheet(product: p, currency: menu.store.currency, canOrder: menu.store.openNow),
    );
    if (line == null || !mounted) return;
    if (cart.isOtherStore(menu.store)) {
      final replace = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Start a new cart?'),
          content: Text('Your cart has items from ${cart.store!.name}. You can order from one store at a time.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Keep my cart')),
            TextButton(key: const Key('new-cart'), onPressed: () => Navigator.pop(ctx, true), child: const Text('New cart')),
          ],
        ),
      );
      if (replace != true) return;
    }
    cart.add(menu.store, line);
    if (mounted) {
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text('Added ${line.quantity} × ${p.name}'), duration: const Duration(seconds: 2)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final menu = _menu;
    final s = menu?.store ?? widget.summary;
    return Scaffold(
      appBar: AppBar(title: Text(s?.name ?? 'Store')),
      bottomNavigationBar: const CartBar(),
      body: _error != null
          ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!, textAlign: TextAlign.center)))
          : menu == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(
                  padding: const EdgeInsets.only(bottom: 24),
                  children: [
                    if (menu.store.coverUrl != null)
                      Image.network(menu.store.coverUrl!, height: 160, fit: BoxFit.cover, errorBuilder: (_, __, ___) => const SizedBox.shrink()),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        ShopImage(url: menu.store.logoUrl, size: 56),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(menu.store.name, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
                            if (menu.store.description != null) Text(menu.store.description!, style: const TextStyle(color: Colors.black54)),
                            const SizedBox(height: 4),
                            Text(
                              [
                                menu.store.facts,
                                if (menu.store.minOrder > 0) 'Minimum order ${money(menu.store.minOrder, menu.store.currency)}',
                              ].join(' · '),
                              style: const TextStyle(fontSize: 13, color: Colors.black54),
                            ),
                          ]),
                        ),
                      ]),
                    ),
                    if (!menu.store.openNow)
                      Container(
                        key: const Key('store-closed'),
                        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(color: Brand.red.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(12)),
                        child: const Text('This store is closed right now. You can look at the menu, but not order.', style: TextStyle(color: Brand.red)),
                      ),
                    if (menu.store.deliversHere == false)
                      Container(
                        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(12)),
                        child: const Text('This store doesn\'t deliver to where you are now. You can still order to an address it covers.'),
                      ),
                    for (final section in menu.sections) ...[
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 20, 16, 4),
                        child: Text(section.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      ),
                      for (final p in section.products) _ProductTile(product: p, currency: menu.store.currency, onTap: () => _open(p)),
                    ],
                    if (menu.sections.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Text('Nothing on the menu yet.')),
                  ],
                ),
    );
  }
}

class _ProductTile extends StatelessWidget {
  const _ProductTile({required this.product, required this.currency, required this.onTap});
  final Product product;
  final String currency;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => InkWell(
        key: Key('product-${product.id}'),
        onTap: product.available ? onTap : null,
        child: Opacity(
          opacity: product.available ? 1 : 0.45,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(product.name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 16)),
                  if (product.description != null)
                    Text(product.description!, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.black54)),
                  const SizedBox(height: 4),
                  Text(product.available ? money(product.price, currency) : 'Sold out', style: const TextStyle(fontWeight: FontWeight.w600)),
                ]),
              ),
              if (product.photoUrl != null) ...[const SizedBox(width: 12), ShopImage(url: product.photoUrl, size: 76, icon: Icons.fastfood)],
            ]),
          ),
        ),
      );
}

/// Choose options, quantity and a note; pops with the [CartLine].
class ProductSheet extends StatefulWidget {
  const ProductSheet({super.key, required this.product, required this.currency, this.canOrder = true});
  final Product product;
  final String currency;
  final bool canOrder;

  @override
  State<ProductSheet> createState() => _ProductSheetState();
}

class _ProductSheetState extends State<ProductSheet> {
  final Map<String, List<String>> _choices = {};
  final _note = TextEditingController();
  int _qty = 1;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  CartLine get _line => CartLine(
        product: widget.product,
        quantity: _qty,
        choices: {for (final e in _choices.entries) e.key: List.of(e.value)},
        note: _note.text,
      );

  List<OptionGroup> get _missing => [for (final g in widget.product.options) if (g.required && (_choices[g.name] ?? const []).isEmpty) g];

  void _toggle(OptionGroup g, OptionChoice c) {
    setState(() {
      final list = _choices.putIfAbsent(g.name, () => []);
      if (g.multiple) {
        list.contains(c.name) ? list.remove(c.name) : list.add(c.name);
      } else {
        list
          ..clear()
          ..add(c.name);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.product;
    final missing = _missing;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.85),
        child: SafeArea(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Flexible(
              child: ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.symmetric(horizontal: 16),
                children: [
                  if (p.photoUrl != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: Image.network(p.photoUrl!, height: 180, fit: BoxFit.cover, errorBuilder: (_, __, ___) => const SizedBox.shrink()),
                      ),
                    ),
                  Text(p.name, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold)),
                  if (p.description != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(p.description!, style: const TextStyle(color: Colors.black54))),
                  const SizedBox(height: 4),
                  Text(money(p.price, widget.currency), style: const TextStyle(fontWeight: FontWeight.w600)),
                  for (final g in p.options) ...[
                    const SizedBox(height: 16),
                    Row(children: [
                      Expanded(child: Text(g.name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16))),
                      Text(g.required ? (g.multiple ? 'Required' : 'Choose 1') : (g.multiple ? 'Optional' : 'Optional, pick 1'),
                          style: TextStyle(fontSize: 12, color: g.required ? Brand.red : Colors.black45)),
                    ]),
                    for (final c in g.choices)
                      g.multiple
                          ? CheckboxListTile(
                              key: Key('choice-${g.name}-${c.name}'),
                              contentPadding: EdgeInsets.zero,
                              dense: true,
                              value: _choices[g.name]?.contains(c.name) ?? false,
                              onChanged: (_) => _toggle(g, c),
                              title: Text(c.name),
                              secondary: c.price > 0 ? Text('+${money(c.price, widget.currency)}') : null,
                            )
                          : ListTile(
                              key: Key('choice-${g.name}-${c.name}'),
                              contentPadding: EdgeInsets.zero,
                              dense: true,
                              leading: Icon(
                                (_choices[g.name]?.contains(c.name) ?? false) ? Icons.radio_button_checked : Icons.radio_button_unchecked,
                                color: (_choices[g.name]?.contains(c.name) ?? false) ? Brand.blue : Colors.black38,
                              ),
                              title: Text(c.name),
                              trailing: c.price > 0 ? Text('+${money(c.price, widget.currency)}') : null,
                              onTap: () => _toggle(g, c),
                            ),
                  ],
                  const SizedBox(height: 12),
                  TextField(
                    key: const Key('item-note'),
                    controller: _note,
                    maxLength: 200,
                    decoration: const InputDecoration(hintText: 'Note for the store (optional)', isDense: true, counterText: ''),
                  ),
                  const SizedBox(height: 12),
                  Center(child: QtyStepper(value: _qty, min: 1, keyPrefix: 'sheet-qty', onChanged: (v) => setState(() => _qty = v))),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
              child: FilledButton(
                key: const Key('add-to-cart'),
                onPressed: !widget.canOrder || missing.isNotEmpty ? null : () => Navigator.pop(context, _line),
                child: Text(!widget.canOrder
                    ? 'Store is closed'
                    : missing.isNotEmpty
                        ? 'Choose ${missing.first.name}'
                        : 'Add $_qty to cart · ${money(_line.total, widget.currency)}'),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}
