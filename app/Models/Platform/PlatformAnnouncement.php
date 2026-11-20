<?php
namespace App\Models\Platform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAnnouncement extends Model
{
    public const SYSTEM_TYPES = ['info', 'warning', 'critical'];
    public const TYPE_BANNER = 'banner';

    protected $fillable = ['title', 'body', 'cta_label', 'cta_url', 'type', 'target', 'target_value', 'publish_at', 'expires_at', 'send_email', 'created_by_admin_id'];

    protected function casts(): array
    {
        return ['publish_at' => 'datetime', 'expires_at' => 'datetime', 'send_email' => 'boolean'];
    }

    /** Published and not yet expired. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeOfType(Builder $query, string $type): Builder { return $query->where('type', $type); }

    public function createdBy(): BelongsTo { return $this->belongsTo(PlatformAdmin::class, 'created_by_admin_id'); }
}
