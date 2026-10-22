<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'payload',
        'ip_address',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The record this entry is about, as a model where one still exists.
     *
     * Used to name people instead of printing "User #17", which tells an
     * administrator nothing they can act on.
     */
    public function subject(): ?Model
    {
        if (! $this->subject_type || ! $this->subject_id) {
            return null;
        }

        $class = 'App\\Models\\'.$this->subject_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::find($this->subject_id);
    }

    /**
     * How the subject should be named on screen.
     *
     * A user is named. Anything else keeps its class and id, because an
     * appointment #42 is a legitimate way to point at a record and "Appointment"
     * on its own is not.
     */
    public function subjectLabel(): string
    {
        // Only User subjects are looked up, because those are the ones where a
        // number tells the reader nothing ("User #17"). Looking up every
        // subject type would put one query per row behind this screen.
        if ($this->subject_type === 'User' && $this->subject_id) {
            $name = User::whereKey($this->subject_id)->value('name');

            if ($name) {
                return $name;
            }
        }

        if ($this->subject_type) {
            return $this->subject_type.' #'.$this->subject_id;
        }

        return '—';
    }

    /**
     * The payload as readable lines, for the Details column.
     *
     * before/after pairs are the common shape and read as noise when printed as
     * JSON, so they collapse to "field: old -> new" and only changed fields are
     * shown. Everything else becomes "key: value". Raw JSON stays available for
     * anything this does not recognise.
     *
     * @return array<int, string>
     */
    public function payloadSummary(): array
    {
        $payload = $this->payload;

        if (! is_array($payload) || $payload === []) {
            return [];
        }

        $after = is_array($payload['after'] ?? null) ? $payload['after'] : null;

        if ($after !== null) {
            $before = is_array($payload['before'] ?? null) ? $payload['before'] : [];

            $lines = [];

            foreach ($after as $key => $value) {
                $old = $before[$key] ?? null;

                if ($old !== null && $old === $value) {
                    continue;
                }

                $lines[] = $old === null
                    ? $this->labelise($key).': '.$this->renderValue($value)
                    : $this->labelise($key).': '.$this->renderValue($old).' -> '.$this->renderValue($value);
            }

            if ($lines !== []) {
                return $lines;
            }
        }

        $lines = [];

        foreach ($payload as $key => $value) {
            $lines[] = is_array($value)
                ? $this->labelise($key).': '.$this->renderValue($value)
                : $this->labelise($key).': '.$this->renderValue($value);
        }

        return $lines;
    }

    /**
     * The action as a sentence, for a summary line.
     *
     * The stored key is a stable identifier and belongs in the audit trail's
     * filter and its raw view. Printing it where somebody is skimming for
     * "did my change take effect" showed them "appointment.rescheduled" and
     * "time_slots.regenerated", which are code, not English.
     *
     * An unrecognised key is humanised rather than hidden: a new action should
     * still say something true, and the filter on the audit screen matches the
     * raw key either way.
     */
    /**
     * Every action the application writes, in words.
     *
     * A constant rather than a local array, because two screens need it: the
     * dashboard's "Recent changes" and the audit trail's own list and filter.
     * The audit trail's filter used to offer "auth.login (3)" and the list
     * printed "auth.login" - a key, in a place a person reads.
     *
     * Held to exactly the set of actions the application writes, by
     * AuditActionSentenceTest, which reads the source to find them.
     *
     * @var array<string, string>
     */
    public const ACTION_SENTENCES = [
        'appointment.booked' => 'Booked an appointment',
        'appointment.rescheduled' => 'Moved an appointment',
        'appointment.cancelled' => 'Cancelled an appointment',
        'appointment.confirmed' => 'Confirmed an appointment',
        'appointment.walk_in_created' => 'Added a walk-in',
        'appointment.priority_verified' => 'Verified a priority claim',
        'appointment.priority_refused' => 'Refused a priority claim',
        'appointment.checked_in' => 'Checked a client in',
        'appointment.serving' => 'Started serving a client',
        'appointment.completed' => 'Completed an appointment',
        'appointment.not_completed' => 'Recorded an appointment as not completed',
        'appointment.no_show' => 'Recorded a no-show',
        'appointment.skipped' => 'Skipped a client',
        'auth.login' => 'Signed in',
        'auth.login_failed' => 'Failed to sign in',
        'auth.logout' => 'Signed out',
        'auth.password_reset_requested' => 'Asked for a password reset email',
        'auth.password_reset' => 'Reset a password with an email link',
        'client.registered' => 'Created an account',
        'holiday.created' => 'Declared a holiday',
        'holiday.deleted' => 'Removed a holiday',
        'holiday.seeded' => 'Added the regular holidays',
        'service.created' => 'Added a service',
        'service.updated' => 'Changed a service',
        'service.deleted' => 'Removed a service',
        'slot_template.created' => 'Added a slot template',
        'slot_template.updated' => 'Changed a slot template',
        'slot_template.deleted' => 'Removed a slot template',
        'time_slot.updated' => 'Changed a slot',
        'time_slot.deleted' => 'Removed a slot',
        'time_slots.regenerated' => 'Rebuilt slots',
        'time_slots.bulk_cleared' => 'Cleared future unbooked slots',
        'user.created' => 'Added a user account',
        'user.updated' => 'Changed a user account',
        'user.deleted' => 'Removed a user account',
        'user.activated' => 'Activated a user account',
        'user.deactivated' => 'Deactivated a user account',
        'user.password_reset' => 'Reset a password for a user',
        'user.password_changed' => 'Changed a password',
        'window.created' => 'Added a service window',
        'window.updated' => 'Changed a service window',
        'window.deleted' => 'Removed a service window',
    ];

    /**
     * The sentence for an action, whether or not it is on a record.
     *
     * Used by the audit trail's filter, which lists action keys rather than
     * records and so cannot ask a record for its own sentence.
     */
    public static function sentenceFor(string $action): string
    {
        return self::ACTION_SENTENCES[$action]
            ?? ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    /**
     * What this record says happened, in words.
     *
     * An unrecognised key is humanised rather than hidden: a new action should
     * still say something true, and the filter on the audit screen matches the
     * raw key either way.
     */
    public function actionSentence(): string
    {
        return self::sentenceFor((string) $this->action);
    }

    private function labelise(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private function renderValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            $value === true => 'yes',
            $value === false => 'no',
            is_array($value) => collect($value)
                ->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v))
                ->implode(', '),
            default => (string) $value,
        };
    }

    /** Write an audit entry. Never throws - auditing must not break the request. */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?array $payload = null,
        ?int $userId = null,
    ): void {
        try {
            static::create([
                'user_id' => $userId ?? auth()->id(),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'payload' => $payload,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
