<?php

declare(strict_types=1);

use OCA\Timesheets\Service\StorageService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

class JobStorageFixture {
    private array $previous = [];
    private Folder $folder;
    private array $references;

    public function __construct(string $ownerId, array $references) {
        $this->references = $references;
        $config = Server::get(IConfig::class);
        $existing = $config->getAppKeys('timesheets');
        foreach ([
            'storage_owner', 'payroll_storage_root', 'sales_order_storage_root',
            'job_reference_format', 'job_year_base', 'job_folder_template',
            'job_group_first_end', 'job_group_size', 'job_group_name_format', 'payroll_week_start',
        ] as $key) {
            $this->previous[$key] = in_array($key, $existing, true) ? $config->getAppValue('timesheets', $key) : null;
        }
        $name = 'timesheets-job-storage-' . bin2hex(random_bytes(6));
        $this->folder = Server::get(IRootFolder::class)->getUserFolder($ownerId)->newFolder($name);
        $this->folder->newFolder('Payroll');
        $jobs = $this->folder->newFolder('Jobs');
        foreach ($references as $reference) {
            $jobs->newFolder($reference . ' - Test');
        }
        Server::get(StorageService::class)->saveRoots(
            $ownerId, $name . '/Payroll', $name . '/Jobs', 'SO{sequence:4}', '2000', '{jobId}',
            payrollWeekStart: '1',
        );
    }

    public function cleanup(): void {
        $this->folder->delete();
        foreach ($this->references as $reference) {
            $query = Server::get(\OCP\IDBConnection::class)->getQueryBuilder();
            $query->delete('timesheets_clock_locks')
                ->where($query->expr()->eq('user_id', $query->createNamedParameter('job:' . hash('sha256', $reference))));
            $query->executeStatement();
        }
        $config = Server::get(IConfig::class);
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                $config->deleteAppValue('timesheets', $key);
            } else {
                $config->setAppValue('timesheets', $key, $value);
            }
        }
        Server::get(IAppConfig::class)->clearCache();
    }
}
