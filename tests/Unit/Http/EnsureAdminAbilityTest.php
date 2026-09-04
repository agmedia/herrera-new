<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\EnsureAdminAbility;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class EnsureAdminAbilityTest extends TestCase
{
    #[DataProvider('readonlyLivewireMethods')]
    public function test_pagination_and_refresh_calls_are_classified_as_read_only(string $method): void
    {
        $reflection = new ReflectionMethod(EnsureAdminAbility::class, 'isReadonlyLivewireMethod');

        $this->assertTrue($reflection->invoke(new EnsureAdminAbility, strtolower($method)));
    }

    /** @return array<string, array{string}> */
    public static function readonlyLivewireMethods(): array
    {
        return [
            'refresh' => ['$refresh'],
            'goto page' => ['gotoPage'],
            'next page' => ['nextPage'],
            'previous page' => ['previousPage'],
            'set page' => ['setPage'],
        ];
    }
}
