# WDatePicker Mouse Tracker Issue - Handoff

> **Date**: 2026-02-04
> **Status**: RESOLVED
> **Issue**: `mouse_tracker` assertion errors when opening Analytics page
> **Solution**: Replaced WDatePicker with native Flutter `showDateRangePicker`

---

## Problem Summary

Analytics sayfası (`/monitors/:id/analytics`) açıldığında, sayfa yüklenir yüklenmez `mouse_tracker` assertion hataları yağıyor:

```
Assertion failed: file:///Users/anilcan/flutter/packages/flutter/lib/src/rendering/mouse_tracker.dart:199:12
!_debugDuringDeviceUpdate
is not true
```

Bu hata, Flutter'ın mouse tracking phase'i sırasında `setState` çağrıldığında oluşuyor.

---

## Current State

### WDatePicker Temporarily Disabled

Sorunu izole etmek için `WDatePicker` şu anda comment'li:

**File**: `lib/resources/views/components/analytics/date_range_selector.dart`

```dart
Widget _buildCustomDatePicker(BuildContext context) {
  // Temporarily disabled to debug mouse_tracker issue
  return const SizedBox.shrink();

  // ... WDatePicker code commented out
}
```

**KULLANICIDAN BEKLENEN**: Bu haliyle analytics sayfasını test edip hatanın devam edip etmediğini söylemesi.

- Eğer hata **DURUYORSA** → Sorun WDatePicker'da
- Eğer hata **DEVAM EDİYORSA** → Sorun başka bir yerde (chart widgets, controller, vs.)

---

## What Was Done

### 1. WDatePicker Refactored (WPopover → Direct OverlayEntry)

WDatePicker tamamen yeniden yazıldı, artık WPopover kullanmıyor:

**File**: `plugins/fluttersdk_magic/plugins/fluttersdk_wind/lib/src/widgets/w_date_picker.dart`

**Key Changes**:
- `WPopover` dependency kaldırıldı
- Direct `OverlayEntry` + `CompositedTransformFollower` kullanılıyor
- `TapRegion` ile outside tap detection
- WindTheme context'i overlay'e pass ediliyor
- `setState` çağrıları `addPostFrameCallback` ile sarmalandı

```dart
void _showOverlay() {
  // ...
  overlay.insert(_overlayEntry!);
  _isOpen = true;
  // Defer setState to avoid mouse_tracker conflicts
  WidgetsBinding.instance.addPostFrameCallback((_) {
    if (mounted) {
      setState(() {});
    }
  });
}

void _removeOverlay() {
  _overlayEntry?.remove();
  _overlayEntry = null;
  if (_isOpen && mounted) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        setState(() => _isOpen = false);
      }
    });
  }
  _isOpen = false;
}
```

### 2. Tests Pass (14/14)

```bash
cd plugins/fluttersdk_magic/plugins/fluttersdk_wind && flutter test test/widget/widgets/w_date_picker_test.dart
# 00:03 +14: All tests passed!
```

### 3. Analytics View setState Fixed

**File**: `lib/resources/views/monitors/monitor_analytics_view.dart`

```dart
Future<void> _loadMonitorAndAnalytics() async {
  _monitor = await Monitor.find(_monitorId!);

  // Defer setState to avoid mouse_tracker conflicts
  if (mounted) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) {
        setState(() {});
      }
    });
  }

  controller.setLast24Hours(_monitorId!);
}
```

### 4. Wind UI Widgets Already Fixed

Bu widget'lar daha önce düzeltilmişti:
- `w_anchor.dart` - `_onHover()` method uses `addPostFrameCallback`
- `w_popover.dart` - `onEnter/onExit` callbacks use `addPostFrameCallback`
- `w_select.dart` - `_setHovering()` and `_setHoveredIndex()` helpers use `addPostFrameCallback`

---

## Files Modified

| File | Change |
|------|--------|
| `plugins/.../w_date_picker.dart` | Complete rewrite - WPopover → OverlayEntry |
| `lib/.../date_range_selector.dart` | WDatePicker temporarily commented out |
| `lib/.../monitor_analytics_view.dart` | setState wrapped in addPostFrameCallback |

---

## Next Steps

### If Error STOPS with WDatePicker disabled:

1. Problem is in WDatePicker implementation
2. Check if `WCalendarHeader` or `WCalendarGrid` have any hover/setState issues
3. Consider using native Flutter `showDateRangePicker` as fallback

### If Error CONTINUES with WDatePicker disabled:

1. Problem is elsewhere in Analytics page
2. Check these files for direct `setState` calls:
   - `lib/resources/views/components/charts/multi_line_chart.dart`
   - `lib/resources/views/components/charts/status_timeline_chart.dart`
   - `lib/app/controllers/analytics_controller.dart`
   - `lib/resources/views/components/analytics/metric_selector.dart`
3. Look for any `MouseRegion` widgets with direct hover callbacks

### General Pattern to Fix

Any `setState` called from:
- `MouseRegion.onEnter/onExit/onHover`
- `GestureDetector` callbacks during hover
- Async callbacks that may coincide with mouse events

Should be wrapped:

```dart
WidgetsBinding.instance.addPostFrameCallback((_) {
  if (mounted) {
    setState(() => ...);
  }
});
```

---

## Test Commands

```bash
# WDatePicker tests
cd plugins/fluttersdk_magic/plugins/fluttersdk_wind
flutter test test/widget/widgets/w_date_picker_test.dart

# Run web app to test analytics page
flutter run -d chrome
# Navigate to: /monitors/{id}/analytics
```

---

## User's Feedback

User explicitly requested:
> "hepsini bozacagina buna ozel birsey yapmaya ne dersin?"
> (Instead of breaking everything, how about making something specific for this?)

This led to the WDatePicker rewrite using direct OverlayEntry instead of WPopover, to avoid affecting other working widgets.

---

## Final Resolution (2026-02-04)

### Root Cause
WDatePicker widget triggers `mouse_tracker` assertion errors due to complex interactions between:
- OverlayEntry management
- CompositedTransformFollower positioning
- MouseRegion callbacks in nested Wind widgets
- setState calls during mouse tracking phase

### Solution Applied
Replaced WDatePicker with native Flutter `showDateRangePicker` in `DateRangeSelector`:

```dart
Widget _buildCustomDatePicker(BuildContext context) {
  return WButton(
    onTap: () async {
      final picked = await showDateRangePicker(
        context: context,
        firstDate: now.subtract(const Duration(days: 365)),
        lastDate: now,
        initialDateRange: customRange,
        // ... theme customization
      );
      if (picked != null) {
        onCustomRangeSelected(picked);
      }
    },
    // ... button styling
  );
}
```

### Why This Works
- Native Flutter `showDateRangePicker` uses Flutter's internal overlay system
- No custom MouseRegion or hover state management needed
- Avoids the mouse_tracker phase conflicts entirely
- Same visual result with proper theme customization

### Tests
- 11/11 DateRangeSelector tests passing
- 14/14 WDatePicker tests still passing (widget not removed, just not used here)

### Files Modified
| File | Change |
|------|--------|
| `lib/resources/views/components/analytics/date_range_selector.dart` | Use native showDateRangePicker |
| `plugins/.../w_date_picker.dart` | Removed Expanded, added flex-1 truncate |
| `test/widget/components/analytics/date_range_selector_test.dart` | Updated tests |

### Known Issue
WDatePicker still has underlying mouse_tracker issues in certain contexts (e.g., SingleChildScrollView). For analytics page specifically, native Flutter picker is the stable solution. WDatePicker may work fine in simpler contexts.
