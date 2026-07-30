/// Routing Configuration.
///
/// Controls URL strategy and other routing behavior.
/// Set `'url_strategy'` to `'path'` for clean web URLs (/dashboard instead of /#/dashboard).
/// See: https://magic.fluttersdk.com/docs/basics/routing#url-strategy
Map<String, dynamic> get routingConfig => {
  'routing': {
    // Path strategy, so a web URL is `/monitors/{id}` rather than
    // `/#/monitors/{id}`. It requires the server to answer every path with
    // index.html and let this router take over, which the production vhost does
    // (`try_files $uri $uri/ /index.html`, see deploy/vhost-app.uptizm.com.conf).
    // Do not switch this to `null` without also checking that fallback, or a
    // reload on any deep link will 404 instead of reaching the app.
    'url_strategy': 'path',
  },
};
