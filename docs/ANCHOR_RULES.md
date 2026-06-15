ANCHOR_CORE.md, ANCHOR_PROTOCOL.md и ANCHOR_RULES.md прочитаны и обязательны к исполнению. Нарушение любого правила считается ошибкой реализации.
# ANCHOR_RULES.md

## Purpose

This document defines mandatory development rules for all AI models participating in the project.

The purpose of this document is to preserve architecture integrity, protocol compatibility, economy correctness and implementation consistency across multiple AI sessions and multiple AI models.

If code, patch, epic plan or implementation contradicts this document, the implementation is considered invalid and must be regenerated.

---

# PART 1. AUTHORITY ORDER

## Rule 1. Priority Order

When rules conflict, the following priority must be used:

```text
1. User instruction

2. ANCHOR_CORE.md

3. ANCHOR_PROTOCOL.md

4. ANCHOR_RULES.md

5. IMPLEMENTATION_STATUS.md

6. Epic specification

7. Model preferences
```

Model preferences always have the lowest priority.

---

## Rule 2. Single Source Of Truth

Architecture authority:

```text
docs/ANCHOR_CORE.md
```

Protocol authority:

```text
docs/ANCHOR_PROTOCOL.md
```

Implementation authority:

```text
docs/IMPLEMENTATION_STATUS.md
```

Archived documents must not be treated as authoritative.

---

# PART 2. BEFORE CODING

## Rule 3. Read Before Writing

Before generating code the model must identify:

```text
current Epic

current implementation state

affected modules

affected files
```

If any of this information is unknown:

STOP.

Request clarification.

Assumptions are forbidden.

---

## Rule 4. No Assumptions

The model must never assume:

```text
current code state

implemented features

existing helper functions

existing services

existing packet structures
```

If information is missing:

STOP.

Ask for the missing information.

---

# PART 3. ABSOLUTE PROHIBITIONS

## Rule 5. Forbidden Operations

The following actions are forbidden unless explicitly requested by the user:

```text
rewrite entire file

rewrite entire module

rewrite server.php

rewrite app.js

rewrite architecture

rewrite protocol

rewrite Room structure

rewrite Player structure

rename protocol packets

rename protocol actions

change function signatures

change directory structure

perform hidden refactoring
```

The model must preserve all existing contracts.

---

## Rule 6. Hidden Refactoring Prohibition

The following changes are forbidden when not explicitly required by the Epic:

```text
renaming variables

renaming methods

moving code between files

reordering methods

formatting-only edits

optimization-only edits

splitting files

merging files

style cleanup
```

Every modification must directly implement a documented requirement.

---

## Rule 7. No Hidden Features

The model must not introduce:

```text
new timers

new room states

new player states

new protocol packets

new protocol actions

new economy rules

new admin permissions

new database tables

new persistent structures
```

unless explicitly approved through specification or ADR.

---

# PART 4. PATCH POLICY

## Rule 8. Diff-First Development

All modifications must be delivered using:

```diff
--- old
+++ new
@@
...
```

(diff -u format)

Returning entire files is forbidden.

Exceptions:

```text
new file creation

explicit user request
```

---

## Rule 9. Large File Protection

If a file exceeds:

```text
300 lines
```

the model must never return the entire file.

Only diff -u patches are allowed.

---

## Rule 10. Mandatory Change Report

After every patch the model must provide:

```text
CHANGED:
- item
- item

NOT CHANGED:
- item
- item
```

This section is mandatory.

Patch without a change report is invalid.

---

# PART 5. EPIC SIZE CONTROL

## Rule 11. Epic Isolation

One Epic must solve exactly one problem.

Forbidden:

```text
Auth + Lobby

Lobby + Game

Game + Admin

multiple unrelated systems
```

inside one Epic.

If multiple systems are involved:

decompose the Epic.

---

## Rule 12. Maximum Epic Size

Normal Epic:

```text
300-500 lines of new code
```

Maximum recommended:

```text
500 lines
```

---

## Rule 13. Mandatory Decomposition

If implementation exceeds Epic limits:

STOP.

Return:

```text
Epic is too large.

Decomposition required.
```

Code generation is forbidden.

---

## Rule 14. File Modification Limit

Normal Epic:

```text
1-3 files
```

Maximum:

```text
5 files
```

If more files are affected:

STOP.

Request Epic decomposition.

---

# PART 6. SERVER ARCHITECTURE DISCIPLINE

## Rule 15. server.php Responsibilities

Target size:

```text
<= 500 lines
```

Allowed:

```text
Workerman bootstrap

worker initialization

dependency wiring

action routing

timer registration

module loading
```

Forbidden:

```text
authentication logic

room management logic

economy logic

victory logic

apartment logic

reconnect logic

admin logic
```

Business logic must not exist inside server.php.

---

## Rule 16. Bootstrap Files

Bootstrap files include:

