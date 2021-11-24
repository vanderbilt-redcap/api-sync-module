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

        $f1 = 'field1';
        $f2 = 'field2';
        $f3 = 'field3';

        $instance = [
            $f1 => rand(),
            $f2 => rand()
        ];

        $assert = function($type, $fieldName, $expectedFieldNames) use ($instance){
            $expected = [];
            foreach($expectedFieldNames as $expectedFieldName){
                if(isset($instance[$expectedFieldName])){
                    $expected[$expectedFieldName] = $instance[$expectedFieldName];
                }
            }
            
            $project = [
                "-field-list-type" => $type,
                '-field-list' => [$fieldName]
            ];
            
            $this->module->filterByFieldList($project, $instance);

            $this->assertSame($expected, $instance);
        };

        $assert('include', $f1, [$f1]);
        $assert('exclude', $f1, [$f2]);

        $assert('include', $f3, []);
        $assert('exclude', $f3, [$f1, $f2]);

        foreach([null, ''] as $emptyType){
            $assert($emptyType, $f3, [$f1, $f2]);
        }
    }
}