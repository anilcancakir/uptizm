import 'package:fluttersdk_magic/fluttersdk_magic.dart';

class AppEventServiceProvider extends EventServiceProvider {
  AppEventServiceProvider(super.app);

  @override
  Map<Type, List<MagicListener Function()>> get listen => {};
}
