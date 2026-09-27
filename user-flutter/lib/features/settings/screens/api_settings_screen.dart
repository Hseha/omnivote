import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/api_constants.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/top_bar.dart';
import '../../../data/services/api_config.dart';

/// Dev-facing screen to view and change the API base URL at runtime.
///
/// Lets a single installed build talk to the local dev backend
/// (e.g. http://100.100.224.74:8000/api) during testing and to the deployed
/// backend later, without rebuilding or reinstalling.
class ApiSettingsScreen extends ConsumerStatefulWidget {
  const ApiSettingsScreen({super.key});

  @override
  ConsumerState<ApiSettingsScreen> createState() => _ApiSettingsScreenState();
}

class _ApiSettingsScreenState extends ConsumerState<ApiSettingsScreen> {
  late final TextEditingController _urlController;
  bool _checking = false;
  String? _checkResult;
  bool _checkOk = false;

  @override
  void initState() {
    super.initState();
    _urlController = TextEditingController(
      text: ref.read(apiBaseUrlProvider) ?? ApiConstants.baseUrl,
    );
  }

  @override
  void dispose() {
    _urlController.dispose();
    super.dispose();
  }

  Future<void> _applyUrl(String url) async {
    final normalized = normalizeApiBaseUrl(url);
    await ApiConfigStorage.saveBaseUrl(normalized);
    ref.read(apiBaseUrlProvider.notifier).state = normalized;
  }

  Future<void> _testConnection() async {
    final base = normalizeApiBaseUrl(_urlController.text);
    setState(() {
      _checking = true;
      _checkResult = null;
    });
    try {
      final dio = Dio(BaseOptions(
        baseUrl: base,
        connectTimeout: const Duration(seconds: 8),
        receiveTimeout: const Duration(seconds: 8),
        headers: {'Accept': 'application/json'},
      ));
      final response = await dio.get<dynamic>('/election/status');
      setState(() {
        _checking = false;
        _checkOk = response.statusCode != null && response.statusCode! < 400;
        _checkResult =
            _checkOk ? 'Reachable (HTTP ${response.statusCode})' : 'HTTP ${response.statusCode}';
      });
    } catch (e) {
      setState(() {
        _checking = false;
        _checkOk = false;
        _checkResult = 'Cannot reach: $e';
      });
    }
  }

  Future<void> _save() async {
    final raw = _urlController.text.trim();
    if (!raw.startsWith('http://') && !raw.startsWith('https://')) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('URL must start with http:// or https://')),
      );
      return;
    }
    await _applyUrl(raw);
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('API base URL saved.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final current = ref.read(apiBaseUrlProvider) ?? ApiConstants.baseUrl;
    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'API Settings'),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text(
            'Currently pointing at:',
            style: Theme.of(context).textTheme.labelLarge,
          ),
          const SizedBox(height: 8),
          SelectableText(
            current,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 24),
          Text('Quick presets', style: Theme.of(context).textTheme.labelLarge),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              ActionChip(
                label: const Text('Deployed (debian tailnet)'),
                onPressed: () {
                  _urlController.text = ApiConstants.baseUrl;
                },
              ),
              ActionChip(
                label: const Text('Local: tailnet IP'),
                onPressed: () {
                  _urlController.text = 'http://100.100.224.74:8000/api';
                },
              ),
              ActionChip(
                label: const Text('Local: LAN IP'),
                onPressed: () {
                  _urlController.text = 'http://192.168.1.64:8000/api';
                },
              ),
            ],
          ),
          const SizedBox(height: 24),
          TextField(
            controller: _urlController,
            decoration: const InputDecoration(
              labelText: 'API base URL',
              hintText: 'https://.../api',
              border: OutlineInputBorder(),
            ),
            keyboardType: TextInputType.url,
          ),
          const SizedBox(height: 24),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _checking ? null : _testConnection,
                  icon: _checking
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.network_check),
                  label: const Text('Test connection'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: _save,
                  icon: const Icon(Icons.save),
                  label: const Text('Save'),
                ),
              ),
            ],
          ),
          if (_checkResult != null) ...[
            const SizedBox(height: 16),
            Row(
              children: [
                Icon(
                  _checkOk ? Icons.check_circle : Icons.error,
                  color: _checkOk ? Colors.green : AppColors.errorRed,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    _checkResult!,
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: 24),
          Text(
            'Switch to the deployed URL after the backend is live, or to a'
            ' local URL while developing. The change takes effect immediately.',
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}