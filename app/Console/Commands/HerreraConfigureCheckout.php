<?php

namespace App\Console\Commands;

use App\Services\Import\HerreraCheckoutConfigurationService;
use Illuminate\Console\Command;

class HerreraConfigureCheckout extends Command
{
    protected $signature = 'herrera:configure-checkout {--source-connection=herrera_source}';

    protected $description = 'Restore Herrera shipping and payment settings from the isolated legacy snapshot';

    public function handle(HerreraCheckoutConfigurationService $configuration): int
    {
        try {
            $result = $configuration->import((string) $this->option('source-connection'));
            $this->info('Herrera checkout configured: '.$result['shipping_ranges'].' shipping ranges, '.$result['active_payment_methods'].' active payment methods. WSPay '.($result['wspay_active'] ? 'active' : 'inactive').'.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof \RuntimeException ? $exception->getMessage() : 'Checkout import failed. No settings were changed.');

            return self::FAILURE;
        }
    }
}
