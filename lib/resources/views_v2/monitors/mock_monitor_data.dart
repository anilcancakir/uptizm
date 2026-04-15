/// Mock data for monitor show view prototype.
///
/// Replace with real model bindings during API integration.
class MockMonitor {
  const MockMonitor({
    required this.id,
    required this.name,
    required this.url,
    required this.status,
    required this.lastStatus,
    required this.method,
    required this.checkInterval,
    required this.timeout,
    required this.expectedStatusCode,
    required this.lastResponseTimeMs,
    required this.locations,
  });

  final String id;
  final String name;
  final String url;
  final String status;
  final String lastStatus;
  final String method;
  final int checkInterval;
  final int timeout;
  final int expectedStatusCode;
  final int lastResponseTimeMs;
  final List<String> locations;
}

class MockCheck {
  const MockCheck({
    required this.status,
    this.responseTimeMs,
    required this.checkedAt,
    this.statusCode,
    this.location,
    this.errorMessage,
  });

  final String status;
  final int? responseTimeMs;
  final String checkedAt;
  final int? statusCode;
  final String? location;
  final String? errorMessage;
}

// -------  Sample Data  -------

const mockMonitor = MockMonitor(
  id: '9e2f3a1b-4c5d-6e7f-8a9b-0c1d2e3f4a5b',
  name: 'Production API',
  url: 'https://api.example.com/health',
  status: 'active',
  lastStatus: 'up',
  method: 'GET',
  checkInterval: 30,
  timeout: 30,
  expectedStatusCode: 200,
  lastResponseTimeMs: 245,
  locations: ['EU West', 'US East', 'AP Southeast'],
);

const mockChecks = <MockCheck>[
  MockCheck(
    status: 'up',
    responseTimeMs: 245,
    checkedAt: '2m ago',
    statusCode: 200,
    location: 'EU West',
  ),
  MockCheck(
    status: 'up',
    responseTimeMs: 312,
    checkedAt: '5m ago',
    statusCode: 200,
    location: 'US East',
  ),
  MockCheck(
    status: 'degraded',
    responseTimeMs: 1245,
    checkedAt: '8m ago',
    statusCode: 200,
    location: 'AP Southeast',
  ),
  MockCheck(
    status: 'down',
    checkedAt: '11m ago',
    statusCode: 500,
    errorMessage: 'Connection timeout after 30s',
  ),
  MockCheck(
    status: 'up',
    responseTimeMs: 198,
    checkedAt: '14m ago',
    statusCode: 200,
    location: 'EU Central',
  ),
];

/// Computed stats from mock checks.
const mockUptime = '99.95%';
const mockAvgResponse = '245ms';
const mockLastCheck = '2m';
