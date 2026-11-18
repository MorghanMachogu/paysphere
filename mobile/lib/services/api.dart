import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

/// Base URL of the PaySphere API. Override at build time:
///   flutter run --dart-define=API_BASE_URL=https://paysphere.example.co.ke/api
/// The default reaches a PHP server on the host machine from the Android emulator.
const String apiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8000/api');

class ApiException implements Exception {
  final String message;
  final int status;
  ApiException(this.message, this.status);
  @override
  String toString() => message;
}

class Api {
  Api._();
  static final Api instance = Api._();
  final _storage = const FlutterSecureStorage();
  String? _token;

  Future<String?> loadToken() async => _token = await _storage.read(key: 'token');

  Future<void> saveToken(String? token) async {
    _token = token;
    if (token == null) {
      await _storage.delete(key: 'token');
    } else {
      await _storage.write(key: 'token', value: token);
    }
  }

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
      };

  /// Returns the `data` field of a successful response.
  Future<dynamic> get(String path, [Map<String, String>? query]) async {
    final uri = Uri.parse('$apiBaseUrl/$path').replace(queryParameters: query);
    return _handle(await http.get(uri, headers: _headers).timeout(const Duration(seconds: 30)));
  }

  /// Returns the whole body: {success, message, data}.
  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? body]) async {
    final res = await http
        .post(Uri.parse('$apiBaseUrl/$path'), headers: _headers, body: jsonEncode(body ?? {}))
        .timeout(const Duration(seconds: 30));
    await _handle(res);
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> put(String path, Map<String, dynamic> body) async {
    final res = await http
        .put(Uri.parse('$apiBaseUrl/$path'), headers: _headers, body: jsonEncode(body))
        .timeout(const Duration(seconds: 30));
    await _handle(res);
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  Future<dynamic> _handle(http.Response res) async {
    Map<String, dynamic> body;
    try {
      body = jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {
      throw ApiException('Unexpected response from the server (${res.statusCode}).', res.statusCode);
    }
    if (res.statusCode >= 400 || body['success'] != true) {
      if (res.statusCode == 401) await saveToken(null);
      throw ApiException(body['message']?.toString() ?? 'Request failed.', res.statusCode);
    }
    return body['data'];
  }
}

/// KES amount with thousands separators.
String kes(dynamic v) {
  final n = double.tryParse('$v') ?? 0;
  final parts = n.toStringAsFixed(2).split('.');
  final whole = parts[0].replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},');
  return 'KES $whole.${parts[1]}';
}

const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September',
  'October', 'November', 'December'];

String periodName(dynamic m, dynamic y) => '${monthNames[int.parse('$m') - 1]} $y';

/// Day counts arrive as decimals ("2.0"); show whole numbers without the ".0".
String days(dynamic v) {
  final n = double.tryParse('$v') ?? 0;
  return n == n.roundToDouble() ? n.toInt().toString() : n.toString();
}
