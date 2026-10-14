<?php

namespace App\Services\Import;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Copies an already frozen local database into a new, exclusively rehearsal-owned schema. */
class HerreraLocalDatabaseCopy
{
    public function copy(ConnectionInterface $server, string $source, string $destination): int
    {
        HerreraCutoverSafety::assertNoSplitConnection($server->getConfig());
        if (! app()->environment('local')
            || ! in_array($server->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || ($source !== 'herrera_new_migration' && ! preg_match('/^herrera_live_snapshot_[a-zA-Z0-9_]+$/D', $source))
            || ! preg_match('/^herrera_rehearsal_(?:source|target)_[0-9]{8}_[0-9]{6}_[a-f0-9]{4}$/D', $destination)
            || $source === $destination) {
            throw new RuntimeException('Database copy rejected: only new local rehearsal schemas are permitted.');
        }
        $tables = $server->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.tables WHERE table_schema = ? ORDER BY TABLE_NAME', [$source]);
        if ($tables === [] || collect($tables)->contains(fn ($table) => $table->type !== 'BASE TABLE')) {
            throw new RuntimeException('The frozen source must contain ordinary tables; review views separately.');
        }
        if ($server->selectOne('SELECT SCHEMA_NAME FROM information_schema.schemata WHERE schema_name = ?', [$destination])) {
            throw new RuntimeException('The rehearsal schema already exists; it will never be replaced.');
        }
        $server->statement('CREATE DATABASE `'.$destination.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $previousMode = (string) $server->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $previousForeignKeys = (int) $server->selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled;
        // Preserve legacy zero dates and zero IDs exactly in the isolated copy.
        $copyModes = array_values(array_diff(explode(',', $previousMode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES']));
        $copyModes[] = 'NO_AUTO_VALUE_ON_ZERO';
        $server->statement('SET SESSION sql_mode = ?', [implode(',', array_unique($copyModes))]);
        $server->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                $name = $table->name;
                if (! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                    throw new RuntimeException('An unsupported table identifier requires review.');
                }
                $definition = array_values((array) $server->selectOne('SHOW CREATE TABLE `'.$source.'`.`'.$name.'`'))[1];
                $sql = preg_replace('/^CREATE TABLE `'.preg_quote($name, '/').'`/', 'CREATE TABLE `'.$destination.'`.`'.$name.'`', $definition, 1, $replaced);
                if ($replaced !== 1) {
                    throw new RuntimeException('The table definition could not be safely qualified.');
                }
                $server->statement($sql);
            }
            if ($server->selectOne('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>? LIMIT 1', [$destination, $destination])) {
                throw new RuntimeException('A copied foreign key points outside the rehearsal database; importing is blocked.');
            }
            // All DDL is completed before the consistent data transaction begins.
            $server->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $server->beginTransaction();
            try {
                foreach ($tables as $table) {
                    $name = $table->name;
                    $columns = collect($server->select('SELECT COLUMN_NAME AS name FROM information_schema.columns WHERE table_schema=? AND table_name=? AND EXTRA NOT LIKE ? AND EXTRA NOT LIKE ? ORDER BY ORDINAL_POSITION', [$source, $name, '%VIRTUAL GENERATED%', '%STORED GENERATED%']))
                        ->map(fn ($column) => '`'.str_replace('`', '``', $column->name).'`')->implode(',');
                    $server->statement('INSERT INTO `'.$destination.'`.`'.$name.'` ('.$columns.') SELECT '.$columns.' FROM `'.$source.'`.`'.$name.'`');
                }
                $server->commit();
            } catch (\Throwable $exception) {
                $server->rollBack();
                throw $exception;
            }
        } finally {
            $server->statement('SET FOREIGN_KEY_CHECKS='.$previousForeignKeys);
            $server->statement('SET SESSION sql_mode = ?', [$previousMode]);
        }

        return count($tables);
    }
}
