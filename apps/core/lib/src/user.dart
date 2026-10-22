class User {
  User({required this.id, required this.name, this.phone, this.email, required this.role, this.avatarUrl, this.rating});

  final int id;
  final String name;
  final String? phone;
  final String? email;

  /// rider | driver | admin
  final String role;
  final String? avatarUrl;

  /// Drivers only: average rating.
  final double? rating;

  factory User.fromJson(Map<String, dynamic> j) {
    final profile = j['driver_profile'];
    final rating = profile is Map ? profile['rating_avg'] : null;
    return User(
      id: j['id'] is num ? (j['id'] as num).toInt() : int.tryParse('${j['id']}') ?? 0,
      name: j['name']?.toString() ?? '',
      phone: j['phone']?.toString(),
      email: j['email']?.toString(),
      role: j['role']?.toString() ?? 'rider',
      avatarUrl: j['avatar_url']?.toString(),
      rating: rating is num ? rating.toDouble() : double.tryParse('${rating ?? ''}'),
    );
  }

  String get firstName => name.split(' ').first;
}
