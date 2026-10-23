import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:ridecoop_core/ridecoop_core.dart';

import '../api/models.dart';
import '../state/scope.dart';

typedef PickedFile = ({List<int> bytes, String name, String mime});

/// Camera or gallery → JPEG bytes. Tests replace [DocumentsScreen.pick].
Future<PickedFile?> pickWithImagePicker(ImageSource source) async {
  final x = await ImagePicker().pickImage(source: source, maxWidth: 2000, maxHeight: 2000, imageQuality: 85);
  if (x == null) return null;
  final name = x.name.isEmpty ? 'photo.jpg' : x.name;
  final lower = name.toLowerCase();
  final mime = lower.endsWith('.png') ? 'image/png' : (lower.endsWith('.heic') ? 'image/heic' : (lower.endsWith('.webp') ? 'image/webp' : 'image/jpeg'));
  return (bytes: await x.readAsBytes(), name: name, mime: mime);
}

/// Driver documents, or a vehicle's documents and photos when [vehicle] is given.
class DocumentsScreen extends StatefulWidget {
  const DocumentsScreen({super.key, this.vehicle});
  final Vehicle? vehicle;

  static Future<PickedFile?> Function(ImageSource source) pick = pickWithImagePicker;

  @override
  State<DocumentsScreen> createState() => _DocumentsScreenState();
}

class _DocumentsScreenState extends State<DocumentsScreen> {
  List<DocItem>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final items = await AppScope.of(context).api.documents(vehicleId: widget.vehicle?.id);
      if (mounted) setState(() => _items = items);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _open(DocItem item) async {
    final uploaded = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _UploadSheet(item: item, vehicleId: widget.vehicle?.id, scope: AppScope.of(context)),
    );
    if (uploaded == true) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${item.name} sent for review.')));
      await _load();
    }
  }

  Color _stateColor(String s) => switch (s) {
        'approved' => const Color(0xFF1E9E5A),
        'pending' => Brand.blue,
        'expiring_soon' => const Color(0xFFB7791F),
        _ => Brand.red,
      };

  @override
  Widget build(BuildContext context) {
    final items = _items;
    return Scaffold(
      appBar: AppBar(title: Text(widget.vehicle == null ? 'Your documents' : 'Vehicle ${widget.vehicle!.plate}')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        const Text('Take clear, well-lit photos with all four corners showing. We review them, usually within a day.', style: TextStyle(color: Colors.black54)),
        const SizedBox(height: 12),
        if (_error != null) Text(_error!, style: const TextStyle(color: Brand.red)),
        if (items == null && _error == null) const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
        if (items != null)
          for (final d in items)
            Card(
              key: Key('doc-${d.typeKey}'),
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                leading: Icon(d.imageOnly ? Icons.photo_camera_outlined : Icons.description_outlined),
                title: Text(d.name),
                subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  if (!d.required) const Text('Optional'),
                  if (d.state == 'rejected' && d.reviewNote != null) Text('Rejected: ${d.reviewNote}', style: const TextStyle(color: Brand.red)),
                  if (d.expiresAt != null) Text('Expires ${d.expiresAt}'),
                ]),
                trailing: Text(d.stateLabel, key: Key('state-${d.typeKey}'), style: TextStyle(color: _stateColor(d.state), fontWeight: FontWeight.w600)),
                onTap: () => _open(d),
              ),
            ),
      ]),
    );
  }
}

class _UploadSheet extends StatefulWidget {
  const _UploadSheet({required this.item, required this.vehicleId, required this.scope});
  final DocItem item;
  final int? vehicleId;
  final AppScope scope;

  @override
  State<_UploadSheet> createState() => _UploadSheetState();
}

class _UploadSheetState extends State<_UploadSheet> {
  late final _number = TextEditingController(text: widget.item.number ?? '');
  String? _expiry;
  PickedFile? _file;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _number.dispose();
    super.dispose();
  }

  Future<void> _pick(ImageSource source) async {
    try {
      final f = await DocumentsScreen.pick(source);
      if (f != null && mounted) setState(() => _file = f);
    } catch (e) {
      if (mounted) setState(() => _error = 'Could not open the ${source == ImageSource.camera ? 'camera' : 'photo library'}.');
    }
  }

  Future<void> _pickExpiry() async {
    final now = DateTime.now();
    final picked = await showDatePicker(context: context, firstDate: now, lastDate: DateTime(now.year + 15), initialDate: now.add(const Duration(days: 365)));
    if (picked != null) {
      setState(() => _expiry = '${picked.year}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}');
    }
  }

  Future<void> _upload() async {
    final item = widget.item;
    if (_file == null) {
      setState(() => _error = 'Add a photo first.');
      return;
    }
    if (item.requiresNumber && _number.text.trim().isEmpty) {
      setState(() => _error = 'Enter the document number.');
      return;
    }
    if (item.requiresExpiry && _expiry == null) {
      setState(() => _error = 'Choose the expiry date.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.scope.api.uploadDocument(
        vehicleId: widget.vehicleId,
        typeKey: item.typeKey,
        bytes: _file!.bytes,
        filename: _file!.name,
        contentType: _file!.mime,
        number: item.requiresNumber ? _number.text : null,
        expiresAt: _expiry,
      );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.field('file') ?? e.field('number') ?? e.field('expires_at') ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 0, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Text(item.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          if (item.description != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(item.description!)),
          if (item.state == 'approved')
            const Padding(padding: EdgeInsets.only(top: 8), child: Text('Approved. Upload a new one only if it has changed or is expiring.')),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                key: const Key('take-photo'),
                onPressed: _busy ? null : () => _pick(ImageSource.camera),
                icon: const Icon(Icons.photo_camera),
                label: const Text('Take photo'),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                key: const Key('choose-photo'),
                onPressed: _busy ? null : () => _pick(ImageSource.gallery),
                icon: const Icon(Icons.photo_library),
                label: const Text('Choose photo'),
              ),
            ),
          ]),
          if (_file != null)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(children: [
                const Icon(Icons.check_circle, color: Color(0xFF1E9E5A)),
                const SizedBox(width: 8),
                Expanded(child: Text('${_file!.name} (${(_file!.bytes.length / 1024).round()} KB)', key: const Key('picked'))),
              ]),
            ),
          if (item.requiresNumber) ...[
            const SizedBox(height: 12),
            TextField(key: const Key('doc-number'), controller: _number, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(labelText: 'Document number')),
          ],
          if (item.requiresExpiry) ...[
            const SizedBox(height: 12),
            InkWell(
              key: const Key('doc-expiry'),
              onTap: _pickExpiry,
              child: InputDecorator(decoration: const InputDecoration(labelText: 'Expiry date'), child: Text(_expiry ?? 'Choose a date')),
            ),
          ],
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, key: const Key('upload-error'), style: const TextStyle(color: Brand.red))),
          const SizedBox(height: 16),
          FilledButton(
            key: const Key('upload'),
            onPressed: _busy ? null : _upload,
            child: _busy
                ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                : const Text('Send for review'),
          ),
        ]),
      ),
    );
  }
}
