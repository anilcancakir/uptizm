# i18n-audit-fix Plan Learnings

## Task 6: scan-translations Skill Creation

**Date:** 2026-02-07

### Skill Structure

Created `.opencode/skills/scan-translations/SKILL.md` following the established skill format:

- **YAML Frontmatter:** Name + bilingual description (English + Turkish keywords)
- **Core Algorithm:** 6-step process for scanning i18n issues
- **Exclusion Patterns:** 17 comprehensive categories to prevent false positives
- **Usage Examples:** Full scan, feature-specific scan, validation workflows
- **Anti-Patterns:** Clear guidance on what NOT to report

### Scanning Algorithm

The skill documents a 6-step algorithm:

1. **Build Valid Keys:** Parse `en.json` and flatten to dot notation
2. **Find trans() Calls:** Use grep to extract all trans() usage
3. **Cross-Reference:** Compare extracted keys against valid set
4. **Find Hardcoded Strings:** Search for string literals in UI contexts
5. **Apply Exclusions:** Filter using 17 exclusion categories
6. **Report Findings:** Group by file, categorize by severity

### Key Exclusion Categories Discovered

**High Priority (prevent false positives):**
1. Already translated (wrapped in trans())
2. Comments/documentation (// and ///)
3. Styling/className strings (Wind UI patterns)
4. Route paths
5. Format strings (DateFormat, NumberFormat)

**Technical Identifiers:**
- Empty strings and single characters
- Variable interpolations ($var)
- Icons and assets
- Log messages
- Technical keys (snake_case, dot.notation)
- HTTP methods and status codes
- RegExp patterns
- Config/env keys

**Context-Specific:**
- Enum values in switch/case
- Test/mock data
- JSON keys in Map literals

### Verification Results

Created `scan_translations.py` implementing the skill's algorithm:

**Initial scan:** Found 2 false positives (trans() calls in docstring examples)

**After adding comment exclusion:** ✅ ZERO broken keys
- 613 valid translation keys in en.json
- 591 trans() calls in lib/
- 100% valid references

This confirms the codebase is properly internationalized after Tasks 1-5.

### Implementation Note

The exclusion for comments/documentation is critical:
```python
# Skip lines starting with // or ///
stripped = line.strip()
if stripped.startswith('//') or stripped.startswith('///'):
    continue
```

Without this, docstring examples containing trans() would be flagged as broken keys.

### Skill Discoverability

Bilingual description ensures the skill can be found via:
- English: "translation audit", "i18n scan", "broken keys", "hardcoded strings"
- Turkish: "çeviri tarama", "i18n denetim", "trans() hatası", "kodlanmış metin"

### Next Steps (Future Work)

The skill is a SCANNER/REPORTER only. Potential enhancements:
- Auto-fix mode with user confirmation
- Suggestion system (propose trans() key names)
- Integration with CI/CD pipelines
- IDE plugin for real-time checking
