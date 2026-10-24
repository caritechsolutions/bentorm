import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/api_client.dart';
import '../../api/models.dart';
import '../../api/shop_models.dart';
import '../../brand.dart';
import '../../config.dart';
import '../../state/cart.dart';
import '../../state/scope.dart';
import '../cards_screen.dart';
import '../search_screen.dart';
import 'order_screen.dart';
import 'shop_widgets.dart';

/// Cart + checkout: what's in it, where it goes, which card, and the priced total from the server.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  /// Opens the bank's 3-D Secure page (replaced in tests).
  static Future<void> Function(Uri) openUrl = (uri) => launchUrl(uri, mode: LaunchMode.externalApplication);

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  LatLngPoint? _me;
  Place? _dropoff;
  List<PaymentCard> _cards = const [];
  String? _cardId;
  ShopQuote? _quote;
  String? _quoteError;
  bool _quoting = false;
  bool _placing = false;
  final _note = TextEditingController();
  int _seq = 0;
  double _tip = 0;
  String? _promo;
  bool _useCredit = false;
  double _creditBalance = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _init());
  }

  Cart? _cart;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_cart == null) {
      _cart = AppScope.of(context).cart;
      _cart!.addListener(_cartChanged);
    }
  }

  @override
  void dispose() {
    _cart?.removeListener(_cartChanged);
    _note.dispose();
    super.dispose();
  }

  Future<void> _init() async {
    final scope = AppScope.of(context);
    _loadCards();
    _loadCredit();
    final me = await scope.location.current();
    final point = me ?? const LatLngPoint(AppConfig.defaultLat, AppConfig.defaultLng);
    final here = await scope.api.reverse(point.lat, point.lng);
    if (!mounted) return;
    setState(() {
      _me = me;
      _dropoff ??= here ?? Place(name: me != null ? 'Current location' : 'Bridgetown', lat: point.lat, lng: point.lng);
    });
    _price();
  }

  void _cartChanged() {
    if (!mounted) return;
    if (_cart!.isEmpty) {
      Navigator.of(context).maybePop();
      return;
    }
    _price();
  }

  Future<void> _loadCredit() async {
    try {
      final c = await AppScope.of(context).api.credit();
      if (mounted) setState(() => _creditBalance = c.balance);
    } on ApiException {
      // No credit shown.
    }
  }

  Future<void> _loadCards() async {
    try {
      final cards = (await AppScope.of(context).api.cards()).where((c) => c.usable).toList();
      if (!mounted) return;
      setState(() {
        _cards = cards;
        if (!cards.any((c) => c.id == _cardId)) _cardId = (cards.where((c) => c.isDefault).firstOrNull ?? cards.firstOrNull)?.id;
      });
    } on ApiException {
      // Shown as "Add a card".
    }
  }

  Future<void> _price() async {
    final cart = AppScope.of(context).cart;
    final to = _dropoff;
    if (cart.isEmpty || cart.store == null || to == null) return;
    final seq = ++_seq;
    setState(() {
      _quoting = true;
      _quoteError = null;
    });
    try {
      final q = await AppScope.of(context)
          .api
          .shopQuote(cart.store!.slug, cart.toJson(), to.lat, to.lng, tip: _tip, promoCode: _promo, useCredit: _useCredit && _creditBalance > 0);
      if (mounted && seq == _seq) setState(() => _quote = q);
    } on ApiException catch (e) {
      if (mounted && seq == _seq) {
        setState(() {
          _quote = null;
          _quoteError = _firstError(e);
        });
      }
    } finally {
      if (mounted && seq == _seq) setState(() => _quoting = false);
    }
  }

  static String _firstError(ApiException e) {
    final errors = e.body['errors'];
    if (errors is Map) {
      for (final v in errors.values) {
        if (v is List && v.isNotEmpty) return v.first.toString();
      }
    }
    return e.message;
  }

  Future<void> _chooseAddress() async {
    final scope = AppScope.of(context);
    final here = _me == null ? null : await scope.api.reverse(_me!.lat, _me!.lng);
    if (!mounted) return;
    final place = await Navigator.of(context).push<Place>(MaterialPageRoute(
      builder: (_) => SearchScreen(
        title: 'Deliver to',
        near: _me ?? (_dropoff == null ? null : LatLngPoint(_dropoff!.lat, _dropoff!.lng)),
        currentLocation: _me == null ? null : (here ?? Place(name: 'Current location', lat: _me!.lat, lng: _me!.lng)),
      ),
    ));
    if (place != null && mounted) {
      setState(() => _dropoff = place);
      _price();
    }
  }

  Future<void> _chooseCard() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          for (final c in _cards)
            ListTile(
              leading: const Icon(Icons.credit_card),
              title: Text(c.label),
              subtitle: Text('Expires ${c.expiry}'),
              trailing: _cardId == c.id ? const Icon(Icons.check, color: Brand.blue) : null,
              onTap: () => Navigator.pop(ctx, c.id),
            ),
          ListTile(leading: const Icon(Icons.add_card), title: const Text('Add or manage cards'), onTap: () => Navigator.pop(ctx, '_manage')),
        ]),
      ),
    );
    if (!mounted || choice == null) return;
    if (choice == '_manage') {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CardsScreen()));
      await _loadCards();
    } else {
      setState(() => _cardId = choice);
    }
  }

  Future<void> _place() async {
    final scope = AppScope.of(context);
    final cart = scope.cart;
    if (_cardId == null) {
      await _chooseCard();
      return;
    }
    setState(() => _placing = true);
    try {
      final order = await scope.api.placeOrder(
        store: cart.store!.slug,
        items: cart.toJson(),
        dropoff: _dropoff!,
        cardId: _cardId!,
        note: _note.text,
        tip: _tip,
        promoCode: _promo,
        useCredit: _useCredit && _creditBalance > 0,
      );
      if (!mounted) return;
      if (order.status == 'cancelled') {
        // The bank said no straight away: keep the cart so they can try another card.
        _snack(order.declineReason ?? 'Your card wasn\'t accepted. Try another card.');
        return;
      }
      cart.removeListener(_cartChanged);
      cart.clear();
      if (order.needsBank) {
        await CheckoutScreen.openUrl(Uri.parse(order.redirectUrl!));
      }
      if (!mounted) return;
      await Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => OrderScreen(initial: order)));
    } on ApiException catch (e) {
      if (!mounted) return;
      _snack(_firstError(e));
      _price();
    } finally {
      if (mounted) setState(() => _placing = false);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  Future<void> _enterPromo() async {
    final api = AppScope.of(context).api;
    final ctl = TextEditingController(text: _promo ?? '');
    String? error;
    var checking = false;
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setD) => AlertDialog(
          title: const Text('Promo code'),
          content: TextField(
            key: const Key('promo-input'),
            controller: ctl,
            autofocus: true,
            textCapitalization: TextCapitalization.characters,
            decoration: InputDecoration(hintText: 'Code', errorText: error),
          ),
          actions: [
            if (_promo != null) TextButton(onPressed: () => Navigator.pop(ctx, ''), child: const Text('Remove')),
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
            FilledButton(
              key: const Key('promo-apply'),
              onPressed: checking
                  ? null
                  : () async {
                      if (ctl.text.trim().isEmpty) return;
                      setD(() {
                        checking = true;
                        error = null;
                      });
                      try {
                        final p = await api.checkPromo(ctl.text, forWhat: 'shop', amount: _quote?.totals.items);
                        if (ctx.mounted) Navigator.pop(ctx, p.code);
                      } on ApiException catch (e) {
                        setD(() => error = e.field('code') ?? e.message);
                      } finally {
                        if (ctx.mounted) setD(() => checking = false);
                      }
                    },
              child: const Text('Apply'),
            ),
          ],
        ),
      ),
    );
    if (!mounted || result == null) return;
    setState(() => _promo = result.isEmpty ? null : result);
    _price();
  }

  List<Widget> _extras(String currency) {
    final cfg = AppScope.of(context).config;
    return [
      if (cfg.on('tips')) ...[
        const Text('Tip your driver (100% to them)', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        Wrap(spacing: 8, children: [
          for (final a in const [0.0, 2.0, 5.0, 10.0])
            ChoiceChip(
              key: Key('checkout-tip-${a.toStringAsFixed(0)}'),
              label: Text(a == 0 ? 'No tip' : money(a, currency)),
              selected: _tip == a,
              onSelected: (_) {
                setState(() => _tip = a);
                _price();
              },
            ),
        ]),
      ],
      if (cfg.on('promos'))
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            key: const Key('checkout-promo'),
            onPressed: _enterPromo,
            icon: const Icon(Icons.local_offer_outlined, size: 18),
            label: Text(_promo == null ? 'Add a promo code' : 'Promo $_promo${_quote?.promo != null ? ' · ${_quote!.promo}' : ''}'),
          ),
        ),
      if (_creditBalance > 0)
        SwitchListTile(
          key: const Key('checkout-credit'),
          contentPadding: EdgeInsets.zero,
          dense: true,
          title: Text('Use ride credit (${money(_creditBalance, currency)})'),
          value: _useCredit,
          onChanged: (v) {
            setState(() => _useCredit = v);
            _price();
          },
        ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final cart = AppScope.of(context).cart;
    return Scaffold(
      appBar: AppBar(title: const Text('Your cart')),
      body: ListenableBuilder(
        listenable: cart,
        builder: (context, _) {
          final store = cart.store;
          if (store == null || cart.isEmpty) return const Center(child: Text('Your cart is empty.'));
          final q = _quote;
          final currency = q?.currency ?? store.currency;
          final card = _cards.where((c) => c.id == _cardId).firstOrNull;
          return Column(children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                children: [
                  Text(store.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  for (var i = 0; i < cart.lines.length; i++)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(children: [
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(cart.lines[i].product.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                            if (cart.lines[i].optionsText.isNotEmpty) Text(cart.lines[i].optionsText, style: const TextStyle(fontSize: 13, color: Colors.black54)),
                            if ((cart.lines[i].note ?? '').trim().isNotEmpty)
                              Text('“${cart.lines[i].note!.trim()}”', style: const TextStyle(fontSize: 13, color: Colors.black54, fontStyle: FontStyle.italic)),
                            Text(money(cart.lines[i].total, currency), style: const TextStyle(fontSize: 13)),
                          ]),
                        ),
                        QtyStepper(value: cart.lines[i].quantity, keyPrefix: 'line-$i', onChanged: (v) => cart.setQuantity(cart.lines[i], v)),
                      ]),
                    ),
                  if (cart.belowMinimum)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text('Add ${money(store.minOrder - cart.subtotal, currency)} more to reach the ${money(store.minOrder, currency)} minimum.',
                          key: const Key('below-minimum'), style: const TextStyle(color: Brand.red)),
                    ),
                  const Divider(height: 28),
                  ListTile(
                    key: const Key('deliver-to'),
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.place, color: Brand.red),
                    title: const Text('Deliver to', style: TextStyle(fontSize: 13, color: Colors.black54)),
                    subtitle: Text(_dropoff?.label ?? 'Finding your location…', style: const TextStyle(fontSize: 15, color: Brand.charcoal)),
                    trailing: const Icon(Icons.edit_outlined, size: 18),
                    onTap: _chooseAddress,
                  ),
                  TextField(
                    key: const Key('order-note'),
                    controller: _note,
                    maxLength: 500,
                    decoration: const InputDecoration(hintText: 'Directions for the driver (gate colour, apartment…)', isDense: true, counterText: ''),
                  ),
                  ListTile(
                    key: const Key('order-card'),
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.credit_card),
                    title: Text(card?.label ?? 'Add a card'),
                    subtitle: const Text('Card only. We hold the total now and charge it when your order is delivered.'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: _chooseCard,
                  ),
                  ..._extras(currency),
                  const Divider(height: 28),
                  if (_quoting) const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator())),
                  if (!_quoting && _quoteError != null)
                    Text(_quoteError!, key: const Key('quote-error'), style: const TextStyle(color: Brand.red)),
                  if (!_quoting && q != null) ...[
                    for (final l in q.lines)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 3),
                        child: Row(children: [Expanded(child: Text(l.label)), Text(money(l.amount, currency))]),
                      ),
                    const SizedBox(height: 6),
                    Row(children: [
                      const Expanded(child: Text('Total', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 17))),
                      Text(money(q.totals.total, currency), key: const Key('order-total'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 17)),
                    ]),
                    if (q.etaMinutes != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Text('Arrives in about ${q.etaMinutes} min', style: const TextStyle(color: Colors.black54)),
                      ),
                  ],
                ],
              ),
            ),
            SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: FilledButton(
                  key: const Key('place-order'),
                  onPressed: q == null || _quoting || _placing || cart.belowMinimum ? null : _place,
                  child: _placing
                      ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                      : Text(q == null ? 'Place order' : (_cardId == null ? 'Add a card to order' : 'Place order · ${money(q.totals.total, currency)}')),
                ),
              ),
            ),
          ]);
        },
      ),
    );
  }
}
