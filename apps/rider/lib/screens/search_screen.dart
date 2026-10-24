import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';

/// Address search. Pops with the chosen [Place].
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key, required this.title, this.near, this.currentLocation, this.saved = const [], this.showNearby = false});

  final String title;

  /// Bias results and show distances from here.
  final LatLngPoint? near;

  /// Offered as the first option (pickup search).
  final Place? currentLocation;

  /// Home, Work and other saved places, offered before typing.
  final List<SavedPlace> saved;

  /// Offer places around [near] before typing (saving a place: "the shop I'm at").
  final bool showNearby;

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _query = TextEditingController();
  // One session per search, so Google bills autocomplete + details as one lookup.
  final String _session = List.generate(24, (_) => Random().nextInt(36).toRadixString(36)).join();
  Timer? _debounce;
  List<Suggestion> _results = const [];
  bool _loading = false;
  String? _error;
  int _seq = 0;
  List<Place> _nearby = const [];

  @override
  void initState() {
    super.initState();
    final at = widget.near;
    if (widget.showNearby && at != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) async {
        final list = await AppScope.of(context).api.nearbyPlaces(at.lat, at.lng);
        if (mounted) setState(() => _nearby = list);
      });
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _query.dispose();
    super.dispose();
  }

  void _changed(String q) {
    _debounce?.cancel();
    if (q.trim().length < 2) {
      setState(() {
        _results = const [];
        _error = null;
      });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 300), () => _search(q.trim()));
  }

  Future<void> _search(String q) async {
    final seq = ++_seq;
    setState(() => _loading = true);
    try {
      final r = await AppScope.of(context).api.autocomplete(q, lat: widget.near?.lat, lng: widget.near?.lng, session: _session);
      if (!mounted || seq != _seq) return;
      setState(() {
        _results = r;
        _error = r.isEmpty ? 'No places found in our service area.' : null;
      });
    } on ApiException catch (e) {
      if (!mounted || seq != _seq) return;
      setState(() => _error = e.status == 503 ? 'Address search isn\'t available right now.' : e.message);
    } finally {
      if (mounted && seq == _seq) setState(() => _loading = false);
    }
  }

  Future<void> _pick(Suggestion s) async {
    setState(() => _loading = true);
    try {
      final place = await AppScope.of(context).api.place(s.placeId, session: _session);
      if (mounted) Navigator.of(context).pop(place);
    } on ApiException catch (e) {
      if (!mounted) return;
      final msg = e.status == 503 ? 'Couldn\'t get that location right now. Please try again.' : e.message;
      setState(() => _error = msg);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final current = widget.currentLocation;
    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: TextField(
              key: const Key('search'),
              controller: _query,
              autofocus: true,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                hintText: 'Search for a place or address',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: _loading ? const Padding(padding: EdgeInsets.all(14), child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))) : null,
              ),
              onChanged: _changed,
            ),
          ),
          if (_error != null)
            Padding(padding: const EdgeInsets.all(16), child: Text(_error!, style: const TextStyle(color: Colors.black54))),
          Expanded(
            child: ListView(
              children: [
                if (current != null && _query.text.trim().length < 2)
                  ListTile(
                    key: const Key('pick-current'),
                    leading: const Icon(Icons.my_location, color: Brand.blue),
                    title: const Text('Current location'),
                    subtitle: Text(current.label, maxLines: 1, overflow: TextOverflow.ellipsis),
                    onTap: () => Navigator.of(context).pop(current),
                  ),
                if (_query.text.trim().length < 2 && _nearby.isNotEmpty) ...[
                  const Padding(
                    padding: EdgeInsets.fromLTRB(16, 12, 16, 4),
                    child: Text('Nearby', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.black54)),
                  ),
                  for (var i = 0; i < _nearby.length; i++)
                    ListTile(
                      key: Key('nearby-$i'),
                      leading: const Icon(Icons.storefront_outlined),
                      title: Text(_nearby[i].name),
                      subtitle: _nearby[i].address == null ? null : Text(_nearby[i].address!, maxLines: 1, overflow: TextOverflow.ellipsis),
                      onTap: () => Navigator.of(context).pop(_nearby[i]),
                    ),
                ],
                if (_query.text.trim().length < 2)
                  for (final p in widget.saved)
                    ListTile(
                      key: Key('pick-saved-${p.id}'),
                      leading: Icon(p.kind == 'home' ? Icons.home_outlined : (p.kind == 'work' ? Icons.work_outline : Icons.bookmark_border), color: Brand.blue),
                      title: Text(p.label),
                      subtitle: p.address == null ? null : Text(p.address!, maxLines: 1, overflow: TextOverflow.ellipsis),
                      onTap: () => Navigator.of(context).pop(p.place),
                    ),
                for (final s in _results)
                  ListTile(
                    leading: const Icon(Icons.place_outlined),
                    title: Text(s.mainText),
                    subtitle: s.secondaryText == null ? null : Text(s.secondaryText!, maxLines: 1, overflow: TextOverflow.ellipsis),
                    trailing: s.distanceM == null ? null : Text(distanceText(s.distanceM), style: const TextStyle(color: Colors.black54)),
                    onTap: () => _pick(s),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
