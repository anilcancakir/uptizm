---
generated: manual (design:registry planned)
source: magic_starter generic component library
last_updated: 2026-06-25
---

# Component Registry

Machine-readable manifest of every component in the `magic_starter` generic component library. Maps each component to its variants, token bindings, and anti-patterns.

> **design:registry note**: this file is intended to be generated and kept in sync by `make:component` and `previews:refresh`. Until that command emits it automatically, maintain it by hand when adding or modifying components.

---

## Primitives

Components backed by a Wind W-widget with no recipe layer.

---

## Form Inputs

### Button

- **File**: `magic_starter/lib/src/ui/components/button/`
- **Class**: `Button`
- **Recipe**: `WindRecipe` in `button.recipe.dart`
- **Variants**:
  - `intent`: `primary` | `secondary` | `ghost` | `destructive`
  - `size`: `sm` | `md` | `lg`
- **Default variants**: `intent=primary`, `size=md`
- **Token bindings**:
  - `primary`: `bg-primary text-on-primary`
  - `secondary`: `bg-surface-container text-fg border border-color-border`
  - `ghost`: `bg-transparent text-fg-muted`
  - `destructive`: `bg-destructive text-on-destructive`
  - `sm`: `text-xs px-3 py-1.5`
  - `md`: `text-sm px-4 py-2`
  - `lg`: `text-base px-6 py-3`
- **Anti-patterns**:
  - Do not use more than one primary button per section.
  - Do not use destructive intent outside confirm dialogs without a secondary confirmation step.
  - Do not hardcode colors via `className` override when a variant covers the case.

---

### Input

- **File**: `magic_starter/lib/src/ui/components/input/`
- **Class**: `Input`
- **Recipe**: `WindRecipe` in `input.recipe.dart`
- **Variants**:
  - `state`: `default` | `error`
- **Default variants**: `state=default`
- **Token bindings**:
  - `default`: `bg-surface-container-high border border-color-border text-fg`
  - `error`: `bg-surface-container-high border border-color-destructive text-fg`
- **Anti-patterns**:
  - Do not render error state without an error message in the parent `FormField`.
  - Do not use raw `WInput` directly; prefer `Input` so the recipe layer is consistent.

---

### Textarea

- **File**: `magic_starter/lib/src/ui/components/textarea/`
- **Class**: `Textarea`
- **Recipe**: `WindRecipe` in `textarea.recipe.dart`
- **Variants**:
  - `state`: `default` | `error`
- **Default variants**: `state=default`
- **Token bindings**: same as Input.
- **Anti-patterns**: same as Input.

---

### Checkbox

- **File**: `magic_starter/lib/src/ui/components/checkbox/`
- **Class**: `Checkbox`
- **Recipe**: `WindRecipe` in `checkbox.recipe.dart`
- **Variants**: none (state is driven by `checked:` prefix)
- **Token bindings**:
  - unchecked: `border-color-border bg-surface-container-high`
  - checked (`checked:` state): `bg-primary border-primary`
- **Anti-patterns**:
  - Do not use Material `Checkbox`; always use this component.

---

### Switch

- **File**: `magic_starter/lib/src/ui/components/switch/`
- **Class**: `Switch`
- **Recipe**: `WindRecipe` in `switch.recipe.dart`
- **Variants**: none (state is driven by `checked:` prefix on track/thumb)
- **Token bindings**:
  - track off: `bg-surface-container border-color-border`
  - track on (`checked:`): `bg-primary`
  - thumb: `bg-surface`
- **Anti-patterns**:
  - Do not use Material `Switch`.
  - Do not animate thumb translate outside the Wind checked state prefix.

---

### Radio

- **File**: `magic_starter/lib/src/ui/components/radio/`
- **Class**: `Radio`
- **Generic type**: `Radio<T>`
- **Recipe**: `WindRecipe` in `radio.recipe.dart`
- **Variants**: none (state is driven by `selected:` prefix)
- **Token bindings**:
  - unselected: `border-color-border bg-surface-container-high`
  - selected (`selected:`): `bg-primary border-primary`
