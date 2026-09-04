<?php

namespace App\Services\Front;

class DeviceViewResolver
{
    public function variant(?string $userAgent): string
    {
        if (! is_string($userAgent) || trim($userAgent) === '') {
            return 'desktop';
        }

        $ua = strtolower($userAgent);

        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return 'desktop';
        }

        if (str_contains($ua, 'android') && ! str_contains($ua, 'mobile')) {
            return 'desktop';
        }

        $mobileTokens = [
            'iphone',
            'ipod',
            'android',
            'blackberry',
            'bb10',
            'windows phone',
            'mobile',
            'webos',
            'opera mini',
            'opera mobi',
        ];

        foreach ($mobileTokens as $token) {
            if (str_contains($ua, $token)) {
                return 'mobile';
            }
        }

        return 'desktop';
    }
}

