<?php
namespace Vanderbilt\APISyncExternalModule;

class BatchTest extends BaseTest{
    function testAdd(){
        $type = APISyncExternalModule::UPDATE;
        $lastLogId = rand();
        $recordId = rand();
        $recordIds = [$recordId];
        $fields = [];
        $fieldsByRecord = [];

        for($i=0; $i<rand(2,5); $i++){
            $field = rand();
            $fields[$field] = true;
            $fieldsByRecord[$recordId][$field] = true;
        }

        $fields = array_keys($fields);

        $batch = new Batch($type);
        $batch->add($lastLogId, $recordId, $fields);

        foreach(get_class_methods($batch) as $method){
            if(strpos($method, 'get') !== 0){
                continue;
            }

            $variableName = lcfirst(substr($method, 3));

            $this->assertSame($$variableName, $batch->{$method}(), "Testing Batch::$method()");
        }
    }

    function testAdd_duplicates(){
        $batch = new Batch(APISyncExternalModule::UPDATE);
        $recordId = rand();
        
        $assertCount = function($expected, $field) use ($batch, $recordId){
            $batch->add(rand(), $recordId, [$field]);
            $this->assertCount($expected, $batch->getFieldsByRecord()[$recordId]);
        };

        $assertCount(1, 'a');
        $assertCount(1, 'a');
        $assertCount(2, 'b');
        $assertCount(2, 'b');
        $assertCount(2, 'a');
    }
    
    function testAdd_nullFieldsForUpdate(){
        $this->expectExceptionMessage('array must be specified');
        $b = new Batch(APISyncExternalModule::UPDATE);
        $b->add(1, 1, null);
    }

    function testAdd_fieldForDelete(){
        $this->expectExceptionMessage('should be an empty array');
        $b = new Batch(APISyncExternalModule::DELETE);
        $b->add(1, 1, ['a']);
    }
}