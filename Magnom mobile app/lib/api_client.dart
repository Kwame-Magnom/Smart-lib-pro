import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class ApiException implements Exception {
  const ApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient({http.Client? httpClient}) : _http = httpClient ?? http.Client();

  static const _storage = FlutterSecureStorage();
  static const _tokenKey = 'smartlib_access_token';
  static const _serverKey = 'smartlib_server_url';
  final http.Client _http;
  String? _token;
  String? _serverUrl;

  String get serverUrl => _serverUrl ?? _defaultServerUrl;
  bool get hasToken => _token != null;

  static String get _defaultServerUrl {
    if (!kIsWeb && defaultTargetPlatform == TargetPlatform.android) {
      return 'http://10.0.2.2/assignment';
    }
    return 'http://localhost/assignment';
  }

  Future<void> initialize() async {
    final preferences = await SharedPreferences.getInstance();
    _serverUrl = preferences.getString(_serverKey) ?? _defaultServerUrl;
    _token = await _storage.read(key: _tokenKey);
  }

  Future<void> setServerUrl(String value) async {
    final parsed = Uri.tryParse(value.trim());
    if (parsed == null ||
        !parsed.hasScheme ||
        parsed.host.isEmpty ||
        !['http', 'https'].contains(parsed.scheme)) {
      throw const ApiException(
        'Enter a valid server URL, including http:// or https://.',
      );
    }
    final normalized = value.trim().replaceFirst(RegExp(r'/+$'), '');
    final preferences = await SharedPreferences.getInstance();
    await preferences.setString(_serverKey, normalized);
    _serverUrl = normalized;
    _token = null;
    await _storage.delete(key: _tokenKey);
  }

  Future<String> beginLogin(String identifier, String password) async {
    final result = await _send(
      'POST',
      'auth/login',
      body: {'identifier': identifier, 'password': password},
    );
    final challenge = result['challenge'];
    if (challenge is! String || challenge.isEmpty) {
      throw const ApiException(
        'The server returned an invalid sign-in challenge.',
      );
    }
    return challenge;
  }

  Future<Map<String, dynamic>> verifyCode(String challenge, String code) async {
    final result = await _send(
      'POST',
      'auth/verify',
      body: {'challenge': challenge, 'code': code},
    );
    final token = result['token'];
    final user = result['user'];
    if (token is! String || user is! Map<String, dynamic>) {
      throw const ApiException(
        'The server returned an invalid sign-in response.',
      );
    }
    _token = token;
    await _storage.write(key: _tokenKey, value: token);
    return user;
  }

  Future<Map<String, dynamic>> getProfile() async {
    final result = await _send('GET', 'me');
    return _asMap(result['user']);
  }

  Future<Map<String, dynamic>> updateProfile(
    Map<String, dynamic> profile,
  ) async {
    final result = await _send('PATCH', 'profile', body: profile);
    return _asMap(result['user']);
  }

  Future<List<Map<String, dynamic>>> getBooks({String search = ''}) async {
    final result = await _send(
      'GET',
      'books',
      query: {'q': search, 'limit': '40'},
    );
    return _asList(result['books']);
  }

  Future<List<Map<String, dynamic>>> getLoans() async {
    return _asList((await _send('GET', 'loans'))['loans']);
  }

  Future<List<Map<String, dynamic>>> getReservations() async {
    return _asList((await _send('GET', 'reservations'))['reservations']);
  }

  Future<void> reserveBook(int bookId) async {
    await _send('POST', 'reservations', body: {'book_id': bookId});
  }

  Future<List<Map<String, dynamic>>> getCourses() async {
    return _asList((await _send('GET', 'courses'))['courses']);
  }

  Future<List<Map<String, dynamic>>> getCourseRequests() async {
    return _asList((await _send('GET', 'course-requests'))['requests']);
  }

  Future<void> requestCourseBook(int courseBookId) async {
    await _send(
      'POST',
      'course-requests',
      body: {'course_book_id': courseBookId},
    );
  }

  Future<List<Map<String, dynamic>>> getNotifications() async {
    return _asList((await _send('GET', 'notifications'))['notifications']);
  }

  Future<void> markNotificationRead(int id) async {
    await _send('POST', 'notifications/$id/read');
  }

  Future<void> signOut() async {
    try {
      if (_token != null) {
        await _send('POST', 'auth/logout');
      }
    } finally {
      _token = null;
      await _storage.delete(key: _tokenKey);
    }
  }

  Future<void> clearLocalSession() async {
    _token = null;
    await _storage.delete(key: _tokenKey);
  }

  Future<Map<String, dynamic>> _send(
    String method,
    String route, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
  }) async {
    final base = Uri.parse(serverUrl);
    final endpoint = Uri(
      scheme: base.scheme,
      userInfo: base.userInfo,
      host: base.host,
      port: base.hasPort ? base.port : null,
      path: '${base.path}/api/v1/index.php',
      queryParameters: {'route': route, ...?query},
    );
    final headers = <String, String>{'Accept': 'application/json'};
    if (_token != null) {
      headers['Authorization'] = 'Bearer $_token';
    }
    if (body != null) {
      headers['Content-Type'] = 'application/json; charset=utf-8';
    }
    try {
      late http.Response response;
      switch (method) {
        case 'POST':
          response = await _http
              .post(endpoint, headers: headers, body: jsonEncode(body ?? {}))
              .timeout(const Duration(seconds: 25));
        case 'PATCH':
          response = await _http
              .patch(endpoint, headers: headers, body: jsonEncode(body ?? {}))
              .timeout(const Duration(seconds: 25));
        default:
          response = await _http
              .get(endpoint, headers: headers)
              .timeout(const Duration(seconds: 25));
      }
      final decoded = jsonDecode(utf8.decode(response.bodyBytes));
      if (decoded is! Map<String, dynamic>) {
        throw const ApiException('The server returned an invalid response.');
      }
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw ApiException(
          decoded['error'] is String
              ? decoded['error'] as String
              : 'The request failed. Please try again.',
          statusCode: response.statusCode,
        );
      }
      return decoded;
    } on ApiException {
      rethrow;
    } on http.ClientException {
      throw const ApiException(
        'Could not connect to the library server. Check the server URL and network connection.',
      );
    } on FormatException {
      throw const ApiException('The server returned an unreadable response.');
    } on Exception catch (error) {
      if (error.toString().contains('TimeoutException')) {
        throw const ApiException(
          'The server took too long to respond. Try again.',
        );
      }
      rethrow;
    }
  }

  static Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) {
      return value;
    }
    throw const ApiException('The server returned an invalid data record.');
  }

  static List<Map<String, dynamic>> _asList(dynamic value) {
    if (value is List) {
      return value.whereType<Map<String, dynamic>>().toList();
    }
    throw const ApiException('The server returned an invalid list.');
  }
}
