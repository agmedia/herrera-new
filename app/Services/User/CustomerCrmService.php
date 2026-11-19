<?php

namespace App\Services\User;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\CustomerSalesSession;
use App\Models\User\CustomerVisit;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerCrmService
{
    public function recordVisit(User $customer, array $data, User $actor): CustomerVisit
    {
        app(CustomerManagementService::class)->assertContactCustomer($customer, $actor);
        $data['note'] = trim((string) ($data['note'] ?? ''));
        $data['quote_reference'] = trim((string) ($data['quote_reference'] ?? '')) ?: null;
        $validated = Validator::make($data, [
            'presented_skus' => ['nullable', 'string', 'max:4000'],
            'visited_at' => ['required', 'date', 'before_or_equal:now'],
            'contact_type' => ['required', Rule::in(['visit', 'phone', 'email'])],
            'outcome' => ['required', Rule::in(['order', 'quote', 'nothing'])],
            'note' => ['required', 'string', 'min:3', 'max:5000'],
            'order_id' => [Rule::requiredIf(($data['outcome'] ?? '') === 'order'), 'nullable', 'integer', Rule::exists('orders', 'id')->where('user_id', $customer->id)],
            'quote_reference' => [Rule::requiredIf(($data['outcome'] ?? '') === 'quote'), 'nullable', 'string', 'max:191'],
        ])->validate();
        $skus = collect(preg_split('/[,\r\n]+/', $validated['presented_skus'] ?? ''))->map(fn ($sku) => trim($sku))
            ->filter(fn ($sku) => $sku !== '')->uniqueStrict()->values();
        unset($validated['presented_skus']);
        $products = $skus->isEmpty() ? collect() : Product::with('translations')->whereIn('sku', $skus)->get();
        $missing = $skus->diff($products->pluck('sku'));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['presented_skus' => 'Provjerite šifre artikala: '.$missing->implode(', ')]);
        }

        return DB::transaction(function () use ($validated, $data, $customer, $actor, $products) {
            $visit = CustomerVisit::query()->create([
                ...$validated, 'location' => $validated['contact_type'] === 'visit' ? DeviceLocation::from($data['location'] ?? null) : null,
                'customer_user_id' => $customer->id, 'sales_rep_id' => $actor->id, 'performed_by_id' => $this->performer($actor)->id,
                'order_id' => $validated['outcome'] === 'order' ? ($validated['order_id'] ?? null) : null,
                'quote_reference' => $validated['outcome'] === 'quote' ? $validated['quote_reference'] : null,
            ]);
            activity('customer-crm')->performedOn($customer)->causedBy($actor)->event('customer_visit_recorded')
                ->withProperties(['visit_id' => $visit->id, 'contact_type' => $visit->contact_type, 'outcome' => $visit->outcome])
                ->log('Evidentiran kontakt s kupcem.');

            if ($products->isNotEmpty()) {
                $this->recordPresentations($customer, $actor, $products, (string) Str::uuid(), $visit->note, $visit->visited_at, $visit->location);
            }

            return $visit;
        });
    }

    public function recordPresentations(User $customer, User $actor, Collection $products, string $batch, ?string $note, DateTimeInterface $at, ?array $location = null): void
    {
        app(CustomerManagementService::class)->assertContactCustomer($customer, $actor);
        $performedBy = $this->performer($actor);
        $rows = $products->map(fn (Product $product) => [
            'customer_user_id' => $customer->id, 'sales_rep_id' => $actor->id, 'performed_by_id' => $performedBy->id,
            'product_id' => $product->id, 'sku' => $product->sku ?: $product->code,
            'product_name' => $product->translations->firstWhere('locale', 'hr')?->name ?: $product->code,
            'batch' => $batch, 'note' => $note, 'presented_at' => $at,
            'location' => $location ? json_encode($location, JSON_THROW_ON_ERROR) : null,
        ])->all();
        DB::table('customer_product_presentations')->upsert($rows, ['customer_user_id', 'sales_rep_id', 'batch', 'product_id'], ['batch']);
    }

    public function startSession(User $customer, User $actor): CustomerSalesSession
    {
        return CustomerSalesSession::query()->create([
            'customer_user_id' => $customer->id, 'sales_rep_id' => $actor->id, 'performed_by_id' => $this->performer($actor)->id, 'started_at' => now(), 'outcome' => 'open',
            'payload' => ['location' => DeviceLocation::from(request()->input('location'))],
        ]);
    }

    public function recordSessionOrder(Request $request, Order $order, User $actor): void
    {
        $id = (int) $request->session()->get(CustomerImpersonationService::SESSION_KEY.'.sales_session_id');
        $updated = CustomerSalesSession::query()->whereKey($id)->where('sales_rep_id', $actor->id)->where('customer_user_id', $order->user_id)
            ->whereNull('ended_at')->update(['order_id' => $order->id, 'outcome' => 'order', 'updated_at' => now()]);
        if ($updated) {
            $order->forceFill(['sales_session_id' => $id])->save();
        }
    }

    public function endSession(array $context): void
    {
        $session = CustomerSalesSession::query()->whereKey((int) ($context['sales_session_id'] ?? 0))
            ->where('sales_rep_id', (int) ($context['admin_id'] ?? 0))->where('customer_user_id', (int) ($context['customer_id'] ?? 0))
            ->whereNull('ended_at')->first();
        if ($session) {
            $session->update(['ended_at' => now(), 'outcome' => $session->outcome === 'order' ? 'order' : 'nothing']);
        }
    }

    private function performer(User $actor): User
    {
        if ((int) request()->user()?->id !== (int) $actor->id) {
            return $actor;
        }

        return app(StaffImpersonationService::class)->validatedContext(request(), $actor)['actor'] ?? $actor;
    }
}
