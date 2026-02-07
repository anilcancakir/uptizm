---
name: scan-translations
description: "Automated scanner for Flutter i18n issues. ALWAYS activate when auditing translations, finding broken trans() keys, or detecting hardcoded user-facing strings. Scans lib/ for trans() calls referencing non-existent keys and string literals that should be wrapped in trans(). Comprehensive exclusion patterns for className, routes, styling, format strings, technical identifiers. Turkish: çeviri tarama, i18n denetim, trans() hatası, kodlanmış metin. English: translation audit, i18n scan, broken keys, hardcoded strings."
---

# Translation Scanner Skill

Automated scanner for detecting i18n issues in Flutter apps using the Magic Framework's `trans()` function.

> **Purpose:** Find broken translation keys and hardcoded user-facing strings that should be wrapped in `trans()`.

---

## ⚠️ CRITICAL: Scanner Only, Not Auto-Fixer

**This skill is a REPORTER only. It finds issues but does NOT auto-fix them.**

When issues are found:
1. Report findings grouped by file with line numbers
2. Categorize by severity (CRITICAL vs WARNING)
3. Let the user decide how to fix

**NEVER** automatically modify files based on scan results.

---

## Scanning Algorithm

### Step 1: Build Valid Translation Keys Set

Parse `assets/lang/en.json` to extract all valid translation keys in flattened dot notation.

```bash
# Example: Extract keys from nested JSON
# {"auth": {"login": "Sign In", "logout": "Sign Out"}}
# Produces: auth.login, auth.logout
```

**Implementation:**
```python
import json

def flatten_json(data, parent_key='', sep='.'):
    """Flatten nested JSON to dot notation keys."""
    items = []
    for k, v in data.items():
        new_key = f"{parent_key}{sep}{k}" if parent_key else k
        if isinstance(v, dict):
            items.extend(flatten_json(v, new_key, sep=sep).items())
        else:
            items.append((new_key, v))
    return dict(items)

# Load and flatten
with open('assets/lang/en.json') as f:
    translations = json.load(f)
valid_keys = set(flatten_json(translations).keys())
```

### Step 2: Find All trans() Calls

Use `grep` to find all `trans('...')` and `trans("...")` calls across `lib/`.

```bash
# Find all trans() calls with single or double quotes
grep -rn "trans(['\"]" lib/ --include="*.dart"
```

**Extract keys:**
```python
import re

# Pattern to match trans('key') or trans("key")
pattern = r"trans\(['\"]([^'\"]+)['\"]\)"

trans_calls = []
# For each grep result line
for match in re.finditer(pattern, line):
    key = match.group(1)
    trans_calls.append({
        'file': filename,
        'line': line_number,
        'key': key
    })
```

### Step 3: Cross-Reference Trans Keys

Compare extracted keys against valid keys set to find broken references.

```python
broken_keys = []
for call in trans_calls:
    if call['key'] not in valid_keys:
        broken_keys.append({
            'file': call['file'],
            'line': call['line'],
            'key': call['key'],
            'severity': 'CRITICAL'
        })
```

### Step 4: Find Hardcoded Strings in UI Contexts

Use `grep` or `ast-grep` to find string literals in UI widget contexts.

```bash
# Find Text widget with string literals (example)
grep -rn "Text(['\"]" lib/ --include="*.dart"
grep -rn "WText(['\"]" lib/ --include="*.dart"
```

**Target patterns:**
- `Text('hardcoded string')`
- `WText('hardcoded string')`
- `label: 'hardcoded'`
- `title: 'hardcoded'`
- `hintText: 'hardcoded'`
- Button text parameters

### Step 5: Apply Exclusion Patterns

Filter out false positives using comprehensive exclusion rules.

**Exclusion Categories:**

#### 1. Already Translated
```dart
// ✅ EXCLUDE: Already wrapped in trans()
Text(trans('auth.login'))
```

#### 2. Comments & Documentation
```dart
// ✅ EXCLUDE: Lines starting with // or ///
// Example: trans('example.key')
/// Documentation example:
/// ```dart
/// title: trans('page.title'),
/// ```

// Pattern: Line starts with whitespace followed by // or ///
```

#### 3. Styling & CSS
```dart
// ✅ EXCLUDE: className strings (Wind UI)
className: 'bg-primary text-white px-4 py-2 rounded-lg'
className: 'flex items-center gap-2'
className: 'hover:bg-gray-100 dark:bg-gray-800'

