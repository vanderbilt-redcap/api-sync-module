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

        $expected = ['a'];

        $actual = $this->getChangedFieldNamesForLogRow($dataValues, $expected);

        $this->assertSame($expected, $actual);
    }

    function assertFilterByFieldList($typeAll, $fieldListAll, $type, $fieldList, $expectedFields, $instance){
        $this->cacheProjectSetting('-field-list-type-all', $typeAll);
        $this->cacheProjectSetting('-field-list-all', $fieldListAll);

        $project = [
            "-field-list-type" => $type,
            '-field-list' => $fieldList
        ];

        $expected = [];
        foreach($expectedFields as $expectedFieldName){
            if(isset($instance[$expectedFieldName])){
                $expected[$expectedFieldName] = $instance[$expectedFieldName];
            }
        }

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

                $this->assertFilterByFieldList($typeAll, $fieldListAll, $type, $fieldList, $expectedFields, $instance);
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
}