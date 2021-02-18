<?php

carl_log("config_translations.php GET: " . print_r($_GET, true));
carl_log("config_translations.php POST: " . print_r($_POST, true));
carl_log("config_translations.php FILES: " . print_r($_FILES, true));

if (isset($_POST['project-api-key']) and isset($_POST['server-url'])) {
	$import_error_message = $module->importTranslationsFile();
}

$export_servers = $module->getSubSettings('export-servers');
$import_servers = $module->getSubSettings('servers');

function printTranslationsTable($translations = [], $type) {
	$row_count = count($translations);
	if (!empty($row_count)) {
		$column_count = count($translations[0]);
	}
	
	?>
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
				echo "<tr>";
				foreach($row as $name) {
					echo "<td><div contenteditable>$name</div></td>";
				}
				echo "</tr>";
			}?>
		</tbody>
	</table>
	<?php
}

function printProjectCard($project_info) {
	?>
	<div class='card'>
		<div class='card-title m-3'>
			<h3 class='pb-1'>Server #<?= $project_info['server-index'] ?> - Project #<?= $project_info['project-index'] ?></h3>
			<span>API Key: </span><b><span class='project-api-key'><?= $project_info['api-key'] ?></span></b>
			<br>
			<span>Server URL: </span><b><span class='server-url'><?= $project_info['url'] ?></span></b>
			<span class='server-type'><?= $project_info['server-type'] ?></span>
		</div>
		<div class='table-controls ml-3'>
			<button type='button' class='btn btn-outline-primary btn-sm'>+ Row</button>
			<button type='button' class='btn btn-outline-primary btn-sm'>+ Column</button>
			<button type='button' class='btn btn-outline-primary btn-sm' disabled>- Remove</button>
			<button type='button' class='btn btn-outline-info btn-sm save-btn mx-3' disabled>Save</button>
			<button type='button' class='btn btn-outline-info btn-sm export-btn'>Export</button>
			<button type='button' class='btn btn-outline-info btn-sm import-btn' data-translation-type='form'>Import</button>
		</div>
		<div class='card-body'>
			<?php
			if ($project_info['server-type'] == 'export') {
				$translations = json_decode($project_info['export-form-translations']);
			} else {
				$translations = json_decode($project_info['form-translations']);
			}
			printTranslationsTable($translations, 'form');
			?>
		</div>
		
		<div class='table-controls ml-3'>
			<button type='button' class='btn btn-outline-primary btn-sm'>+ Row</button>
			<button type='button' class='btn btn-outline-primary btn-sm'>+ Column</button>
			<button type='button' class='btn btn-outline-primary btn-sm' disabled>- Remove</button>
			<button type='button' class='btn btn-outline-info btn-sm save-btn mx-3' disabled>Save</button>
			<button type='button' class='btn btn-outline-info btn-sm export-btn'>Export</button>
			<button type='button' class='btn btn-outline-info btn-sm import-btn' data-translation-type='event'>Import</button>
		</div>
		<div class='card-body'>
			<?php
			if ($project_info['server-type'] == 'export') {
				$translations = json_decode($project_info['export-event-translations']);
			} else {
				$translations = json_decode($project_info['event-translations']);
			}
			printTranslationsTable($translations, 'event');
			?>
		</div>
	</div>
	<br>
	<?php
}

foreach ($import_servers as $server_i => $server) {
	$url = $server['redcap-url'];
	foreach ($server['projects'] as $project_i => $project) {
		$project['url'] = $url;
		$project['server-index'] = $server_i + 1;
		$project['server-type'] = 'import';
		$project['project-index'] = $project_i + 1;
		printProjectCard($project);
	}
}

echo "<pre>export_servers:\n" . print_r($export_servers, true) . "</pre>";
echo "<pre>import_servers:\n" . print_r($import_servers, true) . "</pre>";

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
		import_error_message: "<?= $import_error_message ?>"
	}
</script>
<script type='text/javascript' src='<?= $module->getUrl('js/config_translations.js') ?>'></script>