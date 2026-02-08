# Production Readiness — Uptizm (Flutter App)

## TL;DR

> **Quick Summary**: Uptizm Flutter uygulamasini production-ready hale getirmek icin 14 maddelik duzeltme ve iyilestirme plani. Hardcoded secret'lar, type safety crash'leri, Wind UI anti-pattern'ler ve kalite iyilestirmeleri kapsaniyor.
>
> **Deliverables**:
> - Tum config dosyalari env-based hale getirilmis
> - Type safety crash riskleri giderilmis
> - Wind UI anti-pattern'ler duzeltilmis
> - Eksik ceviriler, empty state'ler, error handling iyilestirilmis
> - Debug log temizligi yapilmis
>
> **Estimated Effort**: Large (14 task, 3 dalga)
> **Parallel Execution**: YES - 3 waves
> **Critical Path**: Task 1-3 (guvenlik) → Task 4-8 (crash prevention) → Task 9-14 (kalite)

---

## Context

### Original Request
Projeyi production-ready hale getirmek icin tum testleri, yapilari ve kodlari inceleyip guvenlik riskleri, iyilestirmeler, oneriler, duzeltmeler, mantik hatalari vb. arastirip bir rapor hazirlamak ve tumu icin calisma plani olusturmak.

### Interview Summary
**Key Discussions**:
- 4 paralel arastirma ajani ile kapsamli audit yapildi (Flutter Core, UI Layer, Backend, Infra)
- 25 bulgu tespit edildi: 5 Critical, 7 High, 6 Medium, 7 Infrastructure
- Kullanici TUM 25 maddenin tek bir plana dahil edilmesini istedi

**Research Findings**:
- `back-end/` dizini bu repository'de MEVCUT DEGIL — ayri repo. Backend bulgulari bu plandan HARIC tutuldu.
- Flutter test altyapisi guclu: 100 test dosyasi, model/controller/view/enum coverage mevcut
- CI sadece Flutter icin var (analyze, format, test)

### Metis Review
**Identified Gaps** (addressed):
- `scrollPrimary: true` Flutter'da route basina TEK bir primary scroll controller destekliyor — global degil, secici uygulanacak
- 440 `Text` widget'inin tamami `WText`'e cevirilmeyecek — sadece view dosyalarindaki 10 instance hedeflenecek
- `flex-wrap` → `wrap` degisikligi layout'u bozabilir — her degisiklik sonrasi gorsel dogrulama gerekli

---

## Work Objectives

### Core Objective
Uptizm Flutter uygulamasini production ortamina guvenle deploy edilebilir hale getirmek: guvenlik aciklari kapatilacak, crash riskleri giderilecek, UI anti-pattern'ler duzeltilecek ve kod kalitesi iyilestirilecek.

### Concrete Deliverables
- 4 config dosyasi env-based hale getirilmis
- 4 unsafe type cast duzeltilmis
- 2 flex-wrap anti-pattern duzeltilmis
- 33 overflow-y-auto container'dan uygun olanlara scrollPrimary eklenecek
- 10 raw Text → WText, 7 raw TextField → WFormInput, 4 raw GestureDetector → WAnchor
- Tum bulgu maddeleri icin testler

