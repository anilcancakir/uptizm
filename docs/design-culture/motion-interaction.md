# Motion and interaction (Flutter/Wind reference)

Read this when adding any animation or interactive feedback to a screen. The 2026 consensus is
restraint: subtle, fast, purposeful. Motion you notice is usually wrong.

## When to animate (and when not)

Animate only for a reason: feedback (confirm an action), continuity (connect states), spatial
orientation (where did this come from), or guiding attention. Everything else is decoration; cut it.

Frequency rule: the more often an action repeats, the less it should animate.

| Frequency | Guideline |
|---|---|
| 100+ per day (toggles, tab switches) | No animation, instant |
| Tens per day (hover, nav item tap) | Minimal, <=150ms |
| Occasional (modals, drawers, toasts) | Standard animation |
| Rare / first-run (onboarding, empty state) | A little delight is allowed |

## Easing

In Flutter, easing maps to `Curve` values in `CurvedAnimation`:

| Intent | Flutter Curve | Approximate bezier |
|---|---|---|
| Element entering | `Curves.easeOut` | cubic-bezier(0.23, 1, 0.32, 1) |
| Material entering | `Curves.easeOutCubic` | cubic-bezier(0.05, 0.7, 0.1, 1) |
| Moving / morphing | `Curves.easeInOut` | cubic-bezier(0.2, 0, 0, 1) |
| Element exiting | `Curves.easeIn` | acceptable ONLY for exits |
| Continuous (spinner) | `Curves.linear` | linear |

Never use `Curves.easeIn` for entering UI; it delays movement exactly when the user is watching.

## Durations

Exit animations should be 20-30% faster than their enter counterpart.

| Category | Duration |
|---|---|
| Micro (button press, tooltip, hover) | 100-200ms (press feedback 75-100ms) |
| Standard (dropdown, popover, select) | 200-300ms |
| Large (modal, drawer, route transition) | 300-500ms |
| Hard cap for UI feedback | 300ms |

```dart
// Standard dropdown
AnimatedContainer(
  duration: const Duration(milliseconds: 200),
  curve: Curves.easeOut,
  ...
)

// Large modal enter
PageRouteBuilder(
  transitionDuration: const Duration(milliseconds: 350),
  ...
)
```

## Micro-interactions in Flutter

Every interactive element has six states: default, hover (desktop), focused, pressed, disabled,
loading. A missing loading or disabled state is the most common quality gap.

In Wind `className`, the state prefixes are:

```dart
WButton(
  className: '''
    transition-colors duration-100
    hover:bg-primary-container
    focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2
    active:opacity-90
    disabled:opacity-50
    motion-safe:active:scale-95
  ''',
  ...
)
```

- Hover: color/opacity shift, 100-150ms. On mobile there is no hover; `WAnchor` gates hover
  behind pointer-device detection automatically.
- Press: `motion-safe:active:scale-95` at ~75ms for tactile feedback. Gate with `motion-safe:`
  so it does not fire under reduced motion.
- Focus: `focus-visible:ring-2 focus-visible:ring-primary`; keyboard only (never bare `:focus`);
  never removed.
- Disabled: `disabled:opacity-50`. Also set `onPressed: null` to disable the GestureDetector.
- Loading: show a loading spinner or skeleton; do not disable without visual feedback.

## Overlays and transitions in Flutter

For modals, bottom sheets, and drawers, Flutter's built-in route system handles the animation
curve. Customize via `PageRouteBuilder` or `showModalBottomSheet` parameters:

```dart
// Bottom sheet: slide up from bottom, ease-out
showModalBottomSheet(
  context: context,
  isScrollControlled: true,
  transitionAnimationController: AnimationController(
    duration: const Duration(milliseconds: 300),
    vsync: this,
  ),
  builder: (_) => BottomSheetContent(),
)
```

For in-page expand/collapse (accordion, inline panel):

```dart
AnimatedSize(
  duration: const Duration(milliseconds: 200),
  curve: Curves.easeOut,
  child: isExpanded ? ExpandedContent() : const SizedBox.shrink(),
)
```

