<?php

use App\Http\Controllers\Api\V1\Wholesale\CategoryController;
use App\Http\Controllers\Api\V1\Wholesale\ManufacturerController;
use App\Http\Controllers\Api\V1\Wholesale\ProductController;
use App\Http\Controllers\Api\V1\Wholesale\ProductPriceController;
use App\Http\Controllers\Api\V1\Wholesale\ProductQuantityController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/wholesale')
    ->middleware(['catalog.feature:catalog_use_api', 'auth:sanctum', 'api.user.enabled', 'throttle:wholesale-api'])
    ->group(function (): void {
        Route::get('products', [ProductController::class, 'index'])
            ->middleware('ability:products.read,wholesale.read');
        Route::get('products/{identifier}', [ProductController::class, 'show'])
            ->middleware('ability:products.read,wholesale.read');

        Route::get('product_prices', [ProductPriceController::class, 'index'])
            ->middleware('ability:products.read,products.prices.read,wholesale.read');
        Route::get('product_quantities', [ProductQuantityController::class, 'index'])
            ->middleware('ability:products.read,products.quantities.read,wholesale.read');

        Route::get('manufacturers', [ManufacturerController::class, 'index'])
            ->middleware('ability:manufacturers.read,wholesale.read');
        Route::get('manufacturers/{identifier}', [ManufacturerController::class, 'show'])
            ->middleware('ability:manufacturers.read,wholesale.read');

        Route::get('categories', [CategoryController::class, 'index'])
            ->middleware('ability:categories.read,wholesale.read');
        Route::get('categories/{identifier}', [CategoryController::class, 'show'])
            ->middleware('ability:categories.read,wholesale.read');
    });