- **Anti-patterns**:
  - Do not use Material `Radio`.
  - Group state management is the caller's responsibility (pass `groupValue`).

---

## Display

### Badge

- **File**: `magic_starter/lib/src/ui/components/badge/`
- **Class**: `Badge`
- **Recipe**: `WindRecipe` in `badge.recipe.dart`
- **Variants**:
  - `tone`: `neutral` | `primary` | `accent` | `success` | `warning` | `destructive` | `outline`
- **Default variants**: `tone=neutral`
- **Token bindings**:
  - `neutral`: `bg-surface-container text-fg-muted`
  - `primary`: `bg-primary-container text-primary`
  - `accent`: `bg-accent text-on-primary`
  - `success`: `bg-success text-on-primary`
  - `warning`: `bg-warning text-on-primary`
  - `destructive`: `bg-destructive-container text-destructive`
  - `outline`: `bg-transparent text-fg border border-color-border`
- **Anti-patterns**:
  - Do not use badges for interactive elements; they are display-only.
  - Do not use raw hex to create a custom tone; add a new variant value instead.

---

### Typography

- **File**: `magic_starter/lib/src/ui/components/typography/`
- **Class**: `Typography`
- **Recipe**: `WindRecipe` in `typography.recipe.dart`
- **Variants**:
  - `variant`: `h1` | `h2` | `h3` | `body` | `caption`
- **Default variants**: `variant=body`
- **Token bindings**:
  - `h1`: `text-3xl font-bold text-fg leading-tight tracking-tight`
  - `h2`: `text-2xl font-bold text-fg`
  - `h3`: `text-xl font-semibold text-fg`
  - `body`: `text-sm text-fg`
  - `caption`: `text-xs text-fg-muted`
- **Anti-patterns**:
  - Do not use raw `WText` for typographic content; use `Typography` so the scale is consistent.
  - Semantics (h1/h2) are secondary to hierarchy; a section title can use `h2` even inside a card.

---

### Skeleton

- **File**: `magic_starter/lib/src/ui/components/skeleton/`
- **Class**: `Skeleton`
- **Recipe**: `WindRecipe` in `skeleton.recipe.dart`
- **Variants**:
  - `shape`: `block` | `text` | `circle`
- **Default variants**: `shape=block`
- **Token bindings**:
  - all shapes: `bg-surface-container-high motion-safe:animate-pulse`
- **Anti-patterns**:
  - Use `Skeleton` instead of spinners for content loading states.
  - Do not animate outside `motion-safe:` prefix (respect `disableAnimations`).

---

## Card

### Card (migrated from MagicStarterCard)

- **File**: `magic_starter/lib/src/ui/components/card/`
- **Class**: `Card`
- **Enum**: `CardVariant`
- **Recipe**: `WindRecipe` in `card.recipe.dart`
- **Variants**:
  - `tone`: `surface` | `inset` | `elevated`
- **Default variants**: `tone=surface`
- **Token bindings**:
  - `surface`: `bg-surface-container border border-color-border`
  - `inset`: `bg-surface-container-high`
  - `elevated`: `bg-surface shadow-sm`
- **Slots**: `header`, `child` (body), `footer`
- **Anti-patterns**:
  - Do not bake CardVariant logic into child components; pass `tone` to `Card` at the call site.
  - Do not use `elevated` on dark backgrounds where shadow is invisible; prefer `surface` with a border.

---

## Selection

### Select

- **File**: `magic_starter/lib/src/ui/components/select/`
- **Class**: `Select`
- **Recipe**: `WindSlotRecipe` in `select.recipe.dart`
- **Slots**: `trigger`, `popup`, `item`
- **Token bindings**:
  - trigger: `bg-surface-container-high border border-color-border text-fg rounded-DEFAULT`
  - popup: `bg-surface border border-color-border shadow-sm rounded-md`
  - item: `text-sm text-fg hover:bg-surface-container-high`
