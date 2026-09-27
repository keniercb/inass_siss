<?php

declare(strict_types=1);

namespace App\Modules\Security\Presentation\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for the activity trail (RF-AUD-001, architecture doc
 * section 11): every request carries a request_id that the
 * AuditTrailObserver stamps on each activity row it writes, so the
 * full write story of one HTTP interaction stays groupable. A caller
 * may forward its own X-Request-Id; anything malformed is replaced
 * with a fresh UUID instead of being trusted.
 */
final class AssignRequestId
{
    private const int MAX_LENGTH = 100;

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-Id');

        if (! is_string($id) || $id === '' || strlen($id) > self::MAX_LENGTH) {
            $id = Str::uuid()->toString();
        }

        $request->attributes->set('request_id', $id);

        return $next($request);
    }
}
