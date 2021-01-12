<?php
namespace Vanderbilt\APISyncExternalModule;

class BatchBuilderTest extends BaseTest{
    function testAddEvent(){
        $batchSize = rand(2, 5);
        $batchBuilder = new BatchBuilder($batchSize);
        $expected = [];

        $assert = function($event, $fields = []) use ($batchBuilder, &$expected){
            $logId = rand();
            $recordId = rand();
            
            $batchBuilder->addEvent($logId, $recordId, $event, $fields);

            $batch = $expected[count($expected)-1];
            $batch->add($logId, $recordId, $fields);

            $this->assertSame(json_encode($expected, JSON_PRETTY_PRINT), json_encode($batchBuilder->getBatches(), JSON_PRETTY_PRINT));
        };

        $expected[] = new Batch(APISyncExternalModule::UPDATE);
        $assert('UPDATE', ['a', 'b']);

        // Encountering a delete should start a new batch
        $expected[] = new Batch(APISyncExternalModule::DELETE);
        $assert('DELETE');

        // Each delete should be in a batch by itself
        $expected[] = new Batch(APISyncExternalModule::DELETE);
        $assert('DELETE');

        // A new batch should be started on an update after a delete
        $expected[] = new Batch(APISyncExternalModule::UPDATE);
        for($i=0; $i<$batchSize; $i++){
            $assert('UPDATE', ['a']);
        }

        // A new batch should be started when the batch size is reached
        $expected[] = new Batch(APISyncExternalModule::UPDATE);
        $assert('UPDATE', ['b']);
    }
}