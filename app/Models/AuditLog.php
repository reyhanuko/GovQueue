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
