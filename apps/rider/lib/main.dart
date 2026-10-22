import 'package:flutter/material.dart';

/// Ride Coop brand colours, sampled from the store icon.
class Brand {
  static const yellow = Color(0xFFFEBD25);
  static const blue = Color(0xFF0E7BB6);
  static const red = Color(0xFFEA483B);
  static const charcoal = Color(0xFF393D3F);
}

void main() => runApp(const RideCoopApp());

class RideCoopApp extends StatelessWidget {
  const RideCoopApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Ride Coop',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Brand.blue, primary: Brand.blue, secondary: Brand.yellow),
        useMaterial3: true,
      ),
      home: const Scaffold(
        body: Center(
          child: Text('Ride Coop', style: TextStyle(fontSize: 32, fontWeight: FontWeight.bold, color: Brand.charcoal)),
        ),
      ),
    );
  }
}
