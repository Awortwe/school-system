<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use ZipArchive;

class BackupSchool extends Command
{
    protected $signature = 'tss:backup';
    protected $description = 'Back up the configured MySQL database and uploaded school files to private storage';
    public function handle(): int
    {
        $database = config('database.connections.'.config('database.default'));
        if (! in_array(config('database.default'), ['mysql', 'mariadb'], true)) { $this->error('This backup command requires a MySQL or MariaDB connection.'); return self::FAILURE; }
        $directory = storage_path('app/private/backups');
        if (! is_dir($directory)) mkdir($directory, 0700, true);
        $name = 'school-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        $dump = $directory.'/'.$name.'.sql'; $archive = $directory.'/'.$name.'.zip';
        try {
            $binary = config('school.mysqldump');
            $process = new Process([$binary, '--host='.$database['host'], '--port='.$database['port'], '--user='.$database['username'], '--single-transaction', '--routines', '--triggers', '--result-file='.$dump, $database['database']], base_path(), ['MYSQL_PWD' => $database['password']]);
            $process->setTimeout(600); $process->mustRun();
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new \RuntimeException('Could not create backup archive.');
            $zip->addFile($dump, 'database.sql');
            foreach (['private' => storage_path('app/private'), 'public' => storage_path('app/public')] as $label => $root) {
                if (! is_dir($root)) continue;
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if (! $file->isFile() || $file->isLink()) continue;
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    if ($label === 'private' && str_starts_with($relative, 'backups/')) continue;
                    $zip->addFile($file->getPathname(), 'uploads/'.$label.'/'.$relative);
                }
            }
            $zip->addFromString('manifest.json', json_encode(['created_at' => now()->toIso8601String(), 'database' => $database['database'], 'app' => config('app.name'), 'format' => 1], JSON_PRETTY_PRINT));
            if ($password = config('school.backup_password')) {
                $zip->setPassword($password);
                for ($index = 0; $index < $zip->numFiles; $index++) $zip->setEncryptionIndex($index, ZipArchive::EM_AES_256);
            }
            $zip->close(); @chmod($archive, 0600);
            $this->info('Backup created: '.$archive);
            $this->line('SHA256: '.hash_file('sha256', $archive));
            return self::SUCCESS;
        } catch (\Throwable $exception) { report($exception); $this->error('Backup failed. Check database access, dump binary configuration and private server logs.'); return self::FAILURE; }
        finally { if (is_file($dump)) unlink($dump); }
    }
}
