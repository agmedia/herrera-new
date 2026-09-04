<?php

namespace App\Livewire\Admin\Catalog\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\B2BPriceRule;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class B2BPriceRuleForm extends Component
{
    public ?int $ruleId = null;

    public string $targetSearch = '';

    public array $form = [
        'code' => '',
        'name' => '',
        'audience_type' => 'group',
        'customer_group_id' => null,
        'user_id' => null,
        'contract_number' => '',
        'calculation_type' => B2BPriceRule::TYPE_PERCENTAGE_DISCOUNT,
        'value' => 0,
        'target_type' => B2BPriceRule::TARGET_ALL,
        'target_ids' => [],
        'minimum_quantity' => 1,
        'currency_code' => 'EUR',
        'starts_at' => '',
        'ends_at' => '',
        'priority' => 0,
        'is_active' => true,
        'payload_text' => '',
    ];

    public function mount(?int $ruleId = null): void
    {
        if ($ruleId) {
            $this->ruleId = $ruleId;
            $this->loadRule();
        }
    }

    public function updatedFormTargetType(): void
    {
        $this->form['target_ids'] = [];
        $this->targetSearch = '';
    }

    public function updatedFormAudienceType(): void
    {
        if ($this->form['audience_type'] === 'customer') {
            $this->form['customer_group_id'] = null;

            return;
        }

        $this->form['user_id'] = null;
    }

