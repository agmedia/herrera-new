<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\ContractWithdrawal;
use App\Services\Front\ContractWithdrawalNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContractWithdrawalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_keys(ContractWithdrawal::statuses()))],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');

        $withdrawals = ContractWithdrawal::query()
            ->with(['order:id,order_number', 'handler:id,name'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested
                        ->where('reference', 'like', '%'.$search.'%')
                        ->orWhere('order_number', 'like', '%'.$search.'%')
                        ->orWhere('full_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('submitted_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.withdrawals.index', [
            'withdrawals' => $withdrawals,
            'statuses' => ContractWithdrawal::statuses(),
            'search' => $search,
            'selectedStatus' => $status,
        ]);
    }

    public function show(ContractWithdrawal $withdrawal): View
    {
        $withdrawal->load(['order', 'user:id,name,email', 'handler:id,name']);

        return view('admin.withdrawals.show', [
            'withdrawal' => $withdrawal,
            'statuses' => ContractWithdrawal::statuses(),
        ]);
    }

    public function update(Request $request, ContractWithdrawal $withdrawal): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(ContractWithdrawal::statuses()))],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = (string) $validated['status'];
        $isClosed = in_array($status, [
            ContractWithdrawal::STATUS_COMPLETED,
            ContractWithdrawal::STATUS_DECLINED,
        ], true);

        $withdrawal->forceFill([
            'status' => $status,
            'internal_note' => trim((string) ($validated['internal_note'] ?? '')) ?: null,
            'handled_by' => $request->user()?->id,
            'handled_at' => now(),
            'completed_at' => $isClosed ? ($withdrawal->completed_at ?? now()) : null,
        ])->save();

        return redirect()
            ->route('admin.withdrawals.show', $withdrawal)
            ->with('notify', [
                'type' => 'success',
                'message' => 'Status raskida ugovora je spremljen.',
            ]);
    }

    public function resend(
        ContractWithdrawal $withdrawal,
        ContractWithdrawalNotificationService $notifications,
    ): RedirectResponse {
        $withdrawal->forceFill(['notification_error' => null])->save();
        $notifications->send($withdrawal);
        $withdrawal->refresh();

        return redirect()
            ->route('admin.withdrawals.show', $withdrawal)
            ->with('notify', [
                'type' => $withdrawal->notification_error ? 'warning' : 'success',
                'message' => $withdrawal->notification_error
                    ? 'Slanje nije u cijelosti uspjelo. Provjerite prikazanu pogrešku.'
                    : 'Potvrda korisniku i obavijest administratoru ponovno su poslane.',
            ]);
    }
}
