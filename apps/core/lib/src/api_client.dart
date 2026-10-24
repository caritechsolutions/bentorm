import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http_parser/http_parser.dart';


class ApiException implements Exception {
  ApiException(this.status, this.message, [this.body = const {}]);

  /// HTTP status; 0 for network failures.
  final int status;
  final String message;
  final Map<String, dynamic> body;

  /// First validation message for a field, e.g. field('code').
  String? field(String name) {
    final errors = body['errors'];
    if (errors is Map && errors[name] is List && (errors[name] as List).isNotEmpty) {
      return errors[name][0].toString();
    }
    return null;
  }

  bool get isNetwork => status == 0;

  @override
  String toString() => message;
}

/// Thin JSON client for the Bentorm API.
class ApiClient {
  ApiClient({http.Client? client, required this.baseUrl}) : _http = client ?? http.Client();

  final http.Client _http;
  final String baseUrl;
  String? token;

  /// Called when an authenticated request comes back 401 (token revoked / user deactivated).
  void Function()? onUnauthorized;

  Future<dynamic> get(String path, {Map<String, String>? query}) => _send('GET', path, query: query);
  Future<dynamic> post(String path, [Map<String, dynamic>? body]) => _send('POST', path, body: body);
  Future<dynamic> patch(String path, [Map<String, dynamic>? body]) => _send('PATCH', path, body: body);
  Future<dynamic> delete(String path, [Map<String, dynamic>? body]) => _send('DELETE', path, body: body);

  /// Multipart upload (documents, photos). [fields] are sent as text parts.
  Future<dynamic> upload(String path, {required String field, required List<int> bytes, required String filename, String? contentType, Map<String, String> fields = const {}}) async {
    final request = http.MultipartRequest('POST', Uri.parse('$baseUrl$path'))
      ..headers['Accept'] = 'application/json'
      ..fields.addAll(fields)
      ..files.add(http.MultipartFile.fromBytes(field, bytes, filename: filename, contentType: contentType == null ? null : MediaType.parse(contentType)));
    if (token != null) request.headers['Authorization'] = 'Bearer $token';
    return _complete(request, timeout: const Duration(seconds: 90));
  }

  Future<dynamic> _send(String method, String path, {Map<String, dynamic>? body, Map<String, String>? query}) async {
    var uri = Uri.parse('$baseUrl$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: {...uri.queryParameters, ...query});
    }
    final request = http.Request(method, uri)
      ..headers['Accept'] = 'application/json'
      ..headers['Content-Type'] = 'application/json';
    if (token != null) request.headers['Authorization'] = 'Bearer $token';
    if (body != null) request.body = jsonEncode(body);

    return _complete(request);
  }

  Future<dynamic> _complete(http.BaseRequest request, {Duration timeout = const Duration(seconds: 25)}) async {
    http.Response res;
    try {
      res = await http.Response.fromStream(await _http.send(request).timeout(timeout));
    } on TimeoutException {
      throw ApiException(0, 'The server is taking too long. Please try again.');
    } catch (_) {
      throw ApiException(0, 'No connection. Check your internet and try again.');
    }

    dynamic data;
    if (res.body.isNotEmpty) {
      try {
        data = jsonDecode(res.body);
      } catch (_) {
        data = null;
      }
    }

    if (res.statusCode >= 400) {
      final map = data is Map<String, dynamic> ? data : <String, dynamic>{};
      if (res.statusCode == 401 && token != null) onUnauthorized?.call();
      throw ApiException(res.statusCode, (map['message'] as String?) ?? 'Something went wrong (${res.statusCode}).', map);
    }
    return data;
  }
}
