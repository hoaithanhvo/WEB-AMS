<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use DateTime;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    protected $description = 'This PHP script syncs data from SQL to MySQL in a loop, with a 15-second interval between each execution.
    ';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $lastExecution = Carbon::now();
        $timeLimit = 15; // 15 seconds
        for ($i = 0; $i < 4; $i++) {
            $this->customLog('-----SYNC DATA START-----', 'info');
            try {
                // sync IOT data from SQL to MySQL
                $this->syncIOTDataFromSqlToMySql();
                $elapsedTime = Carbon::now()->diffInSeconds($lastExecution);
                if ($elapsedTime < $timeLimit) {
                    $sleepTime = $timeLimit - $elapsedTime;
                    $this->customLog("Started syncIOTDataFromSqlToMySql() function in $elapsedTime seconds, will wait $sleepTime seconds to run again.", "info");
                    
                    // no sleep at last execution
                    if ($i != 3) {
                        sleep($sleepTime - 1);
                    }
                } else {
                    $this->customLog("Started syncIOTDataFromSqlToMySql() function in $elapsedTime seconds.", 'warning');
                }
            } catch (Exception $e) {
                $this->customLog($e->getMessage(), 'error');
            }
           
            $lastExecution = Carbon::now();
            $this->customLog('-----SYNC DATA END-----', 'info');
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

}