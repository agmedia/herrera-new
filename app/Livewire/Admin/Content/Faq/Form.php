<?php

namespace App\Livewire\Admin\Content\Faq;

use App\Models\Content\Support\Faq;
use App\Models\Content\Support\FaqTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $faqId = null;

    public array $form = [
        'code' => '',
        'group_code' => 'general',
        'is_active' => true,
        'is_featured' => false,
        'sort_order' => 0,
        'payload_text' => '',
        'locale' => 'en',
        'question' => '',
        'slug' => '',
        'answer_html' => '',
        'meta_title' => '',
        'meta_description' => '',
        'translation_payload_text' => '',
    ];

    public function mount(?int $faqId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($faqId) {
            $this->faqId = $faqId;
            $this->loadFaq();
        }
    }

    public function updatedFormLocale(): void
    {
        $this->loadTranslationForLocale();
    }

    public function generateSlug(): void
    {
        $question = trim((string) $this->form['question']);
        if ($question !== '') {
            $this->form['slug'] = Str::slug($question);
        }
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $wasEditing = (bool) $this->faqId;

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
            $faqData = [
                'code' => trim((string) $validated['form']['code']),
                'group_code' => trim((string) $validated['form']['group_code']) !== '' ? trim((string) $validated['form']['group_code']) : 'general',
                'is_active' => (bool) $validated['form']['is_active'],
                'is_featured' => (bool) $validated['form']['is_featured'],
                'sort_order' => (int) $validated['form']['sort_order'],
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->faqId) {
                $faq = Faq::query()->findOrFail($this->faqId);
                $faq->fill($faqData)->save();
            } else {
                $faq = Faq::query()->create($faqData + ['created_by' => $userId]);
                $this->faqId = $faq->id;
            }

            $faq->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'question' => $validated['form']['question'],
                    'slug' => $validated['form']['slug'],
                    'answer_html' => $validated['form']['answer_html'] ?: null,
                    'meta_title' => $validated['form']['meta_title'] ?: null,
                    'meta_description' => $validated['form']['meta_description'] ?: null,
                    'payload' => $translationPayload,
                ]
            );

            activity('content_faqs')
                ->performedOn($faq)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'locale' => $validated['form']['locale'],
                    'slug' => $validated['form']['slug'],
                    'group_code' => $validated['form']['group_code'],
                    'is_featured' => (bool) $validated['form']['is_featured'],
                ])
                ->log('FAQ saved');
        });

        $message = $wasEditing ? 'FAQ updated.' : 'FAQ created.';

        return redirect()
            ->route('admin.content.faqs.index', ['locale' => $this->form['locale']])
            ->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.content.faqs.index', ['locale' => $this->form['locale']]);
    }

    public function render()
    {
        return view('livewire.admin.content.faq.form', [
            'isEdit' => (bool) $this->faqId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.code' => ['required', 'string', 'max:120', Rule::unique('content_faqs', 'code')->ignore($this->faqId)],
            'form.group_code' => ['nullable', 'string', 'max:80'],
            'form.is_active' => ['boolean'],
            'form.is_featured' => ['boolean'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.payload_text' => ['nullable', 'string'],
            'form.locale' => ['required', 'string', 'max:12'],
            'form.question' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_faq_translations', 'slug')
                    ->where(fn ($q) => $q->where('locale', $this->form['locale']))
                    ->ignore($this->faqId, 'faq_id'),
            ],
            'form.answer_html' => ['nullable', 'string'],
            'form.meta_title' => ['nullable', 'string', 'max:255'],
            'form.meta_description' => ['nullable', 'string'],
            'form.translation_payload_text' => ['nullable', 'string'],
        ];
    }

    private function loadFaq(): void
    {
        if (!$this->faqId) {
            return;
        }

        $faq = Faq::query()
            ->with('translations')
            ->findOrFail($this->faqId);

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $faq->translations->firstWhere('locale', $preferredLocale)
            ?? $faq->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $faq->translations->first();

        $this->form['code'] = $faq->code;
        $this->form['group_code'] = $faq->group_code;
        $this->form['is_active'] = (bool) $faq->is_active;
        $this->form['is_featured'] = (bool) $faq->is_featured;
        $this->form['sort_order'] = (int) $faq->sort_order;
        $this->form['payload_text'] = $faq->payload
            ? json_encode($faq->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';

        if ($translation) {
            $this->form['locale'] = $translation->locale;
            $this->form['question'] = $translation->question;
            $this->form['slug'] = $translation->slug;
            $this->form['answer_html'] = $translation->answer_html ?? '';
            $this->form['meta_title'] = $translation->meta_title ?? '';
            $this->form['meta_description'] = $translation->meta_description ?? '';
            $this->form['translation_payload_text'] = $translation->payload
                ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
        }
    }

    private function loadTranslationForLocale(): void
    {
        if (!$this->faqId) {
            $this->clearTranslationFields();
            return;
        }

        $translation = FaqTranslation::query()
            ->where('faq_id', $this->faqId)
            ->where('locale', $this->form['locale'])
            ->first();

        if (!$translation) {
            $this->clearTranslationFields();
            return;
        }

        $this->form['question'] = $translation->question;
        $this->form['slug'] = $translation->slug;
        $this->form['answer_html'] = $translation->answer_html ?? '';
        $this->form['meta_title'] = $translation->meta_title ?? '';
        $this->form['meta_description'] = $translation->meta_description ?? '';
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function clearTranslationFields(): void
    {
        $this->form['question'] = '';
        $this->form['slug'] = '';
        $this->form['answer_html'] = '';
        $this->form['meta_title'] = '';
        $this->form['meta_description'] = '';
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
}
