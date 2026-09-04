<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Settings\System\WithdrawalSettings;
use App\Mail\ContractWithdrawalAdminMail;
use App\Mail\ContractWithdrawalReceiptMail;
use App\Models\Sales\ContractWithdrawal;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ContractWithdrawalsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_process_contract_withdrawals(): void
    {
        $admin = $this->makeUserWithRole('admin');
        $withdrawal = $this->makeWithdrawal();

        $this->actingAs($admin)
            ->get('/admin/withdrawals')
            ->assertOk()
            ->assertSee($withdrawal->reference)
            ->assertSee($withdrawal->full_name);

        $this->actingAs($admin)
            ->get('/admin/withdrawals/'.$withdrawal->id)
            ->assertOk()
            ->assertSee($withdrawal->declaration)
            ->assertSee($withdrawal->items);

        $this->actingAs($admin)
            ->patch('/admin/withdrawals/'.$withdrawal->id, [
                'status' => ContractWithdrawal::STATUS_COMPLETED,
                'internal_note' => 'Povrat je obrađen.',
            ])
            ->assertRedirect('/admin/withdrawals/'.$withdrawal->id);

        $this->assertDatabaseHas('contract_withdrawals', [
            'id' => $withdrawal->id,
            'status' => ContractWithdrawal::STATUS_COMPLETED,
            'internal_note' => 'Povrat je obrađen.',
            'handled_by' => $admin->id,
        ]);
        $this->assertNotNull($withdrawal->fresh()?->completed_at);
    }

    public function test_editor_cannot_access_contract_withdrawal_admin(): void
    {
        $editor = $this->makeUserWithRole('editor');
        $withdrawal = $this->makeWithdrawal();

        $this->actingAs($editor)->get('/admin/withdrawals')->assertForbidden();
        $this->actingAs($editor)->get('/admin/withdrawals/'.$withdrawal->id)->assertForbidden();
    }

    public function test_admin_can_save_withdrawal_settings(): void
    {
        $admin = $this->makeUserWithRole('admin');

        Livewire::actingAs($admin)
            ->test(WithdrawalSettings::class)
            ->set('adminEmail', 'returns@example.test')
            ->set('returnAddress', 'Example d.o.o., Ulica 1, Zagreb')
            ->set('instructions', 'Robu sigurno zapakirajte.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('notify');

        $settings = app(SystemSettingsService::class);
        $this->assertSame('returns@example.test', $settings->get('store_withdrawal_admin_email'));
        $this->assertSame('Example d.o.o., Ulica 1, Zagreb', $settings->get('store_withdrawal_return_address'));
        $this->assertSame('Robu sigurno zapakirajte.', $settings->get('store_withdrawal_instructions'));
    }

    public function test_admin_can_resend_both_notifications(): void
    {
        Mail::fake();
        $admin = $this->makeUserWithRole('admin');
        $withdrawal = $this->makeWithdrawal();

        app(SystemSettingsService::class)->putMany([
            'store_withdrawal_admin_email' => 'returns@example.test',
            'store_withdrawal_return_address' => 'Example d.o.o., Ulica 1, Zagreb',
        ]);

        $this->actingAs($admin)
            ->post('/admin/withdrawals/'.$withdrawal->id.'/resend')
            ->assertRedirect('/admin/withdrawals/'.$withdrawal->id);

        Mail::assertSent(ContractWithdrawalReceiptMail::class, static fn ($mail): bool =>
            $mail->hasTo('buyer@example.test')
        );
        Mail::assertSent(ContractWithdrawalAdminMail::class, static fn ($mail): bool =>
            $mail->hasTo('returns@example.test')
        );

        $withdrawal->refresh();
        $this->assertNotNull($withdrawal->consumer_notified_at);
        $this->assertNotNull($withdrawal->admin_notified_at);
        $this->assertNull($withdrawal->notification_error);
    }

    private function makeWithdrawal(): ContractWithdrawal
    {
        $snapshot = [
            'version' => '2026-06-19',
            'submitted_at' => now()->toIso8601String(),
            'data' => ['order_number' => 'T-1001'],
        ];

        return ContractWithdrawal::query()->create([
            'reference' => 'JR-20260729-TEST01',
            'submission_key' => hash('sha256', 'test-submission'),
            'order_number' => 'T-1001',
            'full_name' => 'Ana Horvat',
            'email' => 'buyer@example.test',
            'phone' => '+385 91 000 0000',
            'address_line' => 'Ilica 1',
            'postal_code' => '10000',
            'city' => 'Zagreb',
            'country_code' => 'HR',
            'contract_date' => now()->subDays(8)->toDateString(),
            'received_date' => now()->subDays(5)->toDateString(),
            'items' => 'Proizvod A, 1 kom',
            'declaration' => 'Ovime raskidam ugovor T-1001.',
            'request_snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot)),
            'status' => ContractWithdrawal::STATUS_RECEIVED,
            'locale' => 'hr',
            'submitted_at' => now(),
        ]);
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        Bouncer::assign($role)->to($user);

        return $user;
    }
}
