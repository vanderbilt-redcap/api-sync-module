<?php
if (isset($_POST['project-api-key']) and isset($_POST['server-url'])) {
	if (isset($_POST['table_saved'])) {
		$table_saved_error_message = $module->importTranslationsTable();
		
		$response = new \stdClass();
		$response->success = true;
		if (!empty($table_saved_error_message)) {
			$response->success = false;
			$response->error = $table_saved_error_message;
		}
		
		header('Content-type: application/json');
		exit(json_encode($response));
	} else {
		$import_error_message = $module->importTranslationsFile();
	}
}
require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

$export_servers = $module->getSubSettings('export-servers');
$import_servers = $module->getSubSettings('servers');

function printTranslationsTable($translations, $type, $server_type) {
	$row_count = count($translations);
	if (!empty($row_count)) {
		$column_count = count($translations[0]);
	}
	$col_name = "Destination";
	$col2_name = "Source";
	if ($server_type == 'export') {
		$column_count = 2;
		$col_name = "Source";
		$col2_name = "Destination";
	}
	
	?>
	<div class='table-controls ml-3'>
		<button type='button' class='btn btn-outline-primary btn-sm add-row-btn'>+ Row</button>
		<?php if ($server_type != 'export') { ?> <button type='button' class='btn btn-outline-primary btn-sm add-col-btn'>+ Column</button> <?php } ?>
		<button type='button' class='btn btn-outline-primary btn-sm remove-btn'>- Remove</button>
		<button type='button' class='btn btn-outline-info btn-sm save-btn mx-3' data-translation-type='<?= $type ?>'>Save</button>
		<button type='button' class='btn btn-outline-info btn-sm export-btn'>Export CSV</button>
		<button type='button' class='btn btn-outline-info btn-sm import-btn' data-translation-type='<?= $type ?>'>Import CSV</button>
	</div>
	<div class='card-body'>
		<h4><?= ucfirst($type) ?> Translations Table</h4>
		<table class='table translations-tbl <?=$server_type?>'>
			<thead>
				<tr>
					<th> <?= $col_name . ' ' . ucfirst($type) ?> Name</th>
					<?php
					if (empty($column_count)) {
						echo "<th>$col_name Name</th>";
					} else {
						for ($i = 1; $i <= ($column_count - 1); $i++) {
							echo "<th>$col2_name Name</th>";
						}
					}
					?>
				</tr>
			</thead>
			<tbody>
				<?php foreach($translations as $row) {
					echo "<tr class='border-bottom'>";
					for ($col_index = 1; $col_index <= $column_count; $col_index++) {
						$name = trim($row[$col_index-1]);
						$name = str_replace("\\", "", $name);
						echo "<td><textarea>" . htmlentities($name, ENT_QUOTES) . "</textarea></td>";
					}
					echo "</tr>";
				}?>
			</tbody>
		</table>
	</div>
	<?php
}

function printProjectCard($project_info) {
	$settings_prefix = $project_info['server-type'] == 'export' ? 'export-' : '';
	?>
	<div class='card'>
		<div class='card-title m-3'>
			<h3 class='pb-1'><span class='server-type'><?= ucfirst($project_info['server-type']); ?></span> Project <?= $project_info['project-index'] . ': ' . $project_info["{$settings_prefix}project-name"] ?></h3>
			<span>Server URL: </span><b><span class='server-url'><?= $project_info['url'] ?></span></b>
			<span class='project-api-key'><?= $project_info['api-key'] ?></span>
		</div>
		<div class='loader-container'><div class='loader'></div></div>
		<?php
		foreach (['form', 'event'] as $type) {
			$translations = json_decode($project_info["$settings_prefix$type-translations"]) ?? [];
			printTranslationsTable($translations, $type, $project_info['server-type']);
		}
		?>
	</div>
	<br>
	<?php
}

