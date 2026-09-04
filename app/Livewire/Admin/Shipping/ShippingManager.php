<?php

namespace App\Livewire\Admin\Shipping;

use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\CroatianIslandDestinationClassifier;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class ShippingManager extends Component
{
    use WithPagination;

    private const BOX_NOW_OAUTH_CLIENT_SECRET_ENCRYPTED_KEY = 'boxnow_oauth_client_secret_encrypted';

    public string $search = '';

    public int $returnPage = 1;

    public ?int $editingId = null;

    public bool $editPage = false;

    public bool $createPage = false;

    public bool $boxNowOauthClientSecretStored = false;

    public string $islandPolicy = CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rates = [];

    public function mount(bool $editPage = false, ?int $recordId = null, bool $createPage = false): void
    {
        abort_if($editPage && $createPage, 404);

        $this->editPage = $editPage;
        $this->createPage = $createPage;
        $this->search = trim((string) request()->query('search', ''));
        $this->returnPage = max(1, (int) request()->query('page', 1));

        $storedPolicy = (string) app(SystemSettingsService::class)->get(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            config('termol_shipping.islands.default_policy', CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY),
        );
        $this->islandPolicy = in_array($storedPolicy, CroatianIslandDestinationClassifier::validPolicies(), true)
            ? $storedPolicy
            : CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY;

        $this->resetForm();

        $requestedId = $recordId ?? 0;
        if ($this->editPage) {
            abort_unless($requestedId > 0, 404);
        }

        if ($requestedId > 0) {
            $this->edit($requestedId);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
    }

    public function saveIslandPolicy(): void
    {
        $validated = $this->validate([
            'islandPolicy' => ['required', Rule::in(CroatianIslandDestinationClassifier::validPolicies())],
        ]);

        app(SystemSettingsService::class)->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            (string) $validated['islandPolicy'],
        );

        $this->dispatch(
            'notify',
            type: 'success',
            message: __('Pravilo za otočnu dostavu je spremljeno.'),
        );
    }

    public function edit(int $id): void
    {
        $method = ShippingMethod::query()->with('rates')->findOrFail($id);
        $settings = is_array($method->settings) ? $method->settings : [];

        $this->editingId = (int) $method->id;
        $this->form = [
            'code' => (string) $method->code,
            'name' => (string) $method->name,
            'carrier' => (string) ($method->carrier ?: 'manual'),
            'service_type' => (string) ($method->service_type ?: 'home_delivery'),
            'pricing_type' => (string) ($method->pricing_type ?: 'flat'),
            'geo_zone_id' => $method->geo_zone_id,
            'description' => (string) ($method->description ?? ''),
            'price' => $method->price,
            'free_over' => $method->free_over,
            'min_subtotal' => $method->min_subtotal,
            'max_subtotal' => $method->max_subtotal,
            'min_weight_kg' => $method->min_weight_kg,
            'max_weight_kg' => $method->max_weight_kg,
            'max_length_cm' => $method->max_length_cm,
            'max_width_cm' => $method->max_width_cm,
            'max_height_cm' => $method->max_height_cm,
            'allows_fragile' => (bool) $method->allows_fragile,
            'allows_oversized' => (bool) $method->allows_oversized,
            'allows_heavy' => (bool) $method->allows_heavy,
            'fragile_surcharge' => $method->fragile_surcharge,
            'oversized_surcharge' => $method->oversized_surcharge,
            'heavy_surcharge' => $method->heavy_surcharge,
            'missing_measurements_policy' => (string) ($method->missing_measurements_policy ?: 'allow'),
            'boxnow_partner_id' => (string) ($settings['boxnow_partner_id'] ?? ''),
            'boxnow_api_url' => (string) ($settings['boxnow_api_url'] ?? ''),
            'boxnow_oauth_client_id' => (string) ($settings['boxnow_oauth_client_id'] ?? ''),
            'boxnow_oauth_client_secret' => '',
            'boxnow_warehouse_id' => (string) ($settings['boxnow_warehouse_id'] ?? ''),
            'is_active' => (bool) $method->is_active,
            'sort_order' => (int) $method->sort_order,
        ];
        $this->boxNowOauthClientSecretStored = trim((string) ($settings[self::BOX_NOW_OAUTH_CLIENT_SECRET_ENCRYPTED_KEY] ?? '')) !== '';
        $this->rates = $method->rates
            ->map(fn ($rate): array => [
                'id' => (int) $rate->id,
                'min_weight_kg' => $rate->min_weight_kg,
                'max_weight_kg' => $rate->max_weight_kg,
                'price' => $rate->price,
                'sort_order' => (int) $rate->sort_order,
            ])
            ->values()
            ->all();

        $this->resetValidation();
    }

    public function addRate(): void
    {
        $lastMaximum = collect($this->rates)
            ->pluck('max_weight_kg')
            ->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): float => (float) $value)
            ->max();

        $this->rates[] = [
            'id' => null,
            'min_weight_kg' => $lastMaximum !== null ? $lastMaximum + 0.001 : 0,
            'max_weight_kg' => null,
            'price' => 0,
            'sort_order' => count($this->rates),
        ];
    }

    public function removeRate(int $index): void
    {
        if (! array_key_exists($index, $this->rates)) {
            return;
        }

        unset($this->rates[$index]);
        $this->rates = array_values($this->rates);
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $form = $validated['form'];
        $rates = array_values($validated['rates'] ?? []);

        if (($form['pricing_type'] ?? 'flat') === 'weight_tiers' && $rates === []) {
            $this->addError('rates', __('Dodajte barem jedan raspon težine.'));

            return;
        }

        if (! $this->formRangesAreValid($form)) {
            return;
        }

        if (! $this->ratesAreValid($rates)) {
            return;
        }

        $isBoxNow = $this->formIsBoxNow($form);

        if (
            $isBoxNow
            && (bool) ($form['is_active'] ?? false)
            && ! $this->boxNowFormIsReady($form)
        ) {
            return;
        }

        DB::transaction(function () use ($form, $isBoxNow, $rates): void {
            $method = $this->editingId
                ? ShippingMethod::query()->lockForUpdate()->findOrFail($this->editingId)
                : new ShippingMethod;

            $settings = is_array($method->settings) ? $method->settings : [];
            if ($isBoxNow) {
                foreach (['boxnow_partner_id', 'boxnow_api_url', 'boxnow_oauth_client_id', 'boxnow_warehouse_id'] as $key) {
                    $settings[$key] = trim((string) ($form[$key] ?? ''));
                }

                $clientSecret = trim((string) ($form['boxnow_oauth_client_secret'] ?? ''));
                if ($clientSecret !== '') {
                    $settings[self::BOX_NOW_OAUTH_CLIENT_SECRET_ENCRYPTED_KEY] = Crypt::encryptString($clientSecret);
                }
            } else {
                foreach ([
                    'boxnow_partner_id',
                    'boxnow_api_url',
                    'boxnow_oauth_client_id',
                    self::BOX_NOW_OAUTH_CLIENT_SECRET_ENCRYPTED_KEY,
                    'boxnow_warehouse_id',
                ] as $key) {
                    unset($settings[$key]);
                }
            }

            $method->fill([
                'code' => strtolower(trim((string) $form['code'])),
                'name' => trim((string) $form['name']),
                'carrier' => $isBoxNow ? 'boxnow' : (string) $form['carrier'],
                'service_type' => (string) $form['service_type'],
                'pricing_type' => (string) $form['pricing_type'],
                'geo_zone_id' => $form['geo_zone_id'] ?: null,
                'description' => trim((string) ($form['description'] ?? '')) ?: null,
                'price' => $this->numberOrZero($form['price'] ?? 0),
                'free_over' => $this->nullableNumber($form['free_over'] ?? null),
                'min_subtotal' => $this->nullableNumber($form['min_subtotal'] ?? null),
                'max_subtotal' => $this->nullableNumber($form['max_subtotal'] ?? null),
                'min_weight_kg' => $this->nullableNumber($form['min_weight_kg'] ?? null),
                'max_weight_kg' => $this->nullableNumber($form['max_weight_kg'] ?? null),
                'max_length_cm' => $this->nullableNumber($form['max_length_cm'] ?? null),
                'max_width_cm' => $this->nullableNumber($form['max_width_cm'] ?? null),
                'max_height_cm' => $this->nullableNumber($form['max_height_cm'] ?? null),
                'allows_fragile' => (bool) ($form['allows_fragile'] ?? false),
                'allows_oversized' => (bool) ($form['allows_oversized'] ?? false),
                'allows_heavy' => (bool) ($form['allows_heavy'] ?? false),
                'fragile_surcharge' => $this->numberOrZero($form['fragile_surcharge'] ?? 0),
                'oversized_surcharge' => $this->numberOrZero($form['oversized_surcharge'] ?? 0),
                'heavy_surcharge' => $this->numberOrZero($form['heavy_surcharge'] ?? 0),
                'missing_measurements_policy' => (string) $form['missing_measurements_policy'],
                'is_active' => (bool) ($form['is_active'] ?? false),
                'sort_order' => max(0, (int) ($form['sort_order'] ?? 0)),
                'settings' => $settings === [] ? null : $settings,
            ]);
            $method->save();

            $keptIds = [];
            foreach ($rates as $index => $row) {
                $rateId = (int) ($row['id'] ?? 0);
                $rate = $rateId > 0
                    ? $method->rates()->whereKey($rateId)->firstOrFail()
                    : $method->rates()->make();

                $rate->fill([
                    'min_weight_kg' => $this->numberOrZero($row['min_weight_kg'] ?? 0),
                    'max_weight_kg' => $this->nullableNumber($row['max_weight_kg'] ?? null),
                    'price' => $this->numberOrZero($row['price'] ?? 0),
                    'sort_order' => $index,
                ]);
                $rate->save();
                $keptIds[] = (int) $rate->id;
            }

            $method->rates()
                ->when($keptIds !== [], fn ($query) => $query->whereNotIn('id', $keptIds))
                ->when($keptIds === [], fn ($query) => $query)
                ->delete();

            activity('shipping_methods')
                ->performedOn($method)
                ->causedBy(auth()->user())
                ->event($this->editingId ? 'updated' : 'created')
                ->withProperties([
                    'carrier' => $method->carrier,
                    'service_type' => $method->service_type,
                    'pricing_type' => $method->pricing_type,
                    'rate_count' => count($rates),
                ])
                ->log('Shipping method saved');
        });

        $message = $this->editingId ? __('Način dostave je ažuriran.') : __('Način dostave je kreiran.');

        if ($this->editPage || $this->createPage) {
            return redirect()
                ->route('admin.shipping.index', $this->listRouteParameters())
                ->with('notify', [
                    'type' => 'success',
                    'message' => $message,
                ]);
        }

        $this->dispatch('notify', type: 'success', message: $message);
        $this->resetForm();

        return null;
    }

    /**
     * @return array<string, int|string>
     */
    private function listRouteParameters(): array
    {
        return array_filter([
            'tab' => 'methods',
            'search' => $this->search !== '' ? $this->search : null,
            'page' => $this->returnPage > 1 ? $this->returnPage : null,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    public function toggleActive(int $id): void
    {
        $method = ShippingMethod::query()->findOrFail($id);

        if (! $method->is_active && $this->methodIsBoxNow($method) && ! $this->boxNowMethodIsReady($method)) {
            $this->addError('form.boxnow_partner_id', __('BOX NOW se može uključiti tek nakon Partner ID-a, svih limita i pravila „Sakrij dostavu” za artikle bez mjera.'));
            $this->dispatch(
                'notify',
                type: 'error',
                message: __('Dovršite sigurnosne postavke BOX NOW-a prije uključivanja.'),
            );

            return;
        }

        $method->update(['is_active' => ! $method->is_active]);

        $this->dispatch(
            'notify',
            type: 'info',
            message: $method->is_active ? __('Dostava je uključena.') : __('Dostava je isključena.')
        );
    }

    public function delete(int $id): void
    {
        $method = ShippingMethod::query()->findOrFail($id);
        $method->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->dispatch('notify', type: 'success', message: __('Način dostave je obrisan.'));
    }

    public function render()
    {
        $query = ShippingMethod::query()
            ->with(['geoZone:id,name', 'rates'])
            ->withCount('rates');

        if (trim($this->search) !== '') {
            $search = trim($this->search);
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('carrier', 'like', '%'.$search.'%');
            });
        }

        return view('livewire.admin.shipping.shipping-manager', [
            'rows' => $query->orderBy('sort_order')->orderBy('id')->paginate(20),
            'geoZones' => GeoZone::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'carrierOptions' => ShippingMethod::carrierOptions(),
            'serviceTypeOptions' => ShippingMethod::serviceTypeOptions(),
            'pricingTypeOptions' => ShippingMethod::pricingTypeOptions(),
            'islandPolicyOptions' => [
                CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY => __('Samo otoci bez cestovne veze'),
                CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS => __('Svi hrvatski otoci'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultForm(): array
    {
        return [
            'code' => '',
            'name' => '',
            'carrier' => 'manual',
            'service_type' => 'home_delivery',
            'pricing_type' => 'flat',
            'geo_zone_id' => null,
            'description' => '',
            'price' => 0,
            'free_over' => null,
            'min_subtotal' => null,
            'max_subtotal' => null,
            'min_weight_kg' => null,
            'max_weight_kg' => null,
            'max_length_cm' => null,
            'max_width_cm' => null,
            'max_height_cm' => null,
            'allows_fragile' => true,
            'allows_oversized' => true,
            'allows_heavy' => true,
            'fragile_surcharge' => 0,
            'oversized_surcharge' => 0,
            'heavy_surcharge' => 0,
            'missing_measurements_policy' => 'allow',
            'boxnow_partner_id' => '',
            'boxnow_api_url' => '',
            'boxnow_oauth_client_id' => '',
            'boxnow_oauth_client_secret' => '',
            'boxnow_warehouse_id' => '',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** @param array<string, mixed> $form */
    private function boxNowFormIsReady(array $form): bool
    {
        $ready = true;

        if (trim((string) ($form['boxnow_partner_id'] ?? '')) === '') {
            $this->addError('form.boxnow_partner_id', __('BOX NOW partner ID obavezan je za aktivnu BOX NOW dostavu.'));
            $ready = false;
        }

        if (($form['service_type'] ?? null) !== 'parcel_locker') {
            $this->addError('form.service_type', __('BOX NOW mora koristiti vrstu usluge „Paketomat”.'));
            $ready = false;
        }

        if (($form['pricing_type'] ?? null) !== 'flat') {
            $this->addError('form.pricing_type', __('BOX NOW mora koristiti fiksni način obračuna.'));
            $ready = false;
        }

        foreach (['max_weight_kg', 'max_length_cm', 'max_width_cm', 'max_height_cm'] as $field) {
            if (! is_numeric($form[$field] ?? null) || (float) $form[$field] <= 0) {
                $this->addError('form.'.$field, __('BOX NOW limit mora biti veći od nule.'));
                $ready = false;
            }
        }

        if (($form['missing_measurements_policy'] ?? null) !== 'block') {
            $this->addError('form.missing_measurements_policy', __('Za BOX NOW artikli bez mjera moraju biti skriveni.'));
            $ready = false;
        }

        foreach (['allows_fragile', 'allows_oversized', 'allows_heavy'] as $field) {
            if ((bool) ($form[$field] ?? false)) {
                $this->addError('form.'.$field, __('BOX NOW ne smije prihvaćati lomljive, prevelike ni teške artikle.'));
                $ready = false;
            }
        }

        return $ready;
    }

    private function boxNowMethodIsReady(ShippingMethod $method): bool
    {
        $settings = is_array($method->settings) ? $method->settings : [];

        return trim((string) ($settings['boxnow_partner_id'] ?? '')) !== ''
            && (string) $method->service_type === 'parcel_locker'
            && (string) $method->pricing_type === 'flat'
            && (string) $method->missing_measurements_policy === 'block'
            && (float) $method->max_weight_kg > 0
            && (float) $method->max_length_cm > 0
            && (float) $method->max_width_cm > 0
            && (float) $method->max_height_cm > 0
            && ! (bool) $method->allows_fragile
            && ! (bool) $method->allows_oversized
            && ! (bool) $method->allows_heavy;
    }

    /** @param array<string, mixed> $form */
    private function formIsBoxNow(array $form): bool
    {
        return strtolower(trim((string) ($form['carrier'] ?? ''))) === 'boxnow'
            || in_array(strtolower(trim((string) ($form['code'] ?? ''))), ['boxnow', 'box_now'], true);
    }

    private function methodIsBoxNow(ShippingMethod $method): bool
    {
        return strtolower(trim((string) $method->carrier)) === 'boxnow'
            || in_array(strtolower(trim((string) $method->code)), ['boxnow', 'box_now'], true);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->boxNowOauthClientSecretStored = false;
        $this->form = $this->defaultForm();
        $this->rates = [];
        $this->resetValidation();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.code' => [
                'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9_\\-]+$/',
                Rule::unique('shipping_methods', 'code')->ignore($this->editingId),
            ],
            'form.name' => ['required', 'string', 'max:255'],
            'form.carrier' => ['required', Rule::in(array_keys(ShippingMethod::carrierOptions()))],
            'form.service_type' => ['required', Rule::in(array_keys(ShippingMethod::serviceTypeOptions()))],
            'form.pricing_type' => ['required', Rule::in(array_keys(ShippingMethod::pricingTypeOptions()))],
            'form.geo_zone_id' => ['nullable', 'integer', 'exists:geo_zones,id'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.price' => ['nullable', 'numeric', 'min:0'],
            'form.free_over' => ['nullable', 'numeric', 'min:0'],
            'form.min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'form.max_subtotal' => ['nullable', 'numeric', 'min:0'],
            'form.min_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'form.max_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'form.max_length_cm' => ['nullable', 'numeric', 'min:0'],
            'form.max_width_cm' => ['nullable', 'numeric', 'min:0'],
            'form.max_height_cm' => ['nullable', 'numeric', 'min:0'],
            'form.allows_fragile' => ['boolean'],
            'form.allows_oversized' => ['boolean'],
            'form.allows_heavy' => ['boolean'],
            'form.fragile_surcharge' => ['nullable', 'numeric', 'min:0'],
            'form.oversized_surcharge' => ['nullable', 'numeric', 'min:0'],
            'form.heavy_surcharge' => ['nullable', 'numeric', 'min:0'],
            'form.missing_measurements_policy' => ['required', Rule::in(['allow', 'block'])],
            'form.boxnow_partner_id' => ['nullable', 'string', 'max:120'],
            'form.boxnow_api_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'form.boxnow_oauth_client_id' => ['nullable', 'string', 'max:255'],
            'form.boxnow_oauth_client_secret' => ['nullable', 'string', 'max:2048'],
            'form.boxnow_warehouse_id' => ['nullable', 'string', 'max:120'],
            'form.is_active' => ['boolean'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'rates' => ['array'],
            'rates.*.id' => ['nullable', 'integer'],
            'rates.*.min_weight_kg' => ['required', 'numeric', 'min:0'],
            'rates.*.max_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'rates.*.price' => ['required', 'numeric', 'min:0'],
            'rates.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function formRangesAreValid(array $form): bool
    {
        $valid = true;

        if (
            is_numeric($form['min_subtotal'] ?? null)
            && is_numeric($form['max_subtotal'] ?? null)
            && (float) $form['max_subtotal'] < (float) $form['min_subtotal']
        ) {
            $this->addError('form.max_subtotal', __('Maksimalna vrijednost košarice mora biti veća ili jednaka minimalnoj.'));
            $valid = false;
        }

        if (
            is_numeric($form['min_weight_kg'] ?? null)
            && is_numeric($form['max_weight_kg'] ?? null)
            && (float) $form['max_weight_kg'] < (float) $form['min_weight_kg']
        ) {
            $this->addError('form.max_weight_kg', __('Maksimalna težina mora biti veća ili jednaka minimalnoj.'));
            $valid = false;
        }

        return $valid;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rates
     */
    private function ratesAreValid(array $rates): bool
    {
        $normalized = collect($rates)
            ->map(fn (array $row): array => [
                'min' => (float) ($row['min_weight_kg'] ?? 0),
                'max' => isset($row['max_weight_kg']) && $row['max_weight_kg'] !== ''
                    ? (float) $row['max_weight_kg']
                    : null,
            ])
            ->sortBy('min')
            ->values();

        foreach ($normalized as $index => $row) {
            if ($row['max'] !== null && $row['max'] < $row['min']) {
                $this->addError('rates.'.$index.'.max_weight_kg', __('Maksimalna težina mora biti veća ili jednaka minimalnoj.'));

                return false;
            }

            $next = $normalized->get($index + 1);
            if ($next && ($row['max'] === null || $row['max'] >= $next['min'])) {
                $this->addError('rates', __('Rasponi težine ne smiju se preklapati.'));

                return false;
            }
        }

        return true;
    }

    private function nullableNumber(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function numberOrZero(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
