<?php

namespace Tests\Feature\Integrations;

use App\Services\Integrations\Stock\LegacyStockEnvironmentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LegacyStockEnvironmentReaderTest extends TestCase
{
    public function test_it_returns_only_the_four_literal_import_api_values(): void
    {
        $source = <<<'PHP'
        <?php
        // Other service credentials must never be extracted.
        define('OC_ENV', [
            'api' => ['url' => 'https://wrong.example.test', 'username' => 'wrong', 'password' => 'wrong', 'token' => 'wrong'],
            'import' => [
                'default_language' => 3,
                'api' => [
                    'url' => 'https://erp.example.test/api/',
                    'username' => 'test-user',
                    'password' => 'test-password',
                    'token' => 'test-token',
                    'other_secret' => 'must-be-ignored',
                ],
            ],
        ]);
        PHP;

        $this->assertSame($this->connection(), $this->read($source));
    }

    public function test_it_supports_array_syntax_comments_and_unrelated_dynamic_values(): void
    {
        $source = <<<'PHP'
        <?php
        define /* old configuration */ ('OC_ENV', array(
            'unrelated' => getenv('UNRELATED_SETTING'),
            'import' => array(
                'api' => array(
                    'url' => 'https://erp.example.test/api/',
                    'username' => 'test-user', /* inline comment */
                    'password' => 'test-password',
                    'token' => 'test-token',
                ),
            ),
        ));
        PHP;

        $this->assertSame($this->connection(), $this->read($source));
    }

    public function test_quoted_and_escaped_credentials_are_decoded_faithfully(): void
    {
        $source = <<<'PHP'
        <?php
        define('OC_ENV', ['import' => ['api' => [
            'url' => 'https://erp.example.test/api/',
            'username' => 'test-user',
            'password' => 'p\'ass\\word\n',
            'token' => "a\"b\$c\n\t\x41\101\u{010D}\\z",
        ]]]);
        PHP;

        $expected = $this->connection();
        $expected['password'] = "p'ass\\word\\n";
        $expected['token'] = "a\"b\$c\n\tAAč\\z";
        $this->assertSame($expected, $this->read($source));
    }

    public function test_reading_never_executes_php_before_or_inside_the_configuration(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'stock-reader-execution-');
        unlink($marker);
        $source = '<?php file_put_contents('.var_export($marker, true).', "executed"); '
            .'define("OC_ENV", ["import" => ["api" => ["url" => "https://erp.example.test/api/", "username" => "test-user", "password" => "test-password", "token" => "test-token"]]]);';
        try {
            $this->assertSame($this->connection(), $this->read($source));
            $this->assertFileDoesNotExist($marker);

            $dynamic = str_replace('"password" => "test-password"', '"password" => file_put_contents('.var_export($marker, true).', "executed")', $source);
            $this->assertSame([], $this->read($dynamic));
            $this->assertFileDoesNotExist($marker);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    #[DataProvider('unsupportedConfigurations')]
    public function test_missing_or_dynamic_credentials_are_not_imported(string $source): void
    {
        $this->assertSame([], $this->read($source));
    }

    public static function unsupportedConfigurations(): array
    {
        return [
            'wrong constant' => ["<?php define('OTHER_ENV', ['import' => ['api' => ['url' => 'wrong']]]);"],
            'unrelated API only' => ["<?php define('OC_ENV', ['other' => ['api' => ['url' => 'wrong']]]);"],
            'missing token' => ["<?php define('OC_ENV', ['import' => ['api' => ['url' => 'https://erp.example.test', 'username' => 'test', 'password' => 'test']]]);"],
            'dynamic password' => ["<?php define('OC_ENV', ['import' => ['api' => ['url' => 'https://erp.example.test', 'username' => 'test', 'password' => getenv('SECRET'), 'token' => 'test']]]);"],
            'concatenated password' => ["<?php define('OC_ENV', ['import' => ['api' => ['url' => 'https://erp.example.test', 'username' => 'test', 'password' => 'test'.'suffix', 'token' => 'test']]]);"],
            'interpolated password' => ['<?php define("OC_ENV", ["import" => ["api" => ["url" => "https://erp.example.test", "username" => "test", "password" => "$password", "token" => "test"]]]);'],
            'dynamic whole configuration' => ["<?php define('OC_ENV', require('production.php'));"],
            'method with same name' => ["<?php \$object->define('OC_ENV', ['import' => ['api' => ['url' => 'https://erp.example.test', 'username' => 'test', 'password' => 'test', 'token' => 'test']]]);"],
        ];
    }

    public function test_malformed_arrays_fail_without_exposing_source_or_path(): void
    {
        try {
            $this->read("<?php define('OC_ENV', ['import' => ['api' => ['password' => 'secret-value'");
            $this->fail('Expected malformed source to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stara konfiguracija nema podržanu strukturu.', $exception->getMessage());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }

    public function test_dynamic_array_unpack_cannot_override_a_literal_configuration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->read("<?php define('OC_ENV', ['import' => ['api' => ['url' => 'https://erp.example.test', 'username' => 'test', 'password' => 'test', 'token' => 'test']], ...getenv('OVERRIDE')]);");
    }

    private function connection(): array
    {
        return ['url' => 'https://erp.example.test/api/', 'username' => 'test-user', 'password' => 'test-password', 'token' => 'test-token'];
    }

    private function read(string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'stock-reader-test-');
        file_put_contents($path, $source);
        try {
            return app(LegacyStockEnvironmentReader::class)->read($path);
        } finally {
            unlink($path);
        }
    }
}
