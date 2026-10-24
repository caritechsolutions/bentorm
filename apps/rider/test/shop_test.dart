import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_rider/screens/shop/checkout_screen.dart';
import 'package:ridecoop_rider/screens/shop/order_screen.dart';

import 'booking_test.dart' show signedInServer;
import 'support/fake_server.dart';

/// Shop fixtures come from the local backend (Ann's Fish Pot): fish with a required Side and optional Extras,
/// a sold-out marlin, and Mauby; minimum order BBD 10.
FakeServer shopServer() => signedInServer()
  ..on('GET /me/payment-methods', fixture('payment_methods_card'))
  ..on('GET /shop/orders/active', <dynamic>[])
  ..on('GET /shop/categories', fixture('shop_categories'))
  ..on('GET /shop/stores', fixture('shop_stores'))
  ..on('GET /shop/stores/anns-fish-pot', fixture('shop_store'))
  ..on('POST /shop/quote', fixture('shop_quote'));

int productId(String name) {
  for (final s in fixture('shop_store')['sections'] as List) {
    for (final p in s['products'] as List) {
      if (p['name'] == name) return p['id'] as int;
    }
  }
  throw StateError('no $name');
}

Future<void> openStore(WidgetTester tester) async {
  await tapKey(tester, 'mode-shop');
  expect(find.text("Ann's Fish Pot"), findsOneWidget);
  await tapKey(tester, 'store-anns-fish-pot');
  expect(find.text('Mains'), findsOneWidget);
}

Future<void> addFish(WidgetTester tester) async {
  await tapKey(tester, 'product-${productId('Fried flying fish')}');
  expect(find.text('Choose Side'), findsOneWidget, reason: 'Side is required');
  await tapKey(tester, 'choice-Side-Breadfruit');
  await tapKey(tester, 'choice-Extras-Pepper sauce');
  await tapKey(tester, 'sheet-qty-plus');
  expect(find.text('Add 2 to cart · BBD 52.00'), findsOneWidget, reason: '(22.50 + 3 + 0.50) × 2');
  await tapKey(tester, 'add-to-cart');
}

