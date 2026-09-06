import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/user.dart';

/// What these pin: a wire value of an unexpected type degrades to null instead
/// of throwing out of a getter that runs during `build`.
///
/// Every model accessor used to read `getAttribute('k') as T?`. A hard cast
/// throws on a type mismatch, and these getters are called from widget build
/// methods, so ONE odd field took down the whole screen rather than blanking
/// one value. `escalation_policy.dart` records that exact failure in its own
/// docblock: an unguarded cast on a single step id took the whole policy decode
/// with it and blanked the editor.
///
/// `get<T>` answers null on a mismatch. For every value that already had the
/// right type nothing changes at all, because `as T?` and `is T` are the same
/// test; only the throwing case moves.
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('a fractional int field reads null rather than throwing', () {
    // `last_response_ms` DOES carry an `'int'` cast (`monitor.dart`'s casts
    // map), and that is precisely why this reaches the reader: magic's built-in
    // int cast is `int.tryParse(value.toString()) ?? value`, so a value it
    // cannot parse passes through UNCHANGED rather than being coerced or
    // rejected. A backend that ever sends a fractional millisecond (an average
    // rather than a sample) therefore hands a double to the accessor, and the
    // old `as int?` threw inside MonitorDetailView's build.
    //
    // The PR that introduced this test said the field carried no cast. It was
    // wrong about the mechanism and right about the outcome; the corrected
    // reason is above, because a wrong why is what gets copied.
    final Monitor monitor = Monitor.fromMap(const <String, dynamic>{
      'id': 'api',
      'name': 'API',
      'last_response_ms': 412.5,
    });

    expect(monitor.responseMs, isNull);
    expect(monitor.name, 'API');
  });

  test('a numeric field arriving as a string reads null, not a crash', () {
    final Monitor monitor = Monitor.fromMap(const <String, dynamic>{
      'id': 'api',
      'name': 'API',
      'last_response_ms': 'n/a',
    });

    expect(monitor.responseMs, isNull);
  });

  test('a string field arriving as a number leaves its neighbours readable', () {
    // The point of degrading rather than throwing: the rest of the row still
    // renders. A screen that blanks one value beats a screen that is gone.
    final User user = User.fromMap(const <String, dynamic>{
      'id': 1,
      'name': 42,
      'email': 'ada@example.com',
    });

    expect(user.name, isNull);
    expect(user.email, 'ada@example.com');
  });
}
