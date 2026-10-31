<?php

namespace App\Support\Ops;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Row counts and MySQL CHECKSUM TABLE values for every base table. Written next
 * to each backup and compared against a restored copy, so a restore is proven
 * complete rather than assumed.
 */
class DatabaseManifest
{
    /**
     * Connection to $database on the same server/credentials as the default one.
     */
    public static function connection(?string $database = null): Connection
    {
        if ($database === null || $database === '') {
            return DB::connection();
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new \InvalidArgumentException('Database names may only contain letters, digits and underscores.');
        }

        $default = config('database.default');
        config(['database.connections.ops_manifest' => array_merge(
            config("database.connections.{$default}"),
            ['database' => $database],
        )]);
        DB::purge('ops_manifest');

        return DB::connection('ops_manifest');
    }

    /**
     * @return array{database: string, generated_at: string, tables: array<string, array{rows: int, checksum: string|null}>}
     */
    public static function build(Connection $db): array
    {
        $tables = collect($db->select(
            "select table_name as name from information_schema.tables
             where table_schema = database() and table_type = 'BASE TABLE' order by table_name"
        ))->pluck('name');

        $out = [];
        foreach ($tables as $table) {
            $quoted = '`'.str_replace('`', '``', $table).'`';
            $rows = (int) $db->selectOne("select count(*) as c from {$quoted}")->c;
            $checksum = $db->selectOne("checksum table {$quoted}");
            $out[$table] = [
                'rows' => $rows,
                'checksum' => isset($checksum->Checksum) ? (string) $checksum->Checksum : null,
            ];
        }

        return [
            'database' => (string) $db->getDatabaseName(),
            'generated_at' => now()->toIso8601String(),
            'tables' => $out,
        ];
    }

    /**
     * @param  array{tables: array<string, array{rows: int, checksum: string|null}>}  $expected
     * @param  array{tables: array<string, array{rows: int, checksum: string|null}>}  $actual
     * @param  list<string>  $ignore  tables that may legitimately change during a live backup
     * @return list<string> problems (empty = identical)
     */
    public static function compare(array $expected, array $actual, array $ignore = []): array
    {
        $problems = [];

        foreach ($expected['tables'] as $table => $want) {
            if (in_array($table, $ignore, true)) {
                continue;
            }
            $got = $actual['tables'][$table] ?? null;
            if ($got === null) {
                $problems[] = "{$table}: missing";

                continue;
            }
            if ($got['rows'] !== $want['rows']) {
                $problems[] = "{$table}: {$got['rows']} rows, expected {$want['rows']}";
            } elseif ($got['checksum'] !== $want['checksum']) {
                $problems[] = "{$table}: checksum differs";
            }
        }

        foreach (array_diff(array_keys($actual['tables']), array_keys($expected['tables'])) as $extra) {
            $problems[] = "{$extra}: not in the backup manifest";
        }

        return $problems;
    }
}