if (empty($import_servers) and empty($export_servers)) {
	?>
	<div class='alert alert-primary w-50'>
		<h5 class='py-2'>No export/import servers configured</h5>
		<p>Once you visit the External Modules page and configure servers and projects to export/import to or from, you can return to this page to specify form and event translations.</p>
	</div>
	<?php
} else {
	?>
	<div class='alert alert-primary w-75'>
		<h5 class=''>Form and Event Translations</h5>
		<p>You can edit the tables below to specify form and event translations per project.</p>
		<p>For each table, the first column should hold the name of forms and events that exist on this project.</p>
		<p>For import tables, columns past the first column should hold the names of forms or events that you want the API Sync module to translate upon import. Each value will be translated to the name in the first column.</p>
		<p>For export tables, the API Sync module converts this project's form and event names in the first column to the value in the second column upon export.</p>
		<p>Click table cells to select rows. Click column names to select columns. Selected rows or columns can be removed.</p>
	</div>
	<?php
}

foreach ($import_servers as $server_i => $server) {
	$url = $server['redcap-url'];
	foreach ($server['projects'] as $project_i => $project) {
		$project['project-name'] = $module->getRemoteProjectTitle($url, $project['api-key']);
		$project['url'] = $url;
		$project['server-index'] = $server_i + 1;
		$project['server-type'] = 'import';
		$project['project-index'] = $project_i + 1;
		if (empty($url) or empty($project['api-key'])) {
			continue;
		}
		printProjectCard($project);
	}
}

// show export server projects
foreach ($export_servers as $server_i => $server) {
	$url = $server['export-redcap-url'];
	foreach ($server['export-projects'] as $project_i => $project) {
		$project['url'] = $url;
		$project['server-index'] = $server_i + 1;
		$project['server-type'] = 'export';
		$project['project-index'] = $project_i + 1;
		$project['api-key'] = $project['export-api-key'];
		if (empty($url) or empty($project['api-key'])) {
			continue;
		}
		printProjectCard($project);
	}
}

?>
<!-- file import modal -->
<div id="import-translations" class="modal" tabindex="-1" role="dialog">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Import Translations CSV File</h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<p>Upload a CSV containing name translations below.<br>The first column should contain names local to this project.<br>Proceeding column values should contain the translated names.</p><br>
				<!--<form id='translation-file' action='?prefix=api_sync&page=config_translations&pid=<?= $module->getProjectId() ?>' enctype='multipart/form-data' method='POST'>-->
				<form id='translation-file' action='<?= $module->getUrl('config_translations.php'); ?>' enctype='multipart/form-data' method='POST'>
					<div class="input-group">
						<div class="custom-file">
							<input type='hidden' name='project-api-key' id='project-api-key'>
							<input type='hidden' name='server-url' id='server-url'>
							<input type='hidden' name='server-type' id='server-type'>
							<input type='hidden' name='translations-type' id='translations-type'>
							<input type="file" class="custom-file-input" id="attach-file-1" name="attach-file-1" aria-describedby="attach-file-addon-1">
							<label class="custom-file-label" for="attach-file-1">Choose file</label>
						</div>
						<div class="input-group-append">
							<button class="btn btn-outline-secondary remove-file" type="button" id="attach-file-addon-1" style="line-height: 1.15; font-size: .8rem;">Remove</button>
						</div>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary" data-dismiss="modal" id="import-submit">Save</button>
				<button type="button" class="btn btn-secondary" data-dismiss="modal" id="import-cancel">Cancel</button>
			</div>
		</div>
	</div>
</div>
<script type='text/javascript'>
	api_sync_module = {
		css_url: '<?= $module->getUrl("css/config_translations.css") ?>',
		import_error_message: "<?= $import_error_message ?>",
		table_saved_error_message: "<?= $table_saved_error_message ?>",
		pid: "<?= $module->getProjectId() ?>",
		translation_table_cell: '<?= $module::TRANSLATION_TABLE_CELL; ?>',
		ajax_endpoint: '<?= $module->getUrl("config_translations.php"); ?>'
	}
</script>
<script type='text/javascript' src='<?= $module->getUrl('js/config_translations.js') ?>'></script>

<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';