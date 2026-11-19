<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $db = $schema->getConnection();

        $this->ensureLocation($schema, 'customer_visits');
        if (! $schema->hasColumn('orders', 'sales_session_id')) {
            $schema->table('orders', fn (Blueprint $table) => $table->foreignId('sales_session_id')->nullable());
        }
        $this->ensureOrderSessionReference($schema);

        $db->table('customer_sales_sessions')->whereIn('order_id', $db->table('customer_sales_sessions')->select('order_id')
            ->whereNotNull('order_id')->groupBy('order_id')->havingRaw('COUNT(*) = 1'))->chunkById(500, function ($rows) use ($db) {
                foreach ($rows as $row) {
                    $db->table('orders')->where('id', $row->order_id)->where('user_id', $row->customer_user_id)
                        ->whereNull('sales_session_id')->update(['sales_session_id' => $row->id]);
                }
            });

        $presentations = 'customer_product_presentations';
        if (! $schema->hasTable($presentations)) {
            $schema->create($presentations, function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_user_id');
                $table->foreignId('sales_rep_id')->nullable();
                $table->foreignId('performed_by_id')->nullable();
                $table->foreignId('product_id')->nullable();
                $table->string('sku');
                $table->string('product_name');
                $table->uuid('batch');
                $table->text('note')->nullable();
                $table->json('location')->nullable();
                $table->timestamp('presented_at');
            });
        } else {
            if (! $schema->hasColumns($presentations, ['id', 'customer_user_id', 'sales_rep_id', 'performed_by_id', 'product_id', 'sku', 'product_name', 'batch', 'note', 'presented_at'])) {
                throw new RuntimeException('Existing customer_product_presentations does not contain the expected sales report columns.');
            }
            $this->ensureLocation($schema, $presentations);
        }

        // MySQL can commit CREATE TABLE before the individual foreign keys and indexes.
        $this->ensureReferences($schema, $presentations, [
            'customer_user_id' => ['users', 'cascade'],
            'sales_rep_id' => ['users', 'set null'],
            'performed_by_id' => ['users', 'set null'],
            'product_id' => ['products', 'set null'],
        ]);
        if (! $schema->hasIndex($presentations, ['presented_at'])) {
            $schema->table($presentations, fn (Blueprint $table) => $table->index('presented_at'));
        }
        $batchKey = ['customer_user_id', 'sales_rep_id', 'batch', 'product_id'];
        if (! $schema->hasIndex($presentations, $batchKey, 'unique')) {
            $schema->table($presentations, fn (Blueprint $table) => $table->unique($batchKey, 'unique_presentation_batch_product'));
        }
    }

    private function ensureLocation(Builder $schema, string $table): void
    {
        $column = collect($schema->getColumns($table))->firstWhere('name', 'location');
        if ($column === null) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->json('location')->nullable());

            return;
        }
        $db = $schema->getConnection();
        $json = $column['type_name'] === 'json' || ($db->getDriverName() === 'sqlite' && $column['type_name'] === 'text');
        if ($column['type_name'] === 'longtext' && in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $definition = array_values((array) $db->selectOne('SHOW CREATE TABLE '.$db->getQueryGrammar()->wrapTable($table)))[1];
            $json = preg_match('/json_valid\s*\(\s*`?location`?\s*\)/i', $definition) === 1;
        }
        if (! $json || ! $column['nullable']) {
            throw new RuntimeException("Existing {$table}.location must be nullable JSON.");
        }
    }

    private function ensureOrderSessionReference(Builder $schema): void
    {
        $db = $schema->getConnection();
        $this->checkOrderSessions($db);

        if (! $db instanceof MySqlConnection || collect($schema->getForeignKeys('orders'))->contains('columns', ['sales_session_id'])) {
            $this->ensureReferences($schema, 'orders', ['sales_session_id' => ['customer_sales_sessions', 'set null']]);

            return;
        }

        $grammar = $db->getQueryGrammar();
        $orders = $grammar->wrapTable('orders');
        $sessions = $grammar->wrapTable('customer_sales_sessions');
        // InnoDB would otherwise make a full copy of orders to validate the foreign key.
        $db->statement('SET @herrera_orders_fk_checks = @@SESSION.foreign_key_checks');
        try {
            $db->statement('SET SESSION foreign_key_checks = 0');
            $db->statement("ALTER TABLE {$orders} ADD CONSTRAINT orders_sales_session_id_foreign
                FOREIGN KEY (sales_session_id) REFERENCES {$sessions} (id) ON DELETE SET NULL, ALGORITHM=INPLACE");
        } finally {
            $db->statement('SET SESSION foreign_key_checks = @herrera_orders_fk_checks');
        }

        // Catch writes between the initial check and the DDL. Retries check existing keys too.
        $this->checkOrderSessions($db);
    }

    private function checkOrderSessions(Connection $db): void
    {
        $orphan = $db->table('orders')->useWritePdo()
            ->leftJoin('customer_sales_sessions', 'customer_sales_sessions.id', '=', 'orders.sales_session_id')
            ->whereNotNull('orders.sales_session_id')->whereNull('customer_sales_sessions.id')
            ->first(['orders.id', 'orders.sales_session_id']);

        if ($orphan !== null) {
            throw new RuntimeException("Order {$orphan->id} references missing customer sales session {$orphan->sales_session_id}.");
        }
    }

    private function ensureReferences(Builder $schema, string $table, array $references): void
    {
        $existing = collect($schema->getForeignKeys($table));
        $schema->table($table, function (Blueprint $blueprint) use ($table, $references, $existing) {
            foreach ($references as $column => [$parent, $onDelete]) {
                $key = $existing->firstWhere('columns', [$column]);
                if ($key === null) {
                    $blueprint->foreign($column)->references('id')->on($parent)->onDelete($onDelete);
                } elseif ($key['foreign_table'] !== $parent || $key['foreign_columns'] !== ['id'] || $key['on_delete'] !== $onDelete) {
                    throw new RuntimeException("Incompatible foreign key on {$table}.{$column}.");
                }
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('customer_product_presentations');
        $key = collect($schema->getForeignKeys('orders'))->firstWhere('columns', ['sales_session_id']);
        $schema->table('orders', function (Blueprint $table) use ($key) {
            $table->dropForeign($key['name'] ?? ['sales_session_id']);
            $table->dropColumn('sales_session_id');
        });
        $schema->table('customer_visits', fn (Blueprint $table) => $table->dropColumn('location'));
    }
};
