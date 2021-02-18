
<!-- Bootstrap latest compiled and minified JavaScript -->
<!-- <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script> -->

<?php

carl_log("config_translations.php GET: " . print_r($_GET, true));
carl_log("config_translations.php POST: " . print_r($_POST, true));
carl_log("config_translations.php FILES: " . print_r($_FILES, true));

if (isset($_POST['project-api-key']) and isset($_POST['server-url'])) {
	$module->importTranslationsFile();
}

$export_servers = $module->getSubSettings('export-servers');
$import_servers = $module->getSubSettings('servers');

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
			<button type='button' class='btn btn-outline-info btn-sm'>Export</button>
			<button type='button' class='btn btn-outline-info btn-sm import-btn'>Import</button>
		</div>
		<div class='card-body'>
			<h4>Form Translations Table</h4>
			<table class='table translations-tbl'>
				<thead>
					<tr>
						<th>Local Form Name</th>
						<th>Translated Name #1</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><div contenteditable>Instrument A</div></td>
						<td><div contenteditable>My Instrument</div></td>
					</tr>
					<tr>
						<td><div contenteditable>Instrument A</div></td>
						<td><div contenteditable>My Instrument</div></td>
					</tr>
				</tbody>
			</table>
		</div>
		
		<div class='table-controls ml-3'>
			<button type='button' class='btn btn-outline-primary btn-sm'>+ Row</button>
			<button type='button' class='btn btn-outline-primary btn-sm'>+ Column</button>
			<button type='button' class='btn btn-outline-primary btn-sm' disabled>- Remove</button>
			<button type='button' class='btn btn-outline-info btn-sm save-btn mx-3' disabled>Save</button>
			<button type='button' class='btn btn-outline-info btn-sm'>Export</button>
			<button type='button' class='btn btn-outline-info btn-sm import-btn'>Import</button>
		</div>
		<div class='card-body'>
			<h4>Event Translations Table</h4>
			<table class='table translations-tbl'>
				<thead>
					<tr>
						<th>Local Form Name</th>
						<th>Translated Name #1</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><div contenteditable>Instrument A</div></td>
						<td><div contenteditable>My Instrument</div></td>
					</tr>
					<tr>
						<td><div contenteditable>Instrument A</div></td>
						<td><div contenteditable>My Instrument</div></td>
					</tr>
				</tbody>
			</table>
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
	api_sync_module = {css_url: '<?= $module->getUrl("css/config_translations.css") ?>'}
</script>
<script type='text/javascript' src='<?= $module->getUrl('js/config_translations.js') ?>'></script>