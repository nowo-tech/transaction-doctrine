<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Nowo\TransactionDoctrine\Symfony\EventListener\WriteUnitControllerListener;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function count;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WriteUnitControllerListenerTest extends TestCase
{
    public function testAMarkedControllerIsReplayedWithFreshArgumentsAndAReloadedUser(): void
    {
        $controller = new MarkedController();
        $user       = new InMemoryUser('ada', null);
        $fresh      = new InMemoryUser('ada', null, ['ROLE_FRESH']);
        $tokens     = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->with($user)->willReturn(['id' => 'ada']);
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->expects($this->once())->method('find')->with(InMemoryUser::class, ['id' => 'ada'])->willReturn($fresh);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $resolver = $this->createMock(ArgumentResolverInterface::class);
        $resolver->expects($this->once())->method('getArguments')->willReturn(['second']);
        $listener = new WriteUnitControllerListener($this->writeUnit(), $resolver, $registry, $tokens);
        $event    = $this->event($controller->save(...), ['first'], Request::create('/save', 'POST'));

        $listener($event);
        $response = ($event->getController())();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('second', $response->getContent());
        self::assertSame(['first', 'second'], $controller->seen);
        self::assertSame($fresh, $this->user($tokens));
    }

    public function testAListedRouteRunsAsAUnitAndTenantCopiesMatch(): void
    {
        $listener = new WriteUnitControllerListener($this->writeUnit(), $this->createMock(ArgumentResolverInterface::class), null, null, ['nowo_task_board_task_move'], false);

        foreach (['nowo_task_board_task_move', 'org_nowo_task_board_task_move'] as $route) {
            $request = Request::create('/move', 'POST');
            $request->attributes->set('_route', $route);
            self::assertTrue($listener->listed($request));
            $event    = $this->event([new PlainController(), 'move'], [], $request);
            $original = $event->getController();
            $listener($event);
            self::assertNotSame($original, $event->getController());
        }

        $other = Request::create('/x');
        $other->attributes->set('_route', 'nowo_task_board_task_moved');
        self::assertFalse($listener->listed($other));
        self::assertFalse($listener->listed(Request::create('/x')));
    }

    public function testUnmarkedControllersSubRequestsAndADisabledAttributeAreLeftAlone(): void
    {
        $resolver = $this->createMock(ArgumentResolverInterface::class);
        $listener = new WriteUnitControllerListener($this->writeUnit(), $resolver);

        $plain    = $this->event([new PlainController(), 'move'], [], Request::create('/x'));
        $original = $plain->getController();
        $listener($plain);
        self::assertSame($original, $plain->getController());

        $sub      = $this->event([new MarkedController(), 'save'], ['a'], Request::create('/x'), HttpKernelInterface::SUB_REQUEST);
        $original = $sub->getController();
        $listener($sub);
        self::assertSame($original, $sub->getController());

        $off      = new WriteUnitControllerListener($this->writeUnit(), $resolver, null, null, [], false);
        $event    = $this->event([new MarkedController(), 'save'], ['a'], Request::create('/x'));
        $original = $event->getController();
        $off($event);
        self::assertSame($original, $event->getController());
    }

    public function testTheUserIsKeptWhenItIsNoEntityOrNotFound(): void
    {
        $user   = new InMemoryUser('ada', null);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        $resolver = $this->createMock(ArgumentResolverInterface::class);
        $resolver->method('getArguments')->willReturn(['again']);

        $noManager = $this->createMock(ManagerRegistry::class);
        $noManager->method('getManagerForClass')->willReturn(null);
        $this->replayOnce(new WriteUnitControllerListener($this->writeUnit(), $resolver, $noManager, $tokens));
        self::assertSame($user, $this->user($tokens));

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 'ada']);
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $manager->method('find')->willReturn(null);
        $gone = $this->createMock(ManagerRegistry::class);
        $gone->method('getManagerForClass')->willReturn($manager);
        $this->replayOnce(new WriteUnitControllerListener($this->writeUnit(), $resolver, $gone, $tokens));
        self::assertSame($user, $this->user($tokens));

        $this->replayOnce(new WriteUnitControllerListener($this->writeUnit(), $resolver, $gone, new TokenStorage()));
        $this->replayOnce(new WriteUnitControllerListener($this->writeUnit(), $resolver, null, $tokens));
        self::assertSame($user, $this->user($tokens));
    }

    private function replayOnce(WriteUnitControllerListener $listener): void
    {
        $controller = new MarkedController();
        $event      = $this->event($controller->save(...), ['first'], Request::create('/x', 'POST'));
        $listener($event);
        ($event->getController())();
        self::assertSame(['first', 'again'], $controller->seen);
    }

    private function user(TokenStorage $tokens): ?UserInterface
    {
        return $tokens->getToken()?->getUser();
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
final class MarkedController
{
    /** @var list<string> */
    public array $seen = [];

    #[AsWriteUnit]
    public function save(string $value): Response
    {
        $this->seen[] = $value;
        if (count($this->seen) === 1) {
            throw FakeDriverException::deadlock();
        }

        return new Response($value);
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
