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

        $dbUserName = $db['username'];
        $dbPassword = $db['password'];
        $databaseName = $db['database'];
        // Dump the database contents
        $command = "mysqldump --no-tablespaces --single-transaction --routines --triggers -P {$db['port']} -h {$db['host']} -u\"$dbUserName\" -p\"$dbPassword\" $databaseName";

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
            throw new ApplicationException("Backup failed, the resulting file was $filesize bytes, expected at " .  Backup::LOWEST_FILE_SIZE);
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
        if (! $disk->exists($path)) {
            $disk->makeDirectory($path);
            sleep(1);
            if (! $disk->exists($path)) {
                throw new ApplicationException("Backup failed, was unable to find or create $path on the backup drive");
            }
        }

        $localAdapter = new \League\Flysystem\Local\LocalFilesystemAdapter('/');
        $localfs = new \League\Flysystem\Filesystem($localAdapter, [\League\Flysystem\Config::OPTION_VISIBILITY => \League\Flysystem\Visibility::PRIVATE]);
        // Attempt to upload the file
        $disk->writeStream("$path/$filename", $localfs->readStream($backupFile), []);
        // Verify that the upload succeeded
        $localSize = filesize($backupFile);

        $remoteSize = 0;
        try {
            $remoteSize = $disk->size("$path/$filename");
        }catch(Exception $e) {
            throw new ApplicationException("Storing the backup file $path/$filename failed!");
        }
        

        if ($localSize !== $remoteSize) {
            throw new ApplicationException("Storing the backup file $path/$filename failed, uploaded size is $remoteSize bytes expected $localSize bytes");
        }

        // Clean up the local backup file
        unlink($backupFile);
    }
}
