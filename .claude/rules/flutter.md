---
path: "lib/**/*.dart"
---

# Flutter / Magic Framework

- Import order: `dart:` → `package:flutter/` → `package:magic/` → `package:magic_starter/` → relative imports
- All controllers: `extends MagicController with MagicStateMixin` + `ValueNotifier` for reactive state, no Riverpod, no Bloc, no Provider
- HTTP calls: `Http.get()`, `Http.post()`, `Http.put()`, `Http.delete()`, never raw Dio or http package
- Secure storage: `Vault` facade, never flutter_secure_storage directly
- Auth queries: `Auth.check()`, `Auth.id()`, `Auth.user<User>()`, `Auth.logout()`
- Config access: `Config.get('key.nested', defaultValue)`, never hardcode values available in config
- Logging: `Log.debug()`, `Log.error()`, `Log.info()`, never `print()` or `debugPrint()`
- Routing: `MagicRoute.to()`, `MagicRoute.page()`, `MagicRoute.group()`, never raw GoRouter
- Environment: `env('KEY', defaultValue)` helper from magic, never `String.fromEnvironment` directly
- All model IDs are `String` (UUID), never `int`
- Named constructor: `const MyWidget({super.key})`, use super parameters
- Docblocks: `///` on every public class and method, include `## Usage` code examples on classes
- Section separators: `// -------` comment blocks between logical groups (Typed Accessors, Static Helpers, etc.)
- Wind UI: Use `WDiv`, `WText`, `WIcon`, `WSpacer`, `WAnchor` for layout, Tailwind classes via `className`
- Service registration: bind in `register()` (sync), bootstrap in `boot()` (async), never mix
