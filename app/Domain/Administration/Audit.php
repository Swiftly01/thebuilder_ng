<?php

declare(strict_types=1);

namespace App\Domain\Administration;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class Audit
{
    /** @param array<array-key, mixed> $context */
    public function record(string $event, ?User $actor = null, ?Model $subject = null, array $context = []): void
    {
        AuditEvent::create(['event' => $event, 'actor_id' => $actor?->id, 'subject_type' => $subject?->getTable(), 'subject_id' => $subject?->getKey(), 'context' => $this->redact($context), 'request_id' => request()->attributes->get('request_id'), 'ip_hash' => request()->ip() ? hash_hmac('sha256', request()->ip(), (string) config('app.key')) : null]);
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed> */
    private function redact(array $values): array
    {
        foreach ($values as $k => $v) {
            if (preg_match('/password|secret|token|email|phone|recovery/i', (string) $k)) {
                $values[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $values[$k] = $this->redact($v);
            }
        }

        return $values;
    }
}
