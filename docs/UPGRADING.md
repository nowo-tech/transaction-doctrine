# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [1.0 to 1.1](#10-to-11)
- [1.0.0](#100)

## Unreleased

Nothing to do: tooling, documentation and test layout only (no change under `src/`).

## 1.0 to 1.1

No code change is required.

- `doctrine/dbal ^3.8.2` is now accepted besides `^4.0`. On DBAL 3 with emulated nesting (savepoints off) nested
  leftovers are rolled back level by level instead of closing the connection.
- **A domain failure no longer resets a shared entity manager.** After a refusal that left nothing pending, only the
  entities that entered the identity map during the run are detached; the caller's other entities stay managed. If
  your code relied on the manager being reset after *any* failure, re-load what you need after catching the
  exception (storage failures still close and reset the manager as before).
- After catching a failure from a **nested** `run()`, do not continue the same unit of work: the nested failure can
  clear the caller's pending changes.
- `ConnectionRepairer` has a new optional constructor argument `?bool $emulatedNesting = null` (detected by default).

## 1.0.0

First release. Requires `nowo-tech/transaction-contract ^1.0`.
