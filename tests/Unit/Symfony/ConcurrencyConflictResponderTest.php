<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictExceptionMapperInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

use function dirname;
use function json_decode;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConcurrencyConflictResponderTest extends TestCase
{
    public function testAWriteUnitConflictOnAPageAnswers409WithTheBrandedPage(): void
    {
        $logger    = new RecordingLogger();
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $this->twig(), $this->translator('en'), $logger);
        $request   = Request::create('https://app.test/board/7');
        $request->attributes->set('_route', 'app_board');
        $event = $this->event($request, $this->conflict());

        $responder($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('Someone else was saving at the same moment', $html);
        self::assertStringContainsString('href="/board/7" data-testid="concurrency-conflict-retry"', $html);
        self::assertStringContainsString('<title>409 — ', $html);
        self::assertSame('A write gave up after repeated database lock conflicts; answered the conflict status.', $logger->records[0]['message']);
        self::assertSame(['route' => 'app_board', 'method' => 'GET', 'attempts' => 3, 'status' => 409, 'exception_class' => ConcurrencyConflict::class, 'exception_code' => 0], $logger->records[0]['context']);
    }

    public function testThePageIsTranslated(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $this->twig(null, 'es'), $this->translator('es'));

        $html = (string) $responder->respond(Request::create('/x'), $this->conflict())->getContent();

        self::assertStringContainsString('Otra persona estaba guardando en el mismo momento', $html);
        self::assertStringContainsString('Reintentar', $html);
    }

    public function testWithUiKitThePageExtendsItsErrorPage(): void
    {
        $uiKit = new ArrayLoader(['@NowoUiKit/error/status.html.twig' => '<ui-kit code="{{ error_code }}">{% block error_title %}{% endblock %}|{% block error_actions %}{% endblock %}|{% block error_extra %}{% endblock %}</ui-kit>']);
        $twig  = $this->twig($uiKit);
        $twig->addGlobal('error_code', 409);
        $twig->addGlobal('error', ['homeHref' => '/']);
        foreach (['nowo_ui_class', 'nowo_ui_attrs', 'nowo_ui_error_text'] as $function) {
            $twig->addFunction(new TwigFunction($function, static fn (string $name): string => $function === 'nowo_ui_class' ? 'kit-' . $name : ($function === 'nowo_ui_attrs' ? '' : 'Home'), ['is_safe' => ['html']]));
        }
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $twig, $this->translator('en'));
        $request   = Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => 'https://app.test/form']);

        $html = (string) $responder->respond($request, $this->conflict())->getContent();

        self::assertStringContainsString('<ui-kit code="409">Someone else was saving at the same moment|', $html);
        self::assertStringContainsString('class="kit-error_action" href="https://app.test/form" data-testid="concurrency-conflict-retry">Try again</a>', $html);
        self::assertStringContainsString('data-testid="concurrency-conflict"', $html);
    }

    public function testA503PolicyAnswers503(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(503, 4), [], [], $this->twig(), $this->translator('en'));

        $response = $responder->respond(Request::create('/x'), $this->conflict());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('4', $response->headers->get('Retry-After'));
        self::assertStringContainsString('<title>503 — ', (string) $response->getContent());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function jsonRequests(): iterable
    {
        yield 'API path' => [Request::create('/api/tasks/1', 'PUT')];
        yield 'XHR' => [Request::create('/board', 'POST', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])];
        yield 'JSON body' => [Request::create('/board', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'])];
        yield 'Accept JSON' => [Request::create('/board', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json'])];
        $format = Request::create('/board');
        $format->setRequestFormat('json');

        yield '_format json' => [$format];
    }

    #[DataProvider('jsonRequests')]
    public function testJsonRequestsGetTheStableJsonShape(Request $request): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $this->twig(), $this->translator('en'));

        $response = $responder->respond($request, $this->conflict());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame(
            ['error' => 'concurrency_conflict', 'message' => 'Your change was not saved because the same data was being changed at the same time. Nothing was lost: try again.'],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testTheJsonCodeAndPathsAreConfigurable(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], null, null, null, ConcurrencyConflictResponder::DEFAULT_TEMPLATE, 'unavailable', ['', '/rest/']);

        self::assertTrue($responder->wantsJson(Request::create('/rest/x')));
        self::assertFalse($responder->wantsJson(Request::create('/api/x')));
        self::assertSame('unavailable', json_decode((string) $responder->respond(Request::create('/rest/x'), $this->conflict())->getContent(), true)['error']);
    }

    public function testAFormatterShapesItsOwnRequestsAndTheStatusIsEnforced(): void
    {
        $formatter = new class implements ConflictResponseFormatterInterface {
            public ?ConflictResponseContext $context = null;

            public function format(Request $request, ConflictResponseContext $context): ?Response
            {
                if (!$request->attributes->getBoolean('_task_api')) {
                    return null;
                }
                $this->context = $context;

                return new JsonResponse(['err' => $context->message, 'ECODE' => 'CONFLICT_001'], 500);
            }
        };
        $skip = new class implements ConflictResponseFormatterInterface {
            public function format(Request $request, ConflictResponseContext $context): ?Response
            {
                return null;
            }
        };
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(409, 2), [], [$skip, $formatter]);
        $request   = Request::create('/api/tasks/1', 'PUT');
        $request->attributes->set('_task_api', true);
        $failure = $this->conflict();

        $response = $responder->respond($request, $failure);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('2', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', json_decode((string) $response->getContent(), true)['ECODE']);
        self::assertNotNull($formatter->context);
        self::assertSame(409, $formatter->context->status);
        self::assertSame(2, $formatter->context->retryAfterSeconds);
        self::assertSame('concurrency_conflict', $formatter->context->errorCode);
        self::assertSame(3, $formatter->context->attempts);
        self::assertSame($failure, $formatter->context->failure);
        self::assertSame('concurrency_conflict', json_decode((string) $responder->respond(Request::create('/api/x'), $failure)->getContent(), true)['error']);
    }

    public function testTheExceptionMappingHookAndTheMarkerInterfaceMakeAConflict(): void
    {
        $mapper = new class implements ConflictExceptionMapperInterface {
            public function isConflict(Throwable $failure): bool
            {
                return $failure instanceof TransientStorageFailure;
            }
        };
        $marked    = new class('own') extends RuntimeException implements LockConflictExceptionInterface {};
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [$mapper]);

        self::assertTrue($responder->isConflict(new TransientStorageFailure()));
        self::assertTrue($responder->isConflict(new RuntimeException('wrapper', 0, $marked)));
        self::assertTrue($responder->isConflict($this->conflict()));
        self::assertFalse($responder->isConflict(new RuntimeException('bug')));
        self::assertFalse((new ConcurrencyConflictResponder(new ConflictResponsePolicy()))->isConflict(new TransientStorageFailure()));

        $event = $this->event(Request::create('/api/x'), new TransientStorageFailure());
        $responder($event);
        self::assertSame(409, $responder->respond(Request::create('/api/x'), $marked)->getStatusCode());
        self::assertSame(409, $event->getResponse()?->getStatusCode());
    }

    public function testOtherFailuresAreLeftAlone(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy());
        $event     = $this->event(Request::create('/x'), new RuntimeException('bug'));

        $responder($event);

        self::assertNull($event->getResponse());
    }

    public function testWithoutTwigOrTranslatorThePlainPageIsBuilt(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy());
        $request   = Request::create('https://app.test/save?x=<b>', 'POST');

        $response = $responder->respond($request, $this->conflict());

        $html = (string) $response->getContent();
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('<h1>Someone else was saving at the same moment</h1>', $html);
        self::assertStringContainsString('<a href="/" data-testid="concurrency-conflict-retry">Try again</a>', $html);
    }

    public function testABrokenTemplateFallsBackToThePlainPage(): void
    {
        $logger    = new RecordingLogger();
        $twig      = new Environment(new ArrayLoader(['broken.html.twig' => '{{ missing_function() }}']));
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $twig, null, $logger, 'broken.html.twig');

        $html = (string) $responder->respond(Request::create('/x'), $this->conflict())->getContent();

        self::assertStringContainsString('<h1>Someone else was saving at the same moment</h1>', $html);
        self::assertSame('The conflict page template failed; answered the plain page.', $logger->records[0]['message']);
    }

    public function testRetryUrl(): void
    {
        self::assertSame('/board/7?tab=a', ConcurrencyConflictResponder::retryUrl(Request::create('/board/7?tab=a')));
        self::assertSame('https://APP.test/form', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => 'https://APP.test/form'])));
        self::assertSame('/', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => 'https://evil.test/form'])));
        self::assertSame('/', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST')));
    }

    public function testManyAnswersKeepNoState(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $this->twig(), $this->translator('en'));
        $failure   = $this->conflict();
        $responder->respond(Request::create('/a'), $failure);
        $responder->respond(Request::create('/api/a'), $failure);
        $before = memory_get_usage();

        for ($i = 0; $i < 1000; ++$i) {
            $html = (string) $responder->respond(Request::create('/user/' . $i), $failure)->getContent();
            self::assertStringContainsString('/user/' . $i, $html);
            self::assertStringNotContainsString('/user/' . ($i - 1) . '"', $html);
        }

        self::assertLessThan(512 * 1024, memory_get_usage() - $before);
    }

    private function conflict(): ConcurrencyConflict
    {
        return new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, FakeDriverException::deadlock());
    }

    private function event(Request $request, Throwable $failure): ExceptionEvent
    {
        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }

    private function twig(?ArrayLoader $extra = null, string $locale = 'en'): Environment
    {
        $files = new FilesystemLoader();
        $files->addPath(dirname(__DIR__, 3) . '/templates', 'NowoTransactionDoctrine');
        $twig = new Environment($extra instanceof ArrayLoader ? new ChainLoader([$extra, $files]) : $files, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($this->translator($locale)));

        return $twig;
    }

    private function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'es', 'fr', 'de'] as $language) {
            $translator->addResource('yaml', dirname(__DIR__, 3) . '/translations/nowo_transaction_doctrine.' . $language . '.yaml', $language, 'nowo_transaction_doctrine');
        }

        return $translator;
    }
}
