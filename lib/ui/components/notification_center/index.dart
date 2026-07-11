// NotificationCenter component: folder-local barrel.
//
// Canonical atomic-component shape: the recipe, the component, and the
// preview each live in their own dotted-suffix file; this index re-exports
// the public surface (component + model + recipe). The preview is
// intentionally NOT re-exported here: `previews:refresh` discovers
// `*.preview.dart` files directly, and the preview must stay out of the
// release barrel.

export 'notification_center.dart'
    show
        NotificationCenter,
        NotificationItem,
        AppNotificationKind,
        kSampleNotifications,
        notificationItemFromDatabaseNotification,
        notificationItemsFromDatabaseNotifications;
export 'notification_center.recipe.dart';
