<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Constitution v1.0 · P0 Reliability — "automatic backups + restore testing".
 *
 * Takes a PostgreSQL dump in the custom, compressed, restorable format (pg_dump -Fc), then IMMEDIATELY
 * verifies it is readable with `pg_restore --list` (a backup you cannot list is not a backup). Rotates
 * to the last --keep copies, and can push the dump offsite to any configured filesystem disk (S3, etc.)
 * — because a backup on the same box is not disaster recovery.
 *
 * Schedule it (routes/console.php):
 *     Schedule::command('db:backup --verify --keep=14 --disk=s3')->dailyAt('01:00');
 *
 * Full restore procedure is in DEPLOY-DB-BACKUP.txt (restore-testing is only real when you've done it).
 */
class DbBackup extends Command
{
    protected $signature = 'db:backup
        {--keep=7 : How many local dumps to retain (older ones are deleted)}
        {--disk= : Optional filesystem disk to also upload the dump to (e.g. s3) for offsite DR}
        {--verify : Verify the dump is restorable with pg_restore --list before keeping it}
        {--path= : Local directory for dumps (default: storage/app/backups)}';

    protected $description = 'Back up the PostgreSQL database (pg_dump -Fc), verify it, rotate, optionally push offsite.';

    public function handle(): int
    {
        $default = (string) config('database.default');
        $cfg = config("database.connections.$default");
        if (! is_array($cfg)) {
            $this->error("No config for database connection [$default].");
            return self::FAILURE;
        }
        if (($cfg['driver'] ?? null) !== 'pgsql') {
            $this->error("db:backup supports PostgreSQL only; default connection is [{$cfg['driver']}].");
            return self::FAILURE;
        }

        // pg_dump must be on PATH (Docker image needs the `postgresql-client` package — see DEPLOY note).
        if (! $this->binaryExists('pg_dump') || ! $this->binaryExists('pg_restore')) {
            $this->error('pg_dump / pg_restore not found. Install the postgresql-client package in the image.');
            return self::FAILURE;
        }

        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $port = (string) ($cfg['port'] ?? '5432');
        $db   = (string) ($cfg['database'] ?? '');
        $user = (string) ($cfg['username'] ?? '');
        $pass = (string) ($cfg['password'] ?? '');
        if ($db === '') { $this->error('Empty database name in config.'); return self::FAILURE; }

        $dir = rtrim((string) ($this->option('path') ?: storage_path('app/backups')), '/');
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            $this->error("Cannot create backup dir: $dir");
            return self::FAILURE;
        }

        $stamp = now()->format('Ymd_His');
        $file  = "$dir/{$db}_{$stamp}.dump";

        // 1) DUMP — custom format is compressed and restorable with pg_restore.
        $this->line("Dumping {$db} → {$file}");
        $dump = new Process(
            ['pg_dump', '-h', $host, '-p', $port, '-U', $user, '-d', $db,
             '-Fc', '--no-owner', '--no-privileges', '-f', $file],
            base_path(), ['PGPASSWORD' => $pass], null, 3600.0
        );
        $dump->run();
        if (! $dump->isSuccessful()) {
            $this->error('pg_dump failed: ' . trim($dump->getErrorOutput()));
            @unlink($file);
            return self::FAILURE;
        }
        $bytes = @filesize($file) ?: 0;
        if ($bytes < 1024) {
            $this->error("Dump is suspiciously small ({$bytes} bytes) — treating as failed.");
            @unlink($file);
            return self::FAILURE;
        }

        // 2) VERIFY — a dump you cannot list is not a backup.
        if ($this->option('verify')) {
            $list = new Process(['pg_restore', '--list', $file], base_path(), null, null, 600.0);
            $list->run();
            $tables = substr_count($list->getOutput(), 'TABLE DATA');
            if (! $list->isSuccessful() || $tables === 0) {
                $this->error('Verify FAILED — pg_restore could not list the dump (or it has 0 tables). Backup discarded.');
                @unlink($file);
                return self::FAILURE;
            }
            $this->info("Verified: dump lists cleanly ({$tables} table-data entries).");
        }

        $this->info(sprintf('Backup OK: %s (%.1f MB)', basename($file), $bytes / 1048576));

        // 3) OFFSITE — push to a configured disk (real DR), streamed.
        if ($disk = (string) $this->option('disk')) {
            try {
                $key = 'backups/' . basename($file);
                $stream = fopen($file, 'rb');
                Storage::disk($disk)->writeStream($key, $stream);
                if (is_resource($stream)) fclose($stream);
                $this->info("Uploaded offsite: [{$disk}] {$key}");
            } catch (\Throwable $e) {
                // Offsite failure must not lose the good local backup — warn, keep the local file, fail loud.
                $this->error('Offsite upload failed (local backup kept): ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        // 4) ROTATE — keep the newest --keep local dumps.
        $this->rotate($dir, $db, max(1, (int) $this->option('keep')));

        return self::SUCCESS;
    }

    private function binaryExists(string $bin): bool
    {
        $p = new Process(['which', $bin]);
        $p->run();
        return $p->isSuccessful();
    }

    private function rotate(string $dir, string $db, int $keep): void
    {
        $files = glob("$dir/{$db}_*.dump") ?: [];
        if (count($files) <= $keep) return;
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a)); // newest first
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('Rotated out: ' . basename($old));
        }
    }
}
