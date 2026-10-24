import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../brand.dart';
import '../state/scope.dart';
import 'trip_screen.dart';

/// Rides booked for later. Tap one to see it or cancel it (free until a driver accepts).
class UpcomingScreen extends StatefulWidget {
  const UpcomingScreen({super.key});

  @override
  State<UpcomingScreen> createState() => _UpcomingScreenState();
}

class _UpcomingScreenState extends State<UpcomingScreen> {
  List<Trip>? _trips;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final t = await AppScope.of(context).api.scheduledTrips();
      if (mounted) setState(() => _trips = t);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Booked rides')),
        body: _trips == null
            ? Center(child: _error != null ? Text(_error!) : const CircularProgressIndicator())
            : _trips!.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(24),
                      child: Text('No rides booked. Tap "Schedule a later ride" when you book.', key: Key('no-upcoming'), textAlign: TextAlign.center),
                    ),
                  )
                : ListView.separated(
                    itemCount: _trips!.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, i) {
                      final t = _trips![i];
                      return ListTile(
                        key: Key('upcoming-${t.id}'),
                        leading: const Icon(Icons.event, color: Brand.blue),
                        title: Text(t.scheduledAt == null ? 'Booked' : scheduleLabel(t.scheduledAt!)),
                        subtitle: Text('To ${t.dropoff.address ?? 'drop-off'}', maxLines: 1, overflow: TextOverflow.ellipsis),
                        trailing: Text(money(t.estimatedFare, t.currency)),
                        onTap: () async {
                          await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TripScreen(initial: t)));
                          _load();
                        },
                      );
                    },
                  ),
      );
}
