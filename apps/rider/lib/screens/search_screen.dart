import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';

/// Address search. Pops with the chosen [Place].
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key, required this.title, this.near, this.currentLocation});

  final String title;

  /// Bias results and show distances from here.
  final LatLngPoint? near;

  /// Offered as the first option (pickup search).
  final Place? currentLocation;

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
      if (mounted) setState(() => _error = e.message);
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
                    leading: const Icon(Icons.my_location, color: Brand.blue),
                    title: const Text('Current location'),
                    subtitle: Text(current.label, maxLines: 1, overflow: TextOverflow.ellipsis),
                    onTap: () => Navigator.of(context).pop(current),
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
