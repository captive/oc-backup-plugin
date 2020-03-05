<?php namespace Captive\Backup\Console;

use Log;
use Config;
use Artisan;
use Storage;
use Exception;
use Carbon\Carbon;
use ApplicationException;
use Illuminate\Console\Command;

/**
 * Backup the database and upload to the GoogleDrive
 *
 * command: php artisan captive:backup:db
 */
class Backup extends Command
{
    const LOWEST_FILE_SIZE = 1024; // 1KB
    /**
     * @var string The console command name.
     */
    protected $name = 'captive:backup:db';

    /**
     * @var string The console command description.
     */
    protected $description = 'Backup the database and upload to the GoogleDrive';

    /**
     * yyyymmddhhii
     *
     * @var string
     */
    protected $remoteFilename;

    /**
     * a local file name
     *
     * @var string
     */
    protected $filePrefix;

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        try {
            $this->storeBackup($this->getDatabaseBackup(), $this->getBackupPath());
        } catch (Exception $e) {
            Log::error($e);
            throw $e;
        }
    }


    /**
     * Gets the path for the current back
     *
     * @return string example: 2019-05/2019.05.29-13.15.db.sql.gz
     */
    protected function getBackupPath()
    {
        $date = Carbon::now();
        $date->setTimezone(Config::get('cms.backendTimezone'));
        return $date->format('Y-m') . '/' . $date->format('Y-m-d_H-i-s') . '.db.sql.gz';
    }

    /**
     * Backup the database as it is right now and return the path to the temporary backup file
     *
     * @return string example: temp_path()/23qp8pdfjlaskdjfasdifrewopurh
     */
    protected function getDatabaseBackup()
    {
        $db = Config::get('database.connections.' . Config::get('database.default'));

        // Dump the database contents
        $command = "mysqldump --single-transaction --routines --triggers -P {$db['port']} -h {$db['host']} -u{$db['username']} -p{$db['password']} {$db['database']}";

        // remove the "DEFINER:  /*!50013 DEFINER=`homestead`@`%` SQL SECURITY DEFINER */ -> /*!50013 */" string from generated views in the export
        // remove the DEFINER in the function, procedure. https://stackoverflow.com/questions/9446783/remove-definer-clause-from-mysql-dumps
        $command .= " | sed -e 's/DEFINER[ ]*=[ ]*[^*]*\*/\*/' | sed -e 's/DEFINER[ ]*=[ ]*[^*]*PROCEDURE/PROCEDURE/' | sed -e 's/DEFINER[ ]*=[ ]*[^*]*FUNCTION/FUNCTION/' ";

        // remove any references to the database name in the dump (example: 'databasename'.xxx)
        $command .= " | sed -e \"s/\`{$db['database']}\`\.//g\" ";

        // gzip the results and store it in a temporary file
        $destFile = tempnam(temp_path(), 'backup');
        $command .= " | gzip > $destFile;";

        // Run the command
        shell_exec($command);

        // Sanity check the resulting backup
        $filesize = @filesize($destFile);
        if (empty($filesize) || $filesize <  Backup::LOWEST_FILE_SIZE) {
            throw new ApplicationException('Backup failed, the resulting file was $filesize bytes, expected at ' .  Backup::LOWEST_FILE_SIZE);
        }

        return $destFile;
    }

    /**
     * Store the provided backup file to the backup disk in the provided path
     *
     * @param string $backupFile Path to the local copy of the backup file
     * @param string $path
     * @return void
     */
    protected function storeBackup($backupFile, $path)
    {
        $disk = Storage::disk('backup');

        // Parse the path
        list($path, $filename) = explode('/', $path);

        // Handle Google Drive storage disks
        if ($disk->getDriver()->getAdapter() instanceof \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter) {
            // Attempt to get the folder ID we'll be using
            $info = $this->getGDriveInfo($disk, $path);
            if (empty($info)) {
                $disk->makeDirectory($path);
                sleep(1);
                $info = $this->getGDriveInfo($disk, $path);
            }

            if (!empty($info)) {
                $path = $info['path'];
            } else {
                throw new ApplicationException("Backup failed, was unable to find or create $path on the backup drive");
            }
        }

        // Attempt to upload the file
        $disk->put("$path/$filename", fopen($backupFile, 'r+'));

        // Verify that the upload succeeded
        $localSize = filesize($backupFile);
        $remoteSize = null;
        if ($disk->getDriver()->getAdapter() instanceof \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter) {
            $file = $this->getGDriveInfo($disk, $filename, 'file', $path);
        } else {
            $file = $disk->get("$path/$filename");
        }

        if ($file) {
            $remoteSize = $file['size'];
        }

        if ($localSize !== $remoteSize) {
            throw new ApplicationException("Storing the backup file $path/$filename failed, uploaded size is $remoteSize bytes expected $localSize bytes");
        }

        // Clean up the local backup file
        unlink($backupFile);
    }

    /**
     * Get the Google Drive file info for the provided filename / directory
     *
     * @param Storage $disk
     * @param string $name
     * @param string $type Optional, defaults to 'dir', other 'file'
     * @param string $basePath Optional, defaults to the root
     * @return array
     * (
     *     [name] => 2020-01-01_17-35-01.db.sql.gz
     *     [type] => file
     *     [path] => 1sC39HVAKUl-PvJbd2ojV/1yXPvJKUf4Ma0Psrj
     *     [filename] => 2020-01-01_17-35-01.db.sql
     *     [extension] => gz
     *     [timestamp] => 1558049742
     *     [mimetype] => application/x-gzip
     *     [size] => 215855
     *     [dirname] => 1sC39HVAKUl-PvJbd2ojV
     *     [basename] => 1yXPvJKUf4Ma0Psrj
     * )
     */
    protected function getGDriveInfo($disk, $name, $type = 'dir', $basePath = '/')
    {
        if (!($disk->getDriver()->getAdapter() instanceof \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter)) {
            throw new ApplicationException("The provided disk is not a Google Drive storage disk");
        }

        return collect($disk->listContents($basePath, false))
            ->where('type', '=', $type)
            ->where('name', '=', $name)
            ->sortBy('timestamp')
            ->last();
    }
}
