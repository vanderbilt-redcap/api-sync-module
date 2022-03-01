<?php
namespace Vanderbilt\APISyncExternalModule;

use Exception;

class Batch{
    private $type;
    private $lastLogId;
    private $recordIds = [];
    private $fields = [];
    private $fieldsByRecord = [];

    function __construct($type){
        $this->type = $type;
    }

    function getType(){
        return $this->type;
    }

    function getLastLogId(){
        return $this->lastLogId;
    }

    function getRecordIds(){
        return array_keys($this->recordIds);
    }

    function getFields(){
        return array_keys($this->fields);
    }

    function getFieldsByRecord(){
        return $this->fieldsByRecord;
    }

    function add($logId, $recordId, $fields){
        if(!is_array($fields)){
            throw new Exception('An array must be specified for the fields argument.');
        }

        if($this->type === APISyncExternalModule::DELETE){
            if($fields !== []){
                throw new Exception("The fields argument should be an empty array for delete events.");
            }
        }

        if($this->shouldStartNewBatch($fields)){
            throw new \Exception("Mixing batches with & without field lists is not allowed!  This should have been caught ahead of time in the shouldStartNewBatch() call in BatchBuilder");
        }

        $this->lastLogId = $logId;
        $this->recordIds[$recordId] = true;

        foreach($fields as $field){
            $this->fields[$field] = true;
            $this->fieldsByRecord[$recordId][$field] = true;
        }
    }

    function shouldStartNewBatch($fields){
        return
            /**
             * No reason to start a new batch if this one is empty.
             */
            !empty($this->recordIds)
            &&
            /**
             * A batch should either specify fields or not.
             * Mixing batches with and without fields can skip data and/or sync a lot more data than necessary.
             */
            empty($fields) !== empty($this->fields);
    }
}