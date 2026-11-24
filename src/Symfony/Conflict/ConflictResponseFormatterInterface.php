<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Conflict;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a bundle or host answer a conflict on its own requests in its own shape (a REST API's `{err, ECODE}`, CORS
 * headers…), recognising them by its own means (a request attribute its listener set, a controller class) rather than
 * the responder matching route names. The first formatter (highest tag priority) that returns a response wins; null
 * falls through to the next one and finally to the default JSON or HTML answer. The context carries the conflict code
 * (`$context->contractCode`: ConflictCode::RetrySafe `CONFLICT_001` or ConflictCode::Committed `CONFLICT_002`); a formatter
 * puts it in its own shape (REQ-RUNTIME-003 rule 6). Whatever it returns, the responder sets
 * the policy's status, `Retry-After` and `Cache-Control: no-store`. Autoconfigured with the
 * `nowo_transaction_doctrine.conflict_response_formatter` tag. Implementations must be stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface ConflictResponseFormatterInterface
{
    public const TAG = 'nowo_transaction_doctrine.conflict_response_formatter';

    public function format(Request $request, ConflictResponseContext $context): ?Response;
}
