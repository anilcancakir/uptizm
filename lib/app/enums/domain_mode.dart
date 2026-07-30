import 'package:magic/magic.dart';

/// How a status page is served to the public.
///
/// - [subdomain]: `slug.uptizm.com`.
/// - [path]: `uptizm.com/s/slug`.
/// - [custom]: a tenant-owned hostname stored in `custom_domain`.
///
/// Mirrors `App\Enums\DomainMode` in the backend. Adding a case here is a
/// two-sided contract change: the backend validates the wire value against its
/// own enum and 422s anything it does not know.
///
/// [custom] is readable but not offered by the editor's segmented control (see
/// `status_page_editor_view.dart`), because the app has no custom-domain
/// onboarding flow yet. It is present so a page configured through the API
/// reads back as what it is rather than falling back to another mode.
enum DomainMode {
  /// Served on a dedicated subdomain, e.g. `acme.uptizm.com`.
  subdomain,

  /// Served under a shared path, e.g. `uptizm.com/s/acme`.
  path,

  /// Served on a hostname the tenant owns and points at us.
  custom;

  /// Localized label shown in the editor's domain-mode control.
  String get label => switch (this) {
    DomainMode.subdomain => trans('uptizm.enums.domain_mode.subdomain'),
    DomainMode.path => trans('uptizm.enums.domain_mode.path'),
    DomainMode.custom => trans('uptizm.enums.domain_mode.custom'),
  };
}
