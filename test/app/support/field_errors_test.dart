import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/support/field_errors.dart';

/// Pins the one collapse policy [fieldErrorsFromResponse] and
/// [fieldErrorsFromModel] both apply, per key family: a `credentials.*`
/// sub-key stays whole because the channel form has a separate slot per
/// sub-key, while a bulk `metrics.<row>.<field>.<element>` key collapses onto
/// its field, because the form renders one chip-list per field and nowhere
/// to put a per-element error. A `.split('.').first` reading of the second
/// family answers `metrics`, which is the exact bug this replaces.
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  group('fieldErrorsFromResponse', () {
    final Map<String, ({Map<String, List<String>> errors, Map<String, String> expected})>
    cases = {
      'a flat key stays whole': (
        errors: {
          'name': ['The name field is required.'],
        },
        expected: {'name': 'The name field is required.'},
      ),
      'a credentials sub-key is NOT collapsed, one slot per sub-key': (
        errors: {
          'credentials.token': ['The credentials.token field is required.'],
          'credentials.url': ['The credentials.url field is required.'],
        },
        expected: {
          'credentials.token': 'The credentials.token field is required.',
          'credentials.url': 'The credentials.url field is required.',
        },
      ),
      'a bulk list-element key collapses onto its field, past the row index':
          (
            errors: {
              'metrics.0.ok_values.0': [
                'The metrics.0.ok_values.0 field is invalid.',
              ],
            },
            expected: {'ok_values': 'The metrics.0.ok_values.0 field is invalid.'},
          ),
      'a single-metric list-element key collapses the same way': (
        errors: {
          'ok_values.0': ['The ok_values.0 field is invalid.'],
        },
        expected: {'ok_values': 'The ok_values.0 field is invalid.'},
      ),
      'two failing elements of the same bulk list collapse to one field, '
          'first message wins': (
        errors: {
          'metrics.0.ok_values.0': ['first message'],
          'metrics.0.ok_values.1': ['second message'],
        },
        expected: {'ok_values': 'first message'},
      ),
      'only the first message of a multi-message field is kept': (
        errors: {
          'name': ['first message', 'second message'],
        },
        expected: {'name': 'first message'},
      ),
    };

    for (final MapEntry<
      String,
      ({Map<String, List<String>> errors, Map<String, String> expected})
    >
    entry
        in cases.entries) {
      test(entry.key, () {
        final MagicResponse response = MagicResponse(
          data: {'errors': entry.value.errors},
          statusCode: 422,
        );

        expect(fieldErrorsFromResponse(response), entry.value.expected);
      });
    }
  });

  group('fieldErrorsFromModel', () {
    test(
      'applies the same collapse policy as fieldErrorsFromResponse',
      () async {
        Http.fake(
          (MagicRequest request) => Http.response({
            'message': 'The given data was invalid.',
            'errors': {
              'credentials.token': ['The credentials.token field is required.'],
              'metrics.0.ok_values.0': [
                'The metrics.0.ok_values.0 field is invalid.',
              ],
            },
          }, 422),
        );

        final _RemoteChannel channel = _RemoteChannel()
          ..fill({'name': 'Ops Slack'});
        final bool ok = await channel.save();

        expect(ok, isFalse);
        expect(fieldErrorsFromModel(channel), {
          'credentials.token': 'The credentials.token field is required.',
          'ok_values': 'The metrics.0.ok_values.0 field is invalid.',
        });
      },
    );
  });
}

/// A remote-only model for exercising [fieldErrorsFromModel] against a real
/// 422, mirroring `magic`'s own `_RemoteUser` test model.
class _RemoteChannel extends Model with InteractsWithPersistence {
  @override
  String get table => 'remote_channels';

  @override
  String get resource => 'remote_channels';

  @override
  List<String> get fillable => ['name'];

  @override
  bool get useLocal => false;

  @override
  bool get useRemote => true;
}
