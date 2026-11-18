import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/auth_provider.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _username = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _hide = true;
  String? _error;

  Future<void> _submit() async {
    if (_username.text.trim().isEmpty || _password.text.isEmpty) {
      setState(() => _error = 'Enter your username and password.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<AuthProvider>().login(_username.text.trim(), _password.text);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text('PaySphere', style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.primary)),
              const SizedBox(height: 4),
              const Text('Your payslips, leave and attendance.'),
              const SizedBox(height: 32),
              if (_error != null) ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(6)),
                  child: Text(_error!, style: TextStyle(color: Colors.red.shade900)),
                ),
                const SizedBox(height: 16),
              ],
              TextField(
                controller: _username,
                decoration: const InputDecoration(labelText: 'Username'),
                autofillHints: const [AutofillHints.username],
                textInputAction: TextInputAction.next,
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _password,
                obscureText: _hide,
                decoration: InputDecoration(
                  labelText: 'Password',
                  suffixIcon: IconButton(
                    icon: Icon(_hide ? Icons.visibility : Icons.visibility_off),
                    tooltip: _hide ? 'Show password' : 'Hide password',
                    onPressed: () => setState(() => _hide = !_hide),
                  ),
                ),
                autofillHints: const [AutofillHints.password],
                onSubmitted: (_) => _submit(),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _busy ? null : _submit,
                style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 16)),
                child: Text(_busy ? 'Logging in…' : 'Log in'),
              ),
              const SizedBox(height: 16),
              const Text('Forgot your password? Ask your HR office to reset it.', textAlign: TextAlign.center),
            ]),
          ),
        ),
      ),
    );
  }
}
