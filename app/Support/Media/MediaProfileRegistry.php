<?php

namespace App\Support\Media;

class MediaProfileRegistry
{
    /**
     * @return array<string, mixed>
     */
    public static function forModel(string $modelClass): array
    {
        $models = self::models();

        return (array) ($models[$modelClass] ?? []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function collectionsForModel(string $modelClass): array
    {
        $modelConfig = self::forModel($modelClass);

        return (array) ($modelConfig['collections'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function collectionForModel(string $modelClass, string $collectionName): array
    {
        $collections = self::collectionsForModel($modelClass);

        return (array) ($collections[$collectionName] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function preset(string $presetName): array
    {
        $presets = (array) config('media_profiles.presets', []);

        return (array) ($presets[$presetName] ?? []);
    }

    /**
     * @return array<string, array{preset: array<string,mixed>, collections: array<int,string>}>
     */
    public static function conversionMapForModel(string $modelClass): array
    {
        $map = [];

        foreach (self::collectionsForModel($modelClass) as $collectionName => $collectionConfig) {
            $conversionNames = (array) ($collectionConfig['conversions'] ?? []);

            foreach ($conversionNames as $conversionName) {
                $conversionName = (string) $conversionName;
                if ($conversionName === '') {
                    continue;
                }

                if (! isset($map[$conversionName])) {
                    $map[$conversionName] = [
                        'preset' => self::preset($conversionName),
                        'collections' => [],
                    ];
                }

                $map[$conversionName]['collections'][] = (string) $collectionName;
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public static function modelClasses(): array
    {
        return array_keys(self::models());
    }

    private static function models(): array
    {
        return (array) config('media_profiles.models', []);
    }
}
