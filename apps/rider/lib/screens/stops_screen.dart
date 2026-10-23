import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';
import 'search_screen.dart';

/// Change a live ride's stops. Reached stops stay; the rest can be added, removed or replaced.
/// Pops with the updated [Trip].
class StopsScreen extends StatefulWidget {
  const StopsScreen({super.key, required this.trip, this.maxStops = 2});
  final Trip trip;
  final int maxStops;

  @override
  State<StopsScreen> createState() => _StopsScreenState();
}

class _StopsScreenState extends State<StopsScreen> {
  late final List<TripStop> _reached = widget.trip.stops.where((s) => s.reached).toList();
  late final List<Place> _remaining = widget.trip.remainingStops.map((s) => s.toPlace()).toList();
  bool _busy = false;
  String? _error;

  int get _room => widget.maxStops - _reached.length - _remaining.length;

  Future<void> _add() async {
    final t = widget.trip;
    final place = await Navigator.of(context).push<Place>(MaterialPageRoute(
      builder: (_) => SearchScreen(title: 'Add a stop', near: LatLngPoint(t.pickup.lat, t.pickup.lng)),
    ));
    if (place != null && mounted) setState(() => _remaining.add(place));
  }

  Future<void> _save() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final trip = await AppScope.of(context).api.changeStops(widget.trip.id, _remaining);
      if (mounted) Navigator.of(context).pop(trip);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.field('stops') ?? e.field('stops.0') ?? e.field('stops.1') ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final needsDriver = widget.trip.hasDriver;
    return Scaffold(
      appBar: AppBar(title: const Text('Stops')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('You can have up to ${widget.maxStops} stops. Each stop adds a small fee, and the fare follows the longer route.',
              style: const TextStyle(color: Colors.black54)),
          const SizedBox(height: 12),
          for (final s in _reached)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.check_circle, color: Color(0xFF1E9E5A)),
              title: Text(s.address ?? 'Stop'),
              subtitle: const Text('Done'),
            ),
          for (var i = 0; i < _remaining.length; i++)
            ListTile(
              key: Key('edit-stop-$i'),
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.adjust, color: Colors.orange),
              title: Text(_remaining[i].label),
              trailing: IconButton(key: Key('edit-remove-$i'), icon: const Icon(Icons.close), onPressed: () => setState(() => _remaining.removeAt(i))),
            ),
          if (_room > 0)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(key: const Key('edit-add-stop'), onPressed: _add, icon: const Icon(Icons.add_location_alt_outlined), label: const Text('Add a stop')),
            ),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, key: const Key('stops-error'), style: const TextStyle(color: Brand.red))),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('save-stops'),
            onPressed: _busy ? null : _save,
            child: Text(needsDriver ? 'Send to driver' : 'Update stops'),
          ),
          if (needsDriver)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text('Your driver will be asked to accept the change. Your fare estimate updates when they do.', style: TextStyle(color: Colors.black54, fontSize: 13)),
            ),
        ]),
      ),
    );
  }
}
