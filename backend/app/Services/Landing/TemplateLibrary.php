<?php

namespace App\Services\Landing;

use App\Models\SystemSetting;
use App\Support\Landing\BlockSchema;
use InvalidArgumentException;

/**
 * Hellom Page templates (Fase 7.4). Each template is data — resources/landing/templates/{id}.json:
 * {id, order, name, category, badge, description, slots{key: label}, document{theme, blocks…}} — with
 * image placeholders "{{slot:key}}". Super admin sets the slot images, hides templates and changes
 * their order (SystemSetting `landing_templates`); sellers get the visible ones with images filled in.
 * An empty slot leaves the picture out (initial avatar, no banner).
 */
final class TemplateLibrary
{
    public const SETTING = 'landing_templates';

    public const CATEGORIES = ['kreator' => 'Kreator', 'bisnis' => 'Bisnis & jasa', 'kuliner' => 'Kuliner & UMKM', 'fashion' => 'Fashion & beauty', 'edukasi' => 'Edukasi', 'musik' => 'Musik', 'acara' => 'Acara & komunitas'];

    /** @var array<string, array>|null */
    private ?array $templates = null;

    /** @return array<string, array> raw templates by id, in their default order */
    public function raw(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }
        $list = [];
        foreach (glob(resource_path('landing/templates/*.json')) ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['id'], $data['document']) && preg_match('/^[a-z0-9-]{2,40}$/', (string) $data['id'])) {
                $list[$data['id']] = $data;
            }
        }
        uasort($list, fn ($a, $b) => ((int) ($a['order'] ?? 99)) <=> ((int) ($b['order'] ?? 99)));

        return $this->templates = $list;
    }

    /** @return array{order: list<string>, hidden: list<string>, images: array<string, array<string, string>>} */
    public function overrides(): array
    {
        $saved = json_decode((string) SystemSetting::get(self::SETTING, ''), true);
        $saved = is_array($saved) ? $saved : [];
        $known = array_keys($this->raw());

        return [
            'order' => array_values(array_intersect(array_map('strval', (array) ($saved['order'] ?? [])), $known)),
            'hidden' => array_values(array_intersect(array_map('strval', (array) ($saved['hidden'] ?? [])), $known)),
            'images' => is_array($saved['images'] ?? null) ? $saved['images'] : [],
        ];
    }

    /** @return list<string> template ids in display order (super admin order first, then the rest by default order) */
    public function orderedIds(): array
    {
        $order = $this->overrides()['order'];

        return array_values(array_unique(array_merge($order, array_keys($this->raw()))));
    }

    /**
     * Templates sellers can pick: visible, ordered, slot images filled, documents cleaned.
     *
     * @return list<array{id: string, name: string, category: string, category_label: string, badge: ?string, description: string, document: array}>
     */
    public function forSellers(): array
    {
        $hidden = $this->overrides()['hidden'];
        $out = [];
        foreach ($this->orderedIds() as $id) {
            if (!in_array($id, $hidden, true)) {
                $out[] = $this->summary($id) + ['document' => $this->document($id)];
            }
        }

        return $out;
    }

    /** @return list<array> every template for super admin, with its slots and current images */
    public function forAdmin(): array
    {
        $overrides = $this->overrides();

        return array_map(function (string $id) use ($overrides) {
            $template = $this->raw()[$id];
            $slots = [];
            foreach ((array) ($template['slots'] ?? []) as $key => $label) {
                $slots[] = ['key' => (string) $key, 'label' => (string) $label, 'url' => $overrides['images'][$id][$key] ?? null];
            }

            return $this->summary($id) + ['hidden' => in_array($id, $overrides['hidden'], true), 'slots' => $slots];
        }, $this->orderedIds());
    }

    /** Clean document of one template, with slot images filled in (fresh block ids). */
    public function document(string $id): array
    {
        $template = $this->raw()[$id] ?? throw new InvalidArgumentException('Template tidak ditemukan');
        $images = $this->overrides()['images'][$id] ?? [];
        $fill = function (mixed $value) use (&$fill, $images): mixed {
            if (is_array($value)) {
                return array_map($fill, $value);
            }
            if (is_string($value) && preg_match('/^\{\{slot:([a-z0-9_-]+)\}\}$/', $value, $m)) {
                return (string) ($images[$m[1]] ?? '');
            }

            return $value;
        };

        return BlockSchema::normalize($fill($template['document']));
    }

    /** @param list<string> $order @param list<string> $hidden */
    public function saveLayout(array $order, array $hidden): void
    {
        $known = array_keys($this->raw());
        $saved = $this->overrides();
        $saved['order'] = array_values(array_unique(array_intersect($order, $known)));
        $saved['hidden'] = array_values(array_unique(array_intersect($hidden, $known)));
        SystemSetting::set(self::SETTING, json_encode($saved, JSON_UNESCAPED_SLASHES));
    }

    /** Set (or clear with null) one slot image; returns the previous URL. */
    public function setImage(string $id, string $slot, ?string $url): ?string
    {
        if (!isset(($this->raw()[$id]['slots'] ?? [])[$slot])) {
            throw new InvalidArgumentException('Slot gambar tidak dikenal');
        }
        $saved = $this->overrides();
        $previous = $saved['images'][$id][$slot] ?? null;
        if ($url === null) {
            unset($saved['images'][$id][$slot]);
        } else {
            $saved['images'][$id][$slot] = $url;
        }
        SystemSetting::set(self::SETTING, json_encode($saved, JSON_UNESCAPED_SLASHES));

        return $previous;
    }

    /** @return array{id: string, name: string, category: string, category_label: string, badge: ?string, description: string} */
    private function summary(string $id): array
    {
        $t = $this->raw()[$id];
        $category = isset(self::CATEGORIES[$t['category'] ?? '']) ? $t['category'] : 'bisnis';

        return [
            'id' => $id,
            'name' => (string) ($t['name'] ?? $id),
            'category' => $category,
            'category_label' => self::CATEGORIES[$category],
            'badge' => in_array($t['badge'] ?? null, ['populer', 'baru'], true) ? $t['badge'] : null,
            'description' => (string) ($t['description'] ?? ''),
        ];
    }
}
