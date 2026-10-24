import 'package:flutter/material.dart';

import '../../brand.dart';
import '../../state/scope.dart';
import 'checkout_screen.dart';

/// Bottom bar shown while the cart has items: opens the checkout.
class CartBar extends StatelessWidget {
  const CartBar({super.key});

  @override
  Widget build(BuildContext context) {
    final cart = AppScope.of(context).cart;
    return ListenableBuilder(
      listenable: cart,
      builder: (context, _) {
        if (cart.isEmpty) return const SizedBox.shrink();
        final currency = cart.store?.currency;
        return SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Material(
              color: Brand.blue,
              borderRadius: BorderRadius.circular(14),
              child: InkWell(
                key: const Key('view-cart'),
                borderRadius: BorderRadius.circular(14),
                onTap: () {
                  ScaffoldMessenger.of(context).hideCurrentSnackBar(); // "Added …" would sit over the Place order button
                  Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CheckoutScreen()));
                },
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                  child: Row(children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(8)),
                      child: Text('${cart.count}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text('View cart · ${cart.store?.name ?? ''}',
                          maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 16)),
                    ),
                    Text(money(cart.subtotal, currency), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16)),
                  ]),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

/// A network picture, or a plain placeholder when there is none (or it fails to load).
class ShopImage extends StatelessWidget {
  const ShopImage({super.key, this.url, required this.size, this.icon = Icons.storefront, this.radius = 12});
  final String? url;
  final double size;
  final IconData icon;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final placeholder = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: const Color(0xFFF1F2F4), borderRadius: BorderRadius.circular(radius)),
      child: Icon(icon, color: Colors.black26, size: size * 0.45),
    );
    if (url == null || url!.isEmpty) return placeholder;
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: Image.network(url!, width: size, height: size, fit: BoxFit.cover, errorBuilder: (_, __, ___) => placeholder),
    );
  }
}

/// − n + control.
class QtyStepper extends StatelessWidget {
  const QtyStepper({super.key, required this.value, required this.onChanged, this.min = 0, this.keyPrefix = 'qty'});
  final int value;
  final int min;
  final ValueChanged<int> onChanged;
  final String keyPrefix;

  @override
  Widget build(BuildContext context) => Container(
        decoration: BoxDecoration(color: const Color(0xFFF5F6F7), borderRadius: BorderRadius.circular(20)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          IconButton(
            key: Key('$keyPrefix-minus'),
            visualDensity: VisualDensity.compact,
            icon: Icon(value <= 1 && min == 0 ? Icons.delete_outline : Icons.remove, size: 18),
            onPressed: value > min ? () => onChanged(value - 1) : null,
          ),
          Text('$value', key: Key('$keyPrefix-value'), style: const TextStyle(fontWeight: FontWeight.bold)),
          IconButton(
            key: Key('$keyPrefix-plus'),
            visualDensity: VisualDensity.compact,
            icon: const Icon(Icons.add, size: 18),
            onPressed: value < 50 ? () => onChanged(value + 1) : null,
          ),
        ]),
      );
}
