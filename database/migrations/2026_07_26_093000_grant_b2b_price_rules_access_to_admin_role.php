<?php

use Illuminate\Database\Migrations\Migration;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

return new class extends Migration
{
    private const ABILITIES = [
        'catalog.b2b_prices.view' => 'View B2B price lists',
        'catalog.b2b_prices.create' => 'Create B2B price lists',
        'catalog.b2b_prices.update' => 'Update B2B price lists',
        'catalog.b2b_prices.delete' => 'Delete B2B price lists',
    ];

    public function up(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['title' => 'Administrator'],
        );

        foreach (self::ABILITIES as $name => $title) {
            $ability = Ability::query()
                ->where('name', $name)
                ->whereNull('entity_id')
                ->whereNull('entity_type')
                ->first() ?? new Ability;
            $ability->name = $name;
            $ability->title = $title;
            $ability->options = ['group' => 'catalog.b2b_prices'];
            $ability->save();

            Bouncer::unforbid($role)->to($ability);
            Bouncer::allow($role)->to($ability);
        }

        Bouncer::refresh($role);
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'admin')->first();

        if (! $role) {
            return;
        }

        Ability::query()
            ->whereIn('name', array_keys(self::ABILITIES))
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->get()
            ->each(function (Ability $ability) use ($role): void {
                Bouncer::disallow($role)->to($ability);
            });

        Bouncer::refresh($role);
    }
};
