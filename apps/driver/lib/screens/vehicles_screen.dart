import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../state/scope.dart';
import 'documents_screen.dart';
import 'vehicle_form.dart';

/// My vehicles: the driver's own cars and the fleet cars they've been given. Pick the one you're driving today.
class VehiclesScreen extends StatefulWidget {
  const VehiclesScreen({super.key, this.serviceAreaId});
  final int? serviceAreaId;

  @override
  State<VehiclesScreen> createState() => _VehiclesScreenState();
}

class _VehiclesScreenState extends State<VehiclesScreen> {
  List<Vehicle>? _cars;
  int? _current;
  String? _error;
  int? _busy;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final scope = AppScope.of(context);
    try {
      final cars = await scope.api.vehicles();
      await scope.engine.refreshStatus();
      if (!mounted) return;
      setState(() {
        _cars = cars;
        _current = scope.engine.status?.profile.currentVehicle?.id;
        _error = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _use(Vehicle v) async {
    final scope = AppScope.of(context);
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _busy = v.id);
    try {
      await scope.api.selectVehicle(v.id);
      messenger.showSnackBar(SnackBar(content: Text('You\'re driving ${v.plate}${v.isFleetCar ? ' (${v.fleetName})' : ''}.')));
      await _load();
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _open(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final cars = _cars;
    final online = AppScope.of(context).engine.online;
    return Scaffold(
      appBar: AppBar(title: const Text('My vehicles')),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('add-vehicle'),
        onPressed: () => _open(VehicleForm(serviceAreaId: widget.serviceAreaId)),
        icon: const Icon(Icons.add),
        label: const Text('Add my own car'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        // A handful of cars: build them all (no lazy list), so every card is there to find.
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
          physics: const AlwaysScrollableScrollPhysics(),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (_error != null) Text(_error!, style: const TextStyle(color: Brand.red)),
          if (cars == null && _error == null) const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
          if (online)
            const Padding(
              padding: EdgeInsets.only(bottom: 12),
              child: Text('Go offline to switch cars.', style: TextStyle(color: Colors.black54)),
            ),
          if (cars != null && cars.isEmpty) const Text('No vehicles yet. Add your own car, or accept a fleet\'s invitation.'),
          for (final v in cars ?? const <Vehicle>[]) _card(v, online),
          ]),
        ),
      ),
    );
  }

  Widget _card(Vehicle v, bool online) {
    final current = v.id == _current;
    final inUse = v.inUseBy != null && !current;
    return Card(
      key: Key('car-${v.plate}'),
      margin: const EdgeInsets.only(bottom: 12),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: current ? const Color(0xFF1E9E5A) : const Color(0xFFE3E5E8), width: current ? 2 : 1)),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(v.isFleetCar ? Icons.local_taxi : Icons.directions_car, color: Brand.charcoal),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(v.plate, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                Text([v.title, if (v.colour != null && v.colour!.isNotEmpty) v.colour!].join(' · '), style: const TextStyle(color: Colors.black54)),
              ]),
            ),
            if (current) const Chip(key: Key('current-car'), label: Text('Driving'), backgroundColor: Color(0xFFDDF3E6)),
          ]),
          const SizedBox(height: 8),
          Wrap(spacing: 6, runSpacing: 4, children: [
            if (v.isFleetCar)
              Chip(key: Key('fleet-${v.plate}'), avatar: const Icon(Icons.apartment, size: 16), label: Text(v.fleetName ?? 'Fleet'), visualDensity: VisualDensity.compact)
            else
              const Chip(label: Text('Your car'), visualDensity: VisualDensity.compact),
            Chip(label: Text(_status(v.status)), visualDensity: VisualDensity.compact),
          ]),
          if (v.isFleetCar)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('Earnings in this car are paid to ${v.fleetName ?? 'the fleet'}. Its documents are looked after by the fleet.',
                  style: const TextStyle(color: Colors.black54, fontSize: 13)),
            ),
          if (v.statusNote != null && v.status != 'approved') Padding(padding: const EdgeInsets.only(top: 4), child: Text(v.statusNote!, style: const TextStyle(color: Brand.red))),
          if (inUse)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('In use by ${v.inUseBy}', key: Key('in-use-${v.plate}'), style: const TextStyle(fontWeight: FontWeight.w600, color: Brand.red)),
            ),
          if (!v.isFleetCar)
            Row(children: [
              TextButton(onPressed: () => _open(VehicleForm(vehicle: v, serviceAreaId: widget.serviceAreaId)), child: const Text('Edit')),
              TextButton(onPressed: () => _open(DocumentsScreen(vehicle: v)), child: const Text('Documents')),
            ]),
          if (!current)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: SizedBox(
                width: double.infinity,
                child: FilledButton(
                  key: Key('use-${v.plate}'),
                  onPressed: online || inUse || _busy != null ? null : () => _use(v),
                  child: _busy == v.id
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Text('Drive this car'),
                ),
              ),
            ),
        ]),
      ),
    );
  }

  String _status(String s) => switch (s) { 'approved' => 'Approved', 'pending' => 'Under review', 'rejected' => 'Rejected', 'suspended' => 'Suspended', _ => s };
}
