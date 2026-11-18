import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'services/auth_provider.dart';
import 'screens/login_screen.dart';
import 'screens/change_password_screen.dart';
import 'screens/home_screen.dart';

const ink = Color(0xFF0F3D5E);
const paid = Color(0xFF1F7A5C);

void main() {
  runApp(ChangeNotifierProvider(create: (_) => AuthProvider()..restore(), child: const PaySphereApp()));
}

class PaySphereApp extends StatelessWidget {
  const PaySphereApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'PaySphere',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: ink, primary: ink, secondary: paid),
        scaffoldBackgroundColor: const Color(0xFFF4F6F8),
        useMaterial3: true,
        appBarTheme: const AppBarTheme(backgroundColor: ink, foregroundColor: Colors.white),
        inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
      ),
      home: Consumer<AuthProvider>(builder: (context, auth, _) {
        if (auth.loading) return const Scaffold(body: Center(child: CircularProgressIndicator()));
        if (!auth.loggedIn) return const LoginScreen();
        if (auth.mustChangePassword) return const ChangePasswordScreen();
        return const HomeScreen();
      }),
    );
  }
}
