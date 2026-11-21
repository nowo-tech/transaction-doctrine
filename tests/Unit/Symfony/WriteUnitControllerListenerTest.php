<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Nowo\TransactionDoctrine\Symfony\EventListener\WriteUnitControllerListener;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\Journal;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitRetryEvent;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestPayloadValueResolver;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

use function count;
use function in_array;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WriteUnitControllerListenerTest extends TestCase
{
    public function testAReplayReloadsEntityArgumentsAndTheUserAndKeepsTheOthers(): void
    {
        $controller = new MarkedController();
        $user       = new InMemoryUser('ada', null);
        $fresh      = new InMemoryUser('ada', null, ['ROLE_FRESH']);
        $board      = new BoardEntity(7);
        $freshBoard = new BoardEntity(7);
        $tokens     = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $manager    = $this->manager([InMemoryUser::class => $fresh, BoardEntity::class => $freshBoard]);
        $listener   = new WriteUnitControllerListener($this->writeUnit(), $this->registry($manager), $tokens);
        $request    = Request::create('/save', 'POST');
        $dto        = new PayloadDto();
        $dto->title = 'kept';
        $event      = $this->event($controller->save(...), [$board, $dto, $request], $request);

        $listener($event);
        $response = ($event->getController())();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('saved kept', $response->getContent());
        self::assertSame([$board, $freshBoard], $controller->boards);
        self::assertSame([$dto, $dto], $controller->payloads);
        self::assertSame([$request, $request], $controller->requests);
        self::assertSame($fresh, $this->user($tokens));
    }

    /**
     * Audit SL-M3 (probe P4): a #[MapRequestPayload] DTO resolved by the real resolver survives the replay.
     */
    public function testAReplayKeepsAMapRequestPayloadDto(): void
    {
        $payload    = new RequestPayloadValueResolver(new Serializer([new ObjectNormalizer()], [new JsonEncoder()]));
        $resolver   = new ArgumentResolver(null, [$payload, ...ArgumentResolver::getDefaultArgumentValueResolvers()]);
        $controller = new PayloadController();
        $request    = Request::create('/save', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"title":"x"}');
        $request->attributes->set('_route', 'save');
        $event = new ControllerArgumentsEvent($this->createMock(HttpKernelInterface::class), $controller->save(...), $resolver->getArguments($request, $controller->save(...)), $request, HttpKernelInterface::MAIN_REQUEST);
        $payload->onKernelControllerArguments($event);

        (new WriteUnitControllerListener($this->writeUnit(), null, null, ['save']))($event);
        $response = ($event->getController())(...$event->getArguments());

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('saved x after 2', $response->getContent());
    }

    public function testScalarArgumentsKeepTheirValue(): void
    {
        $request = Request::create('/x', 'POST');
        $request->attributes->set('_route', 'scalar');
        $calls = 0;
        $event = $this->event(static function (int $id, ?string $tag) use (&$calls): Response {
            if (++$calls === 1) {
                throw FakeDriverException::deadlock();
            }

            return new Response($id . '/' . $tag);
        }, [42, null], $request);

        (new WriteUnitControllerListener($this->writeUnit(), null, null, ['scalar']))($event);
        $response = ($event->getController())();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('42/', $response->getContent());
    }

    public function testAnEntityThatNoLongerExistsAnswers404(): void
    {
        $listener = new WriteUnitControllerListener($this->writeUnit(), $this->registry($this->manager([BoardEntity::class => null])));
        $event    = $this->event([new MarkedController(), 'save'], [new BoardEntity(7), new PayloadDto(), Request::create('/x')], Request::create('/x', 'POST'));
        $listener($event);

        $this->expectException(NotFoundHttpException::class);

        ($event->getController())();
    }

    /**
     * Audit SL-I1: a signed-in user that cannot be reloaded fails closed.
     */
    public function testAUserThatNoLongerExistsFailsClosed(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null), 'main'));
        $listener = new WriteUnitControllerListener($this->writeUnit(), $this->registry($this->manager([InMemoryUser::class => null])), $tokens);
        $event    = $this->event([new MarkedController(), 'save'], [new BoardEntity(1), new PayloadDto(), Request::create('/x')], Request::create('/x', 'POST'));
        $listener($event);

        $this->expectException(AccessDeniedHttpException::class);

        ($event->getController())();
    }

    public function testAUserThatIsNoEntityOrNoTokenIsLeftAlone(): void
    {
        $user   = new InMemoryUser('ada', null);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $noManager = $this->createMock(ManagerRegistry::class);
        $noManager->method('getManagerForClass')->willReturn(null);

        foreach ([[$noManager, $tokens], [null, $tokens], [$noManager, new TokenStorage()], [$noManager, null]] as [$registry, $storage]) {
            $controller = new MarkedController();
            $event      = $this->event($controller->save(...), [new BoardEntity(1), new PayloadDto(), Request::create('/x')], Request::create('/x', 'POST'));
            (new WriteUnitControllerListener($this->writeUnit(), $registry, $storage))($event);
            ($event->getController())();
            self::assertCount(2, $controller->boards);
        }

        self::assertSame($user, $this->user($tokens));
    }

    /**
     * Audit SL-L3: exact names, `*` only as an explicit wildcard.
     */
    public function testListedRoutesMatchExactlyOrByExplicitWildcard(): void
    {
        $listener = new WriteUnitControllerListener($this->writeUnit(), null, null, ['save', '*_nowo_task_board_task_move'], false);

        foreach (['save' => true, 'app_save' => false, 'org_nowo_task_board_task_move' => true, 'nowo_task_board_task_move' => false, 'org_nowo_task_board_task_moved' => false] as $route => $expected) {
            $request = Request::create('/x', 'POST');
            $request->attributes->set('_route', $route);
            self::assertSame($expected, $listener->listed($request), $route);
        }

        self::assertFalse($listener->listed(Request::create('/x')));

        $request = Request::create('/x', 'POST');
        $request->attributes->set('_route', 'save');
        $event    = $this->event([new PlainController(), 'move'], [], $request);
        $original = $event->getController();
        $listener($event);
        self::assertNotSame($original, $event->getController());
    }

    public function testUnmarkedControllersSubRequestsAndADisabledAttributeAreLeftAlone(): void
    {
        $listener = new WriteUnitControllerListener($this->writeUnit());

        $plain    = $this->event([new PlainController(), 'move'], [], Request::create('/x'));
        $original = $plain->getController();
        $listener($plain);
        self::assertSame($original, $plain->getController());

        $sub      = $this->event([new MarkedController(), 'save'], [], Request::create('/x'), HttpKernelInterface::SUB_REQUEST);
        $original = $sub->getController();
        $listener($sub);
        self::assertSame($original, $sub->getController());

        $off      = new WriteUnitControllerListener($this->writeUnit(), null, null, [], false);
        $event    = $this->event([new MarkedController(), 'save'], [], Request::create('/x'));
        $original = $event->getController();
        $off($event);
        self::assertSame($original, $event->getController());
    }

    /**
     * 1.9.2: the wrapped controller keeps the replaced controller's attributes (#[AsWriteUnit] and the others, read by
     * the listeners after this one), and passing them raises no deprecation: a flat list on Symfony >= 8.1 (grouped by
     * class name is deprecated there), grouped by class name before. Runs against whichever Symfony is installed (7.4 on
     * PHP 8.2, 8.x on PHP 8.4 / 8.5 in CI).
     */
    public function testTheAttributesAreKeptWithoutADeprecation(): void
    {
        $controller   = new AttributedController();
        $request      = Request::create('/tag', 'POST');
        $event        = $this->event($controller->tag(...), [], $request);
        $deprecations = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test helper, restored in finally)
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            (new WriteUnitControllerListener($this->writeUnit()))($event);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
        self::assertCount(1, $event->getAttributes(AsWriteUnit::class));
        $cache = $event->getAttributes(Cache::class);
        self::assertCount(1, $cache);
        self::assertSame(60, $cache[0]->maxage);
        $response = ($event->getController())();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame('tagged', $response->getContent());
    }

    /**
     * Audit TD192-3 (1.9.2): before Symfony 8.1 nothing reads the `_controller_attributes` request attribute, so a list
     * another listener put there never replaces the controller's attributes (#[Cache] kept). On Symfony >= 8.1 that
     * attribute is where Symfony itself reads them, and it is passed on as is.
     */
    public function testAListInTheRequestAttributeOnlyCountsFromSymfony81(): void
    {
        $controller = new AttributedController();
        $request    = Request::create('/tag', 'POST');
        $event      = $this->event($controller->tag(...), [], $request);
        $event->getAttributes();
        $foreign = [new stdClass()];
        $request->attributes->set('_controller_attributes', $foreign);

        (new WriteUnitControllerListener($this->writeUnit()))($event);

        if ($this->symfonyVersionId() >= 80100) {
            self::assertSame($foreign, $request->attributes->get('_controller_attributes'));
            self::assertSame([], $event->getAttributes(Cache::class));
        } else {
            self::assertCount(1, $event->getAttributes(Cache::class), 'the controller\'s #[Cache] is kept');
            self::assertCount(1, $event->getAttributes(AsWriteUnit::class));
        }
    }

    /** The installed Symfony's version id, read at runtime (the suite runs on Symfony 7.4 and 8.x). */
    private function symfonyVersionId(): int
    {
        return Kernel::VERSION_ID;
    }

    private function user(TokenStorage $tokens): ?UserInterface
    {
        return $tokens->getToken()?->getUser();
    }

    /**
     * @param array<class-string, object|null> $fresh What find() returns per class
     */
    private function manager(array $fresh): ObjectManager
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturnCallback(function (string $class): ClassMetadata {
            $metadata = $this->createMock(ClassMetadata::class);
            $metadata->method('getName')->willReturn($class);
            $metadata->method('getIdentifierValues')->willReturn(['id' => 7]);

            return $metadata;
        });
        $manager->method('find')->willReturnCallback(static fn (string $class): ?object => $fresh[$class] ?? null);

        return $manager;
    }

    public function testTheReplayEventCarriesTheRouteAndAMemoIsResetBeforeTheReplay(): void
    {
        $log        = new Journal();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WriteUnitRetryEvent::class, static function (WriteUnitRetryEvent $event) use ($log): void {
            $log->add('event ' . $event->attempt . ' ' . ($event->unit ?? 'none'));
        });
        $memo       = $log->resetter('reset');
        $unit       = new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(3, 0, 0, 0), null, new MockClock(), null, null, null, [$memo], $dispatcher);
        $listener   = new WriteUnitControllerListener($unit, null, null, ['board_move'], false);
        $controller = static function () use ($log): Response {
            $log->add('attempt');
            if (count($log->entries) === 1) {
                throw FakeDriverException::deadlock();
            }

            return new Response('moved');
        };

        $request = Request::create('/move', 'POST');
        $request->attributes->set('_route', 'board_move');
        $event = $this->event($controller, [], $request);
        $listener($event);

        self::assertSame('moved', ($event->getController())()->getContent());
        self::assertSame(['attempt', 'reset', 'event 2 board_move', 'attempt'], $log->entries);
    }

    public function testAnotherWriteUnitImplementationRunsWithoutAUnitName(): void
    {
        $unit = new class implements WriteUnitInterface {
            public int $runs = 0;

            public function run(callable $work): mixed
            {
                ++$this->runs;

                return $work(1, null);
            }
        };
        $listener = new WriteUnitControllerListener($unit, null, null, ['save']);
        $request  = Request::create('/save', 'POST');
        $request->attributes->set('_route', 'save');
        $event = $this->event(static fn (): Response => new Response('saved'), [], $request);

        $listener($event);

        self::assertSame('saved', ($event->getController())()->getContent());
        self::assertSame(1, $unit->runs);
    }

    private function registry(ObjectManager $manager): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static fn (string $class): ?ObjectManager => in_array($class, [InMemoryUser::class, BoardEntity::class], true) ? $manager : null);

        return $registry;
    }

    private function writeUnit(): WriteUnit
    {
        return new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(3, 0, 0, 0), null, new MockClock());
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private function event(callable $controller, array $arguments, Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ControllerArgumentsEvent
    {
        return new ControllerArgumentsEvent($this->createMock(HttpKernelInterface::class), $controller, $arguments, $request, $type);
    }
}

