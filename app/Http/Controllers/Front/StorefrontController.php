<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Services\Front\StoreSettingsService;
use Illuminate\View\View;

class StorefrontController extends Controller
{
    public function __construct(
        private readonly StoreSettingsService $storeSettingsService
    ) {
    }

    public function home(): View
    {
        return view('front.desktop.home.index', [
            'storeSettings' => $this->storeSettingsService->all(),
        ]);
    }
}