// Pattern: Contains bg-, text-, flex, px-, py-, rounded-, border-, 
//          dark:, hover:, focus:, disabled:, shadow-, overflow-, 
//          gap-, w-, h-, m-, p-, items-, justify-
```

#### 4. Routes & Navigation
```dart
// ✅ EXCLUDE: Route paths
MagicRoute.to('/dashboard')
Route.push('/monitors/create')
Route.toNamed('home')
```

#### 5. Format Strings
```dart
// ✅ EXCLUDE: Date/number formatting
DateFormat('yyyy-MM-dd')
NumberFormat('#,##0.00')
DateFormat.yMd()
```

#### 6. Empty & Single Characters
```dart
// ✅ EXCLUDE: Empty strings and single chars
''
""
'•'
'/'
'-'
':'
' '
```

#### 7. Variable Interpolations
```dart
// ✅ EXCLUDE: Strings starting with $
'$count items'
'${user.name}'
```

#### 8. Icons & Assets
```dart
// ✅ EXCLUDE: Icon references
Icons.home
Icons.add_circle
'assets/images/logo.png'
'assets/icons/check.svg'
```

#### 9. Logging & Debug
```dart
// ✅ EXCLUDE: Log messages
Log.debug('Fetching monitor data')
Log.error('Failed to load: $error')
print('Debug: state = $state')
```

#### 10. Technical Identifiers
```dart
// ✅ EXCLUDE: Keys/identifiers (only lowercase, underscore, dot)
'monitor_id'
'user.email'
'data.status'
'api_key'
'bearer_token'

// Pattern: ^[a-z0-9._]+$
```

#### 11. Numeric Strings
```dart
// ✅ EXCLUDE: Numeric literals as strings
'0'
'200'
'404'
'1'

// Pattern: ^\d+$
```

#### 12. HTTP & API
```dart
// ✅ EXCLUDE: HTTP methods and status codes
'GET'
'POST'
'PUT'
'DELETE'
'PATCH'
'200'
'404'
'500'
```

#### 13. Enum Values
```dart
// ✅ EXCLUDE: Switch/case enum values
switch (status) {
  case 'active':  // Enum value, not user-facing
  case 'paused':
}
```

#### 14. Test & Mock Data
```dart
// ✅ EXCLUDE: Within test files (test/**/*.dart)
// ✅ EXCLUDE: Blocks marked "Mock data"
// Mock data for testing
final mockMonitor = Monitor(name: 'Test Monitor');
```

#### 15. JSON Keys in Maps
```dart
// ✅ EXCLUDE: Keys in Map/JSON literals
{'key': 'value', 'another_key': 'data'}
Map<String, dynamic> body = {'email': email, 'password': pass};
```

#### 16. RegExp Patterns
```dart
// ✅ EXCLUDE: Regular expressions
RegExp(r'^\d{3}-\d{3}-\d{4}$')
```

#### 17. Configuration Values
```dart
// ✅ EXCLUDE: Config keys, env vars
Config.get('app.name')
env('APP_KEY')
'database.connections.mysql'
```

**Exclusion Implementation:**
```python
def should_exclude(string_literal, context, line):
    """Check if string should be excluded from hardcoded string warnings."""
    
    # Comments and documentation (check line content)
    stripped_line = line.strip()
    if stripped_line.startswith('//') or stripped_line.startswith('///'):
        return True
    
    # Empty or whitespace only
    if not string_literal.strip():
        return True
    
    # Single character
    if len(string_literal) == 1:
        return True
    
    # Numeric only
    if re.match(r'^\d+$', string_literal):
        return True
    
    # Technical identifier pattern
    if re.match(r'^[a-z0-9._-]+$', string_literal):
        return True
    
    # CSS/styling keywords
    css_keywords = [
        'bg-', 'text-', 'flex', 'px-', 'py-', 'rounded-', 'border-',
        'dark:', 'hover:', 'focus:', 'disabled:', 'shadow-', 'overflow-',
        'gap-', 'w-', 'h-', 'm-', 'p-', 'items-', 'justify-', 'grid',
        'wrap', 'shrink', 'grow'
    ]
    if any(kw in string_literal for kw in css_keywords):
        return True
    
    # Starts with variable interpolation
    if string_literal.startswith('$'):
        return True
    
    # Icons, assets
    if 'Icons.' in context or 'assets/' in string_literal:
        return True
    
    # HTTP methods
    if string_literal.upper() in ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS']:
        return True
    
    # Already wrapped in trans()
    if 'trans(' in context:
        return True
    
    # Route navigation context
    if any(kw in context for kw in ['Route.to(', 'Route.push(', 'MagicRoute.to(', 'MagicRoute.push(']):
        return True
    
    # Date/number format
    if any(kw in context for kw in ['DateFormat(', 'NumberFormat(', 'DateFormat.']):
        return True
    
    # Logging
    if any(kw in context for kw in ['Log.debug(', 'Log.error(', 'Log.info(', 'Log.warning(', 'print(']):
        return True
    
    # className property
    if 'className:' in context:
        return True
    
    # RegExp
    if 'RegExp(' in context:
        return True
    
    # Config/env
    if any(kw in context for kw in ['Config.get(', 'env(']):
        return True
    
    return False