    public function save()
    {
        $validated = $this->validate($this->rules());

        if (
            $validated['form']['calculation_type'] === B2BPriceRule::TYPE_FIXED_PRICE
            && $validated['form']['target_type'] !== B2BPriceRule::TARGET_PRODUCT
        ) {
            $this->addError(
                'form.calculation_type',
                __('Fiksna cijena može se koristiti samo za pojedinačne proizvode.'),
            );

            return null;
        }

        $payload = $this->decodePayload();
        if ($payload === false) {
            return null;
        }

        $wasEditing = (bool) $this->ruleId;
        $userId = auth()->id();

        DB::transaction(function () use ($validated, $payload, $userId): void {
            $data = $validated['form'];
            $targetIds = array_values(array_unique(array_map('intval', $data['target_ids'] ?? [])));
            if ($data['target_type'] === B2BPriceRule::TARGET_ALL) {
                $targetIds = [];
            }

            $ruleData = [
                'code' => Str::upper(trim((string) $data['code'])),
                'name' => trim((string) $data['name']),
                'customer_group_id' => $data['audience_type'] === 'group'
                    ? (int) $data['customer_group_id']
                    : null,
                'user_id' => $data['audience_type'] === 'customer'
                    ? (int) $data['user_id']
                    : null,
                'contract_number' => trim((string) ($data['contract_number'] ?? '')) ?: null,
                'calculation_type' => (string) $data['calculation_type'],
                'value' => (float) $data['value'],
                'target_type' => (string) $data['target_type'],
                'minimum_quantity' => (int) $data['minimum_quantity'],
                'currency_code' => Str::upper((string) $data['currency_code']),
                'starts_at' => trim((string) ($data['starts_at'] ?? '')) ?: null,
                'ends_at' => trim((string) ($data['ends_at'] ?? '')) ?: null,
                'priority' => (int) $data['priority'],
                'is_active' => (bool) $data['is_active'],
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->ruleId) {
                $rule = B2BPriceRule::query()->findOrFail($this->ruleId);
                $rule->fill($ruleData)->save();
            } else {
                $rule = B2BPriceRule::query()->create($ruleData + ['created_by' => $userId]);
                $this->ruleId = (int) $rule->getKey();
            }

            $rule->targets()->delete();
            foreach ($targetIds as $index => $targetId) {
                $rule->targets()->create([
                    'target_type' => $rule->target_type,
                    'target_id' => $targetId,
                    'sort_order' => $index,
                ]);
            }

            activity('catalog_b2b_prices')
                ->performedOn($rule)
                ->causedBy(auth()->user())
                ->event($rule->wasRecentlyCreated ? 'created' : 'updated')
                ->withProperties([
                    'customer_group_id' => $rule->customer_group_id,
                    'user_id' => $rule->user_id,
                    'target_type' => $rule->target_type,
                    'target_count' => count($targetIds),
                ])
                ->log('B2B price rule saved');
        });

        return redirect()
            ->route('admin.b2b-prices')
            ->with('notify', [
                'type' => 'success',
                'message' => $wasEditing
                    ? __('B2B pravilo je ažurirano.')
                    : __('B2B pravilo je kreirano.'),
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.b2b-prices');
    }

    public function getCustomerGroupOptionsProperty(): Collection
    {
        return CustomerGroup::query()
            ->where(function ($query): void {
                $query->where('is_active', true);

                if ($this->form['customer_group_id']) {
                    $query->orWhere('id', (int) $this->form['customer_group_id']);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active']);
    }

    public function getCustomerOptionsProperty(): Collection
    {
        return User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->whereHas('b2bAccount', function ($query): void {
                $query->where('status', B2BAccount::STATUS_APPROVED);

                if ($this->form['user_id']) {
                    $query->orWhere('user_id', (int) $this->form['user_id']);
                }
            })
            ->with('b2bAccount:id,user_id,company_name,oib,contract_number')
            ->orderBy('users.name')
            ->limit(500)
            ->get();
    }

    public function getTargetOptionsProperty(): Collection
    {
        $targetType = (string) $this->form['target_type'];
        $locale = (string) config('app.locale', 'hr');

        if ($targetType === B2BPriceRule::TARGET_PRODUCT) {
            return Product::query()
                ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
                ->when($this->targetSearch !== '', function ($query): void {
                    $query->where(function ($query): void {
                        $query
                            ->where('code', 'like', '%'.$this->targetSearch.'%')
                            ->orWhere('sku', 'like', '%'.$this->targetSearch.'%')
                            ->orWhere('barcode', 'like', '%'.$this->targetSearch.'%')
                            ->orWhereHas('translations', fn ($translationQuery) => $translationQuery
                                ->where('name', 'like', '%'.$this->targetSearch.'%'));
                    });
                })
                ->orderByDesc('id')
                ->limit(250)
                ->get();
        }

        if ($targetType === B2BPriceRule::TARGET_CATEGORY) {
            return Category::query()
                ->where('scope', Category::SCOPE_CATALOG)
                ->withDepth()
                ->defaultOrder()
                ->with(['translations' => fn ($query) => $query
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->where('locale', $locale)])
                ->when($this->targetSearch !== '', fn ($query) => $query
                    ->whereHas('translations', fn ($translationQuery) => $translationQuery
                        ->where('name', 'like', '%'.$this->targetSearch.'%')))
                ->limit(250)
                ->get();
        }

        if ($targetType === B2BPriceRule::TARGET_MANUFACTURER) {
            return Manufacturer::query()
                ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
                ->when($this->targetSearch !== '', function ($query): void {
                    $query->where(function ($query): void {
                        $query
                            ->where('code', 'like', '%'.$this->targetSearch.'%')
                            ->orWhereHas('translations', fn ($translationQuery) => $translationQuery
                                ->where('name', 'like', '%'.$this->targetSearch.'%'));
                    });
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(250)
                ->get();
        }

        return collect();
    }

    public function render()
    {
        return view('livewire.admin.catalog.pricing.b2b-price-rule-form', [
            'isEdit' => (bool) $this->ruleId,
            'calculationTypeOptions' => B2BPriceRule::calculationTypeOptions(),
            'targetTypeOptions' => B2BPriceRule::targetTypeOptions(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $targetIds = ['array'];
        if ($this->form['target_type'] !== B2BPriceRule::TARGET_ALL) {
            $targetIds[] = 'min:1';
        }

        $rules = [
            'form.code' => [
                'required',
                'string',
                'max:120',
                Rule::unique('catalog_b2b_price_rules', 'code')->ignore($this->ruleId),
            ],
            'form.name' => ['required', 'string', 'max:191'],
            'form.audience_type' => ['required', Rule::in(['group', 'customer'])],
            'form.customer_group_id' => [
                Rule::requiredIf($this->form['audience_type'] === 'group'),
                'nullable',
                'integer',
                Rule::exists('customer_groups', 'id'),
            ],
            'form.user_id' => [
                Rule::requiredIf($this->form['audience_type'] === 'customer'),
                'nullable',
                'integer',
                Rule::exists('b2b_accounts', 'user_id')->where(
                    fn ($query) => $query->where('status', B2BAccount::STATUS_APPROVED),
                ),
            ],
            'form.contract_number' => ['nullable', 'string', 'max:120'],
            'form.calculation_type' => ['required', Rule::in(array_keys(B2BPriceRule::calculationTypeOptions()))],
            'form.value' => ['required', 'numeric', 'min:0'],
            'form.target_type' => ['required', Rule::in(array_keys(B2BPriceRule::targetTypeOptions()))],
            'form.target_ids' => $targetIds,
            'form.minimum_quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'form.currency_code' => ['required', 'string', 'size:3'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date', 'after_or_equal:form.starts_at'],
            'form.priority' => ['required', 'integer', 'min:0', 'max:999999'],
            'form.is_active' => ['boolean'],
            'form.payload_text' => ['nullable', 'string'],
        ];

        $rules['form.target_ids.*'] = match ($this->form['target_type']) {
            B2BPriceRule::TARGET_PRODUCT => ['integer', Rule::exists('products', 'id')],
            B2BPriceRule::TARGET_CATEGORY => [
                'integer',
                Rule::exists('categories', 'id')->where(
                    fn ($query) => $query->where('scope', Category::SCOPE_CATALOG),
                ),
            ],
            B2BPriceRule::TARGET_MANUFACTURER => [
                'integer',
                Rule::exists('catalog_manufacturers', 'id'),
            ],
            default => ['integer'],
        };

        return $rules;
    }

    private function loadRule(): void
    {
        $rule = B2BPriceRule::query()->with('targets')->findOrFail($this->ruleId);

        $this->form = [
            'code' => $rule->code,
            'name' => $rule->name,
            'audience_type' => $rule->user_id ? 'customer' : 'group',
            'customer_group_id' => $rule->customer_group_id ? (int) $rule->customer_group_id : null,
            'user_id' => $rule->user_id ? (int) $rule->user_id : null,
            'contract_number' => $rule->contract_number ?? '',
            'calculation_type' => $rule->calculation_type,
            'value' => (float) $rule->value,
            'target_type' => $rule->target_type,
            'target_ids' => $rule->targets->pluck('target_id')->map(static fn ($id): int => (int) $id)->all(),
            'minimum_quantity' => (int) $rule->minimum_quantity,
            'currency_code' => $rule->currency_code,
            'starts_at' => $rule->starts_at?->format('Y-m-d\TH:i') ?? '',
            'ends_at' => $rule->ends_at?->format('Y-m-d\TH:i') ?? '',
            'priority' => (int) $rule->priority,
            'is_active' => (bool) $rule->is_active,
            'payload_text' => $rule->payload
                ? json_encode($rule->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '',
        ];
    }

    /**
     * @return array<mixed>|null|false
     */
    private function decodePayload(): array|null|false
    {
        $value = trim((string) $this->form['payload_text']);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            $this->addError('form.payload_text', __('JSON zapis nije valjan.'));

            return false;
        }

        return $decoded;
    }
}
