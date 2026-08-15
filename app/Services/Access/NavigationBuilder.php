<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\CompanyModuleSetting;
use App\Models\Module;
use App\Models\User;

class NavigationBuilder
{
    /** @return array<int, array{group: string, items: array<int, array{key: string, label: string, icon: string, href: string}>}> */
    public function for(User $user): array
    {
        $disabled = CompanyModuleSetting::query()
            ->where('enabled', false)
            ->pluck('module_key')
            ->all();

        $groups = [];

        foreach (Module::query()->where('is_active', true)->orderBy('sort')->get() as $module) {
            if (! $module->is_core && in_array($module->key, $disabled, true)) {
                continue;
            }

            if ($module->permission !== null && ! $user->can($module->permission)) {
                continue;
            }

            if ($module->route === null || ! app('router')->has($module->route)) {
                continue;
            }

            $group = $module->nav_group ?? 'Other';

            $groups[$group][] = [
                'key' => $module->key,
                'label' => $module->name,
                'icon' => $module->icon ?? 'bi-dot',
                'href' => route($module->route, absolute: false),
            ];
        }

        return array_map(
            static fn (string $group, array $items): array => ['group' => $group, 'items' => $items],
            array_keys($groups),
            $groups
        );
    }
}
