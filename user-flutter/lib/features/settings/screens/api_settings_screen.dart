import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/constants/app_colors.dart';
import '../../../core/constants/api_constants.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_tokens.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_text_field.dart';
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
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      backgroundColor: context.appBackground,
      appBar: const TopBar(title: 'API Settings'),
      body: ListView(
        padding: AppSpacing.screenPadding,
        children: [
          Text(
            'Currently pointing at:',
            style: Theme.of(context).textTheme.labelLarge,
          ),
          AppSpacing.vSm,
          SelectableText(
            current,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          AppSpacing.vLg,
          Text('Quick presets', style: Theme.of(context).textTheme.labelLarge),
          AppSpacing.vSm,
          Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.sm,
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
          AppSpacing.vLg,
          AppTextField(
            controller: _urlController,
            label: 'API base URL',
            hint: 'https://.../api',
            keyboardType: TextInputType.url,
            autocorrect: false,
          ),
          AppSpacing.vLg,
          Row(
            children: [
              Expanded(
                child: AppButton.secondary(
                  label: 'Test connection',
                  icon: Icons.network_check,
                  onPressed: _checking ? null : _testConnection,
                  isLoading: _checking,
                ),
              ),
              AppSpacing.hSm,
              Expanded(
                child: AppButton.primary(
                  label: 'Save',
                  icon: Icons.save,
                  onPressed: _save,
                ),
              ),
            ],
          ),
          if (_checkResult != null) ...[
            AppSpacing.vMd,
            Row(
              children: [
                Icon(
                  _checkOk ? Icons.check_circle : Icons.error,
                  color: _checkOk ? AppColors.successGreen : scheme.error,
                ),
                AppSpacing.hSm,
                Expanded(
                  child: Text(
                    _checkResult!,
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
          ],
          AppSpacing.vLg,
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