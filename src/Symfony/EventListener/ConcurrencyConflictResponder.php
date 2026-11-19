<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\SafeLog;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictExceptionMapperInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\ThrowableWalk;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use Twig\Environment;

use function htmlspecialchars;
use function in_array;
use function is_string;
use function parse_url;
use function sprintf;
use function str_starts_with;
use function strcasecmp;
use function strtolower;

use const ENT_QUOTES;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Answers a lock conflict the client may retry with the configured status (409 by default, 503 to keep the 1.2
 * behaviour) and `Retry-After`, on any route. A conflict is a ConcurrencyConflict of a write unit, any exception
 * implementing LockConflictExceptionInterface in the previous chain or (since 1.7) inside a Messenger wrapper
 * (TransientFailureClassifier::findLockConflict(), bounded), or whatever a tagged
 * ConflictExceptionMapperInterface accepts (the exception-mapping hook: no route-name matching here).
 *
 * The answer carries a conflict code (REQ-RUNTIME-003 rule 6, ConflictCode::forRequest()): CONFLICT_001 when nothing
 * was committed (with `Retry-After`), CONFLICT_002 when part of the request was (ConcurrencyConflict::$committed, a
 * lost savepoint with no deadlock behind it, or a commit since the request's CommitMark, taken at kernel.request when
 * `commit_detection` is on; no `Retry-After`). It is the first tagged ConflictResponseFormatterInterface that returns
 * one (a bundle's own API shape; the context carries the code); else JSON
 * `{"error": "<json_error>", "code": "CONFLICT_00n", "message": "<text>"}` for JSON, XHR and API-path requests; else the
 * branded HTML page with *Try again* and `data-conflict-code="CONFLICT_00n"` (Twig template
 * `@NowoTransactionDoctrine/conflict.html.twig`, overridable through the bundle path or
 * `conflict_response.template`; it extends UiKit's error page when UiKit is installed). Every answer gets the
 * policy's status, `Retry-After`, `X-Conflict-Code: CONFLICT_00n` and `Cache-Control: no-store`. Without Twig (or when the template fails) a plain page
 * is built here.
 *
 * Runs on kernel.exception at priority 64, before the bundles' own exception mappers. Logs one constant warning with
 * the route name, the method and the attempts (never the URL, SQL or a message). Stateless (FrankenPHP worker, kernel
 * reset off): everything per call comes from the event.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConcurrencyConflictResponder
{
    public const DEFAULT_ERROR_CODE = 'concurrency_conflict';
    public const DEFAULT_TEMPLATE   = '@NowoTransactionDoctrine/conflict.html.twig';
    public const UI_KIT_TEMPLATE    = '@NowoUiKit/error/status.html.twig';
    public const TRANSLATION_DOMAIN = 'nowo_transaction_doctrine';

    /** English fallbacks when no translator is available. */
    private const FALLBACK_TEXTS = [
        'conflict.title'         => 'Someone else was saving at the same moment',
        'conflict.lead'          => 'Your change was not saved because the same data was being changed at the same time. Nothing was lost: try again.',
        'conflict.hint'          => 'If it keeps happening, wait a few seconds before trying again.',
        'conflict.retry'         => 'Try again',
        'conflict.partial.title' => 'Part of your change may have been saved',
        'conflict.partial.lead'  => 'Part of your change may have been saved before the same data was changed at the same time. Reload before trying again.',
        'conflict.partial.hint'  => 'Check what was saved first: sending the same change again could repeat it.',
        'conflict.partial.retry' => 'Reload',
    ];

    /** Suffix of the JSON error code when part of the unit was committed (`concurrency_conflict_partial`). */
    public const PARTIAL_SUFFIX = '_partial';

    private LoggerInterface $logger;

    /**
     * @param iterable<ConflictExceptionMapperInterface> $mappers Exception-mapping hook
     * @param iterable<ConflictResponseFormatterInterface> $formatters Response-shaping hook, by priority
     * @param list<string> $jsonPathPrefixes Path prefixes always answered in JSON
     */
    public function __construct(
        private ConflictResponsePolicy $policy,
        private iterable $mappers = [],
        private iterable $formatters = [],
        private ?Environment $twig = null,
        private ?TranslatorInterface $translator = null,
        ?LoggerInterface $logger = null,
        private string $template = self::DEFAULT_TEMPLATE,
        private string $errorCode = self::DEFAULT_ERROR_CODE,
        private array $jsonPathPrefixes = ['/api/'],
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        if (!$this->isConflict($failure)) {
            return;
        }

        $request  = $event->getRequest();
        $attempts = $this->attempts($failure);
        $code     = ConflictCode::forRequest($request, $failure);
        SafeLog::log($this->logger, 'warning', 'A write gave up after repeated database lock conflicts; answered the conflict status.', [
            'route'     => $this->route($request),
            'method'    => $request->getMethod(),
            'attempts'  => $attempts,
            'status'    => $this->policy->status,
            'committed' => $code === ConflictCode::Committed,
            'code'      => $code->value,
        ] + SafeLog::throwable($failure));
        $event->setResponse($this->respond($request, $failure));
    }

    public function isConflict(Throwable $failure): bool
    {
        if (TransientFailureClassifier::findLockConflict($failure) instanceof LockConflictExceptionInterface) {
            return true;
        }

        foreach ($this->mappers as $mapper) {
            if ($mapper->isConflict($failure)) {
                return true;
            }
        }

        return false;
    }

    public function respond(Request $request, Throwable $failure): Response
    {
        $code      = ConflictCode::forRequest($request, $failure);
        $committed = !$code->retrySafe();
        $context   = new ConflictResponseContext(
            $this->policy->status,
            $committed ? null : $this->policy->retryAfter($this->hint($failure)),
            $this->errorCode . ($committed ? self::PARTIAL_SUFFIX : ''),
            $this->text($committed ? 'conflict.partial.lead' : 'conflict.lead'),
            $this->attempts($failure),
            $failure,
            $committed,
            $code,
        );

        $response = null;
        foreach ($this->formatters as $formatter) {
            $response = $formatter->format($request, $context);
            if ($response instanceof Response) {
                break;
            }
        }

        $response ??= $this->wantsJson($request)
            ? new JsonResponse(['error' => $context->errorCode, 'code' => $context->code->value, 'message' => $context->message])
            : new Response($this->html($request, $context), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);

        $response->setStatusCode($context->status);
        if ($context->retryAfterSeconds === null) {
            $response->headers->remove('Retry-After');
        } else {
            $response->headers->set('Retry-After', (string) $context->retryAfterSeconds);
        }

        $response->headers->set(ConflictResponsePolicy::CODE_HEADER, $context->code->value);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function wantsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()
            || $request->getContentTypeFormat() === 'json'
            || $request->getPreferredFormat(null) === 'json'
            || $request->getRequestFormat(null) === 'json') {
            return true;
        }

        $path = $request->getPathInfo();
        foreach ($this->jsonPathPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Where *Try again* leads: the page itself for a safe method, else the same-host http(s) page the form was sent from, else home. */
    public static function retryUrl(Request $request): string
    {
        if ($request->isMethodSafe()) {
            return $request->getRequestUri();
        }

        $referer = (string) $request->headers->get('referer', '');
        $host    = parse_url($referer, PHP_URL_HOST);
        $scheme  = parse_url($referer, PHP_URL_SCHEME);
        if (is_string($host) && strcasecmp($host, $request->getHost()) === 0 && is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
            return $referer;
        }

        return $request->getBaseUrl() . '/';
    }

    private function html(Request $request, ConflictResponseContext $context): string
    {
        $retryUrl = self::retryUrl($request);

        if ($this->twig instanceof Environment) {
            try {
                return $this->twig->render($this->template, [
                    'status_code'   => $context->status,
                    'exception'     => null,
                    'retry_url'     => $retryUrl,
                    'retry_after'   => $context->retryAfterSeconds,
                    'partial'       => $context->committed,
                    'conflict_code' => $context->code->value,
                    'ui_kit'        => $this->twig->getLoader()->exists(self::UI_KIT_TEMPLATE),
                ]);
            } catch (Throwable $failure) {
                SafeLog::log($this->logger, 'warning', 'The conflict page template failed; answered the plain page.', SafeLog::throwable($failure));
            }
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $prefix = $context->committed ? 'conflict.partial.' : 'conflict.';

        return sprintf(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%d — %s</title></head>'
            . '<body><main data-testid="concurrency-conflict" data-conflict-code="%s"><h1>%s</h1><p>%s</p><p>%s</p><p><a href="%s" data-testid="concurrency-conflict-retry">%s</a></p></main></body></html>',
            $context->status,
            $escape($this->text($prefix . 'title')),
            $context->code->value,
            $escape($this->text($prefix . 'title')),
            $escape($context->message),
            $escape($this->text($prefix . 'hint')),
            $escape($retryUrl),
            $escape($this->text($prefix . 'retry')),
        );
    }

    /**
     * @param key-of<self::FALLBACK_TEXTS> $key
     */
    private function text(string $key): string
    {
        return $this->translator instanceof TranslatorInterface
            ? $this->translator->trans($key, [], self::TRANSLATION_DOMAIN)
            : self::FALLBACK_TEXTS[$key];
    }

    private function attempts(Throwable $failure): ?int
    {
        return ThrowableWalk::first($failure, ConcurrencyConflict::class)?->attempts;
    }

    private function hint(Throwable $failure): ?int
    {
        return ThrowableWalk::first($failure, ConcurrencyConflict::class)?->retryAfterSeconds();
    }

    private function route(Request $request): string
    {
        $route = $request->attributes->get('_route');

        return is_string($route) ? $route : '';
    }
}
