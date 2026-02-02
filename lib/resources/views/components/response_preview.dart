import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';

/// Displays HTTP response preview from test-fetch endpoint.
///
/// Features:
/// - Status code, response time, content type display
/// - JSON syntax highlighting (formatted)
/// - Plain text for non-JSON responses
/// - Loading state
/// - Truncation warning for large responses
class ResponsePreview extends StatelessWidget {
  final Map<String, dynamic>? response;
  final bool isLoading;

  const ResponsePreview({
    Key? key,
    required this.response,
    required this.isLoading,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    if (isLoading) {
      return _buildLoading();
    }

    if (response == null) {
      return _buildEmptyState();
    }

    return _buildResponse();
  }

  Widget _buildLoading() {
    return WDiv(
      className: '''
        flex flex-col items-center justify-center
        py-12 gap-3
        bg-gray-50 dark:bg-gray-900 rounded-lg
      ''',
      children: [
        const CircularProgressIndicator(),
        WText(
          'Fetching...',
          className: 'text-gray-600 dark:text-gray-400 text-sm',
        ),
      ],
    );
  }

  Widget _buildEmptyState() {
    return WDiv(
      className: '''
        flex items-center justify-center
        py-12
        bg-gray-50 dark:bg-gray-900 rounded-lg
      ''',
      child: WText(
        'No response yet. Click "Test Fetch" to preview.',
        className: 'text-gray-500 dark:text-gray-400 text-sm',
      ),
    );
  }

  Widget _buildResponse() {
    final statusCode = (response!['status_code'] as num?)?.toInt() ?? 0;
    final responseTime = (response!['response_time_ms'] as num?)?.toInt() ?? 0;
    final contentType = response!['content_type'] as String?;
    final body = response!['body'] as String?;

    final isError = statusCode >= 400;
    final isJson = contentType?.contains('json') ?? false;
    final isTruncated = body != null && body.length >= 10240;

    return WDiv(
      className: 'w-full flex flex-col gap-4 items-stretch',
      children: [
        // Metadata row
        WDiv(
          className: 'flex flex-row gap-6',
          children: [
            // Status code
            WDiv(
              className: 'flex flex-col gap-1',
              children: [
                WText(
                  'Status',
                  className:
                      'text-xs font-medium text-gray-500 dark:text-gray-400',
                ),
                WText(
                  statusCode.toString(),
                  className: isError
                      ? 'text-lg font-bold text-red-600 dark:text-red-400'
                      : 'text-lg font-bold text-green-600 dark:text-green-400',
                ),
              ],
            ),

            // Response time
            WDiv(
              className: 'flex flex-col gap-1',
              children: [
                WText(
                  'Response Time',
                  className:
                      'text-xs font-medium text-gray-500 dark:text-gray-400',
                ),
                WText(
                  '${responseTime}ms',
                  className: 'text-lg font-bold text-gray-900 dark:text-white',
                ),
              ],
            ),

            // Content type
            if (contentType != null)
              WDiv(
                className: 'flex flex-col gap-1',
                children: [
                  WText(
                    'Content Type',
                    className:
                        'text-xs font-medium text-gray-500 dark:text-gray-400',
                  ),
                  WText(
                    contentType,
                    className: 'text-sm text-gray-700 dark:text-gray-300',
                  ),
                ],
              ),
          ],
        ),

        // Response body
        if (body != null)
          WDiv(
            className: 'w-full flex flex-col gap-2 items-stretch',
            children: [
              WText(
                'Response Body',
                className:
                    'text-xs font-medium text-gray-500 dark:text-gray-400',
              ),

              // Truncation warning
              if (isTruncated)
                WDiv(
                  className: '''
                    px-3 py-2 rounded-lg
                    bg-amber-50 dark:bg-amber-900/20
                    border border-amber-200 dark:border-amber-800
                  ''',
                  child: WText(
                    'Response truncated to 10KB for preview',
                    className: 'text-xs text-amber-700 dark:text-amber-300',
                  ),
                ),

              // Body content
              WDiv(
                className: '''
                    p-4 rounded-lg w-full
                    bg-gray-900 dark:bg-gray-950
                    border border-gray-700
                    max-h-[300px] w-full
                  ''',
                child: SingleChildScrollView(
                  child: isJson
                      ? _buildJsonBody(body)
                      : _buildPlainTextBody(body),
                ),
              ),
            ],
          ),
      ],
    );
  }

  Widget _buildJsonBody(String body) {
    try {
      final decoded = jsonDecode(body);
      final formatted = const JsonEncoder.withIndent('  ').convert(decoded);

      return WText(
        formatted,
        selectable: true,
        className: 'font-mono text-xs text-white',
      );
    } catch (e) {
      // Fallback to plain text if JSON parsing fails
      return _buildPlainTextBody(body);
    }
  }

  Widget _buildPlainTextBody(String body) {
    return WText(
      body,
      selectable: true,
      className: 'font-mono text-xs text-white',
    );
  }
}
