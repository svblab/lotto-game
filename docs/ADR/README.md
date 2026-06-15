# Architecture Decision Records (ADR)

This directory contains Architecture Decision Records for the project.

An ADR documents a significant architectural decision, the context in which
it was made, and its consequences. Any change that affects architecture,
protocol, economy, timers, state machine, or naming as defined in
`ANCHOR_CORE.md`, `ANCHOR_PROTOCOL.md`, or `ANCHOR_RULES.md` must be
recorded as an ADR before implementation.

## Process

1. Copy `000-template.md` to a new file named `NNN-short-title.md`,
   where `NNN` is the next sequential number.
2. Fill in all sections: Title, Status, Context, Decision, Consequences.
3. Reference the ADR number in the related Epic and patch.

## Status Values

```text
proposed
accepted
rejected
superseded
```

Anchor documents (`ANCHOR_CORE.md`, `ANCHOR_PROTOCOL.md`, `ANCHOR_RULES.md`)
remain the single source of truth and are updated only after an ADR is
accepted.
