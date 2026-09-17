<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'app:backup-database
                            {--path= : Directorio de destino fuera de public}
                            {--keep= : Cantidad de dias de retencion}';

    protected $description = 'Crea un respaldo SQL de la base de datos en un directorio privado.';

    public function handle(): int
    {
        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection) || ! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->components->error('El respaldo operativo requiere una conexion MariaDB o MySQL.');

            return self::FAILURE;
        }

        $backupPath = null;
        $credentialsPath = null;

        try {
            $backupDirectory = $this->backupDirectory();
            $retentionDays = $this->retentionDays();
            $this->assertOutsidePublicDirectory($backupDirectory);
            $this->ensureBackupDirectory($backupDirectory);

            $resolvedDirectory = realpath($backupDirectory);

            if ($resolvedDirectory === false || $this->isInsidePublicDirectory($resolvedDirectory)) {
                throw new RuntimeException('El directorio de respaldo debe estar fuera de public.');
            }

            $backupPath = $this->nextBackupPath($resolvedDirectory);
            $credentialsPath = $this->createCredentialsFile($resolvedDirectory, $connection);
            $exitCode = $this->runDump($connection, $credentialsPath, $backupPath);

            if ($exitCode !== 0) {
                throw new RuntimeException('El comando de respaldo termino con un error.');
            }

            if (! chmod($backupPath, 0600)) {
                throw new RuntimeException('No se pudieron restringir los permisos del respaldo.');
            }

            $deletedBackups = $this->pruneBackups($resolvedDirectory, $retentionDays, $backupPath);
        } catch (Throwable $exception) {
            if ($backupPath !== null && is_file($backupPath)) {
                unlink($backupPath);
            }

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($credentialsPath !== null && is_file($credentialsPath)) {
                unlink($credentialsPath);
            }
        }

        $this->components->info("Respaldo creado: {$backupPath}");
        $this->components->info("Respaldos eliminados por retencion: {$deletedBackups}");

        return self::SUCCESS;
    }

    private function backupDirectory(): string
    {
        $path = trim((string) ($this->option('path') ?: config('backup.path')));

        if ($path === '') {
            throw new RuntimeException('Configura un directorio para los respaldos.');
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function retentionDays(): int
    {
        $value = $this->option('keep') ?? config('backup.retention_days', 14);

        if (! is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('La retencion debe ser un numero entero positivo de dias.');
        }

        return (int) $value;
    }

    private function ensureBackupDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio de respaldos.');
        }

        if (! chmod($directory, 0700)) {
            throw new RuntimeException('No se pudieron restringir los permisos del directorio de respaldos.');
        }
    }

    private function isInsidePublicDirectory(string $path): bool
    {
        $publicDirectory = realpath(public_path());

        return $publicDirectory !== false
            && ($path === $publicDirectory || str_starts_with($path, $publicDirectory.DIRECTORY_SEPARATOR));
    }

    private function assertOutsidePublicDirectory(string $path): void
    {
        $candidate = $path;
        $missingSegments = [];

        while (realpath($candidate) === false) {
            $parent = dirname($candidate);

            if ($parent === $candidate) {
                return;
            }

            $missingSegments[] = basename($candidate);
            $candidate = $parent;
        }

        $resolvedPath = realpath($candidate);

        foreach (array_reverse($missingSegments) as $segment) {
            $resolvedPath .= DIRECTORY_SEPARATOR.$segment;
        }

        if ($resolvedPath !== false && $this->isInsidePublicDirectory($resolvedPath)) {
            throw new RuntimeException('El directorio de respaldo debe estar fuera de public.');
        }
    }

    private function nextBackupPath(string $directory): string
    {
        $basePath = $directory.DIRECTORY_SEPARATOR.'sistema-pasteleria-'.now()->format('Ymd_His');
        $backupPath = $basePath.'.sql';
        $sequence = 1;

        while (is_file($backupPath)) {
            $backupPath = $basePath.'-'.$sequence.'.sql';
            $sequence++;
        }

        return $backupPath;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function createCredentialsFile(string $directory, array $connection): string
    {
        $credentials = [
            'host' => $connection['host'] ?? null,
            'port' => $connection['port'] ?? null,
            'user' => $connection['username'] ?? null,
            'password' => $connection['password'] ?? null,
            'socket' => $connection['unix_socket'] ?? null,
        ];

        foreach ($credentials as $value) {
            if ($value !== null && (str_contains((string) $value, "\n") || str_contains((string) $value, "\r"))) {
                throw new RuntimeException('La configuracion de base de datos contiene un salto de linea invalido.');
            }
        }

        $path = tempnam($directory, '.backup-credentials-');

        if ($path === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal de credenciales.');
        }

        $contents = '[client]'.PHP_EOL;

        foreach ($credentials as $key => $value) {
            if ($value !== null && $value !== '') {
                $contents .= $key.'='.$value.PHP_EOL;
            }
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false || ! chmod($path, 0600)) {
            unlink($path);

            throw new RuntimeException('No se pudo proteger el archivo temporal de credenciales.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function runDump(array $connection, string $credentialsPath, string $backupPath): int
    {
        $database = trim((string) ($connection['database'] ?? ''));

        if ($database === '') {
            throw new RuntimeException('La base de datos no esta configurada.');
        }

        $output = fopen($backupPath, 'wb');

        if ($output === false) {
            throw new RuntimeException('No se pudo abrir el archivo de respaldo.');
        }

        $process = new Process([
            (string) config('backup.dump_binary', 'mariadb-dump'),
            '--defaults-extra-file='.$credentialsPath,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--triggers',
            $database,
        ]);

        try {
            return $process->run(function (string $type, string $buffer) use ($output): void {
                if ($type === Process::OUT && fwrite($output, $buffer) === false) {
                    throw new RuntimeException('No se pudo escribir el archivo de respaldo.');
                }
            });
        } finally {
            fclose($output);
        }
    }

    private function pruneBackups(string $directory, int $retentionDays, string $currentBackup): int
    {
        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $deleted = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.sql') ?: [] as $backupPath) {
            if ($backupPath === $currentBackup || ! is_file($backupPath)) {
                continue;
            }

            $modifiedAt = filemtime($backupPath);

            if ($modifiedAt !== false && $modifiedAt < $cutoff && unlink($backupPath)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