/**
 * @internal
 */
final class BoardEntity
{
    public function __construct(public int $id)
    {
    }
}

/**
 * @internal
 */
final class PayloadDto
{
    public string $title = '';
}

/**
 * @internal
 */
final class MarkedController
{
    /** @var list<BoardEntity> */
    public array $boards = [];

    /** @var list<PayloadDto> */
    public array $payloads = [];

    /** @var list<Request> */
    public array $requests = [];

    #[AsWriteUnit]
    public function save(BoardEntity $board, PayloadDto $payload, Request $request): Response
    {
        $this->boards[]   = $board;
        $this->payloads[] = $payload;
        $this->requests[] = $request;
        if (count($this->boards) === 1) {
            throw FakeDriverException::deadlock();
        }

        return new Response('saved ' . $payload->title);
    }
}

/**
 * @internal
 */
final class PayloadController
{
    private int $calls = 0;

    public function save(#[MapRequestPayload] PayloadDto $dto): Response
    {
        if (++$this->calls === 1) {
            throw FakeDriverException::deadlock();
        }

        return new Response('saved ' . $dto->title . ' after ' . $this->calls);
    }
}

/**
 * @internal
 */
final class AttributedController
{
    #[AsWriteUnit]
    #[Cache(maxage: 60)]
    public function tag(): Response
    {
        return new Response('tagged');
    }
}

/**
 * @internal
 */
final class PlainController
{
    public function move(): Response
    {
        return new Response('moved');
    }
}
