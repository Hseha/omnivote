import 'package:flutter/material.dart';

// Standalone runner function to launch this screen directly
void main() {
  runApp(
    const MaterialApp(
      debugShowCheckedModeBanner: false,
      home: LoginScreen(),
    ),
  );
}

// Fallback color styles in case core files don't load
class LocalColors {
  static const Color backgroundGray = Color(0xFFF5F5F5);
  static const Color borderGray = Color(0xFFE0E0E0);
  static const Color primaryBlue = Color(0xFF1E88E5);
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _idController = TextEditingController();
  final _passwordController = TextEditingController();

  void _submit() {
    if (_formKey.currentState!.validate()) {
      // Direct navigation bypasses router framework during isolated manual tests
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Validation successful! Ready to route.')),
      );
    }
  }

  @override
  void dispose() {
    _idController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: LocalColors.backgroundGray,
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24.0),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400),

            child: Card(
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: const BorderSide(color: LocalColors.borderGray),
              ),

              child: Padding(
                padding: const EdgeInsets.all(32.0),
                child: Form(
                  key: _formKey,

                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Text(
                        'Student Portal Login',
                        style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: Colors.black87),
                        textAlign: TextAlign.center,
                      ),

                      const SizedBox(height: 32),

                      TextFormField(
                        controller: _idController,
                        decoration: const InputDecoration(
                          labelText: 'Email / Student ID',
                          border: OutlineInputBorder(),
                        ),
                        validator: (value) => value!.isEmpty ? 'Please enter your ID' : null,
                      ),
                      const SizedBox(height: 24),

                      TextFormField(
                        controller: _passwordController,
                        obscureText: true,
                        decoration: const InputDecoration(
                          labelText: 'Password',
                          border: OutlineInputBorder(),
                        ),

                        validator: (value) => value!.isEmpty ? 'Please enter your password' : null,
                      ),
                      const SizedBox(height: 32),

                      ElevatedButton(
                        onPressed: _submit,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: LocalColors.primaryBlue,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                        ),
                        child: const Text('Login'),
                      ),

                    ],


                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

}
