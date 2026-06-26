// GENERATED: do not edit by hand.
// Regenerate via: dart run magic:artisan plugins:refresh
//
// Source: .artisan/plugins.json

import 'package:fluttersdk_artisan/artisan.dart';
import 'package:fluttersdk_dusk/cli.dart' show FluttersdkDuskArtisanProvider;
import 'package:fluttersdk_telescope/cli.dart'
    show FluttersdkTelescopeArtisanProvider;
import 'package:magic/cli.dart' show MagicArtisanProvider;
import 'package:magic_deeplink/cli.dart' show MagicDeeplinkArtisanProvider;
import 'package:magic_notifications/cli.dart'
    show MagicNotificationsArtisanProvider;
import 'package:magic_social_auth/cli.dart' show MagicSocialAuthArtisanProvider;
import 'package:magic_starter/cli.dart' show MagicStarterArtisanProvider;

List<ArtisanServiceProvider> autoDiscoveredProviders() {
  return <ArtisanServiceProvider>[
    FluttersdkDuskArtisanProvider(),
    FluttersdkTelescopeArtisanProvider(),
    MagicArtisanProvider(),
    MagicDeeplinkArtisanProvider(),
    MagicNotificationsArtisanProvider(),
    MagicSocialAuthArtisanProvider(),
    MagicStarterArtisanProvider(),
  ];
}
