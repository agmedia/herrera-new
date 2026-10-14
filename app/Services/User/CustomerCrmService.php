<?php

namespace App\Services\User;

use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\CustomerSalesSession;
use App\Models\User\CustomerVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CustomerCrmService
{
    public function recordVisit(User $customer, array $data, User $actor): CustomerVisit
    {
        app(CustomerManagementService::class)->assertContactCustomer($customer, $actor);
        $data['note'] = trim((string) ($data['note'] ?? ''));
        $data['quote_reference'] = trim((string) ($data['quote_reference'] ?? '')) ?: null;
        $validated = Validator::make($data, [
            'visited_at' => ['required', 'date', 'before_or_equal:now'],
            'contact_type' => ['required', Rule::in(['visit', 'phone', 'email'])],
            'outcome' => ['required', Rule::in(['order', 'quote', 'nothing'])],
            'note' => ['required', 'string', 'min:3', 'max:5000'],
            'order_id' => [Rule::requiredIf(($data['outcome'] ?? '') === 'order'), 'nullable', 'integer', Rule::exists('orders', 'id')->where('user_id', $customer->id)],
            'quote_reference' => [Rule::requiredIf(($data['outcome'] ?? '') === 'quote'), 'nullable', 'string', 'max:191'],
        ])->validate();
        $visit = CustomerVisit::query()->create([
            ...$validated, 'customer_user_id' => $customer->id, 'sales_rep_id' => $actor->id, 'performed_by_id' => $this->performer($actor)->id,
            'order_id' => $validated['outcome'] === 'order' ? ($validated['order_id'] ?? null) : null,
            'quote_reference' => $validated['outcome'] === 'quote' ? $validated['quote_reference'] : null,
        ]);
        activity('customer-crm')->performedOn($customer)->causedBy($actor)->event('customer_visit_recorded')
            ->withProperties(['visit_id' => $visit->id, 'contact_type' => $visit->contact_type, 'outcome' => $visit->outcome])
            ->log('Evidentiran kontakt s kupcem.');

        return $visit;
    }

    public function startSession(User $customer, User $actor): CustomerSalesSession
    {
        return CustomerSalesSession::query()->create([
            'customer_user_id' => $customer->id, 'sales_rep_id' => $actor->id, 'performed_by_id' => $this->performer($actor)->id, 'started_at' => now(), 'outcome' => 'open',
        ]);
    }

    public function recordSessionOrder(Request $request, Order $order, User $actor): void
    {
        $id = (int) $request->session()->get(CustomerImpersonationService::SESSION_KEY.'.sales_session_id');
        CustomerSalesSession::query()->whereKey($id)->where('sales_rep_id', $actor->id)->where('customer_user_id', $order->user_id)
            ->whereNull('ended_at')->update(['order_id' => $order->id, 'outcome' => 'order', 'updated_at' => now()]);
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
