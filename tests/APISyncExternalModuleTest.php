<?php
namespace Vanderbilt\APISyncExternalModule;

use ExternalModules\ExternalModules;

class APISyncExternalModuleTest extends BaseTest{
    function testGetChangedFieldNamesForLogRow(){
        $expected = ['a', 'b', 'c', 'underscores_and_numbers123'];
        $allFieldNames = array_merge($expected, ['some_unchanged_field']);
        
        $actual = $this->getChangedFieldNamesForLogRow(implode("\n", [
            "a = ''",
            "b(1) = checked",
            "c = 'one\ntwo'",
            "underscores_and_numbers123 = ''",
        ]), $allFieldNames);

        $this->assertSame($expected, $actual);
    }

    function testGetChangedFieldNamesForLogRow_dataValuesMaxLength(){
        $dataValues = '';
        while(strlen($dataValues) < APISyncExternalModule::DATA_VALUES_MAX_LENGTH){
            $dataValues .= ' ';
        }

        $actual = $this->getChangedFieldNamesForLogRow($dataValues, ['whatever']);

        $this->assertSame([], $actual);
    }

    function assertFilterByFieldList($typeAll, $fieldListAll, $type, $fieldList, $instance, $expected){
        $this->cacheProjectSetting('-field-list-type-all', $typeAll);
        $this->cacheProjectSetting('-field-list-all', $fieldListAll);

        $project = [
            "-field-list-type" => $type,
            '-field-list' => $fieldList
        ];

        $_GET['pid'] = ExternalModules::getTestPIDs()[0];
        $this->module->filterByFieldList($project, $instance);

        $this->assertSame($expected, $instance);
    }

    function testFilterByFieldList(){
        $assert = function($type, $fieldListNumbers, $expectedFieldNumbers){
            $assertFilterByFieldList = function($typeAll, $fieldListAll, $type, $fieldList, $expectedFields){
                $instance = [
                    'field1' => rand(),
                    'field2' => rand()
                ];
        
                $fieldNumbersToNames = function($numbers){
                    if($numbers === null){
                        $numbers = [];
                    }
        
                    $names = [];
                    foreach($numbers as $number){
                        $names[] = "field$number";
                    }
        
                    return $names;
                };
        
                $fieldListAll = $fieldNumbersToNames($fieldListAll);
                $fieldList = $fieldNumbersToNames($fieldList);
                $expectedFields = $fieldNumbersToNames($expectedFields);

                $expected = [];
                foreach($expectedFields as $expectedFieldName){
                    if(isset($instance[$expectedFieldName])){
                        $expected[$expectedFieldName] = $instance[$expectedFieldName];
                    }
                }

                $this->assertFilterByFieldList($typeAll, $fieldListAll, $type, $fieldList, $instance, $expected);
            };

            $assertFilterByFieldList(null, null, $type, $fieldListNumbers, $expectedFieldNumbers);
            $assertFilterByFieldList($type, $fieldListNumbers, null, null, $expectedFieldNumbers);

            if(!empty($type)){
                // Make sure project settings take precedence
                $otherType = $type === 'include' ?  'exclude' : 'include';
                $assertFilterByFieldList($otherType, $fieldListNumbers, $type, $fieldListNumbers, $expectedFieldNumbers);
            }
        };

        $assert('include', [], []);
        $assert('include', [1], [1]);
        $assert('include', [1,2], [1,2]);
        $assert('include', [2,3], [2]);
        $assert('include', [3], []);

        $assert('exclude', [], [1,2]);
        $assert('exclude', [1], [2]);
        $assert('exclude', [1,3], [2]);
        $assert('exclude', [1,2], []);
        $assert('exclude', [3], [1, 2]);

        foreach([null, ''] as $emptyType){
            $assert($emptyType, [], [1,2]);
            $assert($emptyType, [1], [1,2]);
            $assert($emptyType, [3], [1,2]);
        }
    }

    function testFilterByFieldList_checkboxes(){
        $fieldName = 'some_checkbox';
        $fieldNameWithSuffix = "{$fieldName}___1";
        $otherFieldName = 'some_other_field';
        $instance = [
            $fieldNameWithSuffix => rand(),
            $otherFieldName => rand()
        ];

        $this->assertFilterByFieldList(null, null, 'exclude', [$fieldName], $instance, [
            $otherFieldName => $instance[$otherFieldName]
        ]);

        $this->assertFilterByFieldList(null, null, 'include', [$fieldName], $instance, [
            $fieldNameWithSuffix => $instance[$fieldNameWithSuffix]
        ]);
    }

    function testLogDetails(){
        $a = str_repeat('a', 65535);
        $b = str_repeat('b', 65535);
        $c = str_repeat('c', 65535);

        $logId = $this->logDetails('message', "$a$b$c");

        $result = $this->queryLogs('select details, details2, details3, details4 where log_id = ?', $logId);
        $actual = $result->fetch_assoc();

        $expected = [
            'details' => $a,
            'details2' => $b,
            'details3' => $c,
            'details4' => null,
        ];

        // Don't use assertSame() because the failure output is too large for the console.
        $this->assertTrue($expected === $actual);
    }
}