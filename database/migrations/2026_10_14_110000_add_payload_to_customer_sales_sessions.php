<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve the requested/current connection afresh: a previously resolved
        // Schema facade may still hold the original database during rehearsal.
        $schema = Schema::connection($this->getConnection());
        $column = collect($schema->getColumns('customer_sales_sessions'))->firstWhere('name', 'payload');
        if ($column) {
            $connection = $schema->getConnection();
            $json = $column['type_name'] === 'json' || ($connection->getDriverName() === 'sqlite' && $column['type_name'] === 'text');
            // MariaDB exposes JSON as LONGTEXT with an enforced JSON_VALID check.
            if (! $json && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) && $column['type_name'] === 'longtext') {
                $definition = array_values((array) $connection->selectOne('SHOW CREATE TABLE '.$connection->getQueryGrammar()->wrapTable('customer_sales_sessions')))[1];
                $json = preg_match('/json_valid\s*\(\s*`?payload`?\s*\)/i', $definition) === 1;
            }
            if (! $column['nullable'] || ! $json) {
                throw new RuntimeException('Existing customer sales session payload must be nullable JSON; no schema changes were made.');
            }

            return;
        }
        $schema->table('customer_sales_sessions', fn (Blueprint $table) => $table->json('payload')->nullable());
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        if ($schema->hasColumn('customer_sales_sessions', 'payload')) {
            $schema->table('customer_sales_sessions', fn (Blueprint $table) => $table->dropColumn('payload'));
        }
    }
};
