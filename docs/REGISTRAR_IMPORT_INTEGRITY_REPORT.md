# Registrar Import Integrity Report

> **Date:** 2026-10-09 · **Committed by:** session "feat/student-notifications" rewrite
> **Status:** implemented, tests green ✅

## 1. Purpose

The registrar import pipeline takes a CSV export of the school's candidate list
and turns each row into (a) an eligibility-feed row, (b) a login email, and
(c) a recovery/activation code for the accounts it creates. Once valid IDs pass
through Excel, they **cannot be recovered** — so the import must decide, at
row time, whether an ID is trustworthy or must be rejected and re-exported.

This report covers the integrity pass: the ID-format gate in the controller,
the config key behind it, the regression tests, and the verification results.

## 2. What changed

### `app/Http/Controllers/RegistrarImportController.php`
- **`studentIdFormatProblem(string $studentId): ?string`** (new static method)
  — the student-ID integrity gate, applied **before** duplicate-in-file and
  identity checks so corrupt rows never reach the feed or the accounts.
  - empty → rejected
  - whitespace/control characters → rejected ("wrapped cells, stray spaces")
  - spreadsheet exponential notation (`2.024E+11`) → rejected with a re-export
    hint (that is the Excel leading-digit-loss signature)
  - matches `config('registrar.student_id_pattern')` → accepted
  - otherwise → rejected (leading zeros dropped by a spreadsheet round-trip)
- **Gate wired into `import()`** on the raw CSV cell, with `line` + `column:
  Student ID` + the exact cell value reported back to the caller.
- All 6 row-reason strings in the controller now carry `Line N, column 'X'`
  (match to the new canonical `"student_id"` format rejection).
- In-file duplicate IDs report **exact CSV lines** (`duplicate_lines`:
  `array_values($duplicateLines)`), header row inclusive.

### `config/registrar.php` (new)
- `student_id_pattern` — PCRE gate, keyed off `REGISTRAR_STUDENT_ID_PATTERN`
  environment variable. Default is deliberately **permissive**
  (`/^[\x20-\x7E]+$/`) so the gate never invents a stricter scheme than the
  registrar confirms. See §4 for the OPEN QUESTION.

### `tests/Feature/RegistrarImportTest.php` (6 new tests)
| Test | Verifies |
|---|---|
| `test_leading_zero_ids_are_preserved_as_strings` | `trim()`/`fgetcsv` never casts the cell; `00123` survives |
| `test_spreadsheet_corrupted_ids_are_rejected_with_line_and_column` | exponential-notation row rejected with exact line/column |
| `test_mid_file_db_failure_rolls_back_the_whole_import` | exception on row 2 → HTTP 500, zero survivors |
| `test_identical_reimport_changes_nothing_sensitive` | no duplicate accounts; password/has_voted untouched |
| `test_duplicate_ids_report_lines_and_first_row_wins` | exact CSV line(s) flagged; first occurrence wins |
| `test_unicode_names_import_cleanly` | UTF-8 names stored byte-for-byte |

## 3. Verification results

```
$ vendor/bin/pint --test app/Http/Controllers/RegistrarImportController.php \
    tests/Feature/RegistrarImportTest.php config/registrar.php
PASS (3 files)

$ vendor/bin/phpunit --filter 'RegistrarImportTest|RegistrarImportCourseTest'
OK (23 tests, 157 assertions)     # 15 new/merged RegistrarImportTest + 8 RegistrarImportCourseTest

$ vendor/bin/phpunit
OK (265 tests, 1126 assertions)   # full suite, sqlite :memory:
```

No failures, no skipped tests, no lint issues. `php -l` clean on all three files.

## 4. Design notes & decisions

- **Why the gate is "reject" rather than "repair":** once Excel drops leading
  zeros (`00123` → `123`) or converts a run of digits to exponential notation,
  the original value is gone. Retrying the row would produce a *different*
  account; refusing the row forces the registrar to re-export as text.
- **Default pattern is intentionally permissive:** a real school ID scheme is
  not documented anywhere in the repo yet (migrations use plain `string(255)`,
  samples look like `2024-0001`). Tightening to e.g. `/^\d{4}-\d{4}$/` is the
  single open question; until then the exponential/whitespace checks do the
  heavy lifting.
- **Transaction semantics:** the whole row loop runs inside `DB::transaction()`;
  a failure in the feed write unwinds everything, which is what the
  mid-file-failure test asserts.

## 5. Open items

- `REGISTRAR_STUDENT_ID_PATTERN` env / config default needs the school's real
  ID format confirmed (see §2 config file "OPEN QUESTION").
- The monitoring doc `docs/PENDING_SERVER_MONITORING.md` is a plan-only
  deliverable; its §1 decisions (alert recipients, SMTP liveness, thresholds)
  remain open and block server-monitoring implementation.
