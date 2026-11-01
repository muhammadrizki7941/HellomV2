<?php

namespace App\Support\Pos;

use App\Models\Organization;
use LogicException;

final class BusinessProfile
{
    /**
     * @return array{
     *   organization_id:int,
     *   business_type:string,
     *   business_type_label:string,
     *   onboarding_completed:bool,
     *   business_types:list<array{key:string,label:string,examples:string}>,
     *   module_presets:array<string,list<string>>,
     *   enabled_modules:list<string>,
     *   modules:list<array{key:string,label:string,description:string,enabled:bool}>
     * }
     */
    public static function present(Organization $organization): array
    {
        $types = config('pos_business.types', []);
        $modules = config('pos_business.modules', []);
        $type = (string) $organization->business_type;
        if (!isset($types[$type])) {
            throw new LogicException("Jenis usaha POS tidak dikenal: {$type}");
        }

        $enabledModules = self::enabledModules($organization);

        return [
            'organization_id' => (int) $organization->id,
            'business_type' => $type,
            'business_type_label' => $types[$type]['label'],
            'onboarding_completed' => $organization->pos_onboarding_completed_at !== null,
            'business_types' => collect($types)->map(
                fn (array $definition, string $key) => [
                    'key' => $key,
                    'label' => $definition['label'],
                    'examples' => $definition['examples'],
                ]
            )->values()->all(),
            'module_presets' => config('pos_business.presets', []),
            'enabled_modules' => $enabledModules,
            'modules' => collect($modules)->map(
                fn (array $definition, string $key) => [
                    'key' => $key,
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'enabled' => in_array($key, $enabledModules, true),
                ]
            )->values()->all(),
        ];
    }

    /** @return list<string> */
    public static function enabledModules(Organization $organization): array
    {
        $modules = config('pos_business.modules', []);
        $enabled = $organization->enabled_modules;
        if ($enabled === null) {
            $enabled = self::preset((string) $organization->business_type);
        }

        if (!is_array($enabled)) {
            throw new LogicException('Daftar modul POS organisasi harus berupa array.');
        }

        foreach ($enabled as $module) {
            if (!is_string($module) || !array_key_exists($module, $modules)) {
                throw new LogicException('Daftar modul POS organisasi memuat modul yang tidak dikenal.');
            }
        }

        return array_values(array_unique($enabled));
    }

    public static function hasModule(Organization $organization, string $module): bool
    {
        if (!array_key_exists($module, config('pos_business.modules', []))) {
            throw new LogicException("Modul POS tidak terdaftar: {$module}");
        }

        return in_array($module, self::enabledModules($organization), true);
    }

    /** @return list<string> */
    public static function preset(string $businessType): array
    {
        $presets = config('pos_business.presets', []);
        if (!array_key_exists($businessType, $presets)) {
            throw new LogicException("Preset jenis usaha POS tidak ditemukan: {$businessType}");
        }

        return $presets[$businessType];
    }
}
