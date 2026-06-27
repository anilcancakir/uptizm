// Assistant component: folder-local barrel.
//
// Canonical atomic-component shape: the recipe, the component, and the
// preview each live in their own dotted-suffix file; this index re-exports
// the public surface (component + message model + recipes). The preview is
// intentionally NOT re-exported here: `previews:refresh` discovers
// `*.preview.dart` files directly, and the preview must stay out of the
// release barrel.

export 'assistant.dart' show Assistant, AssistantMessage, AssistantRole;
export 'assistant.recipe.dart';
