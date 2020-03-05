<?php namespace Captive\Backup;

use Backend;
use Storage;
use Google_Client;
use Google_Service_Drive;
use League\Flysystem\Filesystem;
use Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter;

use System\Classes\PluginBase;


/**
 * Backup Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'captive.backup::lang.plugin.name',
            'description' => 'captive.backup::lang.plugin.description',
            'author'      => 'Captive Media Inc.',
            'icon'        => 'icon-database',

        ];
    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConsoleCommand('captive.backup', 'Captive\Backup\Console\Backup');
    }

    /**
     * Boot method, called right before the request route.
     *
     * @return array
     */
    public function boot()
    {
        // Init Google Drive Storage, TODO: AWS S3
        Storage::extend('googledrive', function($app, $config) {
            traceLog($config);
            $client = new Google_Client();
            $client->setClientId($config['clientId']);
            $client->setClientSecret($config['clientSecret']);
            $client->refreshToken($config['refreshToken']);
            $service = new Google_Service_Drive($client);
            $adapter = new GoogleDriveAdapter($service, $config['folderId']);
            return new Filesystem($adapter);
        });
    }

}
