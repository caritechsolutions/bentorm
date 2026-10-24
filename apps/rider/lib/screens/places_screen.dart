import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';
import 'search_screen.dart';

/// Home, Work and other saved places: one tap to book from the start screen.
class PlacesScreen extends StatefulWidget {
  const PlacesScreen({super.key});

  @override
  State<PlacesScreen> createState() => _PlacesScreenState();
}

class _PlacesScreenState extends State<PlacesScreen> {
  List<SavedPlace>? _places;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final p = await AppScope.of(context).api.savedPlaces();
      if (mounted) setState(() => _places = p);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  void _snack(String m) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  Future<String?> _askName(String title, [String initial = '']) {
    final ctl = TextEditingController(text: initial);
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: TextField(
          key: const Key('place-name'),
          controller: ctl,
          autofocus: true,
          maxLength: 60,
          textCapitalization: TextCapitalization.words,
          decoration: const InputDecoration(hintText: 'e.g. Gym, Mum\'s house'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          FilledButton(key: const Key('place-name-ok'), onPressed: () => Navigator.pop(ctx, ctl.text.trim()), child: const Text('Save')),
        ],
      ),
    );
  }

  /// [kind] home | work | other.
  Future<void> _add(String kind) async {
    final scope = AppScope.of(context);
    final api = scope.api;
    // Offer where the rider is now, and named places around it, before they type.
    final me = await scope.location.current();
    final here = me == null ? null : await api.reverse(me.lat, me.lng);
    if (!mounted) return;
    final place = await Navigator.of(context).push<Place>(MaterialPageRoute(
      builder: (_) => SearchScreen(
        title: kind == 'home' ? 'Set Home' : (kind == 'work' ? 'Set Work' : 'Save a place'),
        near: me,
        currentLocation: me == null ? null : (here ?? Place(name: 'Current location', lat: me.lat, lng: me.lng)),
        showNearby: true,
      ),
    ));
    if (place == null || !mounted) return;
    String? label;
    if (kind == 'other') {
      label = await _askName('Name this place', place.name);
      if (label == null || label.isEmpty) return;
    }
    try {
      await api.savePlace(kind: kind, label: label, place: place);
      await _load();
    } on ApiException catch (e) {
      _snack(e.field('label') ?? e.message);
    }
  }

  Future<void> _rename(SavedPlace p) async {
    final label = await _askName('Rename', p.label);
    if (label == null || label.isEmpty || !mounted) return;
    try {
      await AppScope.of(context).api.renamePlace(p.id, label);
      await _load();
    } on ApiException catch (e) {
      _snack(e.message);
    }
  }

  Future<void> _delete(SavedPlace p) async {
    try {
      final left = await AppScope.of(context).api.deletePlace(p.id);
      if (mounted) setState(() => _places = left);
    } on ApiException catch (e) {
      _snack(e.message);
    }
  }

  Widget _slot(String kind, IconData icon, String title) {
    final p = _places?.where((x) => x.kind == kind).firstOrNull;
    return ListTile(
      key: Key('slot-$kind'),
      leading: Icon(icon, color: Brand.blue),
      title: Text(title),
      subtitle: Text(p?.address ?? 'Add $title', maxLines: 1, overflow: TextOverflow.ellipsis),
      trailing: p == null
          ? const Icon(Icons.add)
          : IconButton(key: Key('delete-${p.id}'), icon: const Icon(Icons.delete_outline), tooltip: 'Remove', onPressed: () => _delete(p)),
      onTap: () => _add(kind),
    );
  }

  @override
  Widget build(BuildContext context) {
    final others = _places?.where((p) => p.kind == 'other').toList() ?? const <SavedPlace>[];
    return Scaffold(
      appBar: AppBar(title: const Text('Saved places')),
      body: _places == null
          ? Center(child: _error != null ? Text(_error!) : const CircularProgressIndicator())
          : ListView(children: [
              _slot('home', Icons.home_outlined, 'Home'),
              _slot('work', Icons.work_outline, 'Work'),
              const Divider(),
              for (final p in others)
                ListTile(
                  key: Key('place-${p.id}'),
                  leading: const Icon(Icons.bookmark_border, color: Brand.blue),
                  title: Text(p.label),
                  subtitle: p.address == null ? null : Text(p.address!, maxLines: 1, overflow: TextOverflow.ellipsis),
                  onTap: () => _rename(p),
                  trailing: IconButton(key: Key('delete-${p.id}'), icon: const Icon(Icons.delete_outline), tooltip: 'Remove', onPressed: () => _delete(p)),
                ),
              ListTile(
                key: const Key('add-place'),
                leading: const Icon(Icons.add_location_alt_outlined),
                title: const Text('Save another place'),
                onTap: () => _add('other'),
              ),
            ]),
    );
  }
}
