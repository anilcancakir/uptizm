// PushPrompt component: folder-local barrel.
//
// Canonical atomic-component shape: the recipe, the component, and the
// preview each live in their own dotted-suffix file; this index re-exports
// the public surface (the presentational component, its platform-wired host,
// its shell marker, and the recipes). The preview is intentionally NOT
// re-exported here: `previews:refresh` discovers `*.preview.dart` files
// directly, and the preview must stay out of the release barrel.

export 'push_prompt.dart' show PushOffNotice, PushPrompt, PushPromptHost;
export 'push_prompt.recipe.dart';
