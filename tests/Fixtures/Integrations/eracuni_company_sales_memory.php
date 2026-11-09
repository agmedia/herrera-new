<?php

use App\Services\Integrations\Eracuni\EracuniCompanySalesClient;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'app.timezone' => 'UTC']);
CarbonImmutable::setTestNow('2026-11-09 12:00:00');

$settings = Mockery::mock(StockSyncSettingsService::class);
$settings->shouldReceive('configured')->with('eracuni')->andReturn(true);
$settings->shouldReceive('connection')->with('eracuni')->andReturn([
    'url' => 'https://erp.example.test/api/WarehouseGetArticleStockQuantity',
    'username' => 'fixture-user', 'token' => 'fixture-token', 'password' => 'fixture-password',
]);
$requests = 0;
$largestBody = 0;
$itemsPerDocument = ($argv[1] ?? '') === 'oversized' ? 100 : 30;
$factory = new Factory;
// Use a non-recording transport: Http::fake() intentionally retains every
// response for assertions, which would measure the test harness, not production.
$factory->globalMiddleware(static function (callable $next) use (&$requests, &$largestBody, $itemsPerDocument) {
    return static function (RequestInterface $request) use (&$requests, &$largestBody, $itemsPerDocument) {
        if ($request->getUri()->getHost() !== 'erp.example.test') {
            throw new RuntimeException('Unexpected fixture destination.');
        }
        $requests++;
        parse_str((string) $request->getBody(), $parameters);
        if (basename($request->getUri()->getPath()) !== 'SalesInvoiceList' || ! isset($parameters['dateFrom'])) {
            return new FulfilledPromise(new Response(200, [], '{"response":{"result":[]}}'));
        }
        $from = $parameters['dateFrom'];
        $until = $parameters['dateTo'];
        $count = $from === $until ? 1 : 500;
        $code = 'RANGE-'.$from.'-'.$until;
        $item = json_encode([
            'productCode' => $code, 'quantity' => 1, 'productName' => str_repeat('p', 120),
            'description' => str_repeat('d', 240), 'unit' => 'kom', 'currency' => 'EUR',
            'price' => '1.25', 'netPrice' => '1.00', 'vatAmount' => '0.25',
            'vatPercentage' => '25', 'discountAmount' => '0.00', 'discountPercentage' => '0',
        ], JSON_THROW_ON_ERROR);
        $items = '['.implode(',', array_fill(0, $itemsPerDocument, $item)).']';
        $body = '{"response":{"result":[';
        for ($i = 0; $i < $count; $i++) {
            if ($i > 0) {
                $body .= ',';
            }
            $body .= '{"documentID":"'.$code.'-'.$i.'","date":"'.$from.'","Items":'.$items.'}';
        }
        $body .= ']}}';
        $largestBody = max($largestBody, strlen($body));

        return new FulfilledPromise(new Response(200, [], $body));
    };
});
Http::swap($factory);

try {
    $result = (new EracuniCompanySalesClient($settings))->fetch('2026-11-01', '2026-11-08');
} catch (\App\Services\Integrations\Eracuni\EracuniCompanySalesException $exception) {
    echo json_encode([
        'complete' => false, 'reason' => $exception->getMessage(),
        'request_count' => $requests, 'largest_body_bytes' => $largestBody,
        'peak_memory_bytes' => memory_get_peak_usage(true), 'memory_limit' => ini_get('memory_limit'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(2);
}
echo json_encode([
    'complete' => $result['complete'], 'request_count' => $requests,
    'document_count' => $result['document_count'], 'code_count' => count($result['recent_product_codes']),
    'parent_code_kept' => in_array('RANGE-2026-11-01-2026-11-08', $result['recent_product_codes'], true),
    'largest_body_bytes' => $largestBody, 'peak_memory_bytes' => memory_get_peak_usage(true),
    'memory_limit' => ini_get('memory_limit'),
], JSON_THROW_ON_ERROR).PHP_EOL;