- **Anti-patterns**:
  - Do not use Material `DropdownButton`; use `Select`.

---

### Combobox

- **File**: `magic_starter/lib/src/ui/components/combobox/`
- **Class**: `Combobox`
- **Recipe**: `WindSlotRecipe` in `combobox.recipe.dart`
- **Slots**: `trigger`, `popup`, `item`
- **Token bindings**: same as Select, plus debounce search input.
- **Anti-patterns**: same as Select.

---

### SegmentedControl

- **File**: `magic_starter/lib/src/ui/components/segmented_control/`
- **Class**: `SegmentedControl`
- **Recipe**: `WindSlotRecipe` in `segmented_control.recipe.dart`
- **Variants**:
  - `size`: `sm` | `md`
- **Slots**: `root`, `item`
- **Token bindings**:
  - root: `bg-surface-container rounded-md p-0.5`
  - item active (`selected:`): `bg-surface text-fg shadow-sm rounded-sm`
  - item inactive: `text-fg-muted`
- **Anti-patterns**:
  - Do not use for more than 4-5 options; use `Tabs` or a `Select` instead.

---

### Tabs

- **File**: `magic_starter/lib/src/ui/components/tabs/`
- **Class**: `Tabs`
- **Recipe**: `WindSlotRecipe` in `tabs.recipe.dart`
- **Slots**: `list`, `tab`, `panel`
- **Token bindings**:
  - list: `border-b border-color-border`
  - tab inactive: `text-fg-muted`
  - tab active (`selected:`): `text-primary border-b-2 border-primary`
  - panel: `pt-4`
- **Anti-patterns**:
  - Do not use Material `TabBar`; use `Tabs`.

---

### Accordion

- **File**: `magic_starter/lib/src/ui/components/accordion/`
- **Class**: `Accordion`
- **Recipe**: `WindSlotRecipe` in `accordion.recipe.dart`
- **Slots**: `root`, `item`, `header`, `trigger`, `panel`
- **Token bindings**:
  - root: `border border-color-border rounded-md divide-y divide-color-border`
  - trigger: `text-fg font-medium`
  - panel: `text-fg-muted text-sm px-4 pb-4`
- **Anti-patterns**:
  - Do not use for top-level navigation; use for secondary content disclosure only.

---

## Overlays

### Dialog

- **File**: `magic_starter/lib/src/ui/components/dialog/`
- **Class**: `Dialog`
- **Recipe**: `WindSlotRecipe` in `dialog.recipe.dart`
- **Slots**: `backdrop`, `panel`, `title`, `footer`
- **Token bindings**:
  - backdrop: `bg-fg/50` (semi-transparent fg overlay)
  - panel: `bg-surface rounded-lg shadow-xl max-w-md w-full`
  - title: `text-fg font-semibold text-lg`
  - footer: `flex gap-3 justify-end pt-4`
- **Anti-patterns**:
  - Always use `Dialog.show()` static factory; do not push dialogs as routes.
  - Keep dialog content focused; avoid multi-step flows inside a single dialog.

---

### ConfirmDialog

- **File**: `magic_starter/lib/src/ui/components/confirm_dialog/`
- **Class**: `ConfirmDialog`
- **Enum**: `ConfirmDialogVariant`
- **Recipe**: `WindSlotRecipe` in `confirm_dialog.recipe.dart`
- **Variants**:
  - `variant`: `primary` | `danger` | `warning`
- **Token bindings**:
  - `danger`: confirm button uses `Button(intent: ButtonIntent.destructive)`
  - `warning`: confirm button uses `Button(intent: ButtonIntent.secondary)` with warning badge
  - `primary`: confirm button uses `Button(intent: ButtonIntent.primary)`
- **Anti-patterns**:
  - Use `danger` for irreversible destructive actions only (account deletion, data wipe).
  - Do not use `warning` for routine confirmation; reserve it for significant but reversible changes.