Pattern: opacity + small directional slide toward the trigger gives spatial context. Keep
tooltip/dropdown entrances 100-150ms.

## Route transitions

In magic_example the router is `go_router`. Route transitions use `CustomTransitionPage`:

```dart
CustomTransitionPage(
  child: const DashboardView(),
  transitionsBuilder: (context, animation, secondaryAnimation, child) {
    return FadeTransition(opacity: animation, child: child);
  },
  transitionDuration: const Duration(milliseconds: 250),
)
```

Auth routes use `RouteTransition.none` (no animation between auth screens). For content
screens a simple fade (150-250ms, `Curves.easeOut`) is the safest default.

## Loading and skeleton states

- Show a `Skeleton` component within ~300ms if data has not arrived.
- Use `Skeleton(shape: SkeletonShape.block)` for card-shaped areas, `SkeletonShape.text` for
  text lines, `SkeletonShape.circle` for avatars.
- Reserve spinners for short blocking mutations (submit, auth).
- Gate the shimmer pulse animation behind `motion-safe:`:

```dart
Skeleton(
  className: 'motion-safe:animate-pulse bg-surface-container-high rounded',
)
```

## Performance in Flutter

- Animate ONLY properties handled by the compositor: `opacity`, `transform` (via `Transform` or
  `AnimatedContainer`). Avoid animating `width`, `height`, `padding`, or `margin` (layout pass
  every frame).
- Use `RepaintBoundary` around complex animated subtrees to isolate repaints.
- Avoid many simultaneous animations in one viewport. Use `ListView.builder` or
  `SliverList` for long off-screen lists; Flutter will tree-shake non-visible widgets.
- `TickerProviderStateMixin` properly disposes controllers; always call `controller.dispose()`
  in `State.dispose()`.

## Accessibility and restraint

Respect the OS "Reduce Motion" setting via `MediaQuery.of(context).disableAnimations`:

```dart
final reduceMotion = MediaQuery.of(context).disableAnimations;

// Wind className: motion-safe: prefix gates the animation token
WButton(className: 'motion-safe:active:scale-95 ...')

// Dart animation controller: skip or instant when reduced
controller.duration = reduceMotion
    ? Duration.zero
    : const Duration(milliseconds: 200);
```

Disable under reduced motion: parallax, scale/zoom, large pan/translate, looping autoplay,
staggered reveals, shimmer. Keep or substitute: opacity fades, color transitions, instant snap.
Substitute, do not just delete, motion that conveys state (for example, replace a spinner with a
static icon when motion is disabled, do not hide loading state entirely).

No content flashes more than 3 times per second. Auto-playing motion over 5 seconds needs a
pause control.

## Per-screen motion checklist

Before marking any screen done:

1. Every animated element guards against reduced motion via `motion-safe:` or
   `MediaQuery.of(context).disableAnimations`.
2. Only `opacity` and `transform` animate; no layout-triggering properties.
3. Entrances `Curves.easeOut`; exits faster; no `Curves.easeIn` on entrances.
4. UI feedback under 200ms; large transitions under 500ms.
5. Six interactive states present (default, hover, focus, active, disabled, loading).
6. Auto-play is controllable; nothing flashes >3 times/sec.

## See also

- [DESIGN.md](../DESIGN.md): brand personality and DESIGN.md motion direction
- [accessibility-wcag.md](accessibility-wcag.md): WCAG 2.2.2 / 2.3.1 reduced-motion requirements
- [wind-responsive.md](wind-responsive.md): safe-area, layout transitions
- [apple-hig.md](apple-hig.md): Apple spring-based motion feel
- [material-design-3.md](material-design-3.md): M3 easing tokens

## Sources

- Emil Kowalski / animations.dev (easing + duration tables, frequency rule).
- Material Design 3 motion: easing/duration tokens (m3.material.io/styles/motion).
- Apple HIG motion (developer.apple.com); WWDC23 "Animate with springs".
- Flutter documentation: CurvedAnimation, AnimationController, AnimatedContainer, AnimatedSize,
  MediaQuery.disableAnimations, RepaintBoundary.
- WCAG 2.2: 2.2.2 / 2.3.1 / 2.3.3.