void main() {
  late Future<void> Function(Uri) realOpen;
  final opened = <Uri>[];
  setUp(() {
    realOpen = CheckoutScreen.openUrl;
    opened.clear();
    CheckoutScreen.openUrl = (uri) async => opened.add(uri);
    OrderScreen.pollEvery = const Duration(seconds: 5);
  });
  tearDown(() => CheckoutScreen.openUrl = realOpen);

  final orderId = fixture('shop_order_placed')['id'] as String;

  testWidgets('shop: store → options → cart → checkout → order placed → store accepts', (tester) async {
    final server = shopServer()..on('POST /shop/orders', fixture('shop_order_placed'), status: 201);
    var stage = 'shop_order_placed';
    server.handle('GET /shop/orders/$orderId', (_) => fixture(stage));
    await pumpApp(tester, server);
    await openStore(tester);

    expect(find.text('Sold out'), findsOneWidget, reason: 'marlin is not available');
    await addFish(tester);
    expect(find.byKey(const Key('view-cart')), findsOneWidget);
    expect(find.text('View cart · Ann\'s Fish Pot'), findsOneWidget);

    await tapKey(tester, 'view-cart');
    expect(find.text('Side: Breadfruit · Extras: Pepper sauce'), findsOneWidget);
    expect(find.textContaining('Delivery (12.2 km)'), findsOneWidget);
    expect(find.text('BBD 96.50'), findsWidgets);
    expect(find.text('visa •••• 4242'), findsOneWidget, reason: 'default card picked');
    final quote = server.calls('POST /shop/quote').last.body;
    expect(quote, contains('"Breadfruit"'));
    expect(quote, contains('"quantity":2'));

    await tester.enterText(find.byKey(const Key('order-note')), 'Blue gate');
    await tapKey(tester, 'place-order');
    final body = server.calls('POST /shop/orders').single.body;
    expect(body, contains('"payment_method_id":"${fixture('payment_methods_card')[0]['id']}"'));
    expect(body, contains('"note":"Blue gate"'));
    expect(body, contains('Bay Street'));

    expect(find.text("Waiting for Ann's Fish Pot to accept"), findsOneWidget);
    expect(find.byKey(const Key('cancel-order')), findsOneWidget);

    stage = 'shop_order_accepted';
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text("Ann's Fish Pot is preparing your order"), findsOneWidget);
    expect(find.byKey(const Key('cancel-order')), findsNothing, reason: 'too late to cancel once accepted');
    await unmount(tester);
  });

  testWidgets('store out of an item: the customer agrees and the new total shows', (tester) async {
    final server = shopServer()
      ..on('GET /shop/orders/active', [fixture('shop_order_change')])
      ..on('GET /shop/orders/$orderId', fixture('shop_order_change'))
      ..on('POST /shop/orders/$orderId/change', fixture('shop_order_change_accepted'));
    await pumpApp(tester, server);
    expect(find.byKey(Key('active-order-$orderId')), findsOneWidget);
    expect(find.text('The store needs your answer'), findsOneWidget);

    await tapKey(tester, 'active-order-$orderId');
    expect(find.byKey(const Key('order-change')), findsOneWidget);
    expect(find.textContaining('out of Mauby'), findsOneWidget);
    expect(find.textContaining('BBD 92.50'), findsOneWidget);

    server.on('GET /shop/orders/$orderId', fixture('shop_order_change_accepted'));
    await tapKey(tester, 'change-accept');
    expect(server.calls('POST /shop/orders/$orderId/change').single.body, contains('"accept":true'));
    expect(find.byKey(const Key('order-change')), findsNothing);
    expect(find.text('Out of stock — removed'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('cancel before the store answers releases the card', (tester) async {
    final cancelled = fixture('shop_order_cancelled') as Map<String, dynamic>;
    final placed = {...fixture('shop_order_placed') as Map<String, dynamic>, 'id': cancelled['id']};
    final server = shopServer()
      ..on('GET /shop/orders/active', [placed])
      ..on('GET /shop/orders/${cancelled['id']}', placed)
      ..on('POST /shop/orders/${cancelled['id']}/cancel', cancelled);
    await pumpApp(tester, server);
    await tapKey(tester, 'active-order-${cancelled['id']}');
    await tapKey(tester, 'cancel-order');
    await tester.tap(find.byKey(const Key('confirm-cancel-order')));
    await settle(tester);
    expect(server.calls('POST /shop/orders/${cancelled['id']}/cancel'), hasLength(1));
    expect(find.text('Order cancelled'), findsOneWidget);
    expect(find.textContaining('hold on your card is released'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('a card needing 3-D Secure opens the bank page', (tester) async {
    final secure = fixture('shop_order_3ds') as Map<String, dynamic>;
    final server = shopServer()
      ..on('POST /shop/orders', secure, status: 201)
      ..on('GET /shop/orders/${secure['id']}', secure);
    await pumpApp(tester, server);
    await openStore(tester);
    await addFish(tester);
    await tapKey(tester, 'view-cart');
    await tapKey(tester, 'place-order');
    expect(opened.single.toString(), secure['payment']['redirect_url']);
    expect(find.text('Confirm the payment with your bank'), findsOneWidget);
    expect(find.byKey(const Key('open-bank')), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('below the minimum order: the server reason shows and ordering is blocked', (tester) async {
    final server = shopServer()..on('POST /shop/quote', fixture('shop_quote_min_order'), status: 422);
    await pumpApp(tester, server);
    await openStore(tester);
    await tapKey(tester, 'product-${productId('Mauby')}');
    await tapKey(tester, 'add-to-cart');
    await tapKey(tester, 'view-cart');
    expect(find.byKey(const Key('below-minimum')), findsOneWidget);
    expect(find.text("Ann's Fish Pot has a minimum order of BBD 10.00."), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('place-order'))).onPressed, isNull);
    await unmount(tester);
  });

  testWidgets('declined orders and history', (tester) async {
    final server = shopServer()
      ..on('GET /shop/orders', fixture('shop_orders_history'))
      ..on('GET /shop/orders/${fixture('shop_order_declined')['id']}', fixture('shop_order_declined'));
    await pumpApp(tester, server);
    await tester.tap(find.byIcon(Icons.menu));
    await settle(tester);
    await tapKey(tester, 'drawer-orders');
    expect(find.textContaining('Declined by the store'), findsOneWidget, reason: 'status label in the list');
    await tapKey(tester, 'order-${fixture('shop_order_declined')['id']}');
    expect(find.text("Ann's Fish Pot couldn't take your order"), findsOneWidget);
    expect(find.textContaining('Kitchen closing early'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('delivery: driver assigned (PIN, call) → on the way → delivered', (tester) async {
    final id = fixture('shop_order_searching')['id'] as String;
    final pin = fixture('shop_order_driver_assigned')['delivery']['pin'] as String;
    var stage = 'shop_order_searching';
    final server = shopServer()
      ..on('GET /shop/orders/active', [fixture('shop_order_searching')])
      ..handle('GET /shop/orders/$id', (_) => fixture(stage));
    await pumpApp(tester, server);
    await tapKey(tester, 'active-order-$id');
    expect(find.byKey(const Key('order-driver')), findsNothing, reason: 'still looking');
    expect(find.byKey(const Key('delivery-pin')), findsOneWidget, reason: 'the PIN is known once the store accepted');

    stage = 'shop_order_driver_assigned';
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('Smoke will collect your order'), findsOneWidget);
    expect(find.text('Toyota Corolla · ZR101'), findsOneWidget);
    expect(find.text(pin.split('').join(' ')), findsOneWidget);
    await tapKey(tester, 'call-driver');
    expect(opened.single.toString(), 'tel:+12465550202');
    expect(find.byKey(const Key('cancel-order')), findsNothing);

    stage = 'shop_order_on_the_way';
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('Smoke is on the way with your order'), findsOneWidget);
    expect(find.byKey(const Key('delivery-pin-card')), findsOneWidget);

    stage = 'shop_order_delivered';
    await tester.pump(const Duration(seconds: 5));
    await settle(tester);
    expect(find.text('Delivered — enjoy!'), findsOneWidget);
    expect(find.byKey(const Key('delivery-pin-card')), findsNothing);
    expect(find.textContaining('charged'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('no driver for a long time: the customer is told and may cancel', (tester) async {
    final o = fixture('shop_order_no_driver') as Map<String, dynamic>;
    final server = shopServer()
      ..on('GET /shop/orders/active', [o])
      ..on('GET /shop/orders/${o['id']}', o)
      ..on('POST /shop/orders/${o['id']}/cancel', fixture('shop_order_cancelled_no_driver'));
    await pumpApp(tester, server);
    await tapKey(tester, 'active-order-${o['id']}');
    expect(find.text('Still looking for a driver'), findsOneWidget);
    expect(find.byKey(const Key('no-driver-note')), findsOneWidget);
    await tapKey(tester, 'cancel-order');
    await tester.tap(find.byKey(const Key('confirm-cancel-order')));
    await settle(tester);
    expect(server.calls('POST /shop/orders/${o['id']}/cancel'), hasLength(1));
    expect(find.text('Order cancelled'), findsOneWidget);
    await unmount(tester);
  });
}
