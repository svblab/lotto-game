ANCHOR_CORE.md, ANCHOR_PROTOCOL.md, ANCHOR_RULES.md are read and mandatory. Violation of any rule = implementation error.

# ANCHOR_RULES.md

## Purpose
Mandatory development rules for all AI models on this project — preserves architecture integrity, protocol compatibility, economy correctness, and consistency across sessions/models. If code/patch/epic plan contradicts this doc, it is invalid and must be regenerated.

---

# PART 1. AUTHORITY ORDER

## Rule 1. Priority Order (conflicts resolved in this order)
```
1. User instruction
2. ANCHOR_CORE.md
3. ANCHOR_PROTOCOL.md
4. ANCHOR_RULES.md
5. IMPLEMENTATION_STATUS.md
6. Epic specification
7. Model preferences (lowest)
```

## Rule 2. Single Source of Truth
- Architecture authority: `docs/ANCHOR_CORE.md`
- Protocol authority: `docs/ANCHOR_PROTOCOL.md`
- Implementation authority: `docs/IMPLEMENTATION_STATUS.md`
Archived documents are not authoritative.

---

# PART 2. BEFORE CODING

## Rule 3. Read Before Writing
Before generating code, identify: current Epic, current implementation state, affected modules, affected files. If unknown → STOP, request clarification. Assumptions forbidden.

## Rule 4. No Assumptions
Never assume: current code state, implemented features, existing helpers/services/packet structures. If missing → STOP and ask.

---

# PART 3. ABSOLUTE PROHIBITIONS

## Rule 5. Forbidden Operations (unless explicitly requested)
Rewrite entire file/module/server.php/app.js/architecture/protocol/Room structure/Player structure; rename protocol packets or actions; change function signatures; change directory structure; hidden refactoring. All existing contracts must be preserved.

## Rule 6. Hidden Refactoring Prohibition
Forbidden unless required by the Epic: renaming variables/methods, moving code between files, reordering methods, formatting-only or optimization-only edits, splitting/merging files, style cleanup. Every change must implement a documented requirement.

## Rule 7. No Hidden Features
Forbidden without spec/ADR approval: new timers, room states, player states, protocol packets/actions, economy rules, admin permissions, database tables, persistent structures.

---

# PART 4. PATCH POLICY

## Rule 8. Diff-First Development
All modifications via `diff -u` (`--- old / +++ new / @@ ...`). Full files forbidden, except new file creation or explicit user request.

## Rule 9. Large File Protection
Files >300 lines: never return entire file — `diff -u` only.

## Rule 10. Mandatory Change Report
Every patch must include:
```
CHANGED:
- item
NOT CHANGED:
- item
```
Patch without this report is invalid.

---

# PART 5. EPIC SIZE CONTROL

## Rule 11. Epic Isolation
One Epic = one problem. Forbidden combos: Auth+Lobby, Lobby+Game, Game+Admin, or any multiple unrelated systems in one Epic. Decompose if needed.

## Rule 12. Maximum Epic Size
Normal: 300-500 lines new code. Max recommended: 500.

## Rule 13. Mandatory Decomposition
If implementation exceeds limits → STOP, return "Epic is too large. Decomposition required." Code generation forbidden.

## Rule 14. File Modification Limit
Normal: 1-3 files. Max: 5. If more → STOP, request decomposition.

---

# PART 6. SERVER ARCHITECTURE DISCIPLINE

## Rule 15. server.php Responsibilities
Target ≤500 lines. Allowed: Workerman bootstrap, worker init, dependency wiring, action routing, timer registration, module loading. Forbidden: auth/room/economy/victory/apartment/reconnect/admin logic.

## Rule 16. Bootstrap Files
`server.php`, `init_db.php` may only initialize, configure, register, wire dependencies. Business logic forbidden.

---

# PART 7. DATABASE DISCIPLINE

## Rule 17. PDO Only
All db access via PDO. Direct SQLite access forbidden.

## Rule 18. Prepared Statements Only
All SQL via prepared statements. String concatenation forbidden.

## Rule 19. Transaction Safety
```php
try { beginTransaction(); ... commit(); } catch (...) { rollBack(); }
```
Partial transactions forbidden.

## Rule 20. Statement Cache
Reuse prepared statements via centralized cache. No repeated preparation of identical SQL.

---

# PART 8. STATE AND ECONOMY PROTECTION

## Rule 21. State Machine Authority
Room states: `waiting, playing, apartment, finished`. Player states: `active, disconnected`. No additional states.

## Rule 22. Economy Protection
Forbidden: partial payouts, implicit refunds, untracked balance changes, coin duplication/creation — except documented mechanics. Every balance change must be explainable.

## Rule 23. Timer Discipline
Every timer must have a creator, owner, and destroyer. If any unknown → implementation invalid.

---

# PART 9. MODULE BOUNDARIES

## Rule 24. Respect Module Boundaries
Modules communicate via public interfaces only. Forbidden: Game→Auth internals, Lobby→Apartment internals, Admin→Game internals. No direct access to internal implementation.

## Rule 25. Service Responsibilities
Handlers: request routing. Services: business logic. Core: infrastructure. Responsibilities must not overlap.

---

# PART 10. PROTOCOL STABILITY

## Rule 26. Protocol Compatibility
Packets are contracts. Forbidden without protocol version change: rename packet, remove field, change field meaning, rename action, change payload format.

## Rule 27. Naming Authority
All names follow NAMING_REGISTRY (in ANCHOR_CORE.md Part 6). Alternative names forbidden.

---

