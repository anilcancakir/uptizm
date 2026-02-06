import 'package:magic/magic.dart';
import '../listeners/auth_restore_listener.dart';

class EventServiceProvider extends BaseEventServiceProvider {
  EventServiceProvider(super.app);

  @override
  Map<Type, List<MagicListener Function()>> get listen => {
    AuthRestored: [() => AuthRestoreListener()],
  };
}
