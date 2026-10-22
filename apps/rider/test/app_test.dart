import 'package:flutter_test/flutter_test.dart';
import 'package:ridecoop_rider/main.dart';

void main() {
  testWidgets('app starts and shows the brand name', (tester) async {
    await tester.pumpWidget(const RideCoopApp());
    expect(find.text('Ride Coop'), findsOneWidget);
  });
}
