// StatusBadge component — folder-local barrel.
//
// Canonical atomic-component shape: the recipe, the component, and the
// preview each live in their own dotted-suffix file; this index re-exports
// the public surface (component + recipe + axis key). The preview is
// intentionally NOT re-exported here — `previews:refresh` discovers
// `*.preview.dart` files directly, and the preview must stay out of the
// release barrel.

export 'status_badge.dart' show StatusBadge;
export 'status_badge.recipe.dart';
