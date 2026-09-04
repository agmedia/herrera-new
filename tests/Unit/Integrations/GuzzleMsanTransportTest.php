<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\Msan\GuzzleMsanTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GuzzleMsanTransportTest extends TestCase
{
    public function test_it_forces_verified_https_mtls_and_streams_to_sink(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'text/xml'], '<NewDataSet />'),
        ]));
        $stack->push(Middleware::history($history));
        $transport = new GuzzleMsanTransport(new Client(['handler' => $stack]));
        $destination = tempnam(sys_get_temp_dir(), 'msan-http-');
        $this->assertIsString($destination);

        try {
            $response = $transport->sendToFile(
                'GET',
                'https://b2b.msan.hr/B2BService/HTTP/Product/GetProductsList.aspx',
                $destination,
                [
                    'certificate_path' => '/private/synthetic-client.p12',
                    'certificate_pin' => 'synthetic-pin',
                    'ca_path' => '/private/synthetic-ca.pem',
                    'max_bytes' => 1024,
                    'connect_timeout' => 12,
                    'timeout' => 90,
                    'headers' => ['Accept' => 'text/xml'],
                    'query' => [],
                ],
            );

            $this->assertSame(200, $response->status);
            $this->assertSame('<NewDataSet />', file_get_contents($destination));
            $options = $history[0]['options'];
            $this->assertSame('/private/synthetic-ca.pem', $options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame($destination, $options['sink']);
            $this->assertTrue($options['curl'][CURLOPT_SSL_VERIFYPEER]);
            $this->assertSame(2, $options['curl'][CURLOPT_SSL_VERIFYHOST]);
            $this->assertSame('P12', $options['curl'][CURLOPT_SSLCERTTYPE]);
            $this->assertSame('/private/synthetic-client.p12', $options['curl'][CURLOPT_SSLCERT]);
            $this->assertSame('synthetic-pin', $options['curl'][CURLOPT_KEYPASSWD]);
            $this->assertSame('/private/synthetic-ca.pem', $options['curl'][CURLOPT_CAINFO]);
            $this->expectException(RuntimeException::class);
            $options['progress'](2048, 0);
        } finally {
            @unlink($destination);
        }
    }

    public function test_it_does_not_expose_low_level_transport_errors(): void
    {
        $request = new Request('GET', 'https://b2b.msan.hr/');
        $stack = HandlerStack::create(new MockHandler([
            new ConnectException('synthetic-pin leaked by low level', $request),
        ]));
        $transport = new GuzzleMsanTransport(new Client(['handler' => $stack]));
        $destination = tempnam(sys_get_temp_dir(), 'msan-http-');
        $this->assertIsString($destination);

        try {
            $transport->sendToFile('GET', 'https://b2b.msan.hr/', $destination, [
                'certificate_path' => '/private/synthetic-client.p12',
                'certificate_pin' => 'synthetic-pin',
            ]);
            $this->fail('Transport exception expected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('M SAN transportni zahtjev nije uspio.', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-pin', $exception->getMessage());
        } finally {
            @unlink($destination);
        }
    }
}
