import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/auth_provider.dart';

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});
  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  String? _error;

  Future<void> _save() async {
    if (_next.text != _confirm.text) {
      setState(() => _error = 'The new passwords do not match.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final auth = context.read<AuthProvider>();
    final messenger = ScaffoldMessenger.of(context);
    final navigator = Navigator.of(context);
    try {
      await auth.changePassword(_current.text, _next.text);
      messenger.showSnackBar(const SnackBar(content: Text('Password changed')));
      if (navigator.canPop()) navigator.pop();
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final forced = context.watch<AuthProvider>().mustChangePassword;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Choose a new password'),
        actions: [if (forced) TextButton(onPressed: () => context.read<AuthProvider>().logout(),
            child: const Text('Log out', style: TextStyle(color: Colors.white)))],
      ),
      body: ListView(padding: const EdgeInsets.all(24), children: [
        if (forced) const Text('You are using a temporary password. Choose your own to continue.'),
        const SizedBox(height: 16),
        if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 12),
            child: Text(_error!, style: TextStyle(color: Colors.red.shade800))),
        TextField(controller: _current, obscureText: true,
            decoration: InputDecoration(labelText: forced ? 'Temporary password' : 'Current password')),
        const SizedBox(height: 16),
        TextField(controller: _next, obscureText: true, decoration: const InputDecoration(
            labelText: 'New password', helperText: 'At least 8 characters with letters and numbers')),
        const SizedBox(height: 16),
        TextField(controller: _confirm, obscureText: true, decoration: const InputDecoration(labelText: 'Confirm new password')),
        const SizedBox(height: 24),
        FilledButton(onPressed: _busy ? null : _save, child: Text(_busy ? 'Saving…' : 'Save password')),
      ]),
    );
  }
}
