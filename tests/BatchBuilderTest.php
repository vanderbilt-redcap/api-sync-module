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

    function testShouldStartNewBatch(){
        $assert = function($fields1, $fields2){
            $b = new BatchBuilder(100);
            $b->addEvent(1, 1, APISyncExternalModule::UPDATE, $fields1);
            $b->addEvent(2, 2, APISyncExternalModule::UPDATE, $fields1);
            $b->addEvent(3, 3, APISyncExternalModule::UPDATE, $fields2);
            $b->addEvent(4, 4, APISyncExternalModule::UPDATE, $fields2);
            
            $batches = $b->getBatches();
            $this->assertSame([1,2], $batches[0]->getRecordIds());
            $this->assertSame([3,4], $batches[1]->getRecordIds());
        };

        $assert(['a'], []);
        $assert([], ['b']);
    }

    function testMergeBatches(){
        $a = new BatchBuilder(100);
        $a->addEvent(1, 1, APISyncExternalModule::UPDATE, ['a']);
        $a->addEvent(3, 1, APISyncExternalModule::UPDATE, ['c']);

        $b = new BatchBuilder(100);
        $b->addEvent(2, 2, APISyncExternalModule::UPDATE, []);
        $b->addEvent(4, 3, APISyncExternalModule::UPDATE, []);

        $batches = $this->mergeBatches($a, $b);
        $this->assertSame($batches, $this->mergeBatches($b, $a), "The order of arguments shouldn't matter since we are sorting by log ID.");

        $this->assertSame(2, count($batches));
        $this->assertSame(['a', 'c'], $batches[0]->getFields());
        $this->assertSame([], $batches[1]->getFields());
    }
}