```

### Step 6: Report Findings

Group issues by file and categorize by severity.

**Report Format:**
```
================================================================================
TRANSLATION SCAN REPORT
================================================================================

SUMMARY:
- Files scanned: 87
- Broken trans() keys: 0
- Hardcoded strings: 2 (0 high-confidence, 2 low-confidence)

================================================================================
CRITICAL ISSUES (0)
================================================================================

None found ✅

================================================================================
WARNINGS (2)
================================================================================

lib/resources/views/monitors/analytics_view.dart:
  Line 45: "Chart View" - Low confidence (could be legitimate constant)
  Line 46: "Table View" - Low confidence (could be legitimate constant)

================================================================================
RECOMMENDATIONS
================================================================================

✅ All trans() keys are valid
✅ No high-confidence hardcoded strings found
⚠️  Review 2 low-confidence warnings manually

================================================================================
```

---

## Usage Examples

### Example 1: Full Scan

```bash
# Scan entire codebase for i18n issues
# This would be implemented as a Python/Dart script based on the algorithm above
```

Expected output when clean:
```
✅ Translation scan complete
✅ 0 broken trans() keys
✅ 0 hardcoded strings
```

### Example 2: Scan After Adding New Feature

After adding a new "Analytics" feature:

```bash
# Scan lib/resources/views/analytics/ specifically
```

May find:
```
❌ CRITICAL: lib/resources/views/analytics/chart_view.dart:23
   trans('analytics.chart_title') - Key not found in en.json
   
⚠️  WARNING: lib/resources/views/analytics/chart_view.dart:45
   "No data available" - Should this use trans()?
```

### Example 3: Validate Translation File Additions

After adding keys to `en.json`:

```bash
# Run scan to confirm all trans() calls now resolve
```

Expected:
```
✅ All trans() keys valid
```

---

## Integration with Development Workflow

### Pre-Commit Hook
```bash
# Add to .git/hooks/pre-commit
#!/bin/bash
# Run translation scan before commit
./scripts/scan_translations.sh
if [ $? -ne 0 ]; then
  echo "❌ Translation scan failed. Fix issues before committing."
  exit 1
fi
```

### CI/CD Pipeline
```yaml
# .github/workflows/i18n-check.yml
- name: Scan translations
  run: |
    python scripts/scan_translations.py
    if [ $? -ne 0 ]; then
      echo "::error::Translation issues found"
      exit 1
    fi
```

---

## Anti-Patterns

```dart
// ❌ NEVER: Report className as hardcoded string
className: 'bg-primary px-4'  // This is styling, not user text

// ❌ NEVER: Report route paths as hardcoded strings
Route.to('/dashboard')  // This is navigation, not user text

// ❌ NEVER: Report technical identifiers
final key = 'monitor_id';  // This is a field name, not user text

// ❌ NEVER: Report format strings
DateFormat('yyyy-MM-dd')  // This is a format pattern, not user text

// ❌ NEVER: Auto-fix without user review
// BAD: Automatically wrap all found strings in trans()
// GOOD: Report findings and let user decide

// ❌ NEVER: Report log messages
Log.debug('Fetching data')  // This is debug output, not user-facing

// ❌ NEVER: Report empty strings or single characters
final separator = '•';  // This is a visual element, not translatable text

// ✅ INSTEAD: Report genuine user-facing strings
Text('Welcome back!')  // ⚠️  Should use trans('welcome.back')
title: 'Dashboard'  // ⚠️  Should use trans('nav.dashboard')
hintText: 'Enter your email'  // ⚠️  Should use trans('fields.email_placeholder')
```

---

## Severity Levels

| Level | Description | Example |
|-------|-------------|---------|
| **CRITICAL** | Broken trans() key - app will show raw key | `trans('nonexistent.key')` |
| **HIGH** | User-facing string not translated | `Text('Sign In')` in widget tree |
| **LOW** | Possible user-facing string (needs review) | String in context without clear UI usage |

---

## Reference Files

| Topic | File |
|-------|------|
| Translation source | `assets/lang/en.json` |
| Magic Framework trans() | `plugins/fluttersdk_magic/lib/src/facades/lang.dart` |
| Project conventions | `AGENTS.md` (i18n section) |

---

## Expected Clean Scan Output

On a properly internationalized codebase (like current Uptizm after Tasks 1-5):

```
================================================================================
TRANSLATION SCAN REPORT
================================================================================

SUMMARY:
- Files scanned: 87
- Broken trans() keys: 0
- Hardcoded strings: 0

================================================================================
✅ ALL CLEAR
================================================================================

No translation issues found. The codebase is properly internationalized.

Files checked:
  - lib/app/controllers/: 12 files
  - lib/resources/views/: 45 files
  - lib/app/models/: 8 files
  - lib/app/enums/: 6 files
  - Other lib/ files: 16 files

All trans() keys reference valid entries in assets/lang/en.json
No hardcoded user-facing strings detected

================================================================================
```
