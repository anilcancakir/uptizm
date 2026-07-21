import 'package:magic/magic.dart';

/// How a status page is served to the public.
///
/// - [subdomain]: `slug.uptizm.com`.
/// - [path]: `uptizm.com/status/slug`.
///
/// Mirrors the `DomainMode` union in the React status mock.
enum DomainMode {
  /// Served on a dedicated subdomain, e.g. `acme.uptizm.com`.
  subdomain,

  /// Served under a shared path, e.g. `uptizm.com/status/acme`.
  path;

  /// Localized label shown in the editor's domain-mode control.
  String get label => switch (this) {
    DomainMode.subdomain => trans('uptizm.enums.domain_mode.subdomain'),
    DomainMode.path => trans('uptizm.enums.domain_mode.path'),
  };
}
