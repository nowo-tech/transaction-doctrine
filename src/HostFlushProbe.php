<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use BackedEnum;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_key_exists;
use function array_keys;
use function get_debug_type;
use function is_array;
use function is_iterable;
use function is_object;
use function ksort;
use function ltrim;
use function method_exists;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strrpos;
use function substr;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Host-flush probe (1.9, REQ-ARCH-001 rule 6 deprecation layer, plan prerequisite P2): a bundle that still flushes the
 * HOST's entity manager in host mode calls beforeHostFlush() right before that flush. Every entity class outside the
 * bundle's own namespaces with pending work in the host's unit of work (a scheduled insert or delete, or a managed
 * entity whose fields, to-one associations or collections differ from what was loaded) is work the bundle's flush
 * writes on the host's behalf: in 2.0 the bundles write through a private manager and no longer do. One
 * `E_USER_DEPRECATED` per bundle and foreign class per unit of work (request, message, command) says so:
 * "your pending changes to App\X were flushed by <Bundle>; 2.0 no longer does". Collected by the host's deprecation log,
 * it becomes the exact migration inventory.
 *
 * Read-only and cheap: it never computes change sets, never clears, closes or resets the host's manager
 * (REQ-RUNTIME-003), reads the scheduled inserts / deletes Doctrine already holds, and compares loaded state only for the
 * entity classes of the identity map that are outside the bundle's namespaces (classes inside them are skipped
 * whole, without looking at a single entity).
 *
 * State: which (bundle, class) pairs already triggered in the current unit of work. The Symfony bundle registers one
 * shared instance (SERVICE_ID, autowirable) and resets it at every boundary (UnitOfWorkBoundaryListener: main request,
 * Messenger message, outermost console command; FrankenPHP worker with kernel reset off included) and at
 * `kernel.reset`. Without the Symfony bundle, create one per unit of work, or call reset() at your boundaries.
 *
 * Never breaks the caller's write (1.9.1, audit XA-3): a Throwable from the inspection (a broken proxy, an entity whose
 * state cannot be read) or from the deprecation itself (a host error handler that ignores `@` and throws on
 * E_USER_DEPRECATED) is caught; it is logged as a warning when a PSR logger is wired (the Symfony bundle wires the
 * `logger` service when there is one), otherwise swallowed. The warning holds the bundle, the entity class (for a
 * failed deprecation) and the failure's type (`exception_type`), never its message or trace (audit XB-2). A failed
 * inspection returns no classes.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class HostFlushProbe implements ResetInterface
{
    /** The service id the Symfony bundle registers (reset at every unit-of-work boundary). */
    public const SERVICE_ID = 'nowo_transaction_doctrine.host_flush_probe';

    /** @var array<string, true> "bundle|class" pairs already reported in this unit of work */
    private array $reported = [];

    /**
     * @param LoggerInterface|null $logger Where a failed inspection or deprecation is logged (warning); null swallows it
     */
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /**
     * Reports the host's pending changes the bundle's next flush of that manager would write, and returns their
     * classes (sorted). Call it right before the bundle flushes the host's manager in host mode; it changes nothing.
     *
     * @param EntityManagerInterface $entityManager The host's manager the bundle is about to flush
     * @param string $bundleName The bundle doing the flush, as shown in the message (for example "NowoWikiBundle")
     * @param list<string> $ownNamespaces The bundle's entity namespaces (for example ["Nowo\WikiBundle\Entity"]): their
     *                                    classes are never reported
     *
     * @return list<class-string> The foreign entity classes with pending work (reported or already reported); none when
     *                            the inspection failed (logged, never thrown)
     */
    public function beforeHostFlush(EntityManagerInterface $entityManager, string $bundleName, array $ownNamespaces): array
    {
        try {
            $foreign = $this->pendingClasses($entityManager, $ownNamespaces);
        } catch (Throwable $throwable) {
            $this->warn('Host-flush probe: inspecting the pending changes {bundle} is about to flush failed; nothing reported.', $bundleName, $throwable);

            return [];
        }

        foreach ($foreign as $class) {
            $key = $bundleName . '|' . $class;
            if (!isset($this->reported[$key])) {
                $this->reported[$key] = true; // @igor-ignore — per unit of work, emptied by reset() at every boundary

                try {
                    @trigger_error(sprintf('Your pending changes to %s were flushed by %s; 2.0 no longer does.', $class, $bundleName), E_USER_DEPRECATED);
                } catch (Throwable $throwable) { // a host error handler that ignores @ and throws (audit XA-3)
                    $this->warn('Host-flush probe: the deprecation for {class} flushed by {bundle} threw; the write goes on.', $bundleName, $throwable, $class);
                }
            }
        }

        return $foreign;
    }

    /** Forgets what was reported: a new unit of work (request, message, command) starts. */
    public function reset(): void
    {
        $this->reported = []; // @igor-ignore — the boundary reset itself
    }

    /**
     * The foreign entity classes with pending work in the host's unit of work, sorted.
     *
     * @param list<string> $ownNamespaces
     *
     * @return list<class-string>
     */
    private function pendingClasses(EntityManagerInterface $entityManager, array $ownNamespaces): array
    {
        $prefixes = [];
        foreach ($ownNamespaces as $namespace) {
            $prefixes[] = rtrim(ltrim($namespace, '\\'), '\\') . '\\';
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        $foreign    = [];
        $note       = function (object $entity) use (&$foreign, $prefixes): void {
            $class = $this->realClass($entity::class);
            if (!$this->owned($class, $prefixes)) {
                $foreign[$class] = true;
            }
        };

        // Scheduled inserts and deletes, orphan removals (audit WD-6), and collections a clear() scheduled for deletion
        // (audit WD-4: the owner's join rows go).
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityDeletions(), OrphanRemovals::pending($unitOfWork)] as $entities) {
            foreach ($entities as $entity) {
                $note($entity);
            }
        }

        foreach ([$unitOfWork->getScheduledCollectionDeletions(), $unitOfWork->getScheduledCollectionUpdates()] as $collections) {
            foreach ($collections as $collection) {
                $owner = $collection->getOwner();
                if ($owner !== null) {
                    $note($owner);
                }
            }
        }

        foreach ($unitOfWork->getIdentityMap() as $rootClass => $entities) {
            // A root inside the bundle's namespaces is skipped whole, unless a class outside them extends it (audit WD-6:
            // a host subclass of a bundle-owned single-table root): then each entity's own class decides.
            if ($this->owned($rootClass, $prefixes) && !$this->hasForeignSubclass($entityManager, $rootClass, $prefixes)) {
                continue;
            }

            foreach ($entities as $entity) {
                $class = $this->realClass($entity::class);
                // An already reported class is walked too (1.9.2, audit TD192-1): changed() also notes the orphans an
                // inverse to-one leaves and the new entities a changed association cascade-persists.
                if (!$this->owned($class, $prefixes) && !$unitOfWork->isUninitializedObject($entity)
                    && $this->changed($entityManager, $entity, $note)) {
                    $foreign[$class] = true;
                }
            }
        }

        ksort($foreign);

        /** @var list<class-string> $classes */
        $classes = array_keys($foreign);

        return $classes;
    }

    /** Logs a probe failure as a warning when a logger is wired; a failing logger is swallowed too (never thrown). */
    private function warn(string $message, string $bundleName, Throwable $throwable, ?string $class = null): void
    {
        try {
            // Only the failure's type and the entity class: its message and trace may hold entity data (audit XB-2).
            $this->logger?->warning($message, ['bundle' => $bundleName, 'class' => $class, 'exception_type' => get_debug_type($throwable)]);
        } catch (Throwable) {
            // The probe never breaks the caller's write, not even through its logger.
        }
    }

    /**
     * @param list<string> $prefixes
     */
    private function owned(string $class, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a managed entity differs from its loaded state in a way the flush writes: a field, an owning-side to-one
     * association, or a to-many collection that is dirty or no longer the managed one. An inverse-side to-one writes
     * nothing for its owner (1.9.2, audit XD2-2: after the host flushed an inverse one-to-one set to null, ORM keeps the
     * old value in the owner's loaded state); with orphan removal its managed former value is noted instead, as the
     * flush deletes it. A new entity a changed association would
     * cascade-persist is noted too (audit WD-6). Read-only entities, and DEFERRED_EXPLICIT entities the host did not
     * persist() again, are never written: not changed (audit WD-6). Reads only (no change-set computation).
     *
     * @param Closure(object): void $note
     */
    private function changed(EntityManagerInterface $entityManager, object $entity, Closure $note): bool
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata   = $entityManager->getClassMetadata($entity::class);
        if ($metadata->isReadOnly || $unitOfWork->isReadOnly($entity)
            || ($metadata->isChangeTrackingDeferredExplicit() && !$unitOfWork->isScheduledForDirtyCheck($entity))) {
            return false;
        }

        $original = $unitOfWork->getOriginalEntityData($entity);
        $changed  = false;
        foreach ($metadata->getFieldNames() as $field) {
            if (array_key_exists($field, $original) && ($value = $metadata->getFieldValue($entity, $field)) !== ($loaded = $this->loadedValue($metadata, $field, $original[$field]))
                && !$this->sameDatabaseValue($unitOfWork, $metadata, $field, $loaded, $value)) {
                $changed = true;

                break;
            }
        }

        foreach ($metadata->getAssociationNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            if ($metadata->isCollectionValuedAssociation($field)) {
                // Unchanged only when it is the loaded collection itself and clean (1.9.2, audit TD192-2): a clean
                // collection of another entity assigned here makes the flush write it for this owner.
                if ($value instanceof PersistentCollection && !$value->isDirty() && array_key_exists($field, $original) && $value === $original[$field]) {
                    continue;
                }

                $changed = true;
                if ($value instanceof PersistentCollection || is_iterable($value)) {
                    $this->noteCascadedPersists($unitOfWork, $metadata->getAssociationMapping($field), $value instanceof PersistentCollection ? $value->unwrap() : $value, $note);
                }
            } elseif (array_key_exists($field, $original) && $value !== ($loaded = $original[$field])) {
                $mapping = $metadata->getAssociationMapping($field);
                if (!$metadata->isAssociationInverseSide($field)) {
                    $changed = true;
                } elseif (is_object($loaded) && $this->isOrphanRemoval($mapping) && $unitOfWork->getEntityState($loaded, UnitOfWork::STATE_NEW) === UnitOfWork::STATE_MANAGED) {
                    $note($loaded); // the flush deletes the managed orphan (audit XD2-2: never the owner's row)
                }

                if (is_object($value)) {
                    $this->noteCascadedPersists($unitOfWork, $mapping, [$value], $note);
                }
            }
        }

        return $changed;
    }

    /**
     * The loaded value of a field as the flush compares it: an enum field's case(s) become backing value(s).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function loadedValue(ClassMetadata $metadata, string $field, mixed $loaded): mixed
    {
        return $this->isEnumField($metadata->fieldMappings[$field]) ? $this->backingValues($loaded) : $loaded;
    }

    /**
     * Whether the field maps a backed enum (`enumType`, also on array / simple_array columns).
     *
     * @param array<string, mixed>|object $mapping The field mapping (ORM 2 array, ORM 3 FieldMapping)
     */
    private function isEnumField(array|object $mapping): bool
    {
        $enumType = is_array($mapping) ? ($mapping['enumType'] ?? null) : ($mapping instanceof FieldMapping ? $mapping->enumType : null); // ORM 2 array, ORM 3 object

        return $enumType !== null && $enumType !== '';
    }

    /**
     * The loaded value of an enum field as UnitOfWork::computeChangeSet() compares it (ORM 2.20, 3.7 and 3.8 alike): the
     * loaded state holds the enum case(s), the entity's field value (getFieldValue()) the backing value(s). A case, and
     * each case of an array, becomes its backing value; anything else (null, a scalar) is compared as loaded (audit XA-1).
     */
    private function backingValues(mixed $loaded): mixed
    {
        if (is_array($loaded)) {
            foreach ($loaded as $key => $case) {
                if ($case instanceof BackedEnum) {
                    $loaded[$key] = $case->value;
                }
            }

            return $loaded;
        }

        return $loaded instanceof BackedEnum ? $loaded->value : $loaded;
    }

    /**
     * Whether ORM itself skips this field as unchanged: in recent ORM 3 releases an object replaced by an equal one (a new
     * DateTimeImmutable of the same instant, an equal value object) is compared by its database value, as the flush
     * does (UnitOfWork::isSameConvertedDatabaseValue(), private: called as is). Older ORMs write it: changed.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function sameDatabaseValue(UnitOfWork $unitOfWork, ClassMetadata $metadata, string $field, mixed $original, mixed $value): bool
    {
        // @phpstan-ignore function.alreadyNarrowedType (absent from older ORMs)
        if (!is_object($original) || !is_object($value) || !method_exists($unitOfWork, 'isSameConvertedDatabaseValue')) {
            return false;
        }

        /** @var bool $same */
        $same = Closure::bind(static fn (UnitOfWork $unitOfWork): mixed => $unitOfWork->isSameConvertedDatabaseValue($metadata, $field, $original, $value), null, UnitOfWork::class)($unitOfWork);

        return $same;
    }

    /**
     * Notes the classes of the new entities an association with cascade persist would insert at the flush.
     *
     * @param array<string, mixed>|object $mapping The association mapping (ORM 2 array, ORM 3 object)
     * @param iterable<mixed> $elements
     * @param Closure(object): void $note
     */
    private function noteCascadedPersists(UnitOfWork $unitOfWork, array|object $mapping, iterable $elements, Closure $note): void
    {
        $cascade = is_array($mapping) ? ($mapping['isCascadePersist'] ?? false) : ($mapping instanceof AssociationMapping && $mapping->isCascadePersist()); // ORM 2 array, ORM 3 object
        if ($cascade !== true) {
            return;
        }

        foreach ($elements as $element) {
            if (is_object($element) && $unitOfWork->getEntityState($element, UnitOfWork::STATE_NEW) === UnitOfWork::STATE_NEW) {
                $note($element);
            }
        }
    }

    /**
     * Whether an association removes its orphans.
     *
     * @param array<string, mixed>|object $mapping The association mapping (ORM 2 array, ORM 3 object)
     */
    private function isOrphanRemoval(array|object $mapping): bool
    {
        return is_array($mapping) ? ($mapping['orphanRemoval'] ?? false) === true : ($mapping instanceof AssociationMapping && $mapping->orphanRemoval); // ORM 2 array, ORM 3 object
    }

    /**
     * Whether a class outside the bundle's namespaces extends this root (a host subclass of a bundle-owned root).
     *
     * @param list<string> $prefixes
     */
    private function hasForeignSubclass(EntityManagerInterface $entityManager, string $rootClass, array $prefixes): bool
    {
        foreach ($entityManager->getClassMetadata($rootClass)->subClasses as $subclass) {
            if (!$this->owned($subclass, $prefixes)) {
                return true;
            }
        }

        return false;
    }

    /** The entity class of a Doctrine proxy class name (`…\__CG__\App\X` → `App\X`), or the class itself. */
    private function realClass(string $class): string
    {
        $marker = strrpos($class, '\\__CG__\\');

        return $marker !== false ? substr($class, $marker + 8) : $class;
    }
}
