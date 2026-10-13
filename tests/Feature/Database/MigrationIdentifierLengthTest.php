<?php

/*
 * MySQL and MariaDB reject identifiers longer than 64 characters (error
 * 1059), SQLite does not. Index names Laravel generates from table and
 * column names can silently exceed it, so the test suite, which runs on
 * SQLite, checks every index of the migrated schema here.
 */

use Illuminate\Support\Facades\Schema;

const MYSQL_IDENTIFIER_MAX_LENGTH = 64;

function migratedTables(): array
{
    return collect(Schema::getTables())
        ->pluck('name')
        ->reject(fn (string $table) => str_starts_with($table, 'sqlite_'))
        ->values()
        ->all();
}

it('keeps every index name within the MySQL/MariaDB identifier limit', function () {
    $tooLong = [];

    foreach (migratedTables() as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if (mb_strlen($index['name']) > MYSQL_IDENTIFIER_MAX_LENGTH) {
                $tooLong[] = "{$table}: {$index['name']} (".mb_strlen($index['name']).')';
            }
        }
    }

    expect($tooLong)->toBe([]);
});

it('keeps every default foreign key name within the MySQL/MariaDB identifier limit', function () {
    // SQLite does not keep foreign key names, so the name Laravel generates
    // on MySQL ("{table}_{columns}_foreign") is rebuilt from the columns.
    $tooLong = [];

    foreach (migratedTables() as $table) {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $name = strtolower($table.'_'.implode('_', $foreignKey['columns']).'_foreign');

            if (mb_strlen($name) > MYSQL_IDENTIFIER_MAX_LENGTH) {
                $tooLong[] = "{$table}: {$name} (".mb_strlen($name).')';
            }
        }
    }

    expect($tooLong)->toBe([]);
});
