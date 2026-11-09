<?php

namespace App\Livewire\Admin\Integrations\Stock;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Services\Integrations\Stock\FixedSupplierAvailabilityService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class FixedAvailability extends Component
{
    public array $manufacturerIds = [];

    #[Locked]
    public array $previewIds = [];

    #[Locked]
    public ?array $preview = null;

    public string $notice = '';

    public function updatedManufacturerIds(): void
    {
        $this->preview = null;
        $this->previewIds = [];
    }

    public function preview(): void
    {
        $this->authorizeManage();
        try {
            $this->preview = app(FixedSupplierAvailabilityService::class)->run($this->manufacturerIds);
            $this->previewIds = $this->manufacturerIds;
            $this->notice = '';
        } catch (\RuntimeException $exception) {
            $this->notice = get_class($exception) === \RuntimeException::class ? $exception->getMessage() : 'Pregled nije moguće dovršiti. Pokušajte ponovno.';
        }
    }

    public function apply(): void
    {
        $this->authorizeManage();
        abort_unless($this->preview !== null && $this->previewIds === $this->manufacturerIds, 422);
        try {
            $result = app(FixedSupplierAvailabilityService::class)->run($this->previewIds, true, auth()->id());
            $this->notice = 'Fiksna raspoloživost spremljena za '.$result['products'].' artikala. Vlastita zaliha je sačuvana.';
            $this->preview = null;
        } catch (\RuntimeException $exception) {
            $this->notice = get_class($exception) === \RuntimeException::class ? $exception->getMessage() : 'Pravilo nije moguće spremiti. Pokušajte ponovno.';
        }
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.admin.integrations.stock.fixed-availability', ['manufacturers' => Manufacturer::query()->with('translations')->orderBy('code')->get()]);
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.stock.manage')), 403);
    }
}
