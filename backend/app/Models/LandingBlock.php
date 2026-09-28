<?php

namespace App\Models;

use App\Support\SafeHtml;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'landing_page_id',
        'block_key',
        'block_type',
        'sort_order',
        'is_visible',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'content' => 'array',
        ];
    }

    /** Keys that deliver a paid product; never sent to the public page before payment. */
    public const SECRET_CONTENT_KEYS = ['fileUrl', 'downloadUrl', 'file_url', 'download_url', 'driveUrl', 'accessUrl', 'deliveryUrl'];

    protected static function booted(): void
    {
        // Seller HTML is always stored sanitised (see App\Support\SafeHtml).
        static::saving(function (LandingBlock $block): void {
            $content = $block->content;
            if (is_array($content) && isset($content['html']) && is_string($content['html'])) {
                $content['html'] = SafeHtml::clean($content['html']);
                $block->content = $content;
            }
        });
    }

    /** A block whose file/link may only be delivered after a paid order. */
    public function isPaidProduct(): bool
    {
        $content = is_array($this->content) ? $this->content : [];

        return match ((string) $this->block_type) {
            'pdf' => (string) ($content['accessType'] ?? 'free') === 'paid',
            'product' => true,
            default => false,
        };
    }

    /**
     * Content safe for anonymous visitors: delivery links of paid products removed,
     * HTML re-sanitised (covers rows stored before sanitising existed).
     *
     * @return array<string, mixed>
     */
    public function publicContent(): array
    {
        $content = is_array($this->content) ? $this->content : [];
        if ($this->isPaidProduct()) {
            foreach (self::SECRET_CONTENT_KEYS as $key) {
                unset($content[$key]);
            }
        }
        if (isset($content['html']) && is_string($content['html'])) {
            $content['html'] = SafeHtml::clean($content['html']);
        }

        return $content;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(OrganizationLandingPage::class, 'landing_page_id');
    }
}
