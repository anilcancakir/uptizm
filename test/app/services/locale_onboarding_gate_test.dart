import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/services/locale_onboarding_gate.dart';

/// Unit test for the one-time locale-onboarding gate.
///
/// The gate persists a "seen" flag in [Vault] and mirrors it into an in-memory
/// field so the routing middleware can read it synchronously. These tests prove
/// the load / mark round-trip against a faked vault.
void main() {
  setUp(() {
    Vault.fake();
    LocaleOnboardingGate.instance.resetForTesting();
  });

  tearDown(() {
    Vault.unfake();
    LocaleOnboardingGate.instance.resetForTesting();
  });

  test('is not completed by default before any load', () {
    expect(LocaleOnboardingGate.instance.isCompleted, isFalse);
  });

  test('load resolves completed when the vault flag is present', () async {
    Vault.fake({LocaleOnboardingGate.vaultKey: '1'});

    await LocaleOnboardingGate.instance.load();

    expect(LocaleOnboardingGate.instance.isCompleted, isTrue);
  });

  test('load resolves not-completed when the vault flag is absent', () async {
    await LocaleOnboardingGate.instance.load();

    expect(LocaleOnboardingGate.instance.isCompleted, isFalse);
  });

  test('markCompleted flips the flag and persists it to the vault', () async {
    final fake = Vault.fake();

    await LocaleOnboardingGate.instance.markCompleted();

    expect(LocaleOnboardingGate.instance.isCompleted, isTrue);
    fake.assertWritten(LocaleOnboardingGate.vaultKey);
    expect(await Vault.get(LocaleOnboardingGate.vaultKey), isNotNull);
  });
}
