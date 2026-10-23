import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../state/scope.dart';

/// Area, licence and PSV permit.
class ProfileForm extends StatefulWidget {
  const ProfileForm({super.key, required this.profile});
  final DriverProfile profile;

  @override
  State<ProfileForm> createState() => _ProfileFormState();
}

class _ProfileFormState extends State<ProfileForm> {
  late final _licence = TextEditingController(text: widget.profile.licenceNumber ?? '');
  late final _psv = TextEditingController(text: widget.profile.psvPermit ?? '');
  late String? _expiry = widget.profile.licenceExpiry;
  late int? _areaId = widget.profile.serviceAreaId;
  List<ServiceAreaOption>? _areas;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      try {
        final areas = await AppScope.of(context).api.serviceAreas();
        if (mounted) setState(() => _areas = areas);
      } on ApiException catch (e) {
        if (mounted) setState(() => _error = e.message);
      }
    });
  }

  @override
  void dispose() {
    _licence.dispose();
    _psv.dispose();
    super.dispose();
  }

  Future<void> _pickExpiry() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      firstDate: now,
      lastDate: DateTime(now.year + 15),
      initialDate: DateTime.tryParse(_expiry ?? '') ?? now.add(const Duration(days: 365)),
      helpText: 'Licence expiry date',
    );
    if (picked != null) setState(() => _expiry = _ymd(picked));
  }

  Future<void> _save() async {
    if (_areaId == null || _licence.text.trim().isEmpty || _expiry == null) {
      setState(() => _error = 'Choose your area and enter your licence number and expiry date.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await AppScope.of(context).api.updateProfile(
            serviceAreaId: _areaId,
            licenceNumber: _licence.text.trim(),
            licenceExpiry: _expiry,
            psvPermit: _psv.text.trim(),
          );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.field('licence_expiry') ?? e.field('licence_number') ?? e.field('service_area_id') ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final areas = _areas;
    return Scaffold(
      appBar: AppBar(title: const Text('Your details')),
      body: SingleChildScrollView(padding: const EdgeInsets.all(16), physics: const AlwaysScrollableScrollPhysics(), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Where will you drive?', style: TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 8),
        if (areas == null)
          const LinearProgressIndicator()
        else
          DropdownButtonFormField<int>(
            key: const Key('area'),
            initialValue: areas.any((a) => a.id == _areaId) ? _areaId : null,
            items: [for (final a in areas) DropdownMenuItem(value: a.id, child: Text(a.name))],
            onChanged: (v) => setState(() => _areaId = v),
            decoration: const InputDecoration(hintText: 'Choose an island'),
          ),
        const SizedBox(height: 16),
        TextField(key: const Key('licence'), controller: _licence, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(labelText: 'Driver\'s licence number')),
        const SizedBox(height: 12),
        InkWell(
          key: const Key('licence-expiry'),
          onTap: _pickExpiry,
          child: InputDecorator(
            decoration: const InputDecoration(labelText: 'Licence expiry date'),
            child: Text(_expiry ?? 'Choose a date'),
          ),
        ),
        const SizedBox(height: 12),
        TextField(key: const Key('psv'), controller: _psv, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(labelText: 'PSV / taxi permit number (if you have one)')),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, key: const Key('error'), style: const TextStyle(color: Brand.red))),
        const SizedBox(height: 24),
        FilledButton(key: const Key('save'), onPressed: _busy ? null : _save, child: const Text('Save')),
      ])),
    );
  }
}

String _ymd(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
