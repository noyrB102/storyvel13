<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TransferSqliteToMysql extends Command
{
    protected $signature = 'db:transfer-sqlite-to-mysql
                            {--sqlite-path= : Absolute path to the SQLite file (defaults to database/database.sqlite)}';

    protected $description = 'Transfer data from a SQLite database into the current MySQL connection';

    protected array $tables = [
        'users',
        'stories',
        'story_messages',
        'story_originals',
        'ideas',
        'story_drafts',
    ];

    public function handle(): int
    {
        $sqlitePath = $this->option('sqlite-path')
            ?? database_path('database.sqlite');

        if (! file_exists($sqlitePath)) {
            $this->error("SQLite file not found at: {$sqlitePath}");
            return self::FAILURE;
        }

        $this->info("Reading from: {$sqlitePath}");
        $this->info('Writing to MySQL: ' . config('database.connections.mysql.database'));
        $this->newLine();

        $sqlite = DB::connectUsing('sqlite_transfer', [
            'driver'   => 'sqlite',
            'database' => $sqlitePath,
            'prefix'   => '',
        ]);

        foreach ($this->tables as $table) {
            $this->transferTable($sqlite, $table);
        }

        $this->newLine();
        $this->info('Transfer complete!');

        return self::SUCCESS;
    }

    protected function transferTable($sqlite, string $table): void
    {
        if (! $sqlite->getSchemaBuilder()->hasTable($table)) {
            $this->line("  <comment>Skipping {$table} (not in SQLite)</comment>");
            return;
        }

        $rows = $sqlite->table($table)->get()->map(fn ($r) => (array) $r)->toArray();

        if (empty($rows)) {
            $this->line("  <comment>Skipping {$table} (empty)</comment>");
            return;
        }

        $this->line("  Transferring <info>{$table}</info> (" . count($rows) . " rows)...");

        DB::table($table)->delete();

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        $this->line("  <info>✓ {$table}</info> — " . count($rows) . ' rows');
    }
}
