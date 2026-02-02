import 'package:flutter/material.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';

import '../../../app/enums/api_key_location.dart';
import '../../../app/enums/monitor_auth_type.dart';
import '../../../app/models/monitor_auth_config.dart';
import 'key_value_editor.dart';

/// Editor for monitor authentication configuration.
///
/// Shows conditional fields based on selected auth type:
/// - None: no extra fields
/// - Basic Auth: username + password
/// - Bearer Token: token field
/// - API Key: key name, value, location (header/query)
/// - Custom Header: key-value editor
class AuthConfigEditor extends StatefulWidget {
  final MonitorAuthConfig value;
  final ValueChanged<MonitorAuthConfig> onChanged;

  const AuthConfigEditor({
    Key? key,
    required this.value,
    required this.onChanged,
  }) : super(key: key);

  @override
  State<AuthConfigEditor> createState() => _AuthConfigEditorState();
}

class _AuthConfigEditorState extends State<AuthConfigEditor> {
  late MonitorAuthType _type;
  late TextEditingController _usernameController;
  late TextEditingController _passwordController;
  late TextEditingController _tokenController;
  late TextEditingController _apiKeyNameController;
  late TextEditingController _apiKeyValueController;
  late ApiKeyLocation _apiKeyLocation;
  late Map<String, String> _customHeaders;

  @override
  void initState() {
    super.initState();
    _type = widget.value.type;
    _usernameController =
        TextEditingController(text: widget.value.basicAuthUsername ?? '');
    _passwordController =
        TextEditingController(text: widget.value.basicAuthPassword ?? '');
    _tokenController =
        TextEditingController(text: widget.value.bearerToken ?? '');
    _apiKeyNameController =
        TextEditingController(text: widget.value.apiKeyName ?? '');
    _apiKeyValueController =
        TextEditingController(text: widget.value.apiKeyValue ?? '');
    _apiKeyLocation =
        widget.value.apiKeyLocation ?? ApiKeyLocation.header;
    _customHeaders =
        Map<String, String>.from(widget.value.customHeaders ?? {});
  }

  @override
  void dispose() {
    _usernameController.dispose();
    _passwordController.dispose();
    _tokenController.dispose();
    _apiKeyNameController.dispose();
    _apiKeyValueController.dispose();
    super.dispose();
  }

  void _notifyChange() {
    widget.onChanged(MonitorAuthConfig(
      type: _type,
      basicAuthUsername:
          _type == MonitorAuthType.basicAuth ? _usernameController.text : null,
      basicAuthPassword:
          _type == MonitorAuthType.basicAuth ? _passwordController.text : null,
      bearerToken:
          _type == MonitorAuthType.bearerToken ? _tokenController.text : null,
      apiKeyName:
          _type == MonitorAuthType.apiKey ? _apiKeyNameController.text : null,
      apiKeyValue:
          _type == MonitorAuthType.apiKey ? _apiKeyValueController.text : null,
      apiKeyLocation:
          _type == MonitorAuthType.apiKey ? _apiKeyLocation : null,
      customHeaders:
          _type == MonitorAuthType.customHeader ? _customHeaders : null,
    ));
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        _buildTypeSelector(),
        ..._buildTypeFields(),
      ],
    );
  }

  Widget _buildTypeSelector() {
    return WDiv(
      className: 'flex flex-col gap-1',
      children: [
        WText(
          'Auth Type',
          className: 'text-sm font-medium text-gray-700 dark:text-gray-300',
        ),
        WSelect<MonitorAuthType>(
          value: _type,
          options: MonitorAuthType.selectOptions,
          onChange: (value) {
            if (value != null) {
              setState(() => _type = value);
              _notifyChange();
            }
          },
          className: '''
            w-full px-3 py-3 rounded-lg
            bg-white dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
            text-gray-900 dark:text-white text-sm
          ''',
          menuClassName: '''
            bg-white dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
            rounded-xl shadow-xl
          ''',
        ),
      ],
    );
  }

  List<Widget> _buildTypeFields() {
    switch (_type) {
      case MonitorAuthType.none:
        return [];
      case MonitorAuthType.basicAuth:
        return _buildBasicAuthFields();
      case MonitorAuthType.bearerToken:
        return _buildBearerTokenFields();
      case MonitorAuthType.apiKey:
        return _buildApiKeyFields();
      case MonitorAuthType.customHeader:
        return _buildCustomHeaderFields();
    }
  }

  List<Widget> _buildBasicAuthFields() {
    return [
      _buildTextField(
        label: 'Username',
        controller: _usernameController,
        placeholder: 'admin',
      ),
      _buildTextField(
        label: 'Password',
        controller: _passwordController,
        placeholder: '••••••••',
        obscure: true,
      ),
    ];
  }

  List<Widget> _buildBearerTokenFields() {
    return [
      _buildTextField(
        label: 'Token',
        controller: _tokenController,
        placeholder: 'eyJhbGciOiJIUzI1NiIs...',
      ),
    ];
  }

  List<Widget> _buildApiKeyFields() {
    return [
      _buildTextField(
        label: 'Key Name',
        controller: _apiKeyNameController,
        placeholder: 'X-API-Key',
      ),
      _buildTextField(
        label: 'Key Value',
        controller: _apiKeyValueController,
        placeholder: 'your-api-key',
      ),
      WDiv(
        className: 'flex flex-col gap-1',
        children: [
          WText(
            'Key Location',
            className:
                'text-sm font-medium text-gray-700 dark:text-gray-300',
          ),
          WSelect<ApiKeyLocation>(
            value: _apiKeyLocation,
            options: ApiKeyLocation.selectOptions,
            onChange: (value) {
              if (value != null) {
                setState(() => _apiKeyLocation = value);
                _notifyChange();
              }
            },
            className: '''
              w-full px-3 py-3 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              text-gray-900 dark:text-white text-sm
            ''',
            menuClassName: '''
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              rounded-xl shadow-xl
            ''',
          ),
        ],
      ),
    ];
  }

  List<Widget> _buildCustomHeaderFields() {
    return [
      KeyValueEditor(
        entries: _customHeaders,
        onChanged: (entries) {
          _customHeaders = entries;
          _notifyChange();
        },
      ),
    ];
  }

  Widget _buildTextField({
    required String label,
    required TextEditingController controller,
    String? placeholder,
    bool obscure = false,
  }) {
    return WDiv(
      className: 'flex flex-col gap-1',
      children: [
        WText(
          label,
          className: 'text-sm font-medium text-gray-700 dark:text-gray-300',
        ),
        WInput(
          value: controller.text,
          onChanged: (value) {
            controller.text = value;
            _notifyChange();
          },
          type: obscure ? InputType.password : InputType.text,
          placeholder: placeholder,
          className: '''
            w-full px-3 py-3 rounded-lg
            bg-white dark:bg-gray-800
            border border-gray-200 dark:border-gray-700
            text-gray-900 dark:text-white text-sm
            focus:border-primary focus:ring-2 focus:ring-primary/20
          ''',
        ),
      ],
    );
  }
}
