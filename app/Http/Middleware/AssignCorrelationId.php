<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request one id that ties together its log lines, its
 * audit_events rows and its provider calls.
 *
 * An inbound X-Correlation-Id is honoured only when it is a well-formed UUID,
 * so a caller cannot inject arbitrary text into the audit trail.
 */
class AssignCorrelationId
{
    public const CONTAINER_KEY = 'audit.correlation_id';

    public const REQUEST_KEY = 'audit.request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $inbound = $request->headers->get('X-Correlation-Id');
        $correlationId = ($inbound && Str::isUuid($inbound)) ? $inbound : (string) Str::uuid();
        $requestId = (string) Str::uuid();

        app()->instance(self::CONTAINER_KEY, $correlationId);
        app()->instance(self::REQUEST_KEY, $requestId);

        Log::withContext(['correlation_id' => $correlationId, 'request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
