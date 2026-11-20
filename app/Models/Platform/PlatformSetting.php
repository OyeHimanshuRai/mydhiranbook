<?php
namespace App\Models\Platform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    protected $table = 'platform_settings';
    protected $fillable = ['key', 'value'];

    /** Read a setting (cached 5 minutes). */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("platform_setting:{$key}", 300, fn () => static::where('key', $key)->value('value')) ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget("platform_setting:{$key}");
    }

    public static function bool(string $key, bool $default = true): bool
    {
        $raw = static::get($key);
        return $raw === null ? $default : filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
