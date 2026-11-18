import 'package:flutter/foundation.dart';
import 'api.dart';

class AuthProvider extends ChangeNotifier {
  Map<String, dynamic>? user;
  bool loading = true;

  bool get loggedIn => user != null;
  bool get mustChangePassword => user?['force_password_change'] == true;

  Future<void> restore() async {
    final token = await Api.instance.loadToken();
    if (token != null) {
      try {
        user = Map<String, dynamic>.from(await Api.instance.get('auth.php', {'action': 'me'}));
      } catch (_) {
        await Api.instance.saveToken(null);
      }
    }
    loading = false;
    notifyListeners();
  }

  Future<void> login(String username, String password) async {
    final res = await Api.instance.post('auth.php?action=login', {'username': username, 'password': password});
    final data = Map<String, dynamic>.from(res['data']);
    final u = Map<String, dynamic>.from(data['user']);
    if (u['user_type'] != 'employee') {
      throw ApiException('The mobile app is for employees. HR staff, please use the web app.', 403);
    }
    await Api.instance.saveToken(data['token'] as String);
    user = u;
    notifyListeners();
  }

  Future<void> changePassword(String current, String next) async {
    final res = await Api.instance.post('auth.php?action=change_password',
        {'current_password': current, 'new_password': next});
    user = Map<String, dynamic>.from(res['data']);
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await Api.instance.post('auth.php?action=logout');
    } catch (_) {}
    await Api.instance.saveToken(null);
    user = null;
    notifyListeners();
  }
}
