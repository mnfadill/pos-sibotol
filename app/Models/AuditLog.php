<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\AppendOnly;
use App\Models\Scopes\OutletScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail entry.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $organization_id
 * @property int|null $actor_id
 * @property string|null $actor_name_snapshot
 * @property string|null $actor_role_snapshot
 * @property int|null $outlet_id
 * @property int|null $pos_terminal_id
 * @property AuditAction $action_event
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $request_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class AuditLog extends Model
{
    use AppendOnly;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'actor_id', 'actor_name_snapshot', 'actor_role_snapshot', 'outlet_id', 'pos_terminal_id',
        'action_event', 'entity_type', 'entity_id', 'old_values', 'new_values', 'metadata', 'ip_address',
        'request_id', 'created_at',
    ];

    protected static function booted(): void
    {
        // Outlet isolation, but keep org-wide events (login, etc. have outlet_id NULL) hidden from outlet users.
        static::addGlobalScope(new OutletScope);
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'action_event' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** ISO-8601 with microseconds for exact ordering / forensic use. */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s.uP');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<PosTerminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'pos_terminal_id')->withTrashed();
    }

    /**
     * @param  Builder<self>  $query
     * @param  array{
     *     date_from?: string|null, date_to?: string|null, actor_id?: int|string|null,
     *     action_events?: list<string>|null, entity_type?: string|null, entity_id?: string|null,
     *     outlet_id?: int|string|null, pos_terminal_id?: int|string|null, search?: string|null
     * }  $filters
     * @return Builder<self>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $v) => $q->where('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $v) => $q->where('created_at', '<=', $v))
            ->when($filters['actor_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('actor_id', (int) $v))
            ->when($filters['action_events'] ?? null, fn (Builder $q, array $v) => $q->whereIn('action_event', $v))
            ->when($filters['entity_type'] ?? null, fn (Builder $q, string $v) => $q->where('entity_type', $v))
            ->when($filters['entity_id'] ?? null, fn (Builder $q, string $v) => $q->where('entity_id', $v))
            ->when($filters['outlet_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('outlet_id', (int) $v))
            ->when($filters['pos_terminal_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('pos_terminal_id', (int) $v))
            ->when($filters['search'] ?? null, function (Builder $q, string $v): void {
                $like = '%'.mb_strtolower($v).'%';
                $q->where(static function (Builder $w) use ($like, $v): void {
                    $w->whereRaw('LOWER(actor_name_snapshot) LIKE ?', [$like])
                        ->orWhere('entity_id', $v)
                        ->orWhere('request_id', $v);
                });
            });
    }
}

