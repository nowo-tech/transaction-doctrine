<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_is_list;
use function is_array;
use function is_object;
use function is_string;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_replace;

/**
 * Runs a controller marked #[AsWriteUnit] (option `controller_attribute`), or a route listed in `controller_routes`
 * (bundle controllers the host cannot annotate), as one write unit.
 *
 * On a replay only the **Doctrine entity arguments** are reloaded, by identifier, from the cleared or reset manager;
 * every other argument keeps its first-attempt value (`#[MapRequestPayload]` / `#[MapQueryString]` DTOs,
 * `#[MapUploadedFile]` files, the request, values another controller_arguments listener set). An entity that no
 * longer exists answers 404. The signed-in user, when it is an entity, is reloaded into the token; when it no longer
 * exists the replay fails closed (403) instead of running with the stale user.
 *
 * Request memos are not arguments: services implementing ResetBetweenAttemptsInterface are emptied by the write unit
 * before the replay (the WriteUnitRetryEvent carries the route name as the unit).
 *
 * Access checks (#[IsGranted] and the other attribute listeners) run once, before the first attempt, on the
 * first-attempt entities: the replay acts on the same rows reloaded, whose state may have changed meanwhile.
 *
 * A route entry matches the route name exactly; `*` in an entry is an explicit wildcard (`*_nowo_task_board_task_move`
 * for tenant-prefixed copies). Listing a bundle's route also needs that bundle's ConflictResponseFormatterInterface,
 * or its API clients get this package's JSON shape when the unit gives up.
 *
 * Listens on kernel.controller_arguments at priority -10100, after the security attribute listeners and the request
 * payload resolvers. Main requests only. Stateless (FrankenPHP worker, kernel reset off): the wrapped controller is a
 * closure on the event, never kept on the service.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class WriteUnitControllerListener
{
    public const PRIORITY = -10100;

    /**
     * @param list<string> $routes Route names (exact, or with `*` wildcards) that run as a write unit
     * @param bool $attribute Whether #[AsWriteUnit] is honoured
     */
    public function __construct(
        private WriteUnitInterface $writeUnit,
        private ?ManagerRegistry $registry = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private array $routes = [],
        private bool $attribute = true,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->applies($event, $event->getRequest())) {
            return;
        }

        $controller = $event->getController();
        $arguments  = $event->getArguments();
        $route      = $event->getRequest()->attributes->get('_route');
        $unit       = is_string($route) ? $route : null;
        $event->setController(fn (): mixed => $this->runAsUnit($controller, $arguments, $unit), $this->attributes($event));
    }

    public function listed(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return false;
        }

        foreach ($this->routes as $entry) {
            if ($route === $entry || (str_contains($entry, '*') && preg_match('/^' . str_replace('\*', '.*', preg_quote($entry, '/')) . '$/', $route) === 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The replaced controller's attributes, in the shape setController() takes (1.9.2): a flat list on Symfony >= 8.1,
     * which deprecates attributes grouped by class name, grouped by class name before. Feature-detected: since 8.1
     * getAttributes() also keeps them as a flat list in the `_controller_attributes` request attribute (public since
     * 8.1, unused before), so that list is passed when present and the grouped array otherwise.
     *
     * @return array<array-key, mixed>
     */
    private function attributes(ControllerArgumentsEvent $event): array
    {
        $grouped = $event->getAttributes();
        $flat    = $event->getRequest()->attributes->get('_controller_attributes');

        return is_array($flat) && array_is_list($flat) ? $flat : $grouped;
    }

    private function applies(ControllerArgumentsEvent $event, Request $request): bool
    {
        return ($this->attribute && $event->getAttributes(AsWriteUnit::class) !== []) || $this->listed($request);
    }

    /**
     * @param array<array-key, mixed> $arguments The arguments resolved for the first attempt
     */
    private function runAsUnit(callable $controller, array $arguments, ?string $unit): mixed
    {
        $work = fn (int $attempt): mixed => $controller(...($attempt === 1 ? $arguments : $this->replayArguments($arguments)));

        // WriteUnitInterface::run() takes no unit name before 2.0; the bundle's WriteUnit does.
        return $this->writeUnit instanceof WriteUnit ? $this->writeUnit->run($work, $unit) : $this->writeUnit->run($work);
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function replayArguments(array $arguments): array
    {
        $this->reloadSignedInUser();

        foreach ($arguments as $index => $argument) {
            if (!is_object($argument)) {
                continue;
            }

            $manager = $this->managerOf($argument);
            if ($manager instanceof ObjectManager) {
                $arguments[$index] = $this->reload($manager, $argument) ?? throw new NotFoundHttpException('The resource no longer exists.');
            }
        }

        return $arguments;
    }

    /** The token's user is an entity of the failed attempt's (cleared or reset) manager: load it again by id. */
    private function reloadSignedInUser(): void
    {
        $token = $this->tokenStorage?->getToken();
        $user  = $token?->getUser();
        if (!$token instanceof TokenInterface || !$user instanceof UserInterface) {
            return;
        }

        $manager = $this->managerOf($user);
        if (!$manager instanceof ObjectManager) {
            return;
        }

        $fresh = $this->reload($manager, $user);
        if (!$fresh instanceof UserInterface) {
            throw new AccessDeniedHttpException('The signed-in user no longer exists.');
        }

        $token->setUser($fresh); // @igor-ignore — this request's token (the token storage is per request)
    }

    private function managerOf(object $object): ?ObjectManager
    {
        return $this->registry?->getManagerForClass($object::class);
    }

    private function reload(ObjectManager $manager, object $entity): ?object
    {
        $metadata = $manager->getClassMetadata($entity::class);

        return $manager->find($metadata->getName(), $metadata->getIdentifierValues($entity));
    }
}
