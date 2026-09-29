<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's ad/analytics ids for Hellom Page. Only ids that match strict patterns are
 * stored (they are printed into public pages, so free text would be script injection).
 * The Meta Conversions API token is encrypted and never sent to the browser.
 */
class LandingTrackingSetting extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    public const PATTERNS = [
        'meta_pixel_id' => '/^\d{10,20}$/',
        'ga4_id' => '/^G-[A-Z0-9]{6,12}$/',
        'google_ads_id' => '/^AW-\d{6,12}$/',
        'google_ads_label' => '/^[A-Za-z0-9_-]{4,40}$/',
        'tiktok_pixel_id' => '/^[A-Z0-9]{15,25}$/',
        'meta_test_event_code' => '/^TEST\d{3,10}$/',
    ];

    protected $fillable = ['organization_id', 'meta_pixel_id', 'meta_capi_token', 'meta_test_event_code', 'ga4_id', 'google_ads_id', 'google_ads_label', 'tiktok_pixel_id'];

    protected $hidden = ['meta_capi_token'];

    protected function casts(): array
    {
        return ['meta_capi_token' => 'encrypted'];
    }

    /**
     * Ids that may be printed into public pages (re-checked against the patterns).
     *
     * @return array<string, string>
     */
    public function publicIds(): array
    {
        $ids = [];
        foreach (['meta_pixel_id', 'ga4_id', 'google_ads_id', 'google_ads_label', 'tiktok_pixel_id'] as $key) {
            $value = (string) $this->{$key};
            if ($value !== '' && preg_match(self::PATTERNS[$key], $value)) {
                $ids[$key] = $value;
            }
        }

        return $ids;
    }
}
