# 022 — Registry Hygiene: Reserved Fields and Test Hook

## Status

Accepted

## Context

A full documentation-vs-code audit of `main` found three Room Structure
registry gaps:

1. **`bet_per_card`** — declared in `ANCHOR_CORE.md` and initialized in
   `RoomManager::createRoom()`, but never read by any production code path.
   Stake calculation uses `Constants::BET_PER_CARD` directly.
2. **`pause_for_apartment`** — declared and initialized to `false` in
   `RoomManager::createRoom()`, but never read or toggled in production.
   Apartment pause is represented by `status === 'apartment'`.
3. **`_apartment_participants`** — read defensively by
   `ApartmentService::getParticipants()` when present, but undeclared in the
   registry. Set only by manual-test harnesses (`test_apartment.php`,
   `test_admin_kick.php`). Production code never creates this key.

Constraints: ANCHOR_RULES.md Rule 6 (no code changes in this pass); registry
compliance per Rule 27 (naming drift must be documented).

## Decision

1. **Keep `bet_per_card` and `pause_for_apartment` as documented-reserved**
   — no code change, consistent with ADR-007's treatment of `error.banned`
   (declared in the registry, not actively consumed). Document their reserved
   semantics in `ANCHOR_CORE.md` § Room Structure and § Room Structure Keys.
2. **Formally register `_apartment_participants` as a test-only hook** —
   leading underscore marks it as non-production; document in
   `ANCHOR_CORE.md` § Room Structure Keys. Do not remove the defensive read in
   `ApartmentService::getParticipants()` and do not promote it to a production
   feature.

No protocol version bump; documentation-only alignment.

## Consequences

Positive:

- Closes the registry compliance gap found in the documentation audit.
- Test harnesses can continue using `_apartment_participants` without
  violating the "no undeclared keys" rule.
- Reserved fields remain available for future room snapshots or per-room
  bet variants without a breaking rename.

Negative / limitations:

- `bet_per_card` / `pause_for_apartment` still occupy memory on every room
  until a future cleanup Epic explicitly removes them (would require ADR +
  fixture migration).
- `_apartment_participants` must not be set by production code — only manual
  tests.

No runtime behavior change.
