<?php

namespace App\Services\Audit;

use App\Http\Middleware\AssignCorrelationId;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single writer of `audit_events`.
 *
 * This does NOT replace ActivityLogService / UserActivityLogService -- those
 * keep serving the two existing admin feeds unchanged. Sensitive actions write
 * to both: the feed keeps its shape, the audit trail gets actor + resource +
 * result + correlation id.
 *
 * Like the two existing loggers this swallows throwables: an audit write must
 * never be the reason a payment or a verification fails. It does, however, log
 * the failure at error level so a silently missing trail is discoverable.
 */
class AuditLogger
{
    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const RESULT_DENIED = 'denied';

    /** Always redacted from $context, at any nesting depth. */
    private const REDACT_KEYS = [
        'national_code', 'nationalcode', 'national_id',
        'pan', 'card_number', 'cardnumber',
        'iban', 'sheba', 'sayad_id', 'sayad_id_encrypted', 'sayad_id_hash',
        'otp', 'code', 'password', 'secret', 'token', 'api_key',
    ];

    public static function log(
        string $action,
        string $resourceType,
        int|string|null $resourceId = null,
        string $result = self::RESULT_SUCCESS,
        array $context = [],
        ?Model $actor = null,
    ): void {
        try {
            [$actorType, $actorId, $actorLabel] = self::resolveActor($actor);

            AuditEvent::create([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_label' => $actorLabel,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'result' => $result,
                'correlation_id' => self::correlationId(),
                'request_id' => self::requestId(),
                'ip_address' => self::safeRequestValue(fn () => request()->ip()),
                'user_agent' => Str::limit(
                    (string) self::safeRequestValue(fn () => request()->userAgent()),
                    1000,
                    ''
                ) ?: null,
                'context' => self::redact($context),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AuditLogger failed to persist an audit event', [
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stable for the lifetime of one request (or one queued job that was given
     * one). Falls back to a fresh uuid rather than null so no audit row is ever
     * orphaned from its siblings.
     */
    public static function correlationId(): string
    {
        if (app()->bound(AssignCorrelationId::CONTAINER_KEY)) {
            return app(AssignCorrelationId::CONTAINER_KEY);
        }

        $generated = (string) Str::uuid();
        app()->instance(AssignCorrelationId::CONTAINER_KEY, $generated);

        return $generated;
    }

    public static function requestId(): ?string
    {
        return app()->bound(AssignCorrelationId::REQUEST_KEY)
            ? app(AssignCorrelationId::REQUEST_KEY)
            : null;
    }

    /** Bind a correlation id explicitly -- used by queued jobs and commands. */
    public static function useCorrelationId(string $correlationId): void
    {
        app()->instance(AssignCorrelationId::CONTAINER_KEY, $correlationId);
    }

    /** @return array{0: string, 1: ?int, 2: ?string} */
    private static function resolveActor(?Model $actor): array
    {
        $actor ??= auth()->user();

        if (! $actor instanceof User) {
            return ['system', null, null];
        }

        // config('rental.admin.roles') is the single source of truth for which
        // Spatie roles count as staff -- shared with EnsureIsAdmin and
        // AdminLoginController so the three can never drift.
        $isAdmin = method_exists($actor, 'hasAnyRole')
            && $actor->hasAnyRole(config('rental.admin.roles', []));

        return [$isAdmin ? 'admin' : 'user', $actor->id, $actor->mobile];
    }

    private static function safeRequestValue(callable $fn): ?string
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redact($value);

                continue;
            }

            if (in_array(strtolower((string) $key), self::REDACT_KEYS, true)) {
                $context[$key] = '[redacted]';
            }
        }

        return $context;
    }
}