---

### BottomSheet

- **File**: `magic_starter/lib/src/ui/components/bottom_sheet/`
- **Class**: `BottomSheet`
- **Recipe**: `WindSlotRecipe` in `bottom_sheet.recipe.dart`
- **Slots**: `backdrop`, `panel`, `handle`, `title`, `footer`
- **Token bindings**:
  - panel: `bg-surface rounded-t-xl`
  - handle: `bg-surface-container-high rounded-full`
- **Anti-patterns**:
  - Respect `SafeArea` at the bottom for home indicator.
  - Do not embed complex multi-step flows; keep to contextual actions.

---

### Toast

- **File**: `magic_starter/lib/src/ui/components/toast/`
- **Class**: `Toast`
- **Recipe**: `WindRecipe` in `toast.recipe.dart`
- **Variants**:
  - `tone`: `neutral` | `success` | `warning` | `destructive`
- **Token bindings**:
  - `neutral`: `bg-surface border border-color-border text-fg`
  - `success`: `bg-success text-on-primary`
  - `warning`: `bg-warning text-on-primary`
  - `destructive`: `bg-destructive text-on-destructive`
- **Anti-patterns**:
  - Use for non-critical feedback only; critical errors belong in a dialog or inline error state.
  - Auto-dismiss after 4-6 seconds unless action is required.

---

### Tooltip

- **File**: `magic_starter/lib/src/ui/components/tooltip/`
- **Class**: `Tooltip`
- **Recipe**: `WindSlotRecipe` in `tooltip.recipe.dart`
- **Slots**: `trigger`, `content`
- **Token bindings**:
  - content: `bg-fg text-surface text-xs rounded-md px-2 py-1`
- **Anti-patterns**:
  - Do not use tooltips for essential information; they are invisible on touch devices.
  - WPopover real-click dismiss race is a known issue; do not add Tooltip to interactive paths that require precise tap timing.

---

### DropdownMenu

- **File**: `magic_starter/lib/src/ui/components/dropdown_menu/`
- **Class**: `DropdownMenu`
- **Recipe**: `WindSlotRecipe` in `dropdown_menu.recipe.dart`
- **Slots**: `trigger`, `panel`, `item`, `separator`
- **Token bindings**:
  - panel: `bg-surface border border-color-border rounded-md shadow-sm`
  - item: `text-sm text-fg hover:bg-surface-container-high`
  - separator: `border-t border-color-border my-1`
- **Anti-patterns**:
  - Do not use for primary navigation (use `Navbar` or `Tabs`).
  - WPopover real-click dismiss race is a known issue; do not regress dismiss behavior.

---

## Structure

### FormField

