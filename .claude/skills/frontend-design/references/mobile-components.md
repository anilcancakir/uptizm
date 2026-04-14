# Mobile Components

Platform-specific component patterns, navigation, and mobile finishing touches.

## Contents

- [Navigation Patterns](#navigation-patterns)
- [App Bar Patterns](#app-bar-patterns)
- [List Patterns](#list-patterns)
- [Form Patterns](#form-patterns)
- [Feedback Patterns](#feedback-patterns)
- [Empty States](#empty-states)
- [Pull to Refresh](#pull-to-refresh)
- [Mobile Finishing Touches](#mobile-finishing-touches)
- [Touch Target Checklist](#touch-target-checklist)

---

## Navigation Patterns

| Pattern | Use Case | Platform |
|---------|----------|----------|
| Bottom navigation | 3-5 primary destinations | Both |
| Tab bar (top) | Content categories | Android |
| Navigation drawer | Many destinations, settings | Both |
| Bottom sheet nav | Contextual actions | Both |

**Bottom navigation rules:**

- 3-5 items maximum
- Icons required, labels recommended
- Active state: filled icon + color
- Badge for notifications

---

## App Bar Patterns

| Type | Use Case |
|------|----------|
| Standard | Title, leading icon, actions |
| Search | Integrated search field |
| Collapsing | Hero image + title that shrinks |
| Bottom app bar | FAB integration (Android) |

**Leading icons:**

- Menu hamburger: opens drawer
- Back arrow: navigation pop
- Close X: dismiss modal/sheet

---

## List Patterns

| Pattern | When to Use |
|---------|-------------|
| Simple list | Text-only items |
| List with avatar | Person/entity lists |
| List with thumbnail | Media content |
| Two-line list | Title + subtitle |
| Three-line list | Title + subtitle + meta (dense info) |

**Interactive lists:**

- Swipe to delete/archive
- Long press for selection mode
- Pull to refresh

---

## Form Patterns

**Input fields:**

- Full width with horizontal padding
- Label above or floating
- Helper text below
- Error state: red border + message

**Buttons:**

- Primary: Full width at bottom OR floating action button
- Secondary: Outlined or text, paired with primary
- Vertical stacks: Primary on top

---

## Feedback Patterns

| Type | Use Case |
|------|----------|
| Snackbar | Transient message, optional undo action |
| Toast | Simple confirmation |
| Dialog | Requires user decision |
| Bottom sheet | Multiple options to choose from |

**Loading states:**

- Skeleton screens (preferred over spinners)
- Shimmer effect for content placeholders
- Circular progress: centered
- Linear progress: top of screen

---

## Empty States

Design empty states as first impressions:

- Illustration or icon to grab attention
- Clear title explaining what goes here
- Helpful description
- Call-to-action button to get started
- Hide irrelevant UI (tabs, filters that don't work yet)

---

## Pull to Refresh

- Circular indicator appears on pull
- Responsive feel
- Show loading state during refresh
- Snap back on release

---

## Mobile Finishing Touches

- Custom checkbox/radio with brand colors
- Styled switches (iOS or custom)
- Animated icons (loading, success states)
- Hero animations between screens
- Haptic feedback on key actions

---

## Touch Target Checklist

- Icon buttons have invisible padding to reach 44pt/48dp minimum
- Links in body text are easy to tap (or use buttons instead)
- Close buttons aren't pushed into corners where fingers slip off edges
- Adequate spacing between adjacent interactive elements
- Visual feedback on press (ripple, scale, opacity change)
