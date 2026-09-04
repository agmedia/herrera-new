<?php

namespace App\Livewire\Admin\Catalog\Action;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Action\CatalogActionTranslation;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $actionId = null;
    public string $targetSearch = '';
    public string $userSearch = '';

    public array $form = [
        'code' => '',
        'locale' => 'en',
        'is_active' => true,
        'is_exclusive' => false,
        'scope' => CatalogAction::SCOPE_PRODUCT,
        'type' => CatalogAction::TYPE_PERCENTAGE,
        'discount_value' => '',
        'target_type' => CatalogAction::TARGET_ALL,
        'target_ids' => [],
        'audience_type' => CatalogAction::AUDIENCE_ALL,
        'customer_group_id' => null,
        'user_id' => null,
        'coupon_code' => '',
        'min_subtotal' => '',
        'buy_qty' => '',
        'get_qty' => '',
        'gift_product_id' => null,
        'starts_at' => '',
        'ends_at' => '',
        'priority' => 0,
        'usage_limit' => '',
        'usage_limit_per_user' => '',
        'payload_text' => '',
        'title' => '',
        'description' => '',
        'badge' => '',
        'translation_payload_text' => '',
    ];

    public function mount(?int $actionId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($actionId) {
            $this->actionId = $actionId;
            $this->loadAction();
        }
    }

    public function updatedFormLocale(): void
    {
        $this->loadTranslationForLocale();
    }

    public function updatedFormTargetType(): void
    {
        $this->form['target_ids'] = [];
        $this->targetSearch = '';
    }

    public function updatedFormAudienceType(): void
    {
        if ($this->form['audience_type'] !== CatalogAction::AUDIENCE_USER_GROUP) {
            $this->form['customer_group_id'] = null;
        }
        if ($this->form['audience_type'] !== CatalogAction::AUDIENCE_USER) {
            $this->form['user_id'] = null;
            $this->userSearch = '';
        }
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $wasEditing = (bool) $this->actionId;

        $payload = $this->decodeJsonField('form.payload_text');
        if ($payload === false) {
            return null;
        }

        $translationPayload = $this->decodeJsonField('form.translation_payload_text');
        if ($translationPayload === false) {
            return null;
        }

        $userId = auth()->id();

        DB::transaction(function () use ($validated, $payload, $translationPayload, $userId, $wasEditing): void {
            $data = $validated['form'];
            $targetIds = array_values(array_unique(array_map('intval', $data['target_ids'] ?? [])));

            if ($data['target_type'] === CatalogAction::TARGET_ALL) {
                $targetIds = [];
            }

            $actionData = [
                'code' => trim((string) $data['code']),
                'scope' => (string) $data['scope'],
                'type' => (string) $data['type'],
                'discount_value' => $this->nullableDecimal($data['discount_value'] ?? null),
                'target_type' => (string) $data['target_type'],
                'audience_type' => (string) $data['audience_type'],
                'customer_group_id' => $data['audience_type'] === CatalogAction::AUDIENCE_USER_GROUP ? (int) $data['customer_group_id'] : null,
                'role_id' => null,
                'user_id' => $data['audience_type'] === CatalogAction::AUDIENCE_USER ? (int) $data['user_id'] : null,
                'coupon_code' => $this->nullableCouponCode($data['coupon_code'] ?? null),
                'min_subtotal' => $this->nullableDecimal($data['min_subtotal'] ?? null),
                'buy_qty' => $this->nullableInt($data['buy_qty'] ?? null),
                'get_qty' => $this->nullableInt($data['get_qty'] ?? null),
                'gift_product_id' => $this->nullableInt($data['gift_product_id'] ?? null),
                'starts_at' => $this->nullableDateTime($data['starts_at'] ?? null),
                'ends_at' => $this->nullableDateTime($data['ends_at'] ?? null),
                'priority' => max(0, (int) ($data['priority'] ?? 0)),
                'is_exclusive' => (bool) ($data['is_exclusive'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'usage_limit' => $this->nullableInt($data['usage_limit'] ?? null),
                'usage_limit_per_user' => $this->nullableInt($data['usage_limit_per_user'] ?? null),
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->actionId) {
                $action = CatalogAction::query()->findOrFail($this->actionId);
                $action->fill($actionData)->save();
            } else {
                $action = CatalogAction::query()->create($actionData + ['created_by' => $userId]);
                $this->actionId = $action->id;
            }

            $action->translations()->updateOrCreate(
                ['locale' => $data['locale']],
                [
                    'title' => trim((string) $data['title']),
                    'description' => $this->nullableString($data['description'] ?? null),
                    'badge' => $this->nullableString($data['badge'] ?? null),
                    'payload' => $translationPayload,
                ]
            );

            $action->targets()->delete();

            if ($targetIds !== []) {
                foreach ($targetIds as $index => $targetId) {
                    $action->targets()->create([
                        'target_type' => (string) $data['target_type'],
                        'target_id' => $targetId,
                        'sort_order' => $index,
                    ]);
                }
            }

            activity('catalog_actions')
                ->performedOn($action)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'locale' => $data['locale'],
                    'scope' => $data['scope'],
                    'type' => $data['type'],
                    'target_type' => $data['target_type'],
                    'target_count' => count($targetIds),
                ])
                ->log('Catalog action saved');
        });

        $message = $wasEditing ? __('Action updated.') : __('Action created.');

        return redirect()
            ->route('admin.actions', ['locale' => $this->form['locale']])
            ->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.actions', ['locale' => $this->form['locale']]);
    }

    public function getCustomerGroupOptionsProperty(): Collection
    {
        return CustomerGroup::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function getUserOptionsProperty(): Collection
    {
        return User::query()
            ->when($this->userSearch !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('name', 'like', '%'.$this->userSearch.'%')
                        ->orWhere('email', 'like', '%'.$this->userSearch.'%');
                });
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'email']);
    }

    public function getTargetOptionsProperty(): Collection
    {
        $targetType = (string) ($this->form['target_type'] ?? CatalogAction::TARGET_ALL);
        $locale = (string) ($this->form['locale'] ?? config('app.locale', 'en'));

        if ($targetType === CatalogAction::TARGET_PRODUCT) {
            return Product::query()
                ->with([
                    'translations' => fn ($q) => $q->where('locale', $locale),
                ])
                ->when($this->targetSearch !== '', function ($query): void {
                    $query->where(function ($q): void {
                        $q->where('code', 'like', '%'.$this->targetSearch.'%')
                            ->orWhere('sku', 'like', '%'.$this->targetSearch.'%')
                            ->orWhereHas('translations', function ($tq): void {
                                $tq->where('name', 'like', '%'.$this->targetSearch.'%');
                            });
                    });
                })
                ->orderByDesc('id')
                ->limit(250)
                ->get();
        }

        if ($targetType === CatalogAction::TARGET_CATEGORY) {
            return Category::query()
                ->where('scope', Category::SCOPE_CATALOG)
                ->withDepth()
                ->defaultOrder()
                ->with([
                    'translations' => fn ($q) => $q
                        ->where('scope', Category::SCOPE_CATALOG)
                        ->where('locale', $locale),
                ])
                ->when($this->targetSearch !== '', function ($query): void {
                    $query->whereHas('translations', function ($tq): void {
                        $tq->where('name', 'like', '%'.$this->targetSearch.'%');
                    });
                })
                ->limit(250)
                ->get();
        }

        if ($targetType === CatalogAction::TARGET_MANUFACTURER) {
            return Manufacturer::query()
                ->with([
                    'translations' => fn ($q) => $q->where('locale', $locale),
                ])
                ->when($this->targetSearch !== '', function ($query): void {
                    $query->where(function ($q): void {
                        $q->where('code', 'like', '%'.$this->targetSearch.'%')
                            ->orWhereHas('translations', function ($tq): void {
                                $tq->where('name', 'like', '%'.$this->targetSearch.'%');
                            });
                    });
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(250)
                ->get();
        }

        return collect();
    }

    public function getGiftProductOptionsProperty(): Collection
    {
        $locale = (string) ($this->form['locale'] ?? config('app.locale', 'en'));

        return Product::query()
            ->with([
                'translations' => fn ($q) => $q->where('locale', $locale),
            ])
            ->orderByDesc('id')
            ->limit(250)
            ->get();
    }

    public function render()
    {
        return view('livewire.admin.catalog.action.form', [
            'isEdit' => (bool) $this->actionId,
            'scopeOptions' => $this->scopeOptions(),
            'typeOptions' => $this->typeOptions(),
            'targetOptions' => $this->targetTypeOptions(),
            'audienceOptions' => $this->audienceTypeOptions(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $targetRules = ['nullable', 'array'];
        if (($this->form['target_type'] ?? CatalogAction::TARGET_ALL) !== CatalogAction::TARGET_ALL) {
            $targetRules[] = 'min:1';
        }

        $rules = [
            'form.code' => [
                'required',
                'string',
                'max:120',
                Rule::unique('catalog_actions', 'code')->ignore($this->actionId),
            ],
            'form.locale' => ['required', 'string', 'max:12'],
            'form.is_active' => ['boolean'],
            'form.is_exclusive' => ['boolean'],
            'form.scope' => ['required', Rule::in(CatalogAction::availableScopes())],
            'form.type' => ['required', Rule::in(CatalogAction::availableTypes())],
            'form.discount_value' => ['nullable', 'numeric', 'min:0'],
            'form.target_type' => ['required', Rule::in(CatalogAction::availableTargetTypes())],
            'form.target_ids' => $targetRules,
            'form.audience_type' => ['required', Rule::in(CatalogAction::availableAudienceTypes())],
            'form.customer_group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')],
            'form.user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'form.coupon_code' => ['nullable', 'string', 'max:60'],
            'form.min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'form.buy_qty' => ['nullable', 'integer', 'min:1'],
            'form.get_qty' => ['nullable', 'integer', 'min:1'],
            'form.gift_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date', 'after_or_equal:form.starts_at'],
            'form.priority' => ['nullable', 'integer', 'min:0'],
            'form.usage_limit' => ['nullable', 'integer', 'min:1'],
            'form.usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'form.payload_text' => ['nullable', 'string'],
            'form.title' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string'],
            'form.badge' => ['nullable', 'string', 'max:191'],
            'form.translation_payload_text' => ['nullable', 'string'],
        ];

        if (($this->form['audience_type'] ?? '') === CatalogAction::AUDIENCE_USER_GROUP) {
            $rules['form.customer_group_id'] = ['required', 'integer', Rule::exists('customer_groups', 'id')];
        }

        if (($this->form['audience_type'] ?? '') === CatalogAction::AUDIENCE_USER) {
            $rules['form.user_id'] = ['required', 'integer', Rule::exists('users', 'id')];
        }

        $type = (string) ($this->form['type'] ?? CatalogAction::TYPE_PERCENTAGE);
        if (in_array($type, [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED], true)) {
            $rules['form.discount_value'] = ['required', 'numeric', 'min:0'];
        }

        if ($type === CatalogAction::TYPE_BUY_X_GET_Y) {
            $rules['form.buy_qty'] = ['required', 'integer', 'min:1'];
            $rules['form.get_qty'] = ['required', 'integer', 'min:1'];
        }

        if ($type === CatalogAction::TYPE_GIFT_ON_AMOUNT) {
            $rules['form.min_subtotal'] = ['required', 'numeric', 'min:0'];
            $rules['form.gift_product_id'] = ['required', 'integer', Rule::exists('products', 'id')];
        }

        if (($this->form['target_type'] ?? '') === CatalogAction::TARGET_PRODUCT) {
            $rules['form.target_ids.*'] = ['integer', Rule::exists('products', 'id')];
        }
        if (($this->form['target_type'] ?? '') === CatalogAction::TARGET_CATEGORY) {
            $rules['form.target_ids.*'] = ['integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('scope', Category::SCOPE_CATALOG))];
        }
        if (($this->form['target_type'] ?? '') === CatalogAction::TARGET_MANUFACTURER) {
            $rules['form.target_ids.*'] = ['integer', Rule::exists('catalog_manufacturers', 'id')];
        }

        return $rules;
    }

    private function loadAction(): void
    {
        if (!$this->actionId) {
            return;
        }

        $action = CatalogAction::query()
            ->with(['translations', 'targets'])
            ->findOrFail($this->actionId);

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $action->translations->firstWhere('locale', $preferredLocale)
            ?? $action->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $action->translations->first();

        $this->form['code'] = $action->code;
        $this->form['is_active'] = (bool) $action->is_active;
        $this->form['is_exclusive'] = (bool) $action->is_exclusive;
        $this->form['scope'] = (string) $action->scope;
        $this->form['type'] = (string) $action->type;
        $this->form['discount_value'] = $action->discount_value !== null ? (string) $action->discount_value : '';
        $this->form['target_type'] = (string) $action->target_type;
        $this->form['target_ids'] = $action->targets
            ->where('target_type', $action->target_type)
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $audienceType = (string) $action->audience_type;
        if ($audienceType === CatalogAction::AUDIENCE_ROLE) {
            $audienceType = CatalogAction::AUDIENCE_ALL;
        }
        $this->form['audience_type'] = $audienceType;
        $this->form['customer_group_id'] = $action->customer_group_id ? (int) $action->customer_group_id : null;
        $this->form['user_id'] = $action->user_id ? (int) $action->user_id : null;
        $this->form['coupon_code'] = (string) ($action->coupon_code ?? '');
        $this->form['min_subtotal'] = $action->min_subtotal !== null ? (string) $action->min_subtotal : '';
        $this->form['buy_qty'] = $action->buy_qty !== null ? (string) $action->buy_qty : '';
        $this->form['get_qty'] = $action->get_qty !== null ? (string) $action->get_qty : '';
        $this->form['gift_product_id'] = $action->gift_product_id ? (int) $action->gift_product_id : null;
        $this->form['starts_at'] = $action->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->form['ends_at'] = $action->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->form['priority'] = (int) $action->priority;
        $this->form['usage_limit'] = $action->usage_limit !== null ? (string) $action->usage_limit : '';
        $this->form['usage_limit_per_user'] = $action->usage_limit_per_user !== null ? (string) $action->usage_limit_per_user : '';
        $this->form['payload_text'] = $action->payload
            ? json_encode($action->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';

        if ($translation) {
            $this->form['locale'] = $translation->locale;
            $this->form['title'] = $translation->title;
            $this->form['description'] = $translation->description ?? '';
            $this->form['badge'] = $translation->badge ?? '';
            $this->form['translation_payload_text'] = $translation->payload
                ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
        }
    }

    private function loadTranslationForLocale(): void
    {
        if (!$this->actionId) {
            $this->clearTranslationFields();
            return;
        }

        $translation = CatalogActionTranslation::query()
            ->where('action_id', $this->actionId)
            ->where('locale', $this->form['locale'])
            ->first();

        if (!$translation) {
            $this->clearTranslationFields();
            return;
        }

        $this->form['title'] = $translation->title;
        $this->form['description'] = $translation->description ?? '';
        $this->form['badge'] = $translation->badge ?? '';
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function clearTranslationFields(): void
    {
        $this->form['title'] = '';
        $this->form['description'] = '';
        $this->form['badge'] = '';
        $this->form['translation_payload_text'] = '';
    }

    /**
     * @return array<mixed>|null|false
     */
    private function decodeJsonField(string $field): array|null|false
    {
        $value = trim((string) data_get($this, $field));
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($field, __('Invalid JSON payload.'));
            $this->dispatch('notify', type: 'danger', message: __('Invalid JSON payload.'));
            return false;
        }

        if (!is_array($decoded)) {
            $this->addError($field, __('JSON payload must decode to object/array.'));
            $this->dispatch('notify', type: 'danger', message: __('JSON payload must decode to object/array.'));
            return false;
        }

        return $decoded;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableCouponCode(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        return $value === null ? null : strtoupper($value);
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function scopeOptions(): array
    {
        return [
            CatalogAction::SCOPE_PRODUCT => __('Product Action'),
            CatalogAction::SCOPE_CART => __('Cart Discount'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeOptions(): array
    {
        return [
            CatalogAction::TYPE_PERCENTAGE => __('Percentage'),
            CatalogAction::TYPE_FIXED => __('Fixed Amount'),
            CatalogAction::TYPE_BUY_X_GET_Y => __('Buy X Get Y'),
            CatalogAction::TYPE_GIFT_ON_AMOUNT => __('Gift On Amount'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function targetTypeOptions(): array
    {
        return [
            CatalogAction::TARGET_ALL => __('All Products'),
            CatalogAction::TARGET_PRODUCT => __('Specific Products'),
            CatalogAction::TARGET_CATEGORY => __('Category Products'),
            CatalogAction::TARGET_MANUFACTURER => __('Manufacturer Products'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function audienceTypeOptions(): array
    {
        return [
            CatalogAction::AUDIENCE_ALL => __('All Users'),
            CatalogAction::AUDIENCE_USER_GROUP => __('User Group'),
            CatalogAction::AUDIENCE_USER => __('Single User'),
        ];
    }
}
