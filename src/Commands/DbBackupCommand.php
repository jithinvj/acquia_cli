<?php

namespace AcquiaCli\Commands;

use AcquiaCloudApi\Connector\Connector;
use AcquiaCloudApi\Response\EnvironmentResponse;
use AcquiaCloudApi\Endpoints\Databases;
use AcquiaCloudApi\Endpoints\DatabaseBackups;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Class DomainCommand
 * @package AcquiaCli\Commands
 */
class DbBackupCommand extends AcquiaCommand
{

    public $databaseBackupsAdapter;

    private $downloadProgress;

    private $lastStep;

    public function __construct()
    {
        parent::__construct();

        $this->databaseBackupsAdapter = new DatabaseBackups($this->cloudapi);
    }

    /**
     * Backs up all DBs in an environment.
     *
     * @param string              $uuid
     * @param EnvironmentResponse $environment
     *
     * @command database:backup
     * @aliases db:backup
     */
    public function dbBackup($uuid, $environment)
    {
        $this->backupAllEnvironmentDbs($uuid, $environment);
    }

    /**
     * Shows a list of database backups for all databases in an environment.
     *
     * @param string              $uuid
     * @param EnvironmentResponse $environment
     * @param string              $dbName
     *
     * @command database:backup:list
     * @aliases db:backup:list
     */
    public function dbBackupList($uuid, $environment, $dbName = null)
    {
        if (null !== $dbName) {
            $this->cloudapi->addQuery('filter', "name=${dbName}");
        }
        $dbAdapter = new Databases($this->cloudapi);
        $databases = $dbAdapter->getAll($uuid);
        $this->cloudapi->clearQuery();

        $table = new Table($this->output());
        $table->setHeaders(['ID', 'Type', 'Timestamp']);

        foreach ($databases as $database) {
            $backups = $this->databaseBackupsAdapter->getAll($environment->uuid, $database->name);
            $table
                ->addRows(
                    [
                        [new TableCell($database->name, ['colspan' => 3])],
                        new TableSeparator()
                    ]
                );

            foreach ($backups as $backup) {
                $table
                    ->addRows([
                        [
                            $backup->id,
                            ucfirst($backup->type),
                            $backup->completedAt,
                        ],
                    ]);
            }
        }
        $table->render();
    }

    /**
     * Restores a database from a saved backup.
     *
     * @param string              $uuid
     * @param EnvironmentResponse $environment
     * @param string              $dbName
     * @param int                 $backupId
     *
     * @command database:backup:restore
     * @aliases db:backup:restore
     */
    public function dbBackupRestore($uuid, $environment, $dbName, $backupId)
    {
        if ($this->confirm(
            sprintf('Are you sure you want to restore backup id %s to %s?', $backupId, $environment->label)
        )) {
            $response = $this->databaseBackupsAdapter->restore($environment->uuid, $dbName, $backupId);
            $this->waitForNotification($response);
        }
    }

    /**
     * Provides a database backup link.
     *
     * @param string              $uuid
     * @param EnvironmentResponse $environment
     * @param string              $dbName
     * @param int                 $backupId
     *
     * @command database:backup:link
     * @aliases db:backup:link
     */
    public function dbBackupLink($uuid, $environment, $dbName, $backupId)
    {
        $environmentUuid = $environment->uuid;
        $this->say(Connector::BASE_URI .
            "/environments/${environmentUuid}/databases/${dbName}/backups/${backupId}/actions/download");
    }

    /**
     * Downloads a database backup.
     *
     * @param string              $uuid
     * @param EnvironmentResponse $environment
     * @param string              $dbName
     *
     * @command database:backup:download
     * @aliases db:backup:download
     * @option $backup Select which backup to download by backup ID. If omitted, the latest will be downloaded.
     * @option $path Select a path to download the backup to. If omitted, the system temp directory will be used.
     */
    public function dbBackupDownload($uuid, $environment, $dbName, $opts = ['backup' => null, 'path' => null])
    {
        if (!$opts['backup']) {
            $this->cloudapi->addQuery('sort', '-created');
            $this->cloudapi->addQuery('limit', 1);
            $backup = $this->databaseBackupsAdapter->getAll($environment->uuid, $dbName);
            $this->cloudapi->clearQuery();
            if (empty($backup)) {
                throw new Exception('Unable to find a database backup to download.');
            }
            $backupId = $backup[0]->id;
        } else {
            $backupId = $opts['backup'];
        }

        $backupName = sprintf('%s-%s-%s', $environment->name, $dbName, $backupId);

        if (null === $opts['path']) {
            $location = tempnam(sys_get_temp_dir(), $backupName) . '.sql.gz';
        } else {
            $location = sprintf("%s/%s.sql.gz", $opts['path'], $backupName);
        }

        $this->say(sprintf('Downloading database backup to %s', $location));

        $this->downloadProgress = $this->getProgressBar();
        $this->downloadProgress->start();
        $this->downloadProgress->setMessage(sprintf('Downloading database backup to %s', $location));

        $this->cloudapi->addOption('sink', $location);
        $this->cloudapi->addOption('curl.options', ['CURLOPT_RETURNTRANSFER' => true, 'CURLOPT_FILE' => $location]);
        $this->cloudapi->addOption('progress', function (
            $downloadTotal,
            $downloadedBytes
        ) {
            if ($downloadTotal) {
                $currentStep = $downloadedBytes - $this->lastStep;
                $this->downloadProgress->setMaxSteps($downloadTotal);
                $this->downloadProgress->setFormat("<fg=white;bg=cyan> %message:-45s%</>\n%current:6s%/%max:6s% bytes [%bar%] %percent:3s%%");
                $this->downloadProgress->advance($currentStep);
            }
            $this->lastStep = $downloadedBytes;
        });

        $this->databaseBackupsAdapter->download($environment->uuid, $dbName, $backupId);
        $this->downloadProgress->setMessage(sprintf('Database backup downloaded to %s', $location));
        $this->downloadProgress->finish();

        $this->writeln(PHP_EOL);
        $this->say(sprintf('Database backup downloaded to %s', $location));

    }
}