- **File**: `magic_starter/lib/src/ui/components/form_field/`
- **Class**: `FormField` (exported as `MagicFormField` to avoid collision with Flutter's `FormField`)
- **Recipe**: `WindSlotRecipe` in `form_field.recipe.dart`
- **Slots**: `root`, `label`, `hint`, `error`
- **Token bindings**:
  - root: `flex flex-col gap-1`
  - label: `text-sm font-medium text-fg`
  - hint: `text-xs text-fg-muted`
  - error: `text-xs text-destructive`
- **Anti-patterns**:
  - Always wrap `Input`/`Textarea` in `MagicFormField`; never render label/error inline.
  - Import as `MagicFormField` to avoid collision with Flutter's `FormField` widget.

---

### PageHeader

- **File**: `magic_starter/lib/src/ui/components/page_header/`
- **Class**: `PageHeader`
- **Recipe**: `WindSlotRecipe` in `page_header.recipe.dart`
- **Slots**: `title`, `subtitle`, `leading`, `actions`, `inlineActions`
- **Token bindings**:
  - title: `text-xl font-bold text-fg`
  - subtitle: `text-sm text-fg-muted`
- **Anti-patterns**:
  - Do not add navigation chrome inside `PageHeader`; it is a content title, not an app bar.

---

### EmptyState

- **File**: `magic_starter/lib/src/ui/components/empty_state/`
- **Class**: `EmptyState`
- **Recipe**: `WindSlotRecipe` in `empty_state.recipe.dart`
- **Slots**: `root`, `iconWrap`, `title`, `description`, `action`
- **Token bindings**:
  - iconWrap: `text-fg-disabled`
  - title: `text-fg font-semibold text-lg`
  - description: `text-fg-muted text-sm`
- **Anti-patterns**:
  - Always include a call-to-action in the `action` slot; an empty state without an action is a dead end.
  - Hide filters, tabs, or sorting controls that do not apply when the list is empty.

---

### ErrorState

- **File**: `magic_starter/lib/src/ui/components/error_state/`
- **Class**: `ErrorState`
- **Recipe**: `WindSlotRecipe` in `error_state.recipe.dart`
- **Slots**: `root`, `iconWrap`, `title`, `description`, `action`
- **Token bindings**:
  - iconWrap: `text-destructive`
  - title: `text-red-700 dark:text-red-400 font-semibold text-lg`
  - description: `text-fg-muted text-sm`
- **Anti-patterns**:
  - Use for unrecoverable states; for recoverable network errors, show a retry button in the `action` slot.

---

### Navbar

- **File**: `magic_starter/lib/src/ui/components/navbar/`
- **Class**: `Navbar`
- **Recipe**: `WindSlotRecipe` in `navbar.recipe.dart`
- **Slots**: `root`, `item`, `activeItem`
- **Token bindings**:
  - root: `bg-surface border-t border-color-border`
  - item inactive: `text-fg-muted`
  - item active (`selected:`): `text-primary`
- **Anti-patterns**:
  - Limit to 3-5 primary destinations.
  - Do not place secondary actions in the bottom nav; use `DropdownMenu` or a settings page.

---

## Composites

### SocialDivider

- **File**: `magic_starter/lib/src/ui/components/social_divider/`
- **Class**: `SocialDivider`
- **Token bindings**: `border-color-border text-fg-muted`
- **Anti-patterns**:
  - Use only on auth screens to separate email login from social login options.

---

### NotificationDropdown

- **File**: Composite consuming `DropdownMenu` + `Badge`
- **Token bindings**: inherits from composites.
- **Anti-patterns**:
  - Do not change the `StreamBuilder` unread-count subscription pattern; it is intentional.

---

### UserProfileDropdown

- **File**: Composite consuming `DropdownMenu`
- **Anti-patterns**:
  - Do not add business logic to the dropdown; route to profile/settings views.

---

### TeamSelector

- **File**: Composite consuming `Select` or `DropdownMenu`
- **Anti-patterns**:
  - Keep team-switch callback through `teamResolver`; do not hard-wire team ID.

---

## Anti-patterns (global)

| Anti-pattern | Category | Fix |
|-------------|----------|-----|
| Raw `Color(0xFF...)` or `Colors.*` in recipe or widget | Token violation | Use semantic alias (e.g. `bg-primary`) |
| Hardcoded pixel margin (`SizedBox(height: 13)`) | Spacing violation | Use Wind spacing utilities on the 4px scale |
| Multiple preview classes in one file | Preview structure | One `*.preview.dart` per component |
| Exporting preview class from `index.dart` | Preview boundary | `previews:refresh` discovers `*.preview.dart` directly |
| Importing `package:fluttersdk_wind/src/...` directly | Import convention | Use `package:magic/magic.dart` (re-exports wind) |
| Using Material `Switch`, `Checkbox`, `Radio`, `TabBar` | Primitive collision | Use the project component equivalents |
| CSS-only Wind utilities (`box-shadow`, `filter`, `transform`) | Wind unsupported | Use Flutter animation APIs |
| `Icons.*` inline in widget body | Tree-shaking | Extract as `static const IconData _icon = Icons.x;` |
| Missing `dark:` on any color token | Dark parity | Every alias expands to a light+dark pair |
