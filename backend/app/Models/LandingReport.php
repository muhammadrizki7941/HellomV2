<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A visitor's report ("Laporkan") about a seller page or product, reviewed by super admin. */
class LandingReport extends Model
{
    public const REASONS = [
        'scam' => 'Penipuan / produk tidak dikirim',
        'prohibited' => 'Produk terlarang',
        'copyright' => 'Melanggar hak cipta',
        'adult' => 'Konten dewasa / kekerasan',
        'other' => 'Lainnya',
    ];

    public const STATUSES = ['open', 'reviewing', 'resolved', 'dismissed'];

    protected $fillable = [
        'organization_id', 'landing_page_id', 'product_id', 'reason', 'description', 'reporter_email', 'reporter_ip', 'page_url',
        'status', 'resolution_note', 'handled_by_user_id', 'handled_at',
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(LandingProduct::class, 'product_id')->withTrashed();
    }
}