# PART 11. PERFORMANCE DISCIPLINE

## Rule 28. VPS Awareness
Target: 1 CPU, 500MB RAM. Every Epic must consider memory usage, timer count, room count, player count. Avoid unnecessary allocations/duplicated structures.

## Rule 29. Simplicity Preference
When multiple valid implementations exist, prefer simpler/smaller/more predictable. Premature optimization forbidden.

---

# PART 12. IMPLEMENTATION TRACKING

## Rule 30. Status Update
After every successful Epic, update `docs/IMPLEMENTATION_STATUS.md` with: completed, in progress, blocked, known issues.

Mandatory per-Epic fields:
```
## EPIC-X.Y
Status: Completed
Files:
- ...
Commit: ...
Notes: ...
```

---

# PART 13. FINAL PRINCIPLE

## Rule 31. Correctness Over Creativity
Priority order (never reversed): correctness, stability, compatibility, maintainability, performance, beauty. This is engineering work, not a creative exercise.

---

# PART 14. ENVIRONMENT AWARENESS

`docs/LOCAL_ENVIRONMENT.md` is the authoritative source for the target VPS/deployment (PHP, Composer, SQLite, Workerman, Ubuntu, systemd, WebSocket, Git). Before assuming anything about these, consult it.

Forbidden claims (if the software is declared in LOCAL_ENVIRONMENT.md or previously confirmed by user): "PHP/Composer/SQLite not installed", "Environment unavailable", "Verification impossible".

Conflict priority: ANCHOR_CORE.md > ANCHOR_PROTOCOL.md > ANCHOR_RULES.md > LOCAL_ENVIRONMENT.md > User instructions. If assumptions conflict with LOCAL_ENVIRONMENT.md, LOCAL_ENVIRONMENT.md wins.

---

# PART 15. VERIFICATION RULE

Every Epic completion report must contain a `VERIFICATION` section (mandatory).

**Automated**: if executed, describe commands run and results obtained.

**Manual** (if automated execution not possible): provide `MANUAL VERIFICATION REQUIRED` with step-by-step commands, expected outputs, success criteria. Example:
```
MANUAL VERIFICATION REQUIRED
Run: php test_database.php
Expected: Database connection successful
Run: sqlite3 game.db
  PRAGMA journal_mode;  → Expected: wal
  PRAGMA foreign_keys;  → Expected: 1
```

Forbidden responses (unless paired with a full manual verification procedure): "Verification not performed", "Check manually", "Unable to verify", "Environment unavailable".

An Epic cannot be marked Completed/Done/Ready unless automated verification succeeded OR manual verification instructions were provided.

---

# PART 16. GIT CHECKPOINT RULE

After every successfully completed Epic, recommend:
```
git add .
git commit -m "EPIC-X.Y short-description"
git push
```
Commit format: `EPIC-X.Y short-description` (e.g. `EPIC-0.5 project-structure`, `EPIC-3.2 game-engine`).
Do not postpone commits across multiple completed Epics — each Epic gets its own commit.

---

# PART 17. REMOTE EXECUTION RULE

Workflow: AI generates code → User copies to VPS → VPS executes. The model has no direct VPS access.

When `LOCAL_ENVIRONMENT.md` exists, assume its described environment is the deployment target.

The model may state "Automated execution was not possible in the current environment" but must immediately follow with `MANUAL VERIFICATION REQUIRED` instructions. Lack of VPS access never excuses omitting verification steps, expected results, or acceptance criteria.

---

# PART 18. EPIC EXECUTION RULE

**Single Responsibility**: each Epic implements exactly one logical feature.
Allowed: "Create Database class", "Implement login handler", "Implement room creation".
Forbidden (must decompose): "Implement authentication system", "Implement lobby system", "Implement game engine", "Implement reconnect system".

**Size**: 300-500 lines new code max; recommended target 150-300. If exceeded → stop and propose decomposition.

**File count**: normal 1-3, warning at 4, hard limit 5. If more required → stop, create additional Epics.

**Hidden Refactoring Prohibition**: no renaming classes/methods/variables, moving files, changing folder structure or interfaces, unless explicitly required by the Epic.

**Scope Lock**: inside an Epic, only the described functionality may be modified — everything else is frozen.

---

# PART 19. CONTEXT RECOVERY RULE

Before implementing any Epic, review: `ANCHOR_CORE.md, ANCHOR_PROTOCOL.md, ANCHOR_RULES.md, LOCAL_ENVIRONMENT.md, IMPLEMENTATION_STATUS.md`.

Every completed Epic must update `IMPLEMENTATION_STATUS.md` (see Part 12 format).

A new model instance must be able to continue development using only the five docs above, without prior chat history.

---

# PART 20. PATCH DELIVERY RULE

Existing files >300 lines: `diff -u` only. New files: full content allowed.
Forbidden: full `server.php`, `app.js`, `GameService.php` etc. when the file already exists.
Every patch requires the `CHANGED:` / `NOT CHANGED:` report (Rule 10).

---

# PART 21. NAMESPACE PROTECTION RULE

Root namespace is fixed: `Lotto\`. Forbidden: introducing `App\`, `Application\`, custom root namespaces, or modifying the Composer PSR-4 mapping. All new classes use `namespace Lotto\...`. If code contains another root namespace, the model must stop and report a namespace inconsistency.

---

# PART 22. TEST PHILOSOPHY

- Tests verify contracts.
- Tests must not compensate for missing contracts.
- If a test fails because a contract is missing, fix the implementation (not the test).
- No fallback logic, no reflection, no method guessing in tests.
