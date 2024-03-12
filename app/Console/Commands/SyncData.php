<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nidec:sync-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This PHP script syncs data from SQL to MySQL in a loop, with a 15-second interval between each execution.';

    /**
     * The file path for storing lock file.
     *
     * @var string
     */
    protected $lockFilePath;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->lockFilePath = base_path('app/Console/Commands/nidec_sync_data.lock');
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        while (true) {
            $this->customLog('-----SYNC DATA START-----', 'info');
            $timeLimit = $this->getIntervalTime();
            $this->customLog("SYNC_DATA_INTERVAL=$timeLimit", 'info');
            $lastExecution = Carbon::now();

            try {
                // Check if the lock file exists
                if (file_exists($this->lockFilePath)) {
                    $this->customLog('Another instance of nidec:sync-data command is already running. Exiting...', 'error');
                    sleep($timeLimit);
                    continue;
                }

                // Create a lock file
                touch($this->lockFilePath);

                // sync IOT data from SQL to MySQL
                $this->syncIOTDataFromSqlToMySql();
                $elapsedTime = intval(Carbon::now()->diffInSeconds($lastExecution)) + 1;
                if ($elapsedTime < $timeLimit) {
                    $sleepTime = $timeLimit - $elapsedTime;
                    $this->customLog("Started syncIOTDataFromSqlToMySql() function in $elapsedTime seconds, will wait $sleepTime seconds to run again.", "info");
                    sleep($sleepTime);

                } else {
                    $this->customLog("Started syncIOTDataFromSqlToMySql() function in $elapsedTime seconds.", 'warning');
                }
            } catch (Exception $e) {
                $this->customLog($e->getMessage(), 'error');
            } finally {
                // Remove the lock file
                unlink($this->lockFilePath);
                $this->customLog('-----SYNC DATA END-----', 'info');
            }
        }
    }

    private function syncIOTDataFromSqlToMySql() {
        try {
            // get data from IOT database
            $iotData = DB::connection('sqlsrv')->table('T_IOT_MOLD_MASTER')->select('*')->get();
            foreach ($iotData as $item) {
                // Update data to assets based on serial
                DB::table('assets')->where('serial', $item->mold_serial)->whereNull('deleted_at')->update([
                    '_snipeit_maintenance_shot_24' => $item->maintenance_shot,
                    '_snipeit_scrap_shot_26' => $item->scrap_qty,
                    '_snipeit_shot_qty_25' => $item->scrap_shot,
                ]);
            }
        } catch (Exception $e) {
            // Log error
            throw new Exception($e->getMessage());
        }
    }

    // print log message
    private function customLog($message, $type) {
        $currentDateTime = date("Y-m-d H:i:s");
        $log = '[' . $currentDateTime . '] ';

        if ($type == 'error') {
            $log = $log  . 'ERROR: syncIOTDataFromSqlToMySql: ';
        } else if ($type == 'warning') {
            $log = $log  . 'WARNING: syncIOTDataFromSqlToMySql: ';
        } else {
            $log = $log . 'INFO: syncIOTDataFromSqlToMySql: ';
        }

        $this->info($log . $message);
    }

    // get interval time to execute
    private function getIntervalTime() {
        $file = base_path('.env');
        $this->customLog("Environment path: $file", 'info');
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $intervalTime = null;

        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);

            if (count($parts) == 2 && trim($parts[0]) == 'SYNC_DATA_INTERVAL') {
                $intervalTime = trim($parts[1]);
                break;
            }
        }

        return intval($intervalTime);
    }

}