<?php
namespace Vanderbilt\APISyncExternalModule;

class TranslationTest extends BaseTest{
    function test_build_translations() {
		$module = new APISyncExternalModule();
		
        $project1 = [];
		$module->buildTranslations($project1);
		$this->assertSame([], $project1);
		
		$project2 = [
			'form-translations' => '[["Instrument A"," My First Instrument"," My First Instrument_2"],["Instrument B"," Instrument 2"," Instrument 2_2"]]',
			'event-translations' => '[["E_1"," E1"," EA"],["E_2"," E2"," EB"],["E_3"," E3"," EC"]]'
		];
		$module->buildTranslations($project2);
		$this->assertSame($project2, [
			'form-translations' => [
				['instrument_a', 'my_first_instrument', 'my_first_instrument_2'],
				['instrument_b', 'instrument_2', 'instrument_2_2']
			],
			'event-translations' => [
				['e_1', 'e1', 'ea'],
				['e_2', 'e2', 'eb'],
				['e_3', 'e3', 'ec']
			]
		]);
    }
	function test_import_translate_data() {
		$module = new APISyncExternalModule();
		
		$project = [
			'form-translations' => [
				['instrument_a', 'my_first_instrument', 'my_first_instrument_2'],
				['instrument_b', 'instrument_2', 'instrument_2_2']
			],
			'event-translations' => [
				['e_1', 'e1', 'ea'],
				['e_2', 'e2', 'eb'],
				['e_3', 'e3', 'ec']
			]
		];
        $data = [
			[
				'my_rid_field' => 'src1_1',
				'redcap_event_name' => 'e1_arm_1',
				'redcap_repeat_instrument' => null,
				'test' => 'a',
				'my_first_instrument_complete' => 0,
				'instrument_2_complete' => 0
			],
			[
				'my_rid_field' => 'src1_1',
				'redcap_event_name' => 'e2_arm_1',
				'redcap_repeat_instrument' => 'my_first_instrument',
				'redcap_repeat_instance' => '1',
				'test' => 'b',
				'my_first_instrument_complete' => '0',
				'instrument_2_complete' => ''
			]
		];
		
		$module->translateFormNames($data, $project);
		$module->translateEventNames($data, $project);
		
		$this->assertSame([
			[
				'my_rid_field' => 'src1_1',
				'redcap_event_name' => 'e_1_arm_1',
				'redcap_repeat_instrument' => null,
				'test' => 'a',
				'instrument_a_complete' => 0,
				'instrument_b_complete' => 0
			],
			[
				'my_rid_field' => 'src1_1',
				'redcap_event_name' => 'e_2_arm_1',
				'redcap_repeat_instrument' => 'instrument_a',
				'redcap_repeat_instance' => '1',
				'test' => 'b',
				'instrument_a_complete' => '0',
				'instrument_b_complete' => ''
			]
		], $data);
    }
}