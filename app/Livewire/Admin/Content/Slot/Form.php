<?php

namespace App\Livewire\Admin\Content\Slot;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $slotId = null;

    public array $form = [];

    /**
     * @var array<string, string>
     */
    public array $placements = [];

    /**
     * @var array<string, string>
     */
    public array $targetTypes = [
        '' => 'Global (no target)',
        'category' => 'Category',
        'product' => 'Product',
        'blog_post' => 'Blog Post',
        'page' => 'Static Page',
        'custom' => 'Custom',
    ];

    /**
     * @var array<string, string>
     */
    public array $frontendVariants = [
        'all' => 'All Devices',
        'desktop' => 'Desktop Only',
        'mobile' => 'Mobile Only',
    ];

    public function mount(?int $slotId = null): void
    {
        /** @var array<string, string> $placements */
        $placements = config('content_blocks.placements', []);
        $this->placements = $placements;
        $this->targetTypes = collect($this->targetTypes)
            ->map(static fn ($label) => __((string) $label))
            ->all();
        $this->frontendVariants = collect($this->frontendVariants)
            ->map(static fn ($label) => __((string) $label))
            ->all();

        $this->resetForm();

        if ($slotId) {
            $this->slotId = $slotId;
            $this->loadSlot();
        }
    }

    public function getIsEditProperty(): bool
    {
        return (bool) $this->slotId;
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $userId = auth()->id();
        $targetRefs = $this->parseTargetRefs((string) ($validated['form']['target_ref'] ?? ''));
        $hasTargetRefs = $targetRefs !== [];

        if ($hasTargetRefs && empty($validated['form']['target_type'])) {
            $this->addError('form.target_type', __('Target type is required when target ref is set.'));
            $this->dispatch('notify', type: 'warning', message: __('Choose target type when target ref is set.'));
            return null;
        }

        foreach ($targetRefs as $targetRef) {
            if (mb_strlen($targetRef) > 191) {
                $this->addError('form.target_ref', __('Each target ref must be 191 characters or less.'));
                $this->dispatch('notify', type: 'warning', message: __('One or more target refs are too long.'));
                return null;
            }
        }

        if (!$hasTargetRefs) {
            $targetRefs = [null];
        }

        $data = [
            'content_block_id' => (int) $validated['form']['content_block_id'],
            'placement' => $validated['form']['placement'],
            'frontend_variant' => (string) ($validated['form']['frontend_variant'] ?? 'all'),
            'target_type' => $validated['form']['target_type'] ?: null,
            'sort_order' => (int) $validated['form']['sort_order'],
            'is_active' => (bool) $validated['form']['is_active'],
            'starts_at' => $validated['form']['starts_at'] ?: null,
            'ends_at' => $validated['form']['ends_at'] ?: null,
            'updated_by' => $userId,
        ];

        $createdExtra = 0;
        $eventName = $this->isEdit ? 'updated' : 'created';

        if ($this->slotId) {
            $slot = ContentBlockSlot::query()->findOrFail($this->slotId);
            $slot->update($data + ['target_ref' => $targetRefs[0]]);

            $extraRefs = array_slice($targetRefs, 1);
            foreach ($extraRefs as $extraRef) {
                $exists = ContentBlockSlot::query()
                    ->where('content_block_id', $slot->content_block_id)
                    ->where('placement', $slot->placement)
                    ->where('frontend_variant', $slot->frontend_variant)
                    ->where('target_type', $slot->target_type)
                    ->where('target_ref', $extraRef)
                    ->exists();

                if ($exists) {
                    continue;
                }

                ContentBlockSlot::query()->create([
                    'content_block_id' => $slot->content_block_id,
                    'placement' => $slot->placement,
                    'target_type' => $slot->target_type,
                    'target_ref' => $extraRef,
                    'sort_order' => $slot->sort_order,
                    'is_active' => $slot->is_active,
                    'starts_at' => $slot->starts_at,
                    'ends_at' => $slot->ends_at,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
                $createdExtra++;
            }
        } else {
            $firstRef = array_shift($targetRefs);
            $slot = ContentBlockSlot::query()->create($data + [
                'target_ref' => $firstRef,
                'created_by' => $userId,
            ]);

            foreach ($targetRefs as $extraRef) {
                ContentBlockSlot::query()->create($data + [
                    'target_ref' => $extraRef,
                    'created_by' => $userId,
                ]);
                $createdExtra++;
            }
        }

        activity('content_blocks')
            ->performedOn($slot)
            ->causedBy(auth()->user())
            ->event($eventName)
            ->withProperties([
                'placement' => $slot->placement,
                'target_type' => $slot->target_type,
                'target_ref' => $slot->target_ref,
                'extra_created' => $createdExtra,
            ])
            ->log('Content slot saved');

        if ($this->isEdit) {
            $message = $createdExtra > 0
                ? __('Content slot updated. :count additional target slots created.', ['count' => $createdExtra])
                : __('Content slot updated.');
        } else {
            $message = $createdExtra > 0
                ? __('Content slot created. :count additional target slots created.', ['count' => $createdExtra])
                : __('Content slot created.');
        }

        return redirect()->route('admin.content.slots')->with('notify', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.content.slots');
    }

    public function render()
    {
        $blockOptions = ContentBlock::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type']);

        return view('livewire.admin.content.slot.form', [
            'blockOptions' => $blockOptions,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.content_block_id' => ['required', Rule::exists('content_blocks', 'id')],
            'form.placement' => ['required', 'string', 'max:120'],
            'form.frontend_variant' => ['required', Rule::in(['all', 'desktop', 'mobile'])],
            'form.target_type' => ['nullable', 'string', 'max:80'],
            'form.target_ref' => ['nullable', 'string', 'max:2000'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.is_active' => ['boolean'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date', 'after_or_equal:form.starts_at'],
        ];
    }

    private function resetForm(): void
    {
        $this->form = [
            'content_block_id' => null,
            'placement' => array_key_first($this->placements) ?: 'home.hero',
            'frontend_variant' => 'all',
            'target_type' => '',
            'target_ref' => '',
            'sort_order' => 0,
            'is_active' => true,
            'starts_at' => '',
            'ends_at' => '',
        ];
    }

    private function loadSlot(): void
    {
        if (!$this->slotId) {
            return;
        }

        $slot = ContentBlockSlot::query()->findOrFail($this->slotId);

        $this->form['content_block_id'] = $slot->content_block_id;
        $this->form['placement'] = $slot->placement;
        $this->form['frontend_variant'] = $slot->frontend_variant ?? 'all';
        $this->form['target_type'] = $slot->target_type ?? '';
        $this->form['target_ref'] = $slot->target_ref ?? '';
        $this->form['sort_order'] = (int) $slot->sort_order;
        $this->form['is_active'] = (bool) $slot->is_active;
        $this->form['starts_at'] = $slot->starts_at?->format('Y-m-d\\TH:i') ?? '';
        $this->form['ends_at'] = $slot->ends_at?->format('Y-m-d\\TH:i') ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function parseTargetRefs(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\n,;]+/', $raw) ?: [];
        $refs = [];

        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $refs[] = $value;
            }
        }

        return array_values(array_unique($refs));
    }
}
