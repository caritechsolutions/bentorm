import 'package:flutter/material.dart';

/// Ride Coop brand, sampled from the store icon.
class Brand {
  static const yellow = Color(0xFFFEBD25);
  static const blue = Color(0xFF0E7BB6);
  static const red = Color(0xFFEA483B);
  static const charcoal = Color(0xFF393D3F);
  static const name = 'Ride Coop';

  /// [primary]: blue for the rider app, charcoal for the driver app (matching each icon).
  static ThemeData theme({Color primary = blue}) {
    final scheme = ColorScheme.fromSeed(
      seedColor: primary,
      primary: primary,
      secondary: yellow,
      onSecondary: charcoal,
      error: red,
    );
    return ThemeData(
      colorScheme: scheme,
      useMaterial3: true,
      appBarTheme: const AppBarTheme(backgroundColor: Colors.white, foregroundColor: charcoal, elevation: 0),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
        filled: true,
        fillColor: const Color(0xFFF5F6F7),
      ),
    );
  }
}

/// "BBD 57.50" style money.
String money(num? amount, [String? currency]) {
  if (amount == null) return '—';
  final c = (currency == null || currency.isEmpty) ? '\$' : '$currency ';
  return '$c${amount.toStringAsFixed(2)}';
}

String distanceText(int? metres) {
  if (metres == null) return '';
  return metres < 1000 ? '$metres m' : '${(metres / 1000).toStringAsFixed(1)} km';
}

String durationText(int? seconds) {
  if (seconds == null) return '';
  final m = (seconds / 60).round();
  return m < 60 ? '$m min' : '${m ~/ 60} h ${m % 60} min';
}
