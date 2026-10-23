import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../state/scope.dart';

/// Add or edit the vehicle: what it is, how many it carries, what it offers.
class VehicleForm extends StatefulWidget {
  const VehicleForm({super.key, this.vehicle, this.serviceAreaId});
  final Vehicle? vehicle;
  final int? serviceAreaId;

  @override
  State<VehicleForm> createState() => _VehicleFormState();
}

class _VehicleFormState extends State<VehicleForm> {
  late final _make = TextEditingController(text: widget.vehicle?.make ?? '');
  late final _model = TextEditingController(text: widget.vehicle?.model ?? '');
  late final _year = TextEditingController(text: widget.vehicle?.year?.toString() ?? '');
  late final _colour = TextEditingController(text: widget.vehicle?.colour ?? '');
  late final _plate = TextEditingController(text: widget.vehicle?.plate ?? '');
  late String _type = widget.vehicle?.vehicleType ?? 'standard';
  late int _seats = widget.vehicle?.seats ?? 4;
  late int _luggage = widget.vehicle?.luggage ?? 2;
  late final Set<String> _features = {...?widget.vehicle?.features};
  List<VehicleTypeOption>? _types;
  List<FeatureOption>? _featureOptions;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      final api = AppScope.of(context).api;
      try {
        final types = await api.vehicleTypes(widget.serviceAreaId);
        final features = await api.features();
        if (!mounted) return;
        setState(() {
          _types = types;
          _featureOptions = features;
          if (!types.any((t) => t.key == _type) && types.isNotEmpty) _type = types.first.key;
        });
      } on ApiException catch (e) {
        if (mounted) setState(() => _error = e.message);
      }
    });
  }

  @override
  void dispose() {
    for (final c in [_make, _model, _year, _colour, _plate]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_make.text.trim().isEmpty || _model.text.trim().isEmpty || _plate.text.trim().isEmpty) {
      setState(() => _error = 'Make, model and licence plate are required.');
      return;
    }
    final api = AppScope.of(context).api;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final v = await api.saveVehicle({
        'make': _make.text.trim(),
        'model': _model.text.trim(),
        if (_year.text.trim().isNotEmpty) 'year': int.tryParse(_year.text.trim()),
        'colour': _colour.text.trim(),
        'plate': _plate.text.trim().toUpperCase(),
        'vehicle_type': _type,
        'seats': _seats,
        'luggage_capacity': _luggage,
        'features': _features.toList(),
      }, id: widget.vehicle?.id);
      if (widget.vehicle == null) {
        try {
          await api.selectVehicle(v.id);
        } on ApiException {
          // Already the current vehicle (first vehicle is selected automatically).
        }
      }
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _error = e.field('plate') ?? e.field('make') ?? e.field('model') ?? e.field('year') ?? e.field('vehicle_type') ?? e.message);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _stepper(String label, int value, int min, int max, ValueChanged<int> onSet) => Row(children: [
        Expanded(child: Text(label)),
        IconButton(onPressed: value > min ? () => setState(() => onSet(value - 1)) : null, icon: const Icon(Icons.remove_circle_outline)),
        Text('$value', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
        IconButton(onPressed: value < max ? () => setState(() => onSet(value + 1)) : null, icon: const Icon(Icons.add_circle_outline)),
      ]);

  @override
  Widget build(BuildContext context) {
    final types = _types;
    final editingApproved = widget.vehicle?.status == 'approved';
    return Scaffold(
      appBar: AppBar(title: Text(widget.vehicle == null ? 'Add your vehicle' : 'Your vehicle')),
      body: SingleChildScrollView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (editingApproved)
          Container(
            padding: const EdgeInsets.all(12),
            margin: const EdgeInsets.only(bottom: 12),
            decoration: BoxDecoration(color: Brand.yellow.withValues(alpha: 0.2), borderRadius: BorderRadius.circular(10)),
            child: const Text('Changes send your vehicle back for review — you can\'t go online until it\'s approved again.'),
          ),
        Row(children: [
          Expanded(child: TextField(key: const Key('make'), controller: _make, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Make (e.g. Toyota)'))),
          const SizedBox(width: 12),
          Expanded(child: TextField(key: const Key('model'), controller: _model, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Model'))),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: TextField(key: const Key('year'), controller: _year, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Year'))),
          const SizedBox(width: 12),
          Expanded(child: TextField(key: const Key('colour'), controller: _colour, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Colour'))),
        ]),
        const SizedBox(height: 12),
        TextField(key: const Key('plate'), controller: _plate, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(labelText: 'Licence plate')),
        const SizedBox(height: 16),
        const Text('Type of service', style: TextStyle(fontWeight: FontWeight.w600)),
        if (types == null) const LinearProgressIndicator(),
        if (types != null)
          for (final t in types)
            RadioListTile<String>(
              key: Key('type-${t.key}'),
              contentPadding: EdgeInsets.zero,
              value: t.key,
              groupValue: _type,
              onChanged: (v) => setState(() => _type = v ?? _type),
              title: Text(t.name),
              subtitle: Text('Up to ${t.maxPassengers} riders, ${t.maxLuggage} bags'),
            ),
        const Divider(),
        _stepper('Passenger seats (not counting you)', _seats, 1, 16, (v) => _seats = v),
        _stepper('Suitcases it can carry', _luggage, 0, 20, (v) => _luggage = v),
        const Divider(),
        const Text('Your vehicle offers', style: TextStyle(fontWeight: FontWeight.w600)),
        if (_featureOptions != null)
          for (final f in _featureOptions!)
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              value: _features.contains(f.key),
              onChanged: (v) => setState(() => v == true ? _features.add(f.key) : _features.remove(f.key)),
              title: Text(f.label),
            ),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, key: const Key('error'), style: const TextStyle(color: Brand.red))),
        const SizedBox(height: 24),
        FilledButton(key: const Key('save'), onPressed: _busy ? null : _save, child: Text(widget.vehicle == null ? 'Add vehicle' : 'Save changes')),
      ])),
    );
  }
}
