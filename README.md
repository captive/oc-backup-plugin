# WIP

## Google Client ID and Secret Key

* [Google Authentication](https://developers.google.com/adwords/api/docs/guides/authentication)
* [LukeTowers/oc-gdrivefilesystemdriver-plugin](https://github.com/LukeTowers/oc-gdrivefilesystemdriver-plugin/blob/master/README.md)

## Command

```bash
php artisan captive:backup:db
```

## Configuration

### config/filesystem.php

```php
    'backup' => [
            'driver' => 'googledrive',
            'clientId' => env('BACKUP_GOOGLE_CLIENT_ID'),
            'clientSecret' => env('BACKUP_GOOGLE_CLIENT_SECRET'),
            'refreshToken' => env('BACKUP_GOOGLE_REFRESH_TOKEN'),
            'folderId' => env('BACKUP_GOOGLE_FOLDER_ID'),
        ]
```

### .env

```ini
BACKUP_GOOGLE_CLIENT_ID=
BACKUP_GOOGLE_CLIENT_SECRET=
BACKUP_GOOGLE_REFRESH_TOKEN=
BACKUP_GOOGLE_FOLDER_ID=
```

# 3.0 Updates
## Configuration 
BACKUP_GOOGLE_FOLDER_ID changed to folder name

## Lib
Using masbug/flysystem-google-drive-ext
