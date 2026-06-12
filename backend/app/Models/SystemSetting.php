<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class SystemSetting extends Model
{
    use HasFactory;

    protected $table = 'system_settings';
    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null)
    {
        $row = self::query()->where('key', $key)->first();
        if (!$row) {
            return $default;
        }

        return $row->value;
    }

    public static function set(string $key, $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /** @return array<string,string> */
    public static function allAsArray(): array
    {
        return self::query()->pluck('value', 'key')->all();
    }
}
