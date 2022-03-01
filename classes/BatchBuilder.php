<?php
namespace Vanderbilt\APISyncExternalModule;

require_once __DIR__ . '/Batch.php';

use Exception;

class BatchBuilder {
    /** @var Batch[] */
    private $batches = [];
    private $batchSize;

    function __construct($batchSize){
        $this->batchSize = $batchSize;
    }

    function getBatches(){
        return $this->batches;
    }

    function addEvent($logId, $recordId, $event, $fields){
        if($event === 'DELETE'){
            // If any record fails to delete it will stop the deletion of other records.
            // The simplest solution for this was to limit the batch size to one.
            // In the future, we could potentially parse failed record IDs out of responses and remove them from batches instead.
            $this->startNextBatch(APISyncExternalModule::DELETE);

            // The record ID field name will be detected, but specifying fields doesn't make sense when deleting records.
            $fields = [];
        }
        else{
            if($this->shouldStartNextBatchBeforeUpdate($fields)){
                $this->startNextBatch(APISyncExternalModule::UPDATE);
            }
        }

        $this->addToCurrentBatch($logId, $recordId, $fields);
    }

    function startNextBatch($type){
        $this->batches[] = new Batch($type);
    }

    function shouldStartNextBatchBeforeUpdate($fields){
        $batch = $this->getCurrentBatch();
        return 
            $batch === null // The first batch as not been created yet.
            ||
            count($batch->getRecordIds()) === $this->batchSize
            ||
            $batch->getType() === APISyncExternalModule::DELETE // DELETEs should be in batches by themselves.
            ||
            $batch->shouldStartNewBatch($fields)
        ;
    }

    private function addToCurrentBatch($logId, $recordId, $fields){
        $this->getCurrentBatch()->add($logId, $recordId, $fields);
    }

    private function getCurrentBatch(){
        return $this->batches[count($this->batches)-1] ?? null;
    }
}