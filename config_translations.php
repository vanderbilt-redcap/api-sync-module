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

function printTranslationsTable($translations = [], $type) {
	$row_count = count($translations);
	if (!empty($row_count)) {
		$column_count = count($translations[0]);
	}
	
	?>
	<div class='table-controls ml-3'>
		<button type='button' class='btn btn-outline-primary btn-sm add-row-btn'>+ Row</button>
		<button type='button' class='btn btn-outline-primary btn-sm add-col-btn'>+ Column</button>
		<button type='button' class='btn btn-outline-primary btn-sm remove-btn'>- Remove</button>
		<button type='button' class='btn btn-outline-info btn-sm save-btn mx-3' data-translation-type='<?= $type ?>'>Save</button>
		<button type='button' class='btn btn-outline-info btn-sm export-btn'>Export</button>
		<button type='button' class='btn btn-outline-info btn-sm import-btn' data-translation-type='<?= $type ?>'>Import</button>
	</div>
	<div class='card-body'>
		<h4><?= ucfirst($type) ?> Translations Table</h4>
		<table class='table translations-tbl'>
			<thead>
				<tr>
					<th>Local <?= ucfirst($type) ?> Name</th>
					<?php
					if (empty($column_count)) {
						echo "<th>Translated Name #1</th>";
					} else {
						for ($i = 1; $i <= ($column_count - 1); $i++) {
							echo "<th>Translated Name #$i</th>";
						}
					}
					?>
				</tr>
			</thead>
			<tbody>
				<?php foreach($translations as $row) {
					echo "<tr class='border-bottom'>";
					foreach($row as $name) {
						echo "<td><div contenteditable>$name</div></td>";
					}
					echo "</tr>";
				}?>
			</tbody>
		</table>
	</div>
	<?php
}

function printProjectCard($project_info) {
	?>
	<div class='card'>
		<div class='card-title m-3'>
			<h3 class='pb-1'><?= ucfirst($project_info['server-type']) ?> Server #<?= $project_info['server-index'] ?> - Project #<?= $project_info['project-index'] ?></h3>
			<span>Server URL: </span><b><span class='server-url'><?= $project_info['url'] ?></span></b>
			<br>
			<span>API Key: </span><b><span class='project-api-key'><?= $project_info['api-key'] ?></span></b>
			<span class='server-type'><?= $project_info['server-type'] ?></span>
		</div>
		<div class='loader-container'><div class='loader'></div></div>
		<?php
		foreach (['form', 'event'] as $type) {
			if ($project_info['server-type'] == 'export') {
				$translations = json_decode($project_info["export-$type-translations"]);
			} else {
				$translations = json_decode($project_info["$type-translations"]);
			}
			printTranslationsTable($translations, $type);
		}
		?>
	</div>
	<br>
	<?php
}

foreach ([$export_servers, $import_servers] as $server_set) {
	foreach ($server_set as $server_i => $server) {
		$url = $server['redcap-url'];
		foreach ($server['projects'] as $project_i => $project) {
			$project['url'] = $url;
			$project['server-index'] = $server_i + 1;
			$project['server-type'] = $server_set == $import_servers ? 'import' : 'export';
			$project['project-index'] = $project_i + 1;
			printProjectCard($project);
		}
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
				<form id='translation-file' action='?prefix=api_sync&page=config_translations&pid=<?= $module->getProjectId() ?>' enctype='multipart/form-data' method='POST'>
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
		pid: "<?= $module->getProjectId() ?>"
	}
</script>
<script type='text/javascript' src='<?= $module->getUrl('js/config_translations.js') ?>'></script>

<?php
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';