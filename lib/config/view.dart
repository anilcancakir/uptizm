/// View Configuration.
///
/// Customizes the appearance of Magic UI components (dialogs, confirms,
/// loading). These className values are read by MagicFeedback via
/// `Config.get('view.*')`.
///
/// Every colour here is a DESIGN.md semantic alias. The values shipped as raw
/// Tailwind palette tokens (`bg-white dark:bg-gray-800`, `text-gray-900`,
/// `bg-red-500`), which put every framework-raised dialog on a different grey
/// from the rest of the app: uptizm's dark surface is #121518, gray-800 is
/// #1F2937. The confirm button also paired `bg-primary` with `text-white`,
/// but dark-mode primary is #00C292 and its foreground is the near-black
/// `text-on-primary`, so white on that green failed contrast.
///
/// Each alias already carries its own `dark:` peer, so none of these needs one.
Map<String, dynamic> get viewConfig => {
  'view': {
    'dialog': {
      'class':
          'bg-surface-container border border-color-border rounded-lg p-6 '
          'max-w-lg',
    },
    'confirm': {
      'container_class':
          'bg-surface-container border border-color-border rounded-lg p-6 w-80',
      'title_class': 'text-lg font-bold text-fg',
      'message_class': 'text-fg-muted mt-2',
      'button_cancel_class': 'px-4 py-2 text-fg-muted',
      'button_confirm_class':
          'px-4 py-2 bg-primary text-on-primary rounded-lg',
      'button_danger_class':
          'px-4 py-2 bg-destructive text-on-destructive rounded-lg',
    },
  },
};
