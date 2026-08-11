import '../app/support/env_strings.dart' show envString;

/// Network Configuration.
///
/// This config file is OPTIONAL. Only create it if you want to use the
/// Magic Network (Http) system. Don't forget to add `NetworkServiceProvider`
/// to your `app.providers` list.
Map<String, dynamic> get networkConfig => {
  'network': {
    'default': 'api',
    'drivers': {
      'api': {
        // Through [envString]: a present-but-blank API_URL would send every request
        // to a bare path.
        'base_url': envString('API_URL', 'http://localhost:8000/api/v1'),
        // Increased from 10000 to accommodate POST /monitors/analyze, which runs
        // a live probe plus up to two model turns: at 10s the client aborted while
        // the server was still working, charging a metered AI trial. COST WARNING:
        // magic's Dio driver feeds this single value to both connectTimeout and
        // receiveTimeout, and Dio's web adapter sums them into xhr.timeout, so an
        // unreachable backend now waits 120s natively and 240s on web, on every
        // screen, not just analyze. Per-call raw-Dio override via configureDriver()
        // was considered and deliberately rejected in favour of this global raise.
        'timeout': 120000,
        'headers': {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    },
  },
};
