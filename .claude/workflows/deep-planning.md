---
description: Create execution-ready implementation plans that require ZERO additional research, decisions, or thinking from the executing AI agent
---
# Deep Planning Workflow

> **Target Models**: Claude Opus 4.5, Gemini 2.5/3 Pro
> **Purpose**: LLM executable plans with zero ambiguity

---

## PHASE 1: INVESTIGATION (Required Before Writing Plan)

Complete ALL before proceeding:

```
□ Search codebase for relevant files (grep, find, codebase_search)
□ Read existing patterns and conventions
□ Identify reusable utilities (don't reinvent)
□ Map dependencies and impact
□ Find similar implementations as reference
□ Discover existing tests for verification
```

---

## PHASE 2: ASK USER FOR UNCERTAIN DECISIONS

**STOP and ask the user BEFORE finalizing when:**

- Multiple valid architectural approaches exist
- Breaking changes may affect other systems
- Scope is ambiguous or unclear
- Trade-offs need user preference
- Technology choice is uncertain

**Use this format:**

```markdown
## User Feedback Required

Before completing the plan, I need your input:

1. **[Topic]**: Option A vs Option B
   - A: [pros/cons]
   - B: [pros/cons]
   - My recommendation: [choice + reasoning]
```

---

## PHASE 3: WRITE PLAN

### 3.1 Executive Summary

```markdown
## Summary
[One paragraph: what and why]

**Estimated effort**: [S/M/L/XL]
```

### 3.2 Prerequisites

```markdown
## Prerequisites
- [ ] Install: `command`
- [ ] Read: `path/to/file.ext` for context
```

### 3.3 Detailed Tasks

**Every task MUST include:**

```markdown
### Task N: [Title] (Size: S/M/L)

**Goal**: Single sentence outcome

**Files**:
- [MODIFY] `absolute/path/file.ext:45-67` - Description
- [NEW] `absolute/path/newfile.ext` - Description
- [DELETE] `absolute/path/oldfile.ext` - Reason

**Steps**:
1. Open `absolute/path/file.ext`
2. At line 45, replace with:
   ```language
   // Complete, copy-paste ready code
   ```
3. Create `absolute/path/newfile.ext`:
   ```language
   // Complete file content
   ```

**Verification**:
- [ ] Run: `exact command to run`
- [ ] Expected: Specific outcome

**Edge Cases**:
| Input | Expected Output |
|-------|-----------------|
| Empty input | 422 error |
| Invalid data | 400 error |
```

### 3.4 Verification Plan

```markdown
## Verification

### Automated
- [ ] `php artisan test --filter=TestName` → All pass
- [ ] `./vendor/bin/pint --test` → No style errors

### Manual
- [ ] Navigate to /path → See expected UI
- [ ] Submit form → See success message
```

### 3.5 Rollback

```markdown
## Rollback
- After Task 2: `git stash`
- Full revert: `git reset --hard HEAD~N`
```

---

## MANDATORY RULES

### ✅ Zero Research Required

| WRONG | CORRECT |
|-------|---------|
| "Find the auth file" | `app/Http/Controllers/AuthController.php:45` |
| "Add validation" | Add `'email' => 'required\|email'` at line 23 |
| "Use existing pattern" | Copy pattern from `UserController.php:67-89` |

### ✅ Zero Decisions Required

| WRONG | CORRECT |
|-------|---------|
| "Choose appropriate method" | Use `POST` method |
| "Pick a suitable name" | Name it `StoreUserRequest` |
| "Handle as needed" | Return 422 with message "Email required" |

### ✅ Zero Ambiguity

| FORBIDDEN PHRASE | REQUIRED REPLACEMENT |
|------------------|----------------------|
| "Research the best approach" | Complete research, write the approach |
| "Find where X is defined" | Provide exact path: `src/file.ts:142` |
| "Improve the code" | List specific improvements |
| "Handle edge cases" | List each case with handling |
| "Add appropriate tests" | Name each test: `test_empty_input` |
| "Update as needed" | List exactly what to update |
| "Consider adding" | Either add it or don't |
| "May need to" | Will do X or will not do X |

---

## QUALITY CHECKLIST (Before Finalizing)

```
□ Every file path is ABSOLUTE with line numbers
□ Every code change has COPY-PASTE ready code
□ Every task has EXACT verification command
□ NO vague instructions remain
□ All edge cases listed with handling
□ All uncertain decisions asked to user
□ Task dependencies explicit
□ Size estimates on every task
□ Breaking changes marked with ⚠️
□ Test commands verified to exist
```

---

## EXAMPLE: Complete Task Entry

```markdown
### Task 1: Add Email Validation to Registration (Size: S)

**Goal**: Validate email format and uniqueness before user creation

**Files**:
- [MODIFY] `/app/Http/Requests/RegisterRequest.php:15-25` - Add rules
- [MODIFY] `/resources/lang/en/validation.php:45` - Add message

**Steps**:
1. Open `/app/Http/Requests/RegisterRequest.php`
2. At line 18, replace `rules()` method:
   ```php
   public function rules(): array
   {
       return [
           'email' => ['required', 'email:rfc,dns', 'unique:users,email'],
           'password' => ['required', 'min:8', 'confirmed'],
       ];
   }
   ```

3. Open `/resources/lang/en/validation.php`
4. At line 45, add:
   ```php
   'email' => [
       'unique' => 'This email is already registered.',
   ],
   ```

**Verification**:
- [ ] Run: `php artisan test --filter=RegistrationTest`
- [ ] Expected: All 5 tests pass

**Edge Cases**:
| Input | Expected Output |
|-------|-----------------|
| invalid-email | 422: "The email field must be a valid email address." |
| existing@user.com | 422: "This email is already registered." |
| empty string | 422: "The email field is required." |
```