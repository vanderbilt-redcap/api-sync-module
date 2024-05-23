<?php
namespace Vanderbilt\APISyncExternalModule;

class DigestLog
{
    private $accounted_urls = [];
    private $unaccounted_urls = [];
    private $url_statuses;
    private $status_flags = [
        'error' => false,
        'cancelled' => false,
        'complete' => false
    ];
    private $accumulated_record_imports = 0;
    private $error_log_id = null;

    private const MSG_SUBSTRS = [
        "ERROR" => "An error occurred",
        "CANCEL" => "Cancelling current import",
        "IMPORT_START" => "Started import from",
        "IMPORT_BATCH_FINISH" => "Import completed successfully for batch",
        "IMPORT_FINISH" => "Finished import from",
    ];

    function __construct(APISyncExternalModule $module) {
        $this->module = $module;
        $settings = $module->getProjectSettings();
        $unaccounted_urls = $settings['redcap-url'];

        foreach ($unaccounted_urls as $url) {
            $this->url_statuses[$url]['timestamp'] = '';
            $this->url_statuses[$url]['import_source'] = $url;
            $this->url_statuses[$url]['n_records'] = '0';
            $this->url_statuses[$url]['status'] = 'Never run';
            $this->url_statuses[$url]['error_log_id'] = null;
        }

        $this->unaccounted_urls = $unaccounted_urls;
    }


    private function resetStatus(): void {
        $this->accumulated_record_imports = 0;
        $this->error_log_id = null;

        foreach ($this->status_flags as &$flag) { $flag = false; }
    }


    private function cumRecords(array $row): void {
        $log_id = $row['log_id'];
        $result = $this->module->queryLogs('select details where log_id = ?', [$log_id]);
        $detail_row = $result->fetch_assoc();
        try {
            $details = json_decode($detail_row['details'], true);
        } catch (Exception $e) {
            return;
        }

        $batch_records = count($details['ids']);
        $this->accumulated_record_imports += $batch_records;
    }


    private function assignAccumulatedRecords(string $url): void {
        $this->url_statuses[$url]['n_records'] = $this->accumulated_record_imports;
        $this->accumulated_record_imports = 0;
    }


    private function completeUrlEntry(string $url): void {
        // prevent looking past one import event
        $this->unaccounted_urls = array_diff($this->unaccounted_urls, [$url]);
        $this->accounted_urls[] = $url;

        $status_msg = "In progress";

        if ($this->status_flags['complete']) {
            $status_msg = "Complete";
        }
        if($this->status_flags['cancelled']) {
            $status_msg = "This import was cancelled";
        }
        if ($this->status_flags['error']) {
            $status_msg = "An error occurred";
            // TODO: get details
            $this->url_statuses[$url]['error_log_id'] = $this->error_log_id;
        }

        $this->assignAccumulatedRecords($url);
        $this->resetStatus();

        $this->url_statuses[$url]['status'] = $status_msg;
    }

    // analogue of str_starts_with for PHP 7 compatibility
    private function strStartsWith($haystack, $needle): bool {
        // discussion for why strncmp was chosen: https://stackoverflow.com/a/2792233/7418735
        return (strncmp($haystack, $needle, strlen($needle)) === 0);
    }


    public function parseLogRow(array $row): void {
        if ($this->strStartsWith($row['message'], self::MSG_SUBSTRS["ERROR"])) {
            $this->status_flags['error'] = true;
            $this->error_log_id = $row['log_id'];
            return;
        }
        if ($this->strStartsWith($row['message'], self::MSG_SUBSTRS["CANCEL"])) {
            $this->status_flags['cancelled'] = true;
            return;
        }

        if ($this->strStartsWith($row['message'], self::MSG_SUBSTRS["IMPORT_BATCH_FINISH"])) {
            // parse details for number of records imported in a batch
            // NOTE: this assumes no concurrent import processes for different urls

            $this->cumRecords($row);
            return;
        }

        // ignore earlier pull events for already quantified urls
        if ($this->strStartsWith($row['message'], self::MSG_SUBSTRS["IMPORT_START"])) {
            foreach($this->accounted_urls as $url) {
                if (strpos($row['message'], $url) !== false) {
                    $this->resetStatus();
                    return;
                }
            }
        }

        foreach($this->unaccounted_urls as $url) {
            if (strpos($row['message'], $url) !== false) {
                $this->url_statuses[$url]['timestamp'] = htmlentities($row['timestamp'], ENT_QUOTES);

                if ($this->strStartsWith($row['message'], self::MSG_SUBSTRS["IMPORT_FINISH"])) {
                    $this->status_flags['complete'] = true;
                } elseif ($this->strStartsWith($row['message'], self::MSG_SUBSTRS["IMPORT_START"])) {
                    $this->completeUrlEntry($url);
                    $this->accounted_urls[] = $url;
                }
                return;
            }
        }
    }


    public function isDone(): bool {
        return (!$this->unaccounted_urls);
    }


    public function getDigest(): array {
        // NOTE: $url_statuses must have numeric indices as string indices are not handled by the frontend
        return array_values($this->url_statuses);
    }


    public static function createLikeStatements(): string {
        $sql = "(";

        $is_first = true;
        foreach (self::MSG_SUBSTRS as $k => $m) {
            $sql .= ($is_first) ? " " : " OR ";
            $sql .= "message LIKE '$m%'";
            $is_first = false;
        }
        $sql .= ")";
        return ($is_first) ? "true" : $sql;
    }
}
