<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\ContractWithdrawal;
use App\Services\Front\ContractWithdrawalNotificationService;
use App\Services\Sales\ContractWithdrawalWorkflow;
use App\Services\Sales\ReturnWorkflowSettings;
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

    public function show(Request $request, ContractWithdrawal $withdrawal, ReturnWorkflowSettings $settings): View
    {
        $withdrawal->load(['order.items', 'user:id,name,email', 'handler:id,name', 'approver:id,name,email', 'lineItems.receipts.receiver:id,name']);

        return view('admin.withdrawals.show', [
            'withdrawal' => $withdrawal,
            'statuses' => ContractWithdrawal::statuses(),
            'canApprove' => $settings->canApprove($request->user()),
            'approverEmail' => $settings->approverEmail(),
        ]);
    }

    public function update(Request $request, ContractWithdrawal $withdrawal, ContractWithdrawalWorkflow $workflow): RedirectResponse
    {
        $action = $request->input('action', 'tracking');
        if (in_array($action, ['approve', 'receive', 'link_items'], true)) {
            $validated = $request->validate([
                'internal_note' => ['nullable', 'string', 'max:5000'],
                'line_quantities' => [$action === 'link_items' ? 'required' : 'nullable', 'array', 'max:500'],
                'line_quantities.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'receipts' => [$action === 'receive' ? 'required' : 'nullable', 'array', 'max:500'],
                'receipts.*.received' => ['required', 'integer', 'min:0', 'max:1000000'],
                'receipts.*.restocked' => ['required', 'integer', 'min:0', 'max:1000000'],
                'physical_receipt_confirmed' => [$action === 'receive' ? 'accepted' : 'nullable'],
                'inspected_sellable' => ['nullable', 'boolean'],
            ]);
            $note = trim((string) ($validated['internal_note'] ?? '')) ?: null;
            if ($action === 'approve') {
                $workflow->approve($withdrawal, $request->user(), $note);
            } elseif ($action === 'receive') {
                $workflow->receive($withdrawal, $request->user(), $validated['receipts'], $request->boolean('inspected_sellable'), $note);
            } else {
                $workflow->attachItems($withdrawal, $validated['line_quantities']);
            }

            return redirect()->route('admin.withdrawals.show', $withdrawal)
                ->with('notify', ['type' => 'success', 'message' => 'Obrada povrata / reklamacije je spremljena.']);
        }
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(ContractWithdrawal::statuses()))],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = (string) $validated['status'];
        $workflow->updateTracking($withdrawal, $request->user(), $status, trim((string) ($validated['internal_note'] ?? '')) ?: null);

        return redirect()
            ->route('admin.withdrawals.show', $withdrawal)
            ->with('notify', [
                'type' => 'success',
                'message' => 'Bilješka i status povrata / reklamacije su spremljeni.',
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