### Definition of Done
- [ ] `flutter test` → ALL PASS
- [ ] `flutter analyze` → 0 error
- [ ] `grep -r "flex-wrap" lib/` → 0 sonuc
- [ ] `grep -rn "as int" lib/` → 0 unsafe cast (sadece safe pattern'ler)
- [ ] Tum config degerleri env() uzerinden

### Must Have
- Tum hardcoded secret/config'ler env-based
- Tum crash-risk type cast'ler safe pattern'e cevirilmis
- Wind UI anti-pattern'ler duzeltilmis

### Must NOT Have (Guardrails)
- Backend (Laravel) islemleri — ayri repository, bu plana dahil degil
- 440 `Text` widget'inin toptan WText'e cevirilmesi — sadece view dosyalarindaki 10 instance
- `scrollPrimary: true`'nun tum overflow-y-auto container'lara eklenmesi — sadece ana sayfa scroller'larina
- Yeni feature eklenmesi — sadece mevcut kodun iyilestirilmesi
- Gereksiz abstraction veya utility dosyalari olusturulmasi
- Flutter'dan Material widget'larinin tamamen cikarilmasi (chart kutuphanesi icerisindeki Text widget'ları haric)

---

## Verification Strategy (MANDATORY)

> **UNIVERSAL RULE: ZERO HUMAN INTERVENTION**
>
> ALL tasks in this plan MUST be verifiable WITHOUT any human action.

### Test Decision
- **Infrastructure exists**: YES (100 test dosyasi, flutter test calisiyor)
- **Automated tests**: TDD (RED-GREEN-REFACTOR) — Proje convention'i
- **Framework**: `flutter test`

### Agent-Executed QA Scenarios (MANDATORY — ALL tasks)

**Verification Tool by Deliverable Type:**

| Type | Tool | How Agent Verifies |
|------|------|-------------------|
| Config changes | Bash | `grep -rn "hardcoded_value" lib/config/` → 0 sonuc |
| Type safety | Flutter Test | Unit test with num input → no crash |
| Wind UI fixes | Bash + Playwright | `grep -r "flex-wrap" lib/` → 0 sonuc, gorsel dogrulama |
| Widget migration | Bash (grep) | `grep -rn "\bTextField(" lib/resources/views/` → 0 sonuc |
| Error handling | Flutter Test | Controller error logging assertions |

---

## Execution Strategy

### Parallel Execution Waves

```
Wave 1 — Critical Security (Start Immediately):
├── Task 1: Hardcoded config → env (network, notifications, app)
├── Task 2: Social auth callback URL env
└── Task 3: Debug/env defaults

Wave 2 — Crash Prevention (After Wave 1):
├── Task 4: Type safety (as int → safe cast)
├── Task 5: Route parameter null safety
├── Task 6: flex-wrap → wrap fix
├── Task 7: scrollPrimary selective fix
└── Task 8: Raw widget migration (Text, TextField, GestureDetector)

Wave 3 — Quality Improvements (After Wave 2):
├── Task 9: Missing trans() translations
├── Task 10: Enum default value safety
├── Task 11: Controller error handling improvements
├── Task 12: Monitor load null flicker fix
├── Task 13: Empty state widgets
└── Task 14: Debug log cleanup in models
```

### Dependency Matrix

| Task | Depends On | Blocks | Can Parallelize With |
|------|------------|--------|---------------------|
| 1 | None | - | 2, 3 |
| 2 | None | - | 1, 3 |
| 3 | None | - | 1, 2 |
| 4 | None | - | 5, 6, 7, 8 |
| 5 | None | - | 4, 6, 7, 8 |
| 6 | None | - | 4, 5, 7, 8 |
| 7 | None | - | 4, 5, 6, 8 |
| 8 | None | - | 4, 5, 6, 7 |
| 9 | None | - | 10, 11, 12, 13, 14 |
| 10 | None | - | 9, 11, 12, 13, 14 |
| 11 | None | - | 9, 10, 12, 13, 14 |
| 12 | None | - | 9, 10, 11, 13, 14 |
| 13 | None | - | 9, 10, 11, 12, 14 |
| 14 | None | - | 9, 10, 11, 12, 13 |

---

## TODOs

---

- [x] 1. Hardcoded Network & Notification Config'leri Env'e Tasi
- [x] 2. Social Auth Callback URL'lerini Production-Safe Yap
- [x] 3. App Config Debug/Env Defaults'u Production-Safe Yap

  **What to do**:
  - `lib/config/app.dart` L14: `'env': 'local'` → `'env': env('APP_ENV', 'production')`
  - `lib/config/app.dart` L15: `'debug': true` → `'debug': env('APP_DEBUG', false)`
  - `.env.example`'a `APP_ENV=production`, `APP_DEBUG=false` ekle
  - `.env`'ye development icin `APP_ENV=local`, `APP_DEBUG=true` ekle

  **Must NOT do**:
  - Diger config degerlerini degistirme (url, key zaten env kullaniyor)
  - Production'da debug modunu acik birakma

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Tek dosyada 2 satir degisikligi
  - **Skills**: [`magic-framework`]
    - `magic-framework`: env() kullanimi

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 1 (with Tasks 1, 2)
  - **Blocks**: None
  - **Blocked By**: None

  **References**:
  - `lib/config/app.dart:14-15` — Degistirilecek satirlar
  - `lib/config/app.dart:16-17` — env() kullanim ornegi (ayni dosyada)

  **Acceptance Criteria**:
  - [ ] `grep -n "'env': 'local'" lib/config/app.dart` → 0 sonuc
  - [ ] `grep -n "'debug': true" lib/config/app.dart` → 0 sonuc (hardcoded true yok)
  - [ ] `.env.example`'da `APP_ENV=production` ve `APP_DEBUG=false` mevcut
  - [ ] `flutter test` → ALL PASS

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: Debug mode default'u false
    Tool: Bash (grep)
    Steps:
      1. grep "debug" lib/config/app.dart
      2. Assert: env('APP_DEBUG', false) pattern'i kullaniliyor
      3. Assert: Hardcoded 'true' yok
    Expected Result: Production'da debug kapali olacak

  Scenario: Env default'u production
    Tool: Bash (grep)
    Steps:
      1. grep "'env'" lib/config/app.dart
      2. Assert: env('APP_ENV', 'production') pattern'i mevcut
    Expected Result: Env belirtilmezse production varsayilacak
  ```

  **Commit**: YES (Task 1 ile grupla)
  - Message: `fix(config): set production-safe defaults for env and debug`
  - Files: `lib/config/app.dart`, `.env.example`, `.env`
  - Pre-commit: `flutter test`

---

- [x] 4. Type Safety — Unsafe `as int` Cast'leri Duzelt
- [x] 5. Route Parameter Null Safety — Bang Operator Kaldir
- [x] 6. Wind UI flex-wrap Anti-Pattern Duzelt
- [x] 7. scrollPrimary Selective Fix
- [x] 8. Raw Widget → Wind Widget Migration

  **What to do**:
  - 10x `Text(` → `WText(` donusumu (8 dosyada):
    - `charts/response_time_chart.dart:112,131`
    - `charts/multi_line_chart.dart:144,160`
    - `charts/status_timeline_chart.dart:63`
    - `monitors/monitor_basic_info_section.dart:112`
    - 4x announcement views (placeholder/stub views)
  - 7x `TextField(` → `WFormInput(` donusumu (3 dosyada):
    - `incidents/incident_show_view.dart:87`
    - `monitors/assertion_rule_editor.dart:226,243`
    - `monitors/metric_mapping_editor.dart:203,217,274,289`
  - 4x `GestureDetector(` → `WAnchor(` donusumu (3 dosyada):
    - `monitors/monitor_basic_info_section.dart:89`
    - `status_pages/status_page_edit_view.dart:383,661`
    - `status_pages/status_page_create_view.dart:619`
  - Her donusum icin mevcut widget parametrelerini Wind karsiligina cevir
  - RED: Mevcut widget testleri varsa guncellemeden once PASS oldugunu dogrula
  - GREEN: Widget'lari degistir
  - REFACTOR: Import'lari temizle (dart:material → wind import)

  **Must NOT do**:
  - Chart kutuphanesi ICINDEKI Text widget'larini degistirme (fl_chart gibi)
  - Fonksiyonel davranisi degistirme — sadece widget tipi degisecek
  - Tum 440 Text widget'ini degistirme — sadece view dosyalarindaki 10 instance

  **Recommended Agent Profile**:
  - **Category**: `visual-engineering`
    - Reason: UI widget migration, tema tutarliligi, dark mode uyumluluk
  - **Skills**: [`wind-ui`, `flutter-design`]
    - `wind-ui`: WText, WFormInput, WAnchor API ve className pattern'leri
    - `flutter-design`: Widget parametreleri donusumu

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 2 (with Tasks 4, 5, 6, 7)
  - **Blocks**: None
  - **Blocked By**: None

  **References**:
  - Wind UI skill: WText, WFormInput, WAnchor widget API dokumantasyonu
  - `lib/resources/views/` — Mevcut WText, WFormInput kullanim ornekleri icin baska view dosyalari

  **Acceptance Criteria**:
  - [ ] `grep -rn "\bText(" lib/resources/views/` → Sadece chart kutuphanesi icindekiler (external widget)
  - [ ] `grep -rn "\bTextField(" lib/resources/views/` → 0 sonuc
  - [ ] `grep -rn "\bGestureDetector(" lib/resources/views/` → 0 sonuc
  - [ ] `flutter test` → ALL PASS
  - [ ] `flutter analyze` → 0 error

  **Agent-Executed QA Scenarios:**

  ```
  Scenario: TextField tamamen WFormInput'a cevirilmis
    Tool: Bash (grep)
    Steps:
      1. grep -rn "\bTextField(" lib/resources/views/
      2. Assert: 0 sonuc
    Expected Result: View dosyalarinda TextField kullanimi yok

  Scenario: GestureDetector tamamen WAnchor'a cevirilmis
    Tool: Bash (grep)
    Steps:
      1. grep -rn "\bGestureDetector(" lib/resources/views/
      2. Assert: 0 sonuc
    Expected Result: View dosyalarinda GestureDetector kullanimi yok
  ```

  **Commit**: YES
  - Message: `refactor(ui): migrate raw Material widgets to Wind UI equivalents`
  - Files: 8+ dosya (charts, incidents, monitors, status_pages)
  - Pre-commit: `flutter test && flutter analyze`

---

- [x] 9. Eksik trans() Cevirileri Ekle
- [x] 10. Enum Default Value Safety
- [x] 11. Controller Error Handling & Logging Iyilestirmesi
- [x] 12. Monitor Load Null Flicker Fix
- [x] 13. Empty State Widget'lari Ekle
- [x] 14. Model Debug Log Temizligi

  **What to do**:
  - `lib/app/models/monitor.dart` L113-134: `find()` ve `all()` methodlarindaki asiri debug logging'i temizle
  - Production'da her API cagirisinda console'a basilan debug log'lar performans ve guvenlik sorunu
  - `debugPrint`, `print`, `Log.debug()` gibi ifadeleri:
    - YA tamamen kaldir
    - YA DA `Log.debug()` ile sar ki sadece debug modda calissin

  **Must NOT do**:
  - `Log.error()` veya `Log.warning()` satirlarini silme (bunlar onemli)
  - Model is mantigi degistirme
  - API cagirisini degistirme

  **Recommended Agent Profile**:
  - **Category**: `quick`
    - Reason: Satir silme/degistirme islemi
  - **Skills**: [`magic-framework`]
    - `magic-framework`: Log facade API

  **Parallelization**:
  - **Can Run In Parallel**: YES
  - **Parallel Group**: Wave 3 (with Tasks 9-13)
  - **Blocks**: None
  - **Blocked By**: None

  **References**:
  - `lib/app/models/monitor.dart:113-134` — Debug log satirlari
  - `grep -rn "print\|debugPrint" lib/app/` — Diger debug log'lar

  **Acceptance Criteria**:
  - [ ] `grep -rn "\bprint(" lib/app/models/` → 0 sonuc
  - [ ] `grep -rn "debugPrint" lib/app/models/` → 0 sonuc
  - [ ] `flutter test` → ALL PASS

  **Commit**: YES
  - Message: `chore(models): remove excessive debug logging from production code`
  - Files: `lib/app/models/monitor.dart`, diger model dosyalari
  - Pre-commit: `flutter test`

---

## Commit Strategy

| After Task(s) | Message | Verification |
|---------------|---------|--------------|
| 1, 2, 3 | `fix(config): move all hardcoded secrets and configs to env vars` | `flutter test && flutter analyze` |
| 4 | `fix(models): replace unsafe int casts with safe num-to-int pattern` | `flutter test` |
| 5 | `fix(routes): remove bang operators for null safety` | `flutter test && flutter analyze` |
| 6 | `fix(ui): replace no-op flex-wrap with wrap display type` | `flutter test` |
| 7 | `fix(ui): add scrollPrimary to main page scrollers` | `flutter test` |
| 8 | `refactor(ui): migrate raw Material widgets to Wind UI` | `flutter test && flutter analyze` |
| 9 | `fix(i18n): wrap hardcoded strings with trans()` | `flutter test` |
| 10 | `fix(enums): safe defaults for unrecognized values` | `flutter test` |
| 11 | `fix(controllers): add proper error logging` | `flutter test` |
| 12 | `fix(controller): prevent UI flicker on monitor load failure` | `flutter test` |
| 13 | `feat(ui): add empty state widgets` | `flutter test` |
| 14 | `chore(models): remove debug logging` | `flutter test` |

---

## Success Criteria

### Verification Commands
```bash
flutter test                    # Expected: ALL PASS
flutter analyze                 # Expected: 0 errors
grep -rn "192.168.68.117" lib/  # Expected: 0 results
grep -rn "as int[^?]" lib/     # Expected: 0 unsafe casts
grep -rn "flex-wrap" lib/       # Expected: 0 results
grep -rn "\bTextField(" lib/resources/views/  # Expected: 0 results
grep -rn "\bGestureDetector(" lib/resources/views/  # Expected: 0 results
grep -rn "\bprint(" lib/app/models/  # Expected: 0 results
```

### Final Checklist
- [ ] All "Must Have" items present
- [ ] All "Must NOT Have" items absent
- [ ] All 100+ existing tests still pass
- [ ] No hardcoded secrets in lib/config/
- [ ] No unsafe type casts in lib/
- [ ] No Wind UI anti-patterns (flex-wrap, missing scrollPrimary)
- [ ] All controllers have proper error logging
- [ ] Empty states present for all index views
- [ ] No debug print statements in models

---

## Out of Scope (Explicitly Excluded)

| Item | Reason |
|------|--------|
| Laravel Backend fixes | `back-end/` directory not in this repo — separate repository |
| Rate limiting | Backend concern, not in this repo |
| Mass assignment ($fillable) | Backend concern, not in this repo |
| IDOR audit | Backend concern, not in this repo |
| N+1 query optimization | Backend concern, not in this repo |
| Public endpoint data exposure | Backend concern, not in this repo |
| Redis cache migration | Backend concern, not in this repo |
| Docker/docker-compose | Requires architecture decision + backend coordination |
| Centralized logging | Requires infrastructure decision (Papertrail/Datadog/ELK) |
| Secret management (Vault) | Requires infrastructure decision |
| CD pipeline | Requires deployment target decision |
| Dashboard real data connection | Requires backend API endpoints to exist first |
| Sentry Flutter integration | Will be handled separately |
| CI pipeline expansion | Will be handled separately |
| Health check improvements | Will be handled separately |
| Dependency vulnerability scanning | Will be handled separately |
| E2E test foundation | Will be handled separately |
