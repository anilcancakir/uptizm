import '../enums/api_key_location.dart';
import '../enums/monitor_auth_type.dart';

class MonitorAuthConfig {
  final MonitorAuthType type;
  final String? basicAuthUsername;
  final String? basicAuthPassword;
  final String? bearerToken;
  final String? apiKeyName;
  final String? apiKeyValue;
  final ApiKeyLocation? apiKeyLocation;
  final Map<String, String>? customHeaders;

  MonitorAuthConfig({
    this.type = MonitorAuthType.none,
    this.basicAuthUsername,
    this.basicAuthPassword,
    this.bearerToken,
    this.apiKeyName,
    this.apiKeyValue,
    this.apiKeyLocation,
    this.customHeaders,
  });

  factory MonitorAuthConfig.fromMap(Map<String, dynamic>? map) {
    if (map == null || map.isEmpty) return MonitorAuthConfig.none();

    final type = MonitorAuthType.fromValue(map['type'] as String?);

    switch (type) {
      case MonitorAuthType.basicAuth:
        final basic = map['basic_auth'] as Map<String, dynamic>?;
        return MonitorAuthConfig(
          type: type,
          basicAuthUsername: basic?['username'] as String?,
          basicAuthPassword: basic?['password'] as String?,
        );
      case MonitorAuthType.bearerToken:
        final bearer = map['bearer_token'] as Map<String, dynamic>?;
        return MonitorAuthConfig(
          type: type,
          bearerToken: bearer?['token'] as String?,
        );
      case MonitorAuthType.apiKey:
        final apiKey = map['api_key'] as Map<String, dynamic>?;
        return MonitorAuthConfig(
          type: type,
          apiKeyName: apiKey?['key'] as String?,
          apiKeyValue: apiKey?['value'] as String?,
          apiKeyLocation:
              ApiKeyLocation.fromValue(apiKey?['location'] as String?),
        );
      case MonitorAuthType.customHeader:
        final custom = map['custom_header'] as Map<String, dynamic>?;
        final headers = custom?['headers'] as Map<String, dynamic>?;
        return MonitorAuthConfig(
          type: type,
          customHeaders: headers?.map((k, v) => MapEntry(k, v.toString())),
        );
      case MonitorAuthType.none:
        return MonitorAuthConfig.none();
    }
  }

  Map<String, dynamic> toMap() {
    final map = <String, dynamic>{'type': type.value};

    switch (type) {
      case MonitorAuthType.basicAuth:
        map['basic_auth'] = {
          'username': basicAuthUsername,
          'password': basicAuthPassword,
        };
        break;
      case MonitorAuthType.bearerToken:
        map['bearer_token'] = {'token': bearerToken};
        break;
      case MonitorAuthType.apiKey:
        map['api_key'] = {
          'key': apiKeyName,
          'value': apiKeyValue,
          'location': apiKeyLocation?.value ?? ApiKeyLocation.header.value,
        };
        break;
      case MonitorAuthType.customHeader:
        map['custom_header'] = {'headers': customHeaders ?? {}};
        break;
      case MonitorAuthType.none:
        break;
    }

    return map;
  }

  static MonitorAuthConfig none() => MonitorAuthConfig();
}
