# ANCHOR_PROJECT_STATUS.md

## Purpose

This document restores full project context after chat restart.

It contains:

* project vision;
* architectural decisions;
* completed implementation stages;
* known pitfalls;
* mandatory rules for future models.

This file is NOT a replacement for:

* ANCHOR_CORE.md
* ANCHOR_PROTOCOL.md
* ANCHOR_RULES.md

Those remain the authoritative specifications.

This document explains current project status.

---

# Project

Project:

Russian Lotto

Type:

Multiplayer browser game

Stack:

* PHP 8.x
* Workerman WebSocket
* SQLite3
* Vanilla JavaScript

Target environment:

Ubuntu VPS

Resource budget:

* 1 CPU
* 500 MB RAM

Architecture priority:

Simplicity > Scalability

Project intentionally avoids:

* Redis
* MySQL
* Docker
* Frameworks

---

# Development Philosophy

The previous project version (v4) was abandoned.

Reason:

Architecture degradation.

The user intentionally wrote off all previous work as:

"experience gained"

The current implementation is a complete restart.

No code from v4 should be trusted automatically.

Every implementation decision must follow anchor documents.

---

# Anchor Hierarchy

Priority order:

1. ANCHOR_CORE.md
2. ANCHOR_PROTOCOL.md
3. ANCHOR_RULES.md
4. ADR documents
5. Implementation code

If code contradicts anchors:

Code is wrong.

Anchors are correct.

---

# Root Namespace

Mandatory:

```php
namespace Lotto\...
```

Composer:

```json
"autoload": {
    "psr-4": {
        "Lotto\\": "src/"
    }
}
```

Project directory name:

```text
lotto-game
```

Directory name DOES NOT affect namespace.

Namespace remains:

```text
Lotto
```

Always.

---

# Local Environment Clarification

Important:

Another AI model performs development.

The model DOES NOT execute on the real VPS.

The model often incorrectly assumes:

* Composer unavailable
* PHP unavailable
* sqlite unavailable

This assumption is false.

Real VPS contains:

PHP 8.4.x

Composer 2.x

SQLite3

Actual environment verification must always override model assumptions.

A dedicated document exists:

```text
docs/LOCAL_ENVIRONMENT.md
```

Future models must read it before making environment claims.

---

# Implemented Epics

## EPIC-0.1

Project skeleton.

Completed.

---

## EPIC-0.2

Composer configuration.

Completed.

---

## EPIC-0.3

Database layer.

Completed.

Implemented:

```text
Database.php
```

---

## EPIC-0.4

Prepared statement registry.

Completed.

Implemented:

```text
PreparedStatements.php
```

Verified:

Statement caching works.

Same key returns same PDOStatement instance.

---

## EPIC-0.5

Logger.

Completed.

Implemented:

```text
Logger.php
```

Features:

* INFO
* WARNING
* ERROR

Format:

```text
[YYYY-MM-DD HH:MM:SS] [LEVEL] message
```

---

## EPIC-0.6

Constants.

Completed.

Implemented:

```text
Constants.php
```

---

## EPIC-0.7

SessionService.

Completed.

Implemented:

```text
generateToken()
isValidToken()
tokensEqual()
```

Validation:

```php
preg_match('/^[a-f0-9]{32}$/')
```

Timing-safe compare:

```php
hash_equals()
```

---

## EPIC-0.8

Infrastructure testing.

Completed.

Verified:

* Composer
* SQLite
* Database
* PreparedStatements
* Logger
* SessionService

---

## EPIC-0.9

Helpers.

Completed.

Implemented:

```php
sendJson()
sendError()
broadcastToRoom()
serverLog()
```

Manual tests passed.

---

## EPIC-1.0

AuthService (registration)

IN PROGRESS / REQUIRES REVIEW

Current implementation exists.

However:

Implementation contains excessive defensive fallback logic.

Examples:

* Reflection
* Runtime probing
* Dynamic statement discovery
* PDO extraction hacks

This violates project philosophy.

Expected direction:

Simple deterministic implementation.

Future review required.

---

# Current File Inventory

Implemented files:

```text
src/Core/Constants.php
src/Core/Logger.php

src/Infrastructure/Database.php
src/Infrastructure/PreparedStatements.php

src/Auth/AuthService.php
src/Auth/SessionService.php

src/Lobby/LobbyService.php

src/Game/GameService.php
src/Game/LottoEngine.php
src/Game/VictoryService.php
src/Game/ApartmentService.php
src/Game/ReconnectService.php

src/Admin/AdminService.php
```

Many files currently exist only as placeholders.

This is expected.

---

# Critical Lessons Learned

## Lesson 1

AI tends to overengineer.

Project requires:

Simple code.

Not enterprise code.

---

## Lesson 2

AI tends to rewrite files.

Forbidden.

Large files must be changed using:

```diff
diff -u
```

only.

---

## Lesson 3

AI tends to invent architecture.

Forbidden.

Architecture already fixed in anchors.

---

## Lesson 4

AI tends to rename entities.

Forbidden.

Naming Registry is authoritative.

---

## Lesson 5

AI often loses context after 15-20 Epics.

Future models should load:

* ANCHOR_CORE.md
* ANCHOR_PROTOCOL.md
* ANCHOR_RULES.md
* ANCHOR_PROJECT_STATUS.md

before generating code.

---

# Current Development Phase

Phase:

Foundation Layer

Status:

~10% complete

Game logic:

Not started.

Protocol:

Not implemented.

Lobby:

Not implemented.

Workerman:

Not implemented.

WebSocket:

Not implemented.

Economy:

Not implemented.

State machine:

Not implemented.

Timers:

Not implemented.

---

# Next Planned Epic

Next target:

EPIC-1.1

AuthService cleanup and completion.

Objectives:

* remove fallback hacks;
* use PreparedStatements registry directly;
* align implementation with anchors;
* prepare Login flow.

No architecture changes allowed.

---

# Mandatory Rule For Future Models

Before writing code:

1. Read ANCHOR_CORE.md
2. Read ANCHOR_PROTOCOL.md
3. Read ANCHOR_RULES.md
4. Read ANCHOR_PROJECT_STATUS.md

Only then continue development.

Failure to do so will likely reintroduce previously solved problems.
