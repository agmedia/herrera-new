<?php

namespace App\Services\Integrations\Msan;

/**
 * @deprecated Use MsanPricesAndStockSyncService. This alias is kept so jobs
 *             already serialized before deployment can still be processed.
 */
class MsanAvailabilitySyncService extends MsanPricesAndStockSyncService {}
