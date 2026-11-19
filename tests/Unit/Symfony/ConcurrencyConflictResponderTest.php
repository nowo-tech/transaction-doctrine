<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Doctrine\DBAL\Exception\DriverException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictExceptionMapperInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\Conflict\TransientFailureConflictMapper;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

use function array_intersect_key;
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
        self::assertStringContainsString('data-testid="concurrency-conflict" data-conflict-code="CONFLICT_001"', $html);
        self::assertSame('A write gave up after repeated database lock conflicts; answered the conflict status.', $logger->records[0]['message']);
        self::assertSame(['route' => 'app_board', 'method' => 'GET', 'attempts' => 3, 'status' => 409, 'committed' => false, 'code' => 'CONFLICT_001', 'exception_class' => ConcurrencyConflict::class, 'exception_code' => 0], $logger->records[0]['context']);
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
        self::assertStringContainsString('data-testid="concurrency-conflict" data-conflict-code="CONFLICT_001"', $html);
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
            ['error' => 'concurrency_conflict', 'code' => 'CONFLICT_001', 'message' => 'Your change was not saved because the same data was being changed at the same time. Nothing was lost: try again.'],
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

    /**
     * Audit SL-D-M1: when part of the unit committed, no Retry-After and a "reload first" answer, never "try again".
     */
    public function testAPartiallyCommittedConflictIsNeverInvitedToRetryBlindly(): void
    {
        $partial   = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, FakeDriverException::deadlock(), true);
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(409, 3), [], [], $this->twig(), $this->translator('en'));

        $json = $responder->respond(Request::create('/api/x', 'POST'), $partial);
        self::assertSame(409, $json->getStatusCode());
        self::assertFalse($json->headers->has('Retry-After'));
        self::assertSame(
            ['error' => 'concurrency_conflict_partial', 'code' => 'CONFLICT_002', 'message' => 'Part of your change may have been saved before the same data was changed at the same time. Reload before trying again.'],
            json_decode((string) $json->getContent(), true),
        );

        $page = $responder->respond(Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => 'https://app.test/form']), new RuntimeException('wrap', 0, $partial));
        $html = (string) $page->getContent();
        self::assertFalse($page->headers->has('Retry-After'));
        self::assertStringContainsString('Part of your change may have been saved', $html);
        self::assertStringContainsString('href="https://app.test/form" data-testid="concurrency-conflict-retry">Reload</a>', $html);
        self::assertStringNotContainsString('Nothing was lost', $html);
        self::assertStringContainsString('data-conflict-code="CONFLICT_002"', $html);

        $spanish = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $this->twig(null, 'es'), $this->translator('es'));
        self::assertStringContainsString('Puede que parte de tu cambio se haya guardado', (string) $spanish->respond(Request::create('/x'), $partial)->getContent());

        $plain = (string) (new ConcurrencyConflictResponder(new ConflictResponsePolicy()))->respond(Request::create('/x'), $partial)->getContent();
        self::assertStringContainsString('<h1>Part of your change may have been saved</h1>', $plain);
        self::assertStringContainsString('>Reload</a>', $plain);
        self::assertStringContainsString('data-conflict-code="CONFLICT_002"', $plain);

        $logger = new RecordingLogger();
        $event  = $this->event(Request::create('/api/x', 'POST'), $partial);
        (new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], null, null, $logger))($event);
        self::assertTrue($logger->records[0]['context']['committed']);
        self::assertSame('CONFLICT_002', $logger->records[0]['context']['code']);
    }

    public function testAFormatterSeesTheCommittedFlagAndCannotAddRetryAfter(): void
    {
        $formatter = new class implements ConflictResponseFormatterInterface {
            public ?ConflictResponseContext $context = null;

            public function format(Request $request, ConflictResponseContext $context): JsonResponse
            {
                $this->context = $context;

                return new JsonResponse(['err' => $context->message], 200, ['Retry-After' => '9']);
            }
        };
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [$formatter]);

        $response = $responder->respond(Request::create('/api/x'), new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, FakeDriverException::deadlock(), true));

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertNotNull($formatter->context);
        self::assertTrue($formatter->context->committed);
        self::assertNull($formatter->context->retryAfterSeconds);
        self::assertSame('concurrency_conflict_partial', $formatter->context->errorCode);
    }

    /** REQ-RUNTIME-003 rule 6: a formatter receives the code to put in its own shape. */
    public function testAFormatterReceivesTheConflictCode(): void
    {
        $formatter = new class implements ConflictResponseFormatterInterface {
            public function format(Request $request, ConflictResponseContext $context): JsonResponse
            {
                return new JsonResponse(['err' => $context->code->value]);
            }
        };
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [$formatter]);

        $retry = $responder->respond(Request::create('/api/x'), $this->conflict());
        self::assertSame(['err' => 'CONFLICT_001'], json_decode((string) $retry->getContent(), true));
        self::assertSame('1', $retry->headers->get('Retry-After'));

        $committed = $responder->respond(Request::create('/api/x'), new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true));
        self::assertSame(['err' => 'CONFLICT_002'], json_decode((string) $committed->getContent(), true));
        self::assertFalse($committed->headers->has('Retry-After'));
    }

    public function testTheContextDerivesTheCodeFromTheCommittedFlag(): void
    {
        $failure = new RuntimeException('x');

        self::assertSame(ConflictCode::RetrySafe, (new ConflictResponseContext(409, 1, 'e', 'm', null, $failure))->code);
        self::assertSame(ConflictCode::Committed, (new ConflictResponseContext(409, null, 'e', 'm', null, $failure, true))->code);
        self::assertSame(ConflictCode::Committed, (new ConflictResponseContext(409, null, 'e', 'm', null, $failure, false, ConflictCode::Committed))->code);
    }

    /**
     * A bare lost savepoint (1305 with no deadlock behind it) reaching the kernel with `map_transient_failures`: 409
     * CONFLICT_002 and no Retry-After, in JSON and on the page. Without the mapper it is not a conflict (unchanged).
     */
    public function testABareLostSavepointAnswersConflict002WhenTransientFailuresAreMapped(): void
    {
        $lost   = new DriverException(new FakeDriverException('SQLSTATE[42000]: 1305 SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null);
        $mapped = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [new TransientFailureConflictMapper()], [], $this->twig(), $this->translator('en'));

        self::assertFalse((new ConcurrencyConflictResponder(new ConflictResponsePolicy()))->isConflict($lost));
        self::assertTrue($mapped->isConflict($lost));

        $json = $mapped->respond(Request::create('/api/x', 'POST'), $lost);
        self::assertSame(409, $json->getStatusCode());
        self::assertFalse($json->headers->has('Retry-After'));
        self::assertSame('CONFLICT_002', json_decode((string) $json->getContent(), true)['code'] ?? null);

        $page = (string) $mapped->respond(Request::create('/x'), $lost)->getContent();
        self::assertStringContainsString('data-conflict-code="CONFLICT_002"', $page);
        self::assertStringContainsString('Part of your change may have been saved', $page);

        $deadlock = $mapped->respond(Request::create('/api/x', 'POST'), new DriverException(FakeDriverException::deadlock(), null));
        self::assertSame('1', $deadlock->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', json_decode((string) $deadlock->getContent(), true)['code'] ?? null);
    }

    /**
     * Audits TA42-3 / UA2-5 (1.7): a conflict in the second wrapped exception of a synchronous Messenger
     * HandlerFailedException answers 409, not 500: a write unit's ConcurrencyConflict by default (its own attempts and
     * Retry-After hint used), a raw deadlock with `map_transient_failures`.
     */
    public function testAConflictInALaterWrappedMessengerExceptionAnswers409(): void
    {
        $envelope = new Envelope(new stdClass());
        $conflict = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 7, FakeDriverException::deadlock());
        $failure  = new HandlerFailedException($envelope, [new RuntimeException('business rule'), $conflict]);
        $logger   = new RecordingLogger();
        $default  = new ConcurrencyConflictResponder(new ConflictResponsePolicy(409, 1), [], [], null, null, $logger);
        $event    = $this->event(Request::create('/api/x', 'POST'), $failure);

        $default($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('7', $response->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame(3, $logger->records[0]['context']['attempts']);

        $deadlock = new HandlerFailedException($envelope, [new RuntimeException('business rule'), new DriverException(FakeDriverException::deadlock(), null)]);
        $mapped   = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [new TransientFailureConflictMapper()]);
        self::assertFalse($default->isConflict($deadlock));
        self::assertTrue($mapped->isConflict($deadlock));
        self::assertSame(409, $mapped->respond(Request::create('/api/x', 'POST'), $deadlock)->getStatusCode());

        $unique = new HandlerFailedException($envelope, [new RuntimeException('business rule'), new DriverException(FakeDriverException::uniqueViolation(), null)]);
        self::assertFalse($mapped->isConflict($unique));
    }

    /** A host rendering the template without `conflict_code` (an override's include, 1.4 variables) still gets it. */
    public function testTheTemplateDerivesTheCodeWhenTheVariableIsMissing(): void
    {
        $twig = $this->twig();
        $vars = ['status_code' => 409, 'exception' => null, 'retry_url' => '/', 'retry_after' => null, 'ui_kit' => false];

        self::assertStringContainsString('data-conflict-code="CONFLICT_002"', $twig->render(ConcurrencyConflictResponder::DEFAULT_TEMPLATE, $vars + ['partial' => true]));
        self::assertStringContainsString('data-conflict-code="CONFLICT_001"', $twig->render(ConcurrencyConflictResponder::DEFAULT_TEMPLATE, $vars + ['partial' => false]));
    }

    public function testTheConflictsOwnRetryAfterHintIsUsed(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(409, 1));
        $conflict  = new ConcurrencyConflict(2, TransientFailureKind::Deadlock, 7, FakeDriverException::deadlock());

        self::assertSame('7', $responder->respond(Request::create('/api/x'), new RuntimeException('wrap', 0, $conflict))->headers->get('Retry-After'));
        self::assertSame('1', $responder->respond(Request::create('/api/x'), new TransientStorageFailure())->headers->get('Retry-After'));
    }

    /** Audit TH X-2: every answer carries X-Conflict-Code, whatever shape built the body. */
    public function testEveryAnswerCarriesTheConflictCodeHeader(): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [], $this->twig(), $this->translator('en'));
        $partial   = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true);

        self::assertSame('CONFLICT_001', $responder->respond(Request::create('/api/x', 'POST'), $this->conflict())->headers->get('X-Conflict-Code'));
        self::assertSame('CONFLICT_001', $responder->respond(Request::create('/x'), $this->conflict())->headers->get('X-Conflict-Code'));
        self::assertSame('CONFLICT_002', $responder->respond(Request::create('/api/x', 'POST'), $partial)->headers->get('X-Conflict-Code'));
        self::assertSame('CONFLICT_002', $responder->respond(Request::create('/x'), $partial)->headers->get('X-Conflict-Code'));

        $formatter = new class implements ConflictResponseFormatterInterface {
            public function format(Request $request, ConflictResponseContext $context): JsonResponse
            {
                return new JsonResponse(['jsonrpc' => '2.0'], 200, ['X-Conflict-Code' => 'SETTINGS_CHANGED']);
            }
        };
        $formatted = (new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [], [$formatter]))->respond(Request::create('/rpc'), $partial);
        self::assertSame('CONFLICT_002', $formatted->headers->get('X-Conflict-Code'), 'Only the two platform values, set by the responder.');
    }

    /**
     * Audit TH X-1: a conflict after a commit earlier in the same request (since the request's CommitMark) answers
     * CONFLICT_002 with no Retry-After, even when the failed unit itself committed nothing.
     */
    public function testACommitEarlierInTheRequestAnswersConflict002(): void
    {
        $ledger    = new CommitLedger();
        $logger    = new RecordingLogger();
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(409, 3), [], [], null, null, $logger);
        $request   = Request::create('/api/x', 'POST');
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));

        $untouched = $responder->respond($request, $this->conflict());
        self::assertSame('3', $untouched->headers->get('Retry-After'));
        self::assertSame('CONFLICT_001', $untouched->headers->get('X-Conflict-Code'));

        $ledger->record();
        $event = $this->event($request, $this->conflict());
        $responder($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertSame(['error' => 'concurrency_conflict_partial', 'code' => 'CONFLICT_002'], array_intersect_key((array) json_decode((string) $response->getContent(), true), ['error' => 1, 'code' => 1]));
        self::assertSame('CONFLICT_002', $logger->records[0]['context']['code']);
        self::assertTrue($logger->records[0]['context']['committed']);
    }

    public function testRetryUrl(): void
    {
        self::assertSame('/board/7?tab=a', ConcurrencyConflictResponder::retryUrl(Request::create('/board/7?tab=a')));
        self::assertSame('https://APP.test/form', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => 'https://APP.test/form'])));
        self::assertSame('/', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => 'https://evil.test/form'])));
        self::assertSame('/', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST')));
        self::assertSame('/', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => 'javascript://app.test/%0aalert(1)'])));
        self::assertSame('/', ConcurrencyConflictResponder::retryUrl(Request::create('https://app.test/save', 'POST', [], [], [], ['HTTP_REFERER' => '//app.test/form'])));
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
        return new ConcurrencyConflict(3, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock());
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
