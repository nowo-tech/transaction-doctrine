<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function str_ends_with;

/**
 * Runs a controller marked #[AsWriteUnit] (option `controller_attribute`), or a route listed in `controller_routes`
 * (bundle controllers the host cannot annotate), as one write unit. On a replay the arguments are resolved again
 * (entities come from the fresh or cleared entity manager) and the signed-in user, when it is an entity, is reloaded
 * into the token, so nothing the failed attempt loaded is written back. A route matches by name, or by a name ending in
 * `_<entry>` (tenant-prefixed copies of a bundle route).
 *
 * Listens on kernel.controller_arguments at priority -10100, after the security attribute listeners: access checks
 * are not repeated. Main requests only. Stateless (FrankenPHP worker, kernel reset off): the wrapped controller is a
 * closure on the event, never kept on the service.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class WriteUnitControllerListener
{
    public const PRIORITY = -10100;

    /**
     * @param list<string> $routes Route names (or name suffixes) that run as a write unit
     * @param bool $attribute Whether #[AsWriteUnit] is honoured
     */
    public function __construct(
        private WriteUnitInterface $writeUnit,
        private ArgumentResolverInterface $argumentResolver,
        private ?ManagerRegistry $registry = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private array $routes = [],
        private bool $attribute = true,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->applies($event, $request)) {
            return;
        }

        $controller = $event->getController();
        $arguments  = $event->getArguments();
        $event->setController(fn (): mixed => $this->runAsUnit($controller, $arguments, $request), $event->getAttributes());
    }

    public function listed(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route)) {
            return false;
        }

        foreach ($this->routes as $entry) {
            if ($route === $entry || str_ends_with($route, '_' . $entry)) {
                return true;
            }
        }

        return false;
    }

    private function applies(ControllerArgumentsEvent $event, Request $request): bool
    {
        return ($this->attribute && $event->getAttributes(AsWriteUnit::class) !== []) || $this->listed($request);
    }

    /**
     * @param array<array-key, mixed> $arguments The arguments resolved for the first attempt
     */
    private function runAsUnit(callable $controller, array $arguments, Request $request): mixed
    {
        return $this->writeUnit->run(fn (int $attempt): mixed => $attempt === 1 ? $controller(...$arguments) : $this->replay($controller, $request));
    }

    private function replay(callable $controller, Request $request): mixed
    {
        $this->reloadSignedInUser();

        return $controller(...$this->argumentResolver->getArguments($request, $controller));
    }

    /** The token's user is an entity of the failed attempt's (cleared or reset) manager: load it again by id. */
    private function reloadSignedInUser(): void
    {
        $token = $this->tokenStorage?->getToken();
        $user  = $token?->getUser();
        if (!$token instanceof TokenInterface || !$user instanceof UserInterface || !$this->registry instanceof ManagerRegistry) {
            return;
        }

        $manager = $this->registry->getManagerForClass($user::class);
        if (!$manager instanceof ObjectManager) {
            return;
        }

        $fresh = $manager->find($user::class, $manager->getClassMetadata($user::class)->getIdentifierValues($user));
        if ($fresh instanceof UserInterface) {
            $token->setUser($fresh); // @igor-ignore — this request's token (the token storage is per request)
        }
    }
}
