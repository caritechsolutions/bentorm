import 'package:flutter/foundation.dart';

import '../api/shop_models.dart';

/// One product in the cart with the options chosen for it.
class CartLine {
  CartLine({required this.product, required this.quantity, required this.choices, this.note});

  final Product product;
  int quantity;

  /// Group name → chosen choice names.
  final Map<String, List<String>> choices;
  final String? note;

  double get unitPrice {
    var p = product.price;
    for (final g in product.options) {
      for (final c in g.choices) {
        if (choices[g.name]?.contains(c.name) ?? false) p += c.price;
      }
    }
    return p;
  }

  double get total => unitPrice * quantity;

  String get optionsText => [
        for (final g in product.options)
          if ((choices[g.name] ?? const []).isNotEmpty) '${g.name}: ${choices[g.name]!.join(', ')}',
      ].join(' · ');

  /// Same product with the same choices and note: add to it instead of a new line.
  bool sameAs(CartLine o) =>
      o.product.id == product.id && (o.note ?? '') == (note ?? '') && optionsText == o.optionsText;

  Map<String, dynamic> toJson() => {
        'product_id': product.id,
        'quantity': quantity,
        if (choices.values.any((c) => c.isNotEmpty))
          'options': [
            for (final e in choices.entries)
              if (e.value.isNotEmpty) {'group': e.key, 'choices': e.value},
          ],
        if (note != null && note!.trim().isNotEmpty) 'note': note!.trim(),
      };
}

/// The shopping cart: one store at a time, kept while the app runs.
class Cart extends ChangeNotifier {
  StoreSummary? store;
  final List<CartLine> lines = [];

  bool get isEmpty => lines.isEmpty;
  int get count => lines.fold(0, (n, l) => n + l.quantity);
  double get subtotal => lines.fold(0.0, (s, l) => s + l.total);
  bool get belowMinimum => store != null && subtotal < store!.minOrder;

  /// True when [s] is another store and the cart already has items (the caller asks before replacing).
  bool isOtherStore(StoreSummary s) => store != null && store!.slug != s.slug && lines.isNotEmpty;

  void add(StoreSummary s, CartLine line) {
    if (store?.slug != s.slug) lines.clear();
    store = s;
    final same = lines.where((l) => l.sameAs(line)).firstOrNull;
    if (same != null) {
      same.quantity += line.quantity;
    } else {
      lines.add(line);
    }
    notifyListeners();
  }

  void setQuantity(CartLine line, int quantity) {
    if (quantity <= 0) {
      lines.remove(line);
    } else {
      line.quantity = quantity;
    }
    notifyListeners();
  }

  void clear() {
    lines.clear();
    store = null;
    notifyListeners();
  }

  List<Map<String, dynamic>> toJson() => [for (final l in lines) l.toJson()];
}
