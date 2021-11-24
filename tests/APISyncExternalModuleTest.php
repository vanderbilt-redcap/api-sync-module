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

    function testFilterByFieldList(){
        $_GET['pid'] = ExternalModules::getTestPIDs()[0];

        $instance = [
            'field1' => rand(),
            'field2' => rand()
        ];

        $fieldNumbersToNames = function($numbers){
            $names = [];
            foreach($numbers as $number){
                $names[] = "field$number";
            }

            return $names;
        };

        $assert = function($type, $fieldListNumbers, $expectedFieldNumbers) use ($instance, $fieldNumbersToNames){
            $fieldList = $fieldNumbersToNames($fieldListNumbers);
            $expectedFieldNames = $fieldNumbersToNames($expectedFieldNumbers);

            $expected = [];
            foreach($expectedFieldNames as $expectedFieldName){
                if(isset($instance[$expectedFieldName])){
                    $expected[$expectedFieldName] = $instance[$expectedFieldName];
                }
            }
            
            $project = [
                "-field-list-type" => $type,
                '-field-list' => $fieldList
            ];
            
            $this->module->filterByFieldList($project, $instance);

            $this->assertSame($expected, $instance);
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