```text
server.php

init_db.php
```

Bootstrap files may only:

```text
initialize

configure

register

wire dependencies
```

Business logic is forbidden.

---

# PART 7. DATABASE DISCIPLINE

## Rule 17. PDO Only

All database access must use:

```php
PDO
```

Direct SQLite access is forbidden.

---

## Rule 18. Prepared Statements Only

All SQL queries must use:

```php
prepared statements
```

SQL string concatenation is forbidden.

---

## Rule 19. Transaction Safety

Every transaction must use:

```php
try
{
    beginTransaction();

    ...

    commit();
}
catch (...)
{
    rollBack();
}
```

Partial transactions are forbidden.

---

## Rule 20. Statement Cache

Prepared statements must be reused.

Repeated preparation of identical SQL queries is forbidden.

Use centralized statement caching.

---

# PART 8. STATE AND ECONOMY PROTECTION

## Rule 21. State Machine Authority

Room states:

```text
waiting
playing
apartment
finished
```

Player states:

```text
active
disconnected
```

No additional states are allowed.

---

## Rule 22. Economy Protection

Economy is critical infrastructure.

Forbidden:

```text
partial payouts

implicit refunds

untracked balance changes

coin duplication

coin creation
```

except documented mechanics.

Every balance modification must be explainable.

---

## Rule 23. Timer Discipline

Every timer must have:

```text
creator

owner

destroyer
```

If one is unknown:

implementation is invalid.

---

# PART 9. MODULE BOUNDARIES

## Rule 24. Respect Module Boundaries

Modules communicate through public interfaces only.

Forbidden examples:

```text
Game -> Auth internals

Lobby -> Apartment internals

Admin -> Game internals
```

Direct access to internal implementation is forbidden.

---

## Rule 25. Service Responsibilities

Handlers:

```text
request routing
```

Services:

```text
business logic
```

Core:

```text
infrastructure
```

Responsibilities must not overlap.

---

# PART 10. PROTOCOL STABILITY

## Rule 26. Protocol Compatibility

Packets are contracts.

Forbidden:

```text
rename packet

remove field

change field meaning

rename action

change payload format
```

without protocol version change.

---

## Rule 27. Naming Authority

All names must follow:

```text
NAMING_REGISTRY
```

Alternative names are forbidden.

---

# PART 11. PERFORMANCE DISCIPLINE

## Rule 28. VPS Awareness

Target environment:

```text
1 CPU

500 MB RAM
```

Every Epic must consider:

```text
memory usage

timer count

room count

player count
```

Avoid unnecessary allocations.

Avoid duplicated structures.

---

## Rule 29. Simplicity Preference

When multiple valid implementations exist:

prefer:

```text
simpler

smaller

more predictable
```

solution.

Premature optimization is forbidden.

---

# PART 12. IMPLEMENTATION TRACKING

## Rule 30. Status Update

After successful Epic:

mandatory update:

```text
docs/IMPLEMENTATION_STATUS.md
```

Must contain:

```text
completed

in progress

blocked

known issues
```

---

# PART 13. FINAL PRINCIPLE

## Rule 31. Correctness Over Creativity

Priority order:

```text
correctness

stability

compatibility

maintainability

performance

beauty
```

Never reverse this order.

The project is engineering work.

Not a creative exercise.

ENVIRONMENT AWARENESS RULE
Purpose

Prevent incorrect assumptions about the target deployment environment.

Source of Truth

The file:

docs/LOCAL_ENVIRONMENT.md

is the authoritative source of information about the target VPS and deployment environment.

If information exists in LOCAL_ENVIRONMENT.md, the model must use it.

Mandatory Behavior

Before making assumptions about:

PHP
Composer
SQLite
Workerman
Ubuntu
systemd
WebSocket
Git

the model must consult:

docs/LOCAL_ENVIRONMENT.md
Forbidden Statements

The model must NOT claim:

PHP is not installed
Composer is not installed
SQLite is not installed
Environment unavailable
Verification impossible

if the corresponding software is declared in:

docs/LOCAL_ENVIRONMENT.md

or was previously confirmed by the user.

Conflict Resolution

Priority order:

1. ANCHOR_CORE.md
2. ANCHOR_PROTOCOL.md
3. ANCHOR_RULES.md
4. LOCAL_ENVIRONMENT.md
5. User instructions

If a conflict exists between assumptions and LOCAL_ENVIRONMENT.md:

LOCAL_ENVIRONMENT.md wins.
VERIFICATION RULE
Purpose

Ensure every Epic contains a reproducible validation procedure.

Mandatory Verification Section

Every Epic completion report must contain:

VERIFICATION

section.

The section is mandatory.

Automated Verification

If verification was executed:

VERIFICATION

Automated checks:
...

must describe:

commands executed
results obtained
Manual Verification

If verification cannot be executed by the model:

the model must provide:

MANUAL VERIFICATION REQUIRED

