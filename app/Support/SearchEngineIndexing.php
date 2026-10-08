<?php

namespace App\Support;

use Illuminate\Http\Request;

class SearchEngineIndexing
{
    public static function shouldBlock(Request $request): bool
    {
        return ! app()->environment('production')
            || in_array(strtolower($request->getHost()), ['herrera-new.test', 'herrera.herrera.hr'], true);
    }
}
