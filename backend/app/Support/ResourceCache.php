<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ResourceCache
{
    public const TTL_SECONDS = 600;

    public static function key(string $resource, string $variant = 'default'): string
    {
        $version = Cache::get('resource-cache:'.$resource.':version', '1');

        return 'resource-cache:'.$resource.':'.$version.':'.$variant;
    }

    public static function invalidate(string $resource): void
    {
        Cache::forever('resource-cache:'.$resource.':version', (string) Str::uuid());
    }
}
