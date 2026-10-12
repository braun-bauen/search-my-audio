<?php

// Run against the CURRENT production release before deploying this PR.
// Usage: php preservation-inventory.php /app /private/evidence/before.jsonl
// No model accessors, queue deserialization, or database/storage mutations.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

umask(0077);
$appRoot = realpath($argv[1] ?? '');
$output = $argv[2] ?? '';
$parent = realpath(dirname($output));
if (! $appRoot || ! $parent || ! str_starts_with($output, '/') || $parent === $appRoot || str_starts_with($parent, $appRoot.'/')) {
    fwrite(STDERR, "Use an absolute evidence path outside the application directory.\n");
    exit(1);
}
$handle = fopen($output, 'x');
if (! $handle) {
    exit(1);
}
$write = function (array $record) use ($handle): void {
    if (fwrite($handle, json_encode($record, JSON_THROW_ON_ERROR)."\n") === false) {
        throw new RuntimeException('Evidence write failed');
    }
};
try {
    require $appRoot.'/vendor/autoload.php';
    $app = require $appRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $defaultDisk = config('filesystems.default');
    $tempDisk = config('livewire.temporary_file_upload.disk') ?: $defaultDisk;
    $queue = config('queue.default');
    $write(['kind' => 'configuration', 'captured_at' => gmdate('c'), 'database_driver' => DB::connection()->getDriverName(),
        'database_version' => DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
        'queue_connection' => $queue, 'queue_table' => config("queue.connections.$queue.table"),
        'queue_name' => config("queue.connections.$queue.queue"), 'retry_after' => config("queue.connections.$queue.retry_after"),
        'default_disk' => $defaultDisk, 'temporary_disk' => $tempDisk]);
    $tables = array_unique(['users', 'searches', 'files', 'subscriptions', 'subscription_items', 'migrations',
        config('queue.connections.database.table', 'jobs'), config('queue.batching.table', 'job_batches'), config('queue.failed.table', 'failed_jobs')]);
    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) {
            $write(['kind' => 'absent_table', 'table' => $table]);

            continue;
        }
        $count = 0;
        foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
            $row = (array) $row;
            ksort($row);
            $metadata = array_intersect_key($row, array_flip(['id', 'user_id', 'search_id', 'created_at', 'updated_at',
                'status', 'query_total', 'query_count', 'completion_email', 'parsed_date', 'stripe_id', 'stripe_status',
                'stripe_price', 'subscription_id', 'quantity', 'trial_ends_at', 'ends_at', 'queue', 'attempts', 'reserved_at', 'available_at']));
            $write(['kind' => 'row', 'table' => $table, 'metadata' => $metadata,
                'sha256' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))]);
            $count++;
            // Raw references only: getTranscription() can UPDATE historical file status.
            $columns = $table === 'files' ? ['audio_path', 'transcription_path'] : ($table === 'searches' ? ['report_path'] : []);
            foreach ($columns as $column) {
                if (empty($row[$column])) {
                    continue;
                }
                $path = $row[$column];
                $disk = Storage::disk($defaultDisk);
                $exists = $disk->exists($path);
                $object = ['kind' => 'reference', 'table' => $table, 'id' => $row['id'], 'column' => $column,
                    'disk' => $defaultDisk, 'path' => $path, 'exists' => $exists];
                if ($exists) {
                    $object['bytes'] = $disk->size($path);
                    if ($column === 'transcription_path') {
                        $stream = $disk->readStream($path);
                        if (! is_resource($stream)) {
                            throw new RuntimeException('Unreadable transcript');
                        }
                        try {
                            $hash = hash_init('sha256');
                            hash_update_stream($hash, $stream);
                            $object['sha256'] = hash_final($hash);
                        } finally {
                            fclose($stream);
                        }
                    }
                }
                $write($object);
            }
        }
        $write(['kind' => 'table_count', 'table' => $table, 'rows' => $count]);
    }
    foreach (array_unique([$defaultDisk, $tempDisk]) as $name) {
        $config = config("filesystems.disks.$name");
        $write(['kind' => 'disk', 'name' => $name, 'driver' => $config['driver'] ?? null,
            'root' => $config['root'] ?? null, 'bucket' => $config['bucket'] ?? null]);
        // Adapter listing is lazy; do not load a whole bucket into memory.
        foreach (Storage::disk($name)->getDriver()->listContents('', true) as $entry) {
            if ($entry->isFile()) {
                $write(['kind' => 'stored_object', 'disk' => $name, 'path' => $entry->path(),
                    'bytes' => $entry->fileSize(), 'modified_at' => $entry->lastModified()]);
            }
        }
    }
    $write(['kind' => 'complete', 'captured_at' => gmdate('c')]);
    fclose($handle);
    fwrite(STDOUT, "Private inventory complete. Compare quiescent captures; keep evidence out of Git and logs.\n");
} catch (Throwable $e) {
    // Do not print exception messages, SQL, object contents, or connection details.
    $write(['kind' => 'incomplete', 'exception_class' => $e::class]);
    fclose($handle);
    fwrite(STDERR, "Inventory incomplete; stop the release and investigate privately.\n");
    exit(1);
}
