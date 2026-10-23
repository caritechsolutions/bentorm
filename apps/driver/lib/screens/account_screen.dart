import 'package:flutter/material.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../state/scope.dart';
import 'documents_screen.dart';
import 'profile_form.dart';
import 'vehicle_form.dart';

/// Onboarding hub: what's still needed, driver details, vehicle, documents.
class AccountScreen extends StatefulWidget {
  const AccountScreen({super.key});

  @override
  State<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends State<AccountScreen> {
  DriverStatus? _status;
  List<DocItem>? _driverDocs;
  List<DocItem>? _vehicleDocs;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final api = AppScope.of(context).api;
    try {
      final status = await api.status();
      final docs = await api.documents();
      final vehicle = status.profile.currentVehicle;
      final vDocs = vehicle == null ? null : await api.documents(vehicleId: vehicle.id);
      if (!mounted) return;
      setState(() {
        _status = status;
        _driverDocs = docs;
        _vehicleDocs = vDocs;
        _error = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _push(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final s = _status;
    return Scaffold(
      appBar: AppBar(title: const Text('Your account')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(padding: const EdgeInsets.all(16), children: [
          if (_error != null) Text(_error!, style: const TextStyle(color: Brand.red)),
          if (s == null && _error == null) const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
          if (s != null) ...[
            _statusCard(s),
            const SizedBox(height: 16),
            _section(
              key: const Key('step-details'),
              number: 1,
              title: 'Your details',
              done: s.profile.serviceAreaId != null && (s.profile.licenceNumber?.isNotEmpty ?? false),
              subtitle: s.profile.licenceNumber == null ? 'Area you drive in, licence and permit' : 'Licence ${s.profile.licenceNumber}',
              onTap: () => _push(ProfileForm(profile: s.profile)),
            ),
            _section(
              key: const Key('step-vehicle'),
              number: 2,
              title: 'Your vehicle',
              done: s.profile.currentVehicle != null,
              subtitle: s.profile.currentVehicle == null
                  ? 'Make, model, plate, seats and luggage'
                  : '${s.profile.currentVehicle!.title} · ${s.profile.currentVehicle!.plate} · ${_vehicleStatus(s.profile.currentVehicle!.status)}',
              onTap: () => _push(VehicleForm(vehicle: s.profile.currentVehicle, serviceAreaId: s.profile.serviceAreaId)),
            ),
            _section(
              key: const Key('step-documents'),
              number: 3,
              title: 'Your documents',
              done: _driverDocs != null && !_driverDocs!.any((d) => d.needsAction),
              subtitle: _docSummary(_driverDocs),
              onTap: () => _push(const DocumentsScreen()),
            ),
            _section(
              key: const Key('step-vehicle-documents'),
              number: 4,
              title: 'Vehicle documents & photos',
              done: _vehicleDocs != null && !_vehicleDocs!.any((d) => d.needsAction),
              subtitle: s.profile.currentVehicle == null ? 'Add your vehicle first' : _docSummary(_vehicleDocs),
              onTap: s.profile.currentVehicle == null ? null : () => _push(DocumentsScreen(vehicle: s.profile.currentVehicle)),
            ),
          ],
        ]),
      ),
    );
  }

  String _vehicleStatus(String s) => switch (s) { 'approved' => 'Approved', 'pending' => 'Under review', 'rejected' => 'Rejected', 'suspended' => 'Suspended', _ => s };

  String _docSummary(List<DocItem>? docs) {
    if (docs == null) return '';
    final needed = docs.where((d) => d.needsAction).length;
    final review = docs.where((d) => d.state == 'pending').length;
    if (needed > 0) return '$needed still needed${review > 0 ? ' · $review under review' : ''}';
    if (review > 0) return '$review under review';
    return 'All done';
  }

  Widget _statusCard(DriverStatus s) {
    final approved = s.profile.status == 'approved';
    final color = s.canGoOnline ? const Color(0xFF1E9E5A) : (approved ? Brand.yellow : Brand.blue);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(14), border: Border.all(color: color)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(
          s.canGoOnline ? 'You\'re ready to drive' : (approved ? 'Almost there' : 'Application in progress'),
          key: const Key('account-status'),
          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
        ),
        if (s.profile.statusNote != null && s.profile.status != 'approved') Padding(padding: const EdgeInsets.only(top: 4), child: Text(s.profile.statusNote!)),
        for (final i in s.issues)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('•  '),
              Expanded(child: Text(i)),
            ]),
          ),
      ]),
    );
  }

  Widget _section({required Key key, required int number, required String title, required bool done, required String subtitle, VoidCallback? onTap}) => Card(
        key: key,
        margin: const EdgeInsets.only(bottom: 10),
        child: ListTile(
          enabled: onTap != null,
          leading: CircleAvatar(
            backgroundColor: done ? const Color(0xFF1E9E5A) : const Color(0xFFE3E5E8),
            child: done ? const Icon(Icons.check, color: Colors.white) : Text('$number', style: const TextStyle(color: Brand.charcoal)),
          ),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(subtitle),
          trailing: const Icon(Icons.chevron_right),
          onTap: onTap,
        ),
      );
}
