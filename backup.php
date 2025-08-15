<?php

// ============================================================================
// SCRIPT BOOTSTRAP
// ============================================================================

require 'vendor/autoload.php';

// Load environment variables from .env file
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $dotenv->required([
        'AWS_ENDPOINT',
        'AWS_DEFAULT_REGION',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_BUCKET',

        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_DATABASE',
        
        'BACKUP_PATH',
        'BACKUP_MAX_SIZE_GB',
        'BACKUP_DELETE_OLDEST_ON_LIMIT_REACHED'
    ]);
} catch (Exception $e) {
    die('Error loading .env file: ' . $e->getMessage());
}

// ============================================================================
// SCRIPT LOGIC - DO NOT EDIT BELOW THIS LINE
// ============================================================================

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Main function to run the backup process.
 */
function runBackup()
{
    echo "Starting backup process...\n";

    // 1. Enforce storage limit
    enforceStorageLimit();

    // 2. Create a new timestamped directory for this backup
    $backupDir = createBackupDirectory();
    if (!$backupDir) {
        return;
    }

    // 3. Backup the database
    backupDatabase($backupDir);

    // 4. Backup the S3 bucket
    backupS3Bucket($backupDir);

    echo "Backup process completed.\n";
}

/**
 * Creates a new timestamped directory for the current backup.
 *
 * @return string|false The path to the new directory, or false on failure.
 */
function createBackupDirectory()
{
    $timestamp = date('Y-m-d_H-i-s');
    $newBackupDir = env('BACKUP_PATH') . '/' . $timestamp;

    if (!mkdir($newBackupDir, 0755, true)) {
        echo "Error: Could not create backup directory: $newBackupDir\n";
        return false;
    }

    echo "Created backup directory: $newBackupDir\n";
    return $newBackupDir;
}

/**
 * Backs up the MySQL database using mysqldump.
 *
 * @param string $backupDir The directory to save the backup file in.
 */
function backupDatabase($backupDir)
{
    echo "Backing up database '" . env('DB_DATABASE') . "'...\n";
    $backupFile = $backupDir . '/' . env('DB_DATABASE') . '.sql.gz';
    $command = sprintf(
        'mysqldump --skip-ssl -h %s -P %s -u %s -p%s %s | gzip > %s',
        escapeshellarg(env('DB_HOST')),
        escapeshellarg(env('DB_PORT')),
        escapeshellarg(env('DB_USERNAME')),
        escapeshellarg(env('DB_PASSWORD')),
        escapeshellarg(env('DB_DATABASE')),
        escapeshellarg($backupFile)
    );

    exec($command, $output, $returnVar);

    if ($returnVar === 0) {
        echo "Database backup successful: $backupFile\n";
    } else {
        echo "Error: Database backup failed.\n";
    }
}

/**
 * Backs up all objects from the S3 bucket.
 *
 * @param string $backupDir The directory to save the S3 objects in.
 */
function backupS3Bucket($backupDir)
{
    echo "Backing up S3 bucket '" . env('AWS_BUCKET') . "'...\n";
    $s3BackupPath = $backupDir . '/s3_objects';
    if (!mkdir($s3BackupPath, 0755, true)) {
        echo "Error: Could not create S3 backup directory: $s3BackupPath\n";
        return;
    }

    $s3Client = new S3Client([
        'version' => 'latest',
        'region'  => env('AWS_DEFAULT_REGION'),
        'endpoint' => env('AWS_ENDPOINT'),
        'credentials' => [
            'key'    => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
        'use_path_style_endpoint' => true, // Important for some S3-compatible services
    ]);

    try {
        $objects = $s3Client->getIterator('ListObjects', ['Bucket' => env('AWS_BUCKET')]);
        foreach ($objects as $object) {
            $key = $object['Key'];
            $localPath = $s3BackupPath . '/' . $key;
            $localDir = dirname($localPath);

            if (!file_exists($localDir)) {
                mkdir($localDir, 0755, true);
            }

            echo "Downloading s3://".env('AWS_BUCKET')."/$key to $localPath\n";
            $s3Client->getObject([
                'Bucket' => env('AWS_BUCKET'),
                'Key'    => $key,
                'SaveAs' => $localPath
            ]);
        }
        echo "S3 backup successful.\n";
    } catch (AwsException $e) {
        echo "Error: S3 backup failed: " . $e->getMessage() . "\n";
    }
}

/**
 * Checks the total size of the backup directory and deletes the oldest backups if the limit is exceeded.
 */
function enforceStorageLimit()
{
    echo "Checking storage limit...\n";
    $totalSize = getDirectorySize(env('BACKUP_PATH'));
    $limitBytes = env('BACKUP_MAX_SIZE_GB') * 1024 * 1024 * 1024;

    echo "Current backup size: " . round($totalSize / (1024 * 1024 * 1024), 2) . " GB\n";
    echo "Storage limit: " . env('BACKUP_MAX_SIZE_GB') . " GB\n";

    if ($totalSize > $limitBytes && filter_var(env('BACKUP_DELETE_OLDEST_ON_LIMIT_REACHED'), FILTER_VALIDATE_BOOLEAN)) {
        echo "Storage limit exceeded. Deleting oldest backups...\n";
        $backups = getSortedBackups();

        while ($totalSize > $limitBytes && !empty($backups)) {
            $oldestBackup = array_shift($backups);
            $pathToDelete = env('BACKUP_PATH') . '/' . $oldestBackup['name'];
            $sizeToDelete = $oldestBackup['size'];

            echo "Deleting oldest backup: $pathToDelete\n";
            deleteDirectory($pathToDelete);
            $totalSize -= $sizeToDelete;
        }
    }
}

/**
 * Gets the total size of a directory in bytes.
 *
 * @param string $path The path to the directory.
 * @return int The size of the directory in bytes.
 */
function getDirectorySize($path)
{
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $bytes += $file->getSize();
    }
    return $bytes;
}

/**
 * Gets a sorted list of backup directories (oldest first).
 *
 * @return array A sorted list of backup directories.
 */
function getSortedBackups()
{
    $backups = [];
    $items = new DirectoryIterator(env('BACKUP_PATH'));
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isDot()) {
            $backups[] = [
                'name' => $item->getFilename(),
                'time' => $item->getMTime(),
                'size' => getDirectorySize($item->getPathname())
            ];
        }
    }

    usort($backups, function ($a, $b) {
        return $a['time'] <=> $b['time'];
    });

    return $backups;
}

/**
 * Recursively deletes a directory.
 *
 * @param string $dirPath The path to the directory to delete.
 */
function deleteDirectory($dirPath)
{
    if (!is_dir($dirPath)) {
        return;
    }
    $it = new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    rmdir($dirPath);
}

/**
 * Gets the value of an environment variable.
 *
 * @param  string  $key
 * @return mixed
 */
function env($key)
{
    $value = $_ENV[$key];

    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return;
    }

    if (preg_match('/\A([\'"])(.*)\1\z/', $value, $matches)) {
        return $matches[2];
    }

    return $value;
}


// Run the backup process
runBackup();

