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

        $fieldName = 'some_field';
        $instance = [
            $fieldName => rand(),
            'some_other_field' => rand()
        ];

        $assert = function($type) use ($fieldName, $instance){
            $project = [
                "-field-list-type" => $type,
                '-field-list' => [$fieldName]
            ];
            
            $this->module->filterByFieldList($project, $instance);

            $this->assertSame(1, count($instance));
            $this->assertSame(isset($instance[$fieldName]), $type === 'include');
        };

        $listTypes = ['include', 'exclude'];
        foreach($listTypes as $listType){
            $assert($listType);
        }
    }
}