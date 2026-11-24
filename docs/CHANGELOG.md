# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.10.1] - 2026-11-24](#1101---2026-11-24)
- [[1.10.0] - 2026-11-24](#1100---2026-11-24)
- [[1.9.3] - 2026-11-21](#193---2026-11-21)
- [[1.9.2] - 2026-11-21](#192---2026-11-21)
- [[1.9.1] - 2026-11-20](#191---2026-11-20)
- [[1.9.0] - 2026-11-20](#190---2026-11-20)
- [[1.8.2] - 2026-11-20](#182---2026-11-20)
- [[1.8.1] - 2026-11-20](#181---2026-11-20)
- [[1.8.0] - 2026-11-20](#180---2026-11-20)
- [[1.7.2] - 2026-11-20](#172---2026-11-20)
- [[1.7.1] - 2026-11-19](#171---2026-11-19)
- [[1.7.0] - 2026-11-19](#170---2026-11-19)
- [[1.6.1] - 2026-11-19](#161---2026-11-19)
- [[1.6.0] - 2026-11-18](#160---2026-11-18)
- [[1.5.0] - 2026-11-18](#150---2026-11-18)
- [[1.4.0] - 2026-11-18](#140---2026-11-18)
- [[1.3.1] - 2026-11-17](#131---2026-11-17)
- [[1.3.0] - 2026-11-17](#130---2026-11-17)
- [[1.2.0] - 2026-11-17](#120---2026-11-17)
- [[1.1.0] - 2026-11-15](#110---2026-11-15)
- [[1.0.0] - 2026-11-15](#100---2026-11-15)

## [Unreleased]

## [1.10.1] - 2026-11-24

### Fixed

- The deprecated ConflictCode methods no longer trigger a runtime E_USER_DEPRECATED: bundles that fail CI on deprecations broke on 1.10.0. Migration to ConflictCodeResolver stays recommended (docblock deprecation).

## [1.10.0] - 2026-11-24

### Added

- **Framework-free conflict types (REQ-ARCH-002 prerequisite):** requires `nowo-tech/transaction-contract` ^1.1, which
  now holds `ConflictCode`, `TransientFailureKind`, `ConcurrencyConflict` and `LockConflictExceptionInterface` with the
  names, values and semantics of 1.9. A bundle's `Domain` / `Contract` layer can name them without depending on this
  package (whose `ConflictCode` imports HttpFoundation and Messenger).
- `ConflictCodeResolver::forFailure()` / `forRequest()`: the code of a failure as the contract enum (the logic of
  `ConflictCode::forFailure()` / `forRequest()`, moved).
- `ConflictCode::toContract()` / `ConflictCode::fromContract()`; `ConflictResponseContext::$contractCode`.

### Changed

- `ConflictResponsePolicy::headersFor()` / `httpExceptionFor()` and `ConflictResponseContext`'s `$code` argument accept
  either `ConflictCode` enum.
- Internal code (write units, classifier, responder, Messenger middleware, bundle configuration) names the contract
  types. Write units throw the same `ConcurrencyConflict` class as before.

### Deprecated

- `Nowo\TransactionDoctrine\TransientFailureKind`, `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict` and
  `Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface`: now class aliases of the
  `Nowo\TransactionContract\…` types (same class: `catch`, `instanceof` and type declarations interoperate both ways),
  registered eagerly through Composer `autoload.files` (`aliases.php`). Removed in 2.0.
- `Nowo\TransactionDoctrine\ConflictCode`: use `Nowo\TransactionContract\ConflictCode` and `ConflictCodeResolver`.
  It stays an enum of its own in 1.x (its static helpers delegate to the resolver). Removed in 2.0. `forFailure()` and
  `forRequest()` are deprecated by docblock (1.10.0 also triggered a silenced `E_USER_DEPRECATED`; removed in 1.10.1); the aliases trigger none (registered eagerly for every
  process, no hook on alias use).

No BC break: every 1.9 name, signature and behaviour keeps working. One visible difference: the runtime class name of
a write unit's conflict (`get_class()`, the `exception_class` log key) is now
`Nowo\TransactionContract\ConcurrencyConflict`; `ConcurrencyConflict::class` written under the old name still
resolves to the old string, so compare classes with `instanceof`, not by name.

## [1.9.3] - 2026-11-21

### Fixed

- **Host-flush probe: probe reported hydrated entities with clean inverse-side collections as pending host changes:**
  `HostFlushProbe` counted a collection as changed unless it was the clean collection held in the entity's loaded state.
  ORM leaves the collections out of that state once a host `onFlush` listener called `recomputeSingleEntityChangeSet()`
  (an "updated at / updated by" listener), so every such entity with a one-to-many was reported as "flushed by" the
  bundle although the flush writes nothing for it (Beacon census: `User`, `Project`, `Task`). The probe now models what
  `UnitOfWork::computeChangeSets()` and the collection persisters write: an owning side's insert and delete diffs, the
  deletion of a replaced collection (owning side, or one-to-many with orphan removal; skipped like ORM when the
  collection is not in the loaded state), orphan removals and cascaded persists of new elements. An inverse side, dirty
  or not, is otherwise never a change, nor an uninitialised collection. Scheduled collection updates and deletions only
  count when a persister writes them. A real pending host write is still reported; the probe stays read-only and
  stateless beyond its per-unit-of-work report list. No BC break.

## [1.9.2] - 2026-11-21

### Fixed

- **Host runner: orphan removals count as pending host work (audit XD-1):** `forHostEntityManager()->run()` refuses a
  host manager with pending changes before opening a transaction, but its check ignored `UnitOfWork`'s orphan removals.
  Two host changes schedule nothing else: `clear()` on an inverse one-to-many with `orphanRemoval`, and an inverse
  one-to-one with `orphanRemoval` set to `null`. The run was accepted and its flush deleted the host's orphan rows
  inside the unit's transaction. Orphan removals are now pending work (read without computing change sets, ORM 2.20 and
  3.x), so both cases are refused with `LogicException` (`HOST_PENDING_MESSAGE`) and the host's own flush still deletes
  them; loaded, untouched orphanRemoval associations still run. Only managed orphans count: after the host flushed an
  inverse one-to-one set to `null`, ORM schedules the deleted entity again at every change-set computation although no
  flush deletes anything, and those runs are still accepted. Covered on SQLite and on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16. `HostFlushProbe` reads orphan removals through the same internal reader, so it no longer reports
  such a stale orphan either.
- **Write unit: a pending orphan removal makes the unit run once (audit XD-1):** `WriteUnit`'s pending-changes check
  ignored orphan removals too, so a caller's `clear()` on an inverse one-to-many with `orphanRemoval`, or inverse
  one-to-one set to `null`, did not stop a replay, and the repair between attempts silently dropped that removal. A
  managed orphan removal now counts in the run-once, replay and discard decisions: the unit runs once, the failure
  propagates, and the caller's flush still deletes the orphan. Stale, unmanaged orphans do not count.
- **Private entity manager: a pending orphan removal keeps a stale manager (audit XD-1):** a private manager made
  stale by a rollback was replaced when its only pending work was an orphan removal, silently dropping the bundle's
  removal. It is now kept until that work is flushed or the boundary, as for any other pending change; once nothing
  managed is pending, it is replaced as before.
- **Host-flush probe: no report for a flushed inverse one-to-one orphan (audit XD2-2):** after the host flushed an
  inverse one-to-one with `orphanRemoval` set to `null`, ORM keeps the deleted entity in the owner's loaded state, so
  `HostFlushProbe` kept reporting the owner's class at every later bundle flush although Doctrine writes nothing for an
  inverse side. The probe now compares only fields and owning-side to-one associations; a pending inverse orphan
  removal is reported as the class of the entity the flush deletes, and only while that entity is managed.
- **Host-flush probe: an already reported class is still walked (audit TD192-1):** once an entity class was reported
  (for example by a scheduled insertion), the probe skipped the other managed entities of that class, so the orphan an
  inverse one-to-one of one of them left, and the new entities a changed association of one of them cascade-persists,
  were not reported although the flush deletes or inserts them. Every managed entity outside the bundle's namespaces is
  now walked.
- **Host-flush probe: a clean collection moved to another owner is pending work (audit TD192-2, since 1.9.0):** a
  clean `PersistentCollection` counted as unchanged even when it was another entity's collection assigned to this
  owner, whose flush then writes it (join rows of a many-to-many owning side). A collection is now unchanged only when
  it is the loaded one and clean.
- **Private entity manager: dropped orphan removals are logged (audit TD192-4):** the warning logged when a boundary
  drops the private manager with pending changes now counts managed orphan removals too, so a manager holding only an
  orphan removal no longer drops it silently.
- **`#[AsWriteUnit]` controllers: no Symfony 8.1 deprecation:** `WriteUnitControllerListener` passed the replaced
  controller's attributes to `ControllerArgumentsEvent::setController()` grouped by class name, which Symfony 8.1
  deprecates ("Passing an array of attributes grouped by class name ... is deprecated"), once per write-unit request.
  On Symfony >= 8.1 (the installed `Kernel::VERSION_ID`) it now passes the flat list Symfony keeps in the
  `_controller_attributes` request attribute; on older Symfony versions it keeps passing them grouped and ignores that
  attribute, which they never read (audit TD192-3). The attributes still reach the listeners after
  it in both cases.

## [1.9.1] - 2026-11-20

### Fixed

- **Host-flush probe: no false positive for backed-enum fields (audit XA-1):** `HostFlushProbe` compared an enum field's
  value (the backing value, `'draft'`) with its loaded state (the case, `Status::Draft`), so every loaded, untouched
  entity with an enum column was reported as "Your pending changes to App\X were flushed by <Bundle>" although the
  flush writes nothing for it (ORM 2.20, 3.7 and 3.8). The loaded case, and each case of an array of enums
  (`enumType` on array / simple_array columns), is now turned into its backing value before the comparison, exactly as
  `UnitOfWork::computeChangeSet()` does in each supported ORM. Cross-checked against Doctrine's change sets on SQLite,
  MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (untouched, changed, array of enums, nullable enum set to null).
- **Host-flush probe never breaks the caller's write (audit XA-3, defence in depth):** a Throwable from the inspection
  (a broken proxy, an entity whose state cannot be read) or from the deprecation itself (a host error handler that
  ignores `@` and throws on `E_USER_DEPRECATED`) is caught: logged as a warning when a PSR logger is wired (new optional
  constructor argument; the Symfony bundle passes the `logger` service, channel `transaction_doctrine`, when there is
  one), otherwise swallowed. The warning holds the bundle, the entity class and the failure's type (`exception_type`),
  never its message or trace (audit XB-2). A failed inspection reports and returns no classes.
- **Unit-of-work boundaries: outermost units only, and never stuck (duplicate-code survey, row 1):** the 1.9.0
  `UnitOfWorkBoundaryListener` was a plain command-depth counter. A worker run in-process by a message handler ended the
  outer message, a main request handled in-process inside another one (and its `kernel.terminate`) reset the outer
  request's private managers and host-flush probe, and a `console.error` listener that threw (Symfony then never
  dispatches `console.terminate`) left the depth stuck, so no later command reset anything in that process. The Symfony
  bundle now gives the listener an internal tracker, `Symfony\Boundary\UnitOfWorkFrames`, ported from the audited copy
  nine bundles carry (audits NX CR1–CR3, OB-M1, OB-L1–L3, OB2-L1): stacks of the running commands, main requests and
  Messenger workers, changed at the extreme priorities before any other listener can throw; aborted Messenger runs, a
  message skipped with `shouldHandle(false)`, nested workers and nested main requests are handled, and a frame left by a
  throwing `console.error` / `kernel.exception` / `WorkerMessageReceivedEvent` listener is dropped at the next unit.
  Unit boundaries: the outermost console command; a Messenger message with no other message in flight; a main request
  with no other main request and no message in flight, even while a console command runs, so a long-running server
  command still resets at every request as 1.9.0 did (audit XB-1).
  Internal and BC: the listener's methods and constructor keep working (new optional third argument; without it, the
  1.9.0 counter). Real-kernel tests in worker mode with the kernel reset off cover the Messenger, console and
  nested-request cases.

## [1.9.0] - 2026-11-20

### Added (1.9.0)

- **Outermost-commit epoch per connection:** `CommitLedger::mark(Connection)` returns a `TransactionMark` for the
  outermost transaction open on the connection, and `CommitLedger::outcome()` tells whether exactly that transaction
  committed (true), was rolled back, refused at commit or lost with its connection before COMMIT (false), or is still
  open or was lost during COMMIT (null; `CommitLedger::ended()` tells the two apart). Fed by
  `Dbal\CommitTrackingMiddleware` (driver-level BEGIN / COMMIT / ROLLBACK, a connection released with its transaction
  open); savepoints never count. It closes the "phantom" case where a bundle released its savepoint inside a host
  transaction the host then rolled back, while unrelated writes moved `CommitLedger::count()` (Uptime's audit-trail
  outbox, UserKit's savepoint case). Server behaviour followed (audit WD): a COMMIT after a failed statement on
  PostgreSQL is a rollback (false) unless a savepoint rollback ended the failure; a MySQL / MariaDB deadlock rolls the
  transaction back and an implicit-commit statement (DDL) commits it, at once (a mark taken after that, while DBAL still
  counts the transaction open, stands for an auto-committed write). Each mark holds its transaction's outcome,
  so it is never forgotten however many transactions follow; the ledger keeps only the open transaction per native
  connection, weakly.
- **Host-flush probe (REQ-ARCH-001 rule 6 deprecation layer):** `HostFlushProbe::beforeHostFlush($entityManager,
  $bundleName, $ownNamespaces)`, called by a bundle right before it flushes the host's manager in host mode, triggers
  one `E_USER_DEPRECATED` per bundle and foreign entity class per unit of work: "Your pending changes to App\X were
  flushed by <Bundle>; 2.0 no longer does." It sees scheduled inserts and deletes, orphan removals, changed fields,
  to-ones and collections, entities a changed association would cascade-persist, and collections `clear()` scheduled
  for deletion; each entity's own class decides (a host subclass of a bundle-owned root is reported); read-only and
  unpersisted `DEFERRED_EXPLICIT` entities are not (Doctrine writes neither). Read-only (no change-set computation;
  never flushes, clears, closes or resets the host's manager) and cheap on large identity maps (roots inside the
  bundle's namespaces are skipped whole). Registered as `nowo_transaction_doctrine.host_flush_probe` (autowirable),
  reset at every boundary.
- **Private entity manager kit (`PrivateManager\`):** `PrivateEntityManager`, `PrivateManagerRegistry`,
  `PrivateEntityManagerMiddleware` (with its driver and connection; DBAL 3 and 4), `PrivateManagerRepositoryFactory`,
  `TransactionLevelListenerInterface` and `DependencyInjection\PrivateEntityManagerPass`: the shared shape of the
  private managers TaskBoard, Wiki, ChatKit and Vault copy, for a zero-config private manager on the application's
  connection (ORM 2.20 and 3). The private manager goes stale after a rollback of a level it wrote or loaded in, a
  connection closed or lost with its transaction open, a failed rollback to a savepoint, or a PostgreSQL COMMIT after a
  failed statement (the server's ROLLBACK).
- **`CommitLedger::count()` on MySQL / MariaDB:** after a deadlock or an implicit-commit statement (DDL) inside a
  transaction, the writes that follow are counted as the auto-committed writes they are (before, they were taken for
  part of the transaction until its COMMIT or ROLLBACK). Counting more only makes a write unit give up a replay sooner.
- **`unit_of_work_boundaries`** (on by default): `Symfony\EventListener\UnitOfWorkBoundaryListener` resets the probe
  and every service tagged `nowo_transaction_doctrine.private_entity_manager` (autoconfigured for
  `PrivateEntityManager`) at the start and end of the main request, of a Messenger message and of the outermost console
  command, kernel reset off included.

## [1.8.2] - 2026-11-20

### Fixed

- **The host's entities stay managed after a reset or a clear during a write unit (audits VQ5-1 / VQ5-2; the known
  limitation of 1.8.1, also in 1.7.x and 1.8.0 for a failed flush).** When the attempt's own flush failed (the ORM
  closes the manager and the write unit's repairer resets it), when host code reset the manager (in place, as
  DoctrineBundle does, or by a new object), or when the attempt cleared the manager or detached every host entity,
  the host's objects captured before `run()` stayed detached: the replay reported success while writes made to them
  were silently ignored. After the repair, every initialised host entity whose identity no other object holds in the
  manager the registry now serves is registered again under its identifier from before the run and reloaded through
  its entity persister (fields, version, to-ones, fresh collections; no cascade). The same objects are managed again
  and the replay's writes to them are flushed. The write unit still never clears or resets an open host entity manager
  itself. Details (audit VQ6):
  - an uninitialised proxy the host holds (for example `$folder = $leaf->folder`) is registered again too: as it is
    when the manager kept its unit of work (a clear, a detach), so the reloaded owners reuse it; reloaded through the
    new manager's persister after a reset (Doctrine's hydration initialises it), so the same object is bound to the new
    manager (VQ6-1; before, ORM 2.20 replaced it and writes to the held one were lost);
  - the version of each initialised versioned host entity is captured when the run starts; a reload, on the restore
    path or the re-attach path, that finds another version (someone committed in between, which the host's own flush
    would refuse with `OptimisticLockException`) ends the unit with `ConcurrencyConflict` (409, `CONFLICT_001`) instead
    of overwriting it (VQ6-2);
  - a flush is recognised by its unit of work, so a decorated or lazy manager (`EntityManagerDecorator`,
    DoctrineBundle's lazy service) no longer turns off the reload after a flush of the attempt (VQ6-3, also in 1.8.0
    and 1.8.1);
  - the identifier captured before the run is put back after a reload (VQ6-4: ORM 2.20 swapped a backed-enum id and
    the replay's UPDATE failed with an `Error`);
  - an entity without an identifier, or whose identity another object of the manager holds, is unrestorable (409);
    an entity the attempt touched whose row is gone too (VQ6-7);
  - an untouched host entity whose row another session deleted is forgotten again and does not stop the replay, as on
    the restore path (VQ6-5).
  - only a row that is really gone is forgiven that way: any other failure of the reload (a `postLoad` listener that
    throws, a decryption failure, a connection lost during the SELECT) makes the entity unrestorable (409
    `CONFLICT_001`) instead of forgetting it while its row exists and losing the replay's writes to it (VQ7-1).

## [1.8.1] - 2026-11-20

### Fixed

- **A write-unit replay goes ahead again after the host's manager was reset in place or cleared by the attempt
  (regression in 1.8.0, seen in TaskBoard 2.26.0's replay tests).** When a flush inside the attempt failed (a
  deadlock), Doctrine closed the entity manager and host code reset it through the registry
  (`ManagerRegistry::resetManager()`, as TaskBoard's recovering flusher and `DoctrineTransactionRunner::forEntityManager()`
  with a registry do). DoctrineBundle resets its lazy entity manager service in place: `getManager()` keeps returning
  the same object, with a new entity manager and unit of work behind it. 1.8.0's write unit took that empty manager for
  the one it observed, found none of the host's entities in it and gave up with `ConcurrencyConflict` (409,
  `CONFLICT_001`) instead of replaying. A manager whose unit of work changed during the attempt is now treated as a
  new one (nothing of the old one is kept, as for a manager replaced by a new object), and a manager the attempt
  cleared entirely (none of the host's entities managed any more) no longer counts as unrestorable: the replay goes
  ahead, as in 1.7.x. A host entity detached on its own while others stay managed still ends the unit with
  `ConcurrencyConflict`.

  **Known limitation (audits VQ5-1 / VQ5-2, to be closed in 1.8.2):** in those cases the host's entities captured
  before `run()` are detached, and writes made to those old objects are silently ignored: they are not managed by the
  new (or cleared) manager, so no flush writes them. Compared with 1.8.0 this changes two cases from a 409 to a
  silent loss: an attempt that clears the manager, and an attempt that detaches the host's only entity (or all of
  them), before it fails; 1.8.0 answered 409 `CONFLICT_001` there, 1.8.1 replays and the replay reports success
  while nothing is written through the old objects. The same already happened in 1.7.x and in 1.8.0 whenever the
  attempt's own flush failed: the ORM closes the manager and this library's repairer resets it (VQ5-1).

## [1.8.0] - 2026-11-20

### Fixed

- **`forHostEntityManager()` forgets an entity the failed run removed (audit VQ4-1; affects 1.6.0 to 1.7.2).** When
  a run on the host's entity manager loaded an entity, `remove()`d it and then failed before the runner's flush (any
  failure: a deadlock, a lock wait, a domain exception), `releaseHostManager()` left the entity scheduled for removal
  (`UnitOfWork::doDetach()` does nothing for a REMOVED entity), so the host's next `flush()` deleted its row. The
  removal is now forgotten entirely (the identity-map entry, the scheduled removal, state, identifier and original
  data) and the entity unlinked from the caller's entities, like everything else the run brought in. A removal of an
  entity the host managed before the run still stays, as any other change the run made to the caller's entities.

### Changed

- **A write-unit replay no longer clears the host's entity manager (audits VD2-4 / VQ, REQ-RUNTIME-003 rule 2).**
  Between two attempts `WriteUnit` cleared every open entity manager (unless scoped with `forManagers()`), so every
  entity the host had loaded before `run()` was detached, even when the unit wrote only through DBAL. An open manager
  that managed entities before the first attempt is now put back to what the database holds instead (internal
  `KeptEntitiesRestorer`, fed by a per-run `AttemptObserver` on `postLoad` and `onFlush`):
  - host entities a flush of the attempt deleted are managed again;
  - the attempt's unflushed work is dropped: removals of host entities (and of entities the attempt loaded itself,
    which are detached), orphan removals (`removeElement()` / `clear()` on an `orphanRemoval` collection), computed
    updates and collection work;
  - what the attempt brought in is unlinked from the host's entities and detached without cascading into them;
  - host entities are reloaded through their entity persister (no cascade): every initialised one when a flush wrote
    during the attempt (the flush moved the original data to the rolled-back values and bumped any version), else the
    ones the attempt touched: changed (also behind a `computeChangeSets()` inside the attempt), re-hydrated, removed
    or written by one of its flushes.

  When a host entity cannot be brought back (detached by the attempt, or touched by it and then its row gone or its
  reload failing; an untouched host entity whose row another session deleted does not count), the unit
  gives up with `ConcurrencyConflict` (`Retry-After`, `CONFLICT_001`) after every other step, so the host's next
  flush writes nothing of the attempt; the manager stays open and is not cleared, with a warning. A manager that
  managed nothing before `run()` is cleared as before; a closed manager is still reset.
- `DoctrineStateRepairer::discardUnitOfWork()` takes two optional arguments (the write unit's per-manager observers,
  an internal type, and whether to reload every kept entity) and returns the list of managers it could not fully
  restore (it returned nothing before). Called without them it behaves as before.

## [1.7.2] - 2026-11-20

### Fixed

- **No TypeError with DBAL 3 test doubles.** DBAL 3 declares `Connection::getTransactionNestingLevel()` and
  `isRollbackOnly()` without native return types, so a PHPUnit double of DBAL 3's `Connection` returns `null`;
  `DoctrineTransactionRunner::run()` passed it to `ConnectionRepairer::afterFailure(Connection, int)`, which threw a
  `TypeError` instead of the run's own failure (seen in ChatKit's DBAL 3 unit tests). Both values are now read as
  `(int)` / `(bool)` in the runner and the repairer (Rector's `RecastingRemovalRector` is skipped for those two files).
- **The rollback-only flag no longer outlives a closed connection (DBAL 3 and DBAL 4).** DBAL keeps the flag set by
  `Connection::setRollbackOnly()` (or, on DBAL 3 without savepoints, by an inner `rollBack()`) across
  `Connection::close()`; only a level-1 `ROLLBACK` resets it. When `ConnectionRepairer` closed a connection whose
  nested rollback failed (the server dropped the savepoints after a deadlock or a lost connection), it cleared the
  flag only with DBAL 3's emulated nesting, so on DBAL 4 (or DBAL 3 with savepoints) the next transaction on the
  reopened connection failed at commit with `CommitFailedRollbackOnly`. The repairer now reads the flag before
  closing (`afterFailure()` and `betweenRequests()`; an unreadable flag counts as set) and then begins and rolls back
  every level that `BEGIN` left open: one, or two with auto-commit disabled, where the reconnect opens level 1 itself
  (audit VQ-6). A `BEGIN` that fails there is rolled back or the connection closed again, so no level stays counted.
  As a second line, `DoctrineTransactionRunner::run()` clears a stale flag right after its own outermost `BEGIN` (one
  `ROLLBACK` and a new `BEGIN`, with a notice); with auto-commit disabled the run enters at level 1, where the flag
  may be the caller's, so only the repairer clears it there. No entity manager is touched by either.

## [1.7.1] - 2026-11-19

### Fixed

- **Write units read Messenger wrappers through the bounded walk (audit UI2-4).** `WriteUnit::conflictKind()` (used
  by `run()` and the Messenger middleware) called `getWrappedExceptions()` unguarded and recursively: a custom
  `WrappedExceptionsInterface` that threw replaced the failure, one that returned a non-`Throwable` raised a
  `TypeError`, and one that wrapped itself recursed without end. It now reads them through `ThrowableWalk`, as
  `TransientFailureClassifier::findConflict()` and `findLockConflict()` do: each throwable once, at most 256. What
  cannot be read whole (a throwing or broken wrapper, the cap reached) makes the failure not replayable; it is
  rethrown unchanged.
- **Worker hygiene reads Messenger wrappers through the bounded walk (audit UI2-5).**
  `WorkerConnectionHygiene::isDatabaseFailure()` (same signature) called `getWrappedExceptions(null, true)` unguarded:
  a throwing custom wrapper escaped the `kernel.exception` / `WorkerMessageFailedEvent` listener and a self-wrapping
  one recursed without end. It now uses `ThrowableWalk` (each throwable once, at most 256). A truncated walk (the cap
  reached, or a wrapper that threw) counts as a database failure, the conservative side: the repair only resets
  closed managers, rolls back leftover levels and pings the connected connections, while a missed repair would let a
  broken connection reach the next request or message.

### Changed

- **Docs: `DoctrineStateRepairer::discardUnitOfWork()` rolls back every connection (audit UI2-2).** UPGRADING,
  CONCURRENCY and the method's docblock now say to call it only outside a caller's transaction. Behaviour unchanged.
- The two stacked docblocks above the private `WriteUnit::hasPendingChanges()` are merged (audit UI2-3).

## [1.7.0] - 2026-11-19

### Added

- **Conflict detection inside Messenger wrappers (audits TA42-3 / UA2-5):** `TransientFailureClassifier::findConflict()`,
  `findLockConflict()` and `findTransientFailure()` (public, static) return the first conflict a failure carries,
  along `getPrevious()` and, with `symfony/messenger`, inside `getWrappedExceptions()` (a deadlock in the second
  handler of a synchronous `HandlerFailedException`). They use the same bounded walk as `ConflictCode` (each throwable
  once, at most 256). Bundles detecting conflicts in their own exception subscriber can call them instead of walking
  `getPrevious()`.
- **Write units scoped to named entity managers (audit UD2-L-2):** `WriteUnit::forManagers('my_bundle')`, or the new
  last constructor argument `managers: ['my_bundle']`, makes a replay reset or clear only those managers; the host's
  default manager keeps its entities. Leftover transaction levels are still rolled back on every connection. When a
  manager outside the scope is found closed after an attempt (the unit wrote through a manager it did not name), every
  manager is repaired with a warning; when a replay is abandoned with pending changes outside the scope (a resetter
  or retry listener persisted through the host's manager), every manager is cleared (audits UI-1 / UI-3).
  `DoctrineStateRepairer::discardUnitOfWork()` takes the same optional list. An empty list or an unknown manager name throws `InvalidArgumentException`. Without
  it nothing changes: a replay still clears every open manager.

### Changed

- **The 409 responder detects a conflict in a later wrapped exception (audits TA42-3 / UA2-5):**
  `ConcurrencyConflictResponder::isConflict()` used to walk only `getPrevious()`, so a `ConcurrencyConflict` in the
  second wrapped exception of a synchronous `HandlerFailedException` answered 500. It now uses
  `TransientFailureClassifier::findLockConflict()`, and `TransientFailureConflictMapper`
  (`map_transient_failures: true`) uses `findTransientFailure()`. The attempts and `Retry-After` hint come from the
  first `ConcurrencyConflict` in walk order (the `getPrevious()` chain first, then the wrapped exceptions in order),
  which is not necessarily the lock conflict `findLockConflict()` returned (that one may be another
  `LockConflictExceptionInterface`). A wrapper mixing a non-conflict and a conflict (a business exception in the first
  handler, a lock conflict in the second) answers 409 with the conflict's code (`CONFLICT_001` unless something proves
  a commit). Unique violations and lost connections are still never mapped. A custom wrapper whose
  `getWrappedExceptions()` throws no longer escapes the walk: it is skipped and counts as a truncated walk (audit UI-5).
- **A truncated walk answers `CONFLICT_002` (audit TA42-1):** when a failure carries more than 256 throwables,
  `ConflictCode::forFailure()` used to give up and answer `CONFLICT_001`, even though a committed conflict could sit
  beyond the cap. It now answers `CONFLICT_002` (no `Retry-After`), the safe over-approximation.
- **`ConflictCode::forFailure()` applies the lost-savepoint rule to wrapped exceptions too:** a lost savepoint with no
  deadlock behind it in a later wrapped exception answers `CONFLICT_002`, as it does in the `getPrevious()` chain,
  also when the first handler failed with an uncommitted `ConcurrencyConflict` (a conflict decides only its own chain,
  audit UI-2).

### Fixed

- **Duplicates no longer use up the walk's cap (audit TA42-2):** a throwable already visited or queued (a
  `HandlerFailedException`'s previous is also its first wrapped exception) is not queued again.
- **DBAL 3: `Connection::commit()` returns `true` again with commit tracking on (audit UE-CF-1):**
  `CommitTrackingConnection::commit()` is declared `void` to stay valid against DBAL 4, so on DBAL 3 the wrapper's
  `commit()` returned `null` instead of the driver's `true`. Host code testing `=== true` or `!$connection->commit()`
  misread a successful commit; the ORM checks `=== false` and was not affected. On DBAL 3 the driver middleware now
  adds a small `Dbal3\CommitResultConnection` (autoloaded from `compat/dbal3/`, loaded only on DBAL 3) that hands the
  driver's result back. DBAL 4 is unchanged (`void`).

## [1.6.1] - 2026-11-19

### Fixed

- **`forHostEntityManager()` keeps the cause of a refusal (audit TA-3 final, Info 1):** when the pending-changes check
  calls `UnitOfWork::computeChangeSets()` and it throws (a `prePersist` listener or callback of an entity reached by
  cascade persist, for example), the `LogicException` (`DoctrineTransactionRunner::HOST_PENDING_MESSAGE`) now carries
  that exception as `getPrevious()`. The message is unchanged; a refusal for scheduled work has no previous, as
  before.
- **`ConflictCode::forFailure()` answers `CONFLICT_002` for more committed work (audits TA-3 / TJ, same rules as
  `nowo-tech/doctrine-deadlock-retry-bundle` 3.2.0):** it used to walk only `getPrevious()` and let the first
  `ConcurrencyConflict` decide. It now also answers `CONFLICT_002` (no `Retry-After`) when:
  - a `ConcurrencyConflict` with `committed: true` is anywhere in the failure: behind an uncommitted one, or (with
    `symfony/messenger`) inside a wrapper's `getWrappedExceptions()`, at any depth;
  - (with `symfony/messenger`) a `HandlerFailedException` whose envelope carries a `HandledStamp` (another handler of
    the message already succeeded), or any `DelayedMessageHandlingException` (the outer dispatch, and its transaction,
    already committed).
  Known over-approximation (conservative, same as DDRB 3.2.0, audit TA4-2): a `HandledStamp`, or a
  `DelayedMessageHandlingException` under an outer transaction that rolled back, answers `CONFLICT_002` even when
  nothing committed (see the accepted residual risks in [Concurrency](CONCURRENCY.md#accepted-residual-risk)).
  The walk visits each throwable once and at most 256 of them, never queues more than it may still visit, and
  ignores anything a custom wrapper returns that is not a `Throwable` (a cyclic or broken custom wrapper cannot hang
  a worker or turn the 409 into a 500). Without
  `symfony/messenger` the Messenger rules are skipped (`interface_exists()` guard). `ConflictCode::forRequest()` and
  the 409 responder inherit the change.
- **`forEntityManager()` is documented as bundle-owned only (audit TG delta follow-up):** the factory docblock, the
  README logging example, the Usage example and the Configuration table used or described it with the host's
  `default` manager, or said only a closed manager is reset. After any storage failure it closes and resets the
  manager through the registry even when it is still open; for the host's manager use `forHostEntityManager()`. No
  behaviour change.

## [1.6.0] - 2026-11-18

### Added

- **Request commit watermark (audit TH X-1, REQ-RUNTIME-003 rule 6 request-level detection):** a conflict after a
  commit earlier in the same request (the host, another bundle, an earlier unit) answers `CONFLICT_002` without
  `Retry-After` instead of `CONFLICT_001` + `Retry-After`, which invited a blind retry of committed work.
  - New `Nowo\TransactionDoctrine\WriteUnit\CommitMark` (a watermark on the `CommitLedger`, `committedSince()`).
  - New `Nowo\TransactionDoctrine\Symfony\EventListener\RequestCommitWatermark`: with `commit_detection` on, takes the
    mark at `kernel.request` (priority 4096) on the main request, in the request attribute
    `CommitMark::REQUEST_ATTRIBUTE`; sub-requests carry the main request's mark.
  - `ConflictCode::forRequest(Request $request, Throwable $failure)` for bundles answering in their own shape, and an
    optional `?CommitMark $since` argument on `ConflictCode::forFailure()`.
  - The responder (answer and log) uses `ConflictCode::forRequest()`.
  - The `CommitLedger` service is aliased as `Nowo\TransactionDoctrine\WriteUnit\CommitLedger` (with
    `commit_detection`), so a Messenger handler or command can take its own mark.
- **`X-Conflict-Code` header (audit TH X-2, platform convention):** every responder answer carries
  `X-Conflict-Code: CONFLICT_001|CONFLICT_002` (only those two values), whatever built the body.
  `ConflictResponsePolicy::headersFor(ConflictCode $code, ?int $hint)` (the header, plus `Retry-After` only for
  `CONFLICT_001`), `ConflictResponsePolicy::httpExceptionFor()` and the `ConflictResponsePolicy::CODE_HEADER` constant
  let bundles send it too. Documented: cross-origin APIs add it to `Access-Control-Expose-Headers`; clients key on
  `code` or the header, never on `error`.
- **`DoctrineTransactionRunner::forHostEntityManager()` (audit TG delta, REQ-RUNTIME-003 rule 2):** a runner for the
  host application's entity manager. After a failure it never closes, clears or resets an open manager; it replaces a
  closed one through the registry and detaches only what the failed run brought in (entities that entered the
  identity map or were scheduled for insertion during the run). `forEntityManager()` is unchanged and now documented
  as the factory for bundle-owned managers.
  - The detach never cascades (`cascade: detach`) into an entity the host managed before the run (audit TA3-1).
  - The brought entities are removed from the host entities' initialized collections, and a host to-one pointing at
    one gets its original value back, so the host's next flush cannot re-insert them (audit TA3-2).
  - A notice is logged when host entities still have pending changes after the failure (audit TA3-3).
  - `run()` refuses a host manager that already has pending changes (scheduled insertions, updates or deletions,
    after `UnitOfWork::computeChangeSets()`) with a `LogicException`
    (`DoctrineTransactionRunner::HOST_PENDING_MESSAGE`) before opening a transaction: a failure after a flush inside
    the run would otherwise lose the host's work silently (audit TA3-6). A to-one restored on a host entity with no
    original value is set to `null` (TA3-7).

### Changed

- Commit detection: `WITH`, `VALUES` and `TABLE` statements outside a transaction are reads and no longer count as a
  commit; a `WITH` naming `INSERT`, `UPDATE`, `DELETE` or `MERGE` (a data-modifying CTE) still counts (audit TA3-4).
  Fewer `CONFLICT_002` answers and refused replays after a plain read.

### Fixed

- **DBAL 3: a driver error whose code is a SQLSTATE string no longer crashes the classifier (audit TA3-5; affected
  1.1.0 to 1.5.x on DBAL 3).** DBAL 3's `Doctrine\DBAL\Driver\PDO\PDOException` is both a `PDOException` and a driver
  exception, and its `getCode()` is the SQLSTATE (`'40001'`, `'23505'`). `TransientFailureClassifier` passed it to an
  `int` parameter under `strict_types`: a `TypeError` replaced the original failure, so the runner's repair and
  cleanup did not finish and a write unit did not replay. A PostgreSQL serialization failure at COMMIT, for example,
  answered a 500 instead of being replayed. The classifier now reads the vendor code from `errorInfo[1]` and the
  SQLSTATE from `errorInfo[0]` (or `getSQLState()`), and treats any non-int code as no vendor code. `WriteUnit`
  classifies through it, so it is fixed too. DBAL 4 was not affected. No GHSA (owner decision 2026-11-18).

## [1.5.0] - 2026-11-18

### Added

- **Conflict codes (REQ-RUNTIME-003 rule 6):** every conflict answer carries `CONFLICT_001` (nothing committed, with
  `Retry-After`) or `CONFLICT_002` (part of the unit committed, no `Retry-After`).
  - New `Nowo\TransactionDoctrine\ConflictCode` backed enum (`RetrySafe`, `Committed`; constants `RETRY_SAFE`,
    `COMMITTED`) with `ConflictCode::forFailure()` for bundles that answer in their own shape.
  - JSON answers gain a `code` key next to the unchanged `error` and `message` keys.
  - The HTML page (plain, Twig and UiKit variants) carries `data-conflict-code` on its `data-testid="concurrency-conflict"`
    element; the template receives `conflict_code`.
  - `ConflictResponseContext::$code`, so a `ConflictResponseFormatterInterface` receives the code.
  - The responder's log context gains `code`.
- `TransientFailureClassifier::isCommitOutcomeUnknown()`: a lost savepoint (MySQL / MariaDB 1305 about a SAVEPOINT,
  PostgreSQL 3B001) with no transient failure in its chain, answered `CONFLICT_002` by the opt-in
  `map_transient_failures` mapper. A 1305 about a missing function, unique (constraint) violations and lost
  connections are never mapped.
- `docs/CONCURRENCY.md` (write paths, classification, codes, host options, residual risk) and a USAGE section on how
  bundles comply with REQ-RUNTIME-003 using this package.

### Changed

- **Serialization failures are replayed by default** (owner decision 2026-11-18): the default `replay_on` and
  `WriteUnitPolicy` kinds now include `serialization_failure` (PostgreSQL 40001, MariaDB 1020), new constant
  `WriteUnitPolicy::DEFAULT_REPLAY_KINDS`. A write unit replays them and, once the attempts are exhausted, throws
  `ConcurrencyConflict`, answered 409 `CONFLICT_001` + `Retry-After` instead of a 500. An explicit `replay_on` list
  keeps the 1.4 behaviour (see UPGRADING).

### Fixed

- **Duplicate autocommit writes after a failed COMMIT (audit TA2-1, affects 1.3.0–1.4.0; no GHSA, owner decision
  2026-11-18):** when the server refused a COMMIT (a deferred constraint, or a PostgreSQL
  serialization failure at commit time), `CommitTrackingConnection` kept believing a transaction was open, so the
  autocommit writes that followed on that connection were no longer counted by `CommitLedger`. A write unit that met a
  conflict after such a write was then replayed instead of answering `committed: true`, and repeated the write. The
  flag is now reset in a `finally`; a refused COMMIT is still not counted as a commit. Tested on PostgreSQL 16.
- **Audit TA2-2:** a lost savepoint (1305) is recognised on servers with localized messages (`lc_messages` such as
  `es_ES` or `de_DE`): the classifier matches the untranslated `SAVEPOINT` keyword where the server puts it, no longer
  the English "does not exist". A 1305 about a missing `FUNCTION` stays unmapped in every language.
- **Audit TA-6:** a reset-between-attempts service or retry listener that opened a transaction (or persisted an entity)
  and then threw left the connection one nesting level up after the write unit gave up; the unit now discards it
  before throwing `ConcurrencyConflict`. Tested on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16.

## [1.4.0] - 2026-11-18

### Added

- **Reset hook between write-unit attempts** (SYMFONY-BEACON spec 241 T020): request memos that keep entities are
  emptied before a replay, so it no longer runs on detached entities (`ORMInvalidArgumentException` 500 instead of the
  409, or silently lost changes).
  - `ResetBetweenAttemptsInterface::resetBetweenAttempts()`: autoconfigured (tag
    `nowo_transaction_doctrine.reset_between_attempts`), called in tag priority order after the entity managers were
    cleared or reset and before the next attempt, for `#[AsWriteUnit]`, `controller_routes`, the Messenger middleware
    and the programmatic service. Never before the first attempt nor after the last failure.
  - `WriteUnitRetryEvent` (PSR-14, on `event_dispatcher`), dispatched after the resetters: attempt about to run,
    failure, kind and unit name (route name, message class, or the new optional `$unit` argument of `WriteUnit::run()`).
  - A resetter or retry listener that throws abandons the replay: `ConcurrencyConflict` with the attempt's failure as
    previous and the new `ConcurrencyConflict::$resetFailure` (logged at `error`). So does one that leaves a
    transaction open or entity changes pending (discarded first; `$resetFailure` is a `LogicException`), which would
    otherwise make the replay run nested and return without committing.
  - A `Generator` passed as `$resetters` is read once into a list, so units with several replays work.
  - `WriteUnit` takes two new optional constructor arguments: `iterable $resetters` and a PSR-14 `$dispatcher`.

## [1.3.1] - 2026-11-17

### Fixed

- Missing it/nl/pt translations: the `nowo_transaction_doctrine` catalogue now covers the full house set (en, es, it, fr, pt,
  de, nl), so the conflict message and page no longer fall back to English; a unit test requires every key in every locale.

## [1.3.0] - 2026-11-17

### Added

- **Write units** (Beacon KIT-79, spec 239): `WriteUnit` (`WriteUnitInterface`) replays a unit of work after a lock
  conflict, a deadlock or a lock wait timeout by default (`TransientFailureClassifier` kinds; `serialization_failure` /
  `retryable` opt-in), found in the previous chain or inside a Messenger `HandlerFailedException`. Up to 3 attempts
  with a full-jitter pause (20 ms floor, 100 ms base, 200 ms cap; `WriteUnitPolicy`). Only the outermost unit replays:
  inside an open transaction the work runs once. Between attempts `DoctrineStateRepairer` rolls back leftover levels
  (through `ConnectionRepairer`), resets closed entity managers and clears open ones. The last conflict becomes
  `ConcurrencyConflict`, a contract `TransientStorageFailure` with `attempts`, `kind` and a Retry-After hint.
- `#[AsWriteUnit]` controller attribute and `LockConflictExceptionInterface` marker.
- Optional Symfony bundle `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle` (config root
  `nowo_transaction_doctrine`; register it in `config/bundles.php`):
  - `WriteUnitControllerListener`: `#[AsWriteUnit]` controllers and `controller_routes` (exact names, explicit `*`
    wildcard) run as write units; on a replay only Doctrine entity arguments are reloaded by identifier (request
    payload DTOs and other arguments keep their first-attempt value) and an entity user is reloaded (403 when gone);
  - `WriteUnitMiddleware` (service `nowo_transaction_doctrine.write_unit_middleware`): consumed messages run as write
    units;
  - `ConcurrencyConflictResponder`: a lock conflict answers **409 + `Retry-After`** (`conflict_response.status`: 409 or
    503; `retry_after`), JSON `{"error": "concurrency_conflict", "message"}` or a branded HTML page with *Try again*
    (`@NowoTransactionDoctrine/conflict.html.twig`, overridable, extends UiKit's error page when installed;
    translations en / es / fr / de);
  - exception-mapping hook `ConflictExceptionMapperInterface` and response hook `ConflictResponseFormatterInterface`
    (autoconfigured tags; no route-name matching; TaskBoard API small ask C2), plus the opt-in
    `map_transient_failures` mapper;
  - `ConflictResponsePolicy` (service `nowo_transaction_doctrine.conflict_response_policy`): the one status and
    `Retry-After` every bundle reads;
  - `WorkerConnectionHygiene` (`worker_hygiene`): closed managers reset per main request; after a DBAL / ORM failure of
    a request (at kernel.terminate) or a consumed message, leftover levels rolled back and connected connections pinged.
- Commit detection (audit SL-M1): `CommitLedger` and the DBAL driver middleware `Dbal\CommitTrackingMiddleware`
  (DBAL 3 and 4) count successful COMMITs and write statements outside a transaction; a failed attempt that already
  committed is never replayed and becomes `ConcurrencyConflict` with `committed: true`. Bundle option
  `commit_detection` (default true, tag `doctrine.middleware`). Rule documented: a unit commits at most once, or is
  idempotent as a whole.
- A conflict after a commit (`committed: true`, audit SL-D-M1) is answered 409 **without `Retry-After`**, with the JSON
  code `concurrency_conflict_partial` and a "part of your change may have been saved; reload before trying again" text
  and *Reload* link (en / es / fr / de, keys `conflict.partial.*`); `ConflictResponseContext` gains `committed` and a
  nullable `retryAfterSeconds`. `WriteUnitMiddleware` wraps it in an `UnrecoverableMessageHandlingException`, so the
  transport does not retry the message.
- Commit detection also counts multi-statement SQL strings, `EXPLAIN ANALYZE` (also inside an `EXPLAIN (…)` option
  list), and MySQL / MariaDB implicit-commit statements inside a transaction, every `SET [SESSION | @@[SESSION.]]
  autocommit` form included; leading `/* … */`, `--` and `#` comments are skipped before matching (SL-D-L1, SL-2-L2).
  A conflict after a commit carries no Retry-After hint (SL-2-L1). The limits are documented in USAGE.
- Write units run once (no replay, no clear) when an open entity manager has pending changes before the first attempt
  (SL-M2); a Messenger wrapper is replayed only when every wrapped exception is a replayable conflict and no handler
  succeeded (SL-M4); a wrapped inner `ConcurrencyConflict` is never replayed again (SL-L1).
- Real-database tests for write units (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): a real deadlock and lock wait timeouts
  replayed, the last one as `ConcurrencyConflict`, the nested-transaction rule and the repairer.

### Changed

- Owner platform decision: one lock-conflict status, **409 + `Retry-After`**. The bundle's default
  `conflict_response.status` is 409; `503` keeps the 1.2 behaviour of ChatKit, Wiki and TimeTrack (see UPGRADING).
- `composer.json`: Symfony, Twig and Messenger packages as dev requirements and suggestions; `branch-alias`
  `dev-main` → `1.3.x-dev`. Runtime requirements are unchanged.
- `composer.json` `conflict`: `symfony/http-kernel <6.4`, `symfony/messenger <6.4`.
- The responder uses a `ConcurrencyConflict`'s own Retry-After hint, and limits the *Try again* referer to http(s).
- Logging stays constant-message with structured context only (class, code, counters, kind, route name, method).

## [1.2.0] - 2026-11-17

### Added

- REQ-OBS-001: optional PSR-3 logging. `DoctrineTransactionRunner::forConnection()` / `forEntityManager()` and `ConnectionRepairer::__construct()` take an optional trailing `LoggerInterface` (default `NullLogger`; BC-safe). Logged with structured context only (exception class and code, levels, action, manager name; never SQL, parameters or exception messages): the failure and its transient classification (notice when transient, debug otherwise), the repair (notice for rolled-back levels, warning when the connection is closed), and the entity manager reset after a storage failure (notice), clear after a storage failure when no registry is given (notice) or clear after a domain failure (debug). A logger that throws never replaces the original failure.
- Do not give the runner a logger that writes to the **same database connection**: the failure is logged while that connection's transaction is failing or being rolled back, so the log row is lost (or the write fails). Use a file, stream or another connection.
- `exception_code` is the caught exception's `getCode()`. For storage failures it is the driver / SQLSTATE code; for a domain exception it is whatever the host application set (often `0`), so do not treat it as a database error code.
- `psr/log ^1.0 || ^2.0 || ^3.0` is now a direct requirement (already pulled in by `doctrine/dbal`).

### Changed

- Repository tooling and documentation aligned with the nowo-tech package requirements (REQ compliance sweep
  2026-11-16); the only `src/` change is the optional logger above, and without a logger the behaviour is unchanged.
- Development: Docker image + `docker-compose.yml` (MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 behind the `db`
  profile), root `Makefile` (`release-check`, `test-with-db`, `setup-hooks`, `igor`, …), git hooks; PHPStan now
  includes the `nowo-tech/phpstan-frankenphp` classic and worker rulesets; Igor worker-state audit (`composer igor`,
  `make igor`, `make release-check`) with `igor-baseline.json` for the per-run event listener (audit SE-TD-L1);
  CI adds Rector, Igor, `composer audit` and a git-history check; tests moved to `tests/Unit/` and `tests/Integration/Database/`.
- `composer.json`: `archive.exclude`, `support.docs`; `branch-alias` `dev-main` → `1.1.x-dev`.

### Documentation

- `docs/INSTALLATION.md`, `CONFIGURATION.md`, `USAGE.md` (the behaviour reference moved there from the README),
  `UPGRADING.md`, `RELEASE.md`, `ENGRAM.md`, `PSR.md`, `SPEC-DRIVEN-DEVELOPMENT.md`, `SPEC-KIT.md`; GitHub Spec Kit
  baseline (`specs/001-baseline/`, 5/5 files mapped); release security checklist (12.4.1) and logging policy in
  `SECURITY.md`; canonical README.
- `.github/`: issue / pull request templates, `CODEOWNERS`, `SECURITY.md`, Copilot instructions, Dependabot,
  release, release-sync, PR-title lint and stale workflows. `.cursor/` rules and Engram MCP config.

## [1.1.0] - 2026-11-15

### Added

- **DBAL 3 support**: `doctrine/dbal ^3.8.2 || ^4.0`. The code uses no DBAL 4-only API; CI runs the unit and the
  real-database suites on DBAL 4 + ORM 3, DBAL 3 + ORM 3 and DBAL 3 + ORM 2. New real-database tests pin the behaviour
  the repair and the classifier rely on, on both majors: after a deadlock in a nested transaction with savepoints the
  failed `ROLLBACK TO SAVEPOINT` raises 1305 and leaves the nesting level up until the repair closes the connection;
  a lone 1305 is not transient; the ORM's failed rollback carries the `DeadlockException` in its chain.

### Changed

- **A domain failure no longer resets a shared entity manager** (ChatKit audit QD-M1: resetting the host manager on
  a domain refusal detached the host's entities silently). The contract ("nothing of the failed operation is left
  pending") is unchanged; what the runner does to the manager now depends on the failure:
  - storage failure (a DBAL / driver error in the chain) or a manager the ORM closed: closed and reset through the
    registry, or cleared without one (as in 1.0.0);
  - domain failure after the operation changed or persisted something, or flushed inside the runner's transaction:
    cleared, same instance, never reset;
  - domain failure that re-hydrated an entity already managed (`refresh()`, a proxy initialised inside the run):
    cleared;
  - otherwise (a refusal): every entity that entered the identity map during the run is detached, since it may hold
    rows or values the rollback undid (QA-TD11-M1: a row inserted or a value updated through DBAL, then found, stayed
    in the identity map), including `getReference()` proxies and deferred query-cache hydrations that fire no
    `postLoad` (QA-TD11-L4); the host's other entities stay managed. When a detach
    cascades (`cascade: ['detach']`) into a caller's entity, the manager is cleared instead of leaving that entity
    silently detached (QA-TD11-L3).
  The run is watched by a per-run `postFlush` / `postLoad` listener (removed in `finally`) and an identity-map
  snapshot taken when the run starts.
- The pending-change check looks at the scheduled insertions, deletions and collection changes first and calls
  `UnitOfWork::computeChangeSets()` only when there are none; its cost (a scan of the identity map) and side effects
  (cascade-persist runs `prePersist` and may assign generated ids) are documented (QA-TD11-L2).
- A nested domain failure clears the caller's pending changes too: documented in the README, with the rule not to
  continue the same unit of work after catching it (QA-TD11-M2).
- **DBAL 3 emulated nesting** (savepoints off): `ConnectionRepairer` rolls nested leftovers back level by level instead
  of closing, and after a failed real `ROLLBACK` it closes and then begins and rolls back once, because
  `Connection::close()` keeps the rollback-only flag and the next commit would fail (QA-TD11-L1). New optional
  constructor argument `ConnectionRepairer(?bool $emulatedNesting = null)` (null = detect: DBAL 3 without savepoints).

## [1.0.0] - 2026-11-15

### Added

- `DoctrineTransactionRunner` (`forConnection()`, `forEntityManager()`): a one-attempt
  `Nowo\TransactionContract\TransactionRunnerInterface` for plain Doctrine. After any failure it rolls back to the
  entry level and closes the connection only when that rollback fails (session variables and session-level locks
  survive ordinary failures), and leaves no pending changes in the entity manager (closed and reset through the
  registry, or cleared). Transient failures become `TransientStorageFailure`.
- `TransientFailureClassifier` / `TransientFailureKind`: 1213, 1205, MariaDB 1020, SQLSTATE 40001 / 40P01 / 55P03, DBAL
  `RetryableException`; a 1305 lost savepoint only through the deadlock it carries.
- `ConnectionRepairer`.
- Real-database tests (MySQL 8.4, MariaDB 11.4, PostgreSQL 16) and their CI job.

Requires `nowo-tech/transaction-contract ^1.0` and `doctrine/dbal ^4.0`; `doctrine/orm ^3.0` and `doctrine/persistence` are optional.

[Unreleased]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.10.0...HEAD
[1.9.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.8.2...v1.9.0
[1.8.2]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.8.1...v1.8.2
[1.8.1]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.7.2...v1.8.0
[1.7.2]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.7.1...v1.7.2
[1.7.1]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.7.0...v1.7.1
[1.7.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.6.1...v1.7.0
[1.6.1]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/transaction-doctrine/releases/tag/v1.0.0