followed by:

step-by-step commands
expected outputs
success criteria
Minimum Manual Verification Format

Example:

MANUAL VERIFICATION REQUIRED

Run:

php test_database.php

Expected:

Database connection successful

Run:

sqlite3 game.db

PRAGMA journal_mode;

Expected:

wal

PRAGMA foreign_keys;

Expected:

1
Forbidden Verification Responses

The following responses are prohibited:

Verification not performed.

Check manually.
Unable to verify.
Environment unavailable.

unless accompanied by a complete manual verification procedure.

Verification Completion Requirement

An Epic cannot be marked:

Completed
Done
Ready

unless either:

1. Automated verification succeeded

or

2. Manual verification instructions were provided
GIT CHECKPOINT RULE
Purpose

Prevent loss of progress during long multi-Epic development.

Mandatory Checkpoint

After every successfully completed Epic:

the model must recommend:

git add .
git commit -m "EPIC-X.Y short-description"
git push
Commit Naming Convention

Format:

EPIC-X.Y short-description

Examples:

EPIC-0.5 project-structure

EPIC-0.6 database-layer

EPIC-1.1 auth-register

EPIC-3.2 game-engine
Forbidden Behavior

The model must not recommend postponing commits across multiple completed Epics.

Every completed Epic should have its own commit whenever possible.

REMOTE EXECUTION RULE
Purpose

Clarify responsibilities when code is generated remotely and deployed manually by the user.

Deployment Model

Project workflow:

AI Model
    ↓
Generates code

User
    ↓
Copies code to VPS

Target VPS
    ↓
Executes code

The model does not have direct access to the VPS.

Required Assumption

When LOCAL_ENVIRONMENT.md exists:

the model must assume that the described environment is the deployment target.

Verification Limitation

The model may state:

Automated execution was not possible in the current environment.

but must immediately provide:

MANUAL VERIFICATION REQUIRED

instructions.

Forbidden Behavior

The model must not use the lack of VPS access as a reason to omit:

verification steps
expected results
acceptance criteria

EPIC EXECUTION RULE
Purpose

Prevent uncontrolled growth of task scope and hidden architecture changes.

Single Responsibility Rule

Each Epic must implement exactly one logical feature.

Allowed examples:

Create Database class

Create Logger class

Implement register handler

Implement login handler

Implement room creation

Forbidden examples:

Implement authentication system

Implement lobby system

Implement game engine

Implement reconnect system

Such tasks must be decomposed.

Maximum Change Size

One Epic may introduce:

300–500 lines of new code

Recommended target:

150–300 lines

If implementation exceeds the limit:

the model must stop and propose decomposition.

Maximum File Count

Normal Epic:

1–3 files modified

Warning threshold:

4 files

Hard limit:

5 files

If more files are required:

the model must stop and create additional Epics.

Hidden Refactoring Prohibition

The model must not:

rename classes
rename methods
rename variables
move files
change folder structure
change interfaces

unless explicitly required by the Epic.

Scope Lock

Inside an Epic:

only the functionality described in the Epic may be modified.

Everything else is considered frozen.

CONTEXT RECOVERY RULE
Purpose

Allow limited-context models to safely continue development.

Mandatory Reading Order

Before implementing any Epic:

the model must review:

docs/ANCHOR_CORE.md
docs/ANCHOR_PROTOCOL.md
docs/ANCHOR_RULES.md
docs/LOCAL_ENVIRONMENT.md
docs/IMPLEMENTATION_STATUS.md
Status File Rule

Every completed Epic must update:

docs/IMPLEMENTATION_STATUS.md
Mandatory Status Fields

For every Epic:

## EPIC-X.Y

Status:
Completed

Files:
- ...

Commit:
...

Notes:
...
Resume Capability

A new model instance must be able to continue development using only:

ANCHOR_CORE.md
ANCHOR_PROTOCOL.md
ANCHOR_RULES.md
LOCAL_ENVIRONMENT.md
IMPLEMENTATION_STATUS.md

without reading previous chat history.

PATCH DELIVERY RULE
Purpose

Prevent token waste and accidental file corruption.

Existing Files

If a file already exists and exceeds:

300 lines

the model must return:

diff -u

only.

New Files

For newly created files:

full file content is allowed.

Forbidden Output

Forbidden:

Full server.php

Full app.js

Full GameService.php

when the file already exists.

Mandatory Diff Report

After every patch:

CHANGED:
- ...

NOT CHANGED:
- ...

must be included.

После добавления этих правил у тебя получится очень устойчивая система:

ANCHOR_CORE.md — архитектура, экономика, состояния, таймеры, именование.
ANCHOR_PROTOCOL.md — WebSocket-протокол.
ANCHOR_RULES.md — правила работы моделей.
LOCAL_ENVIRONMENT.md — реальный VPS.
IMPLEMENTATION_STATUS.md — текущее состояние проекта.