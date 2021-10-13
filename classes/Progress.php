<?php namespace Vanderbilt\APISyncExternalModule;

class Progress
{
    private $module;
    private $data;

    function __construct($module){
        $this->module = $module;

        $data = $module->getImportProgress();
        if($data){
            $data = json_decode($data, true);
        }
        else{
            $data = [];
        }

        $this->data = $data;
    }

    function getModule(){
        return $this->module;
    }

    function addServer($server){
        $projects =& $this->data[$server['redcap-url']];
        if($projects !== null){
            // This server is already in progress.  Do Nothing.
            return;
        }

        $firstOnServer = true;
        foreach($this->getModule()->getProjects($server) as $project){
            if($firstOnServer){
                $project['log-server-start'] = true;
                $firstOnServer = false;
            }

            $project['batch-index'] = 0;

            $projects[$project['api-key']] = $project;
        }
    }

    function getCurrentServerUrl(){
        return array_key_first($this->data);
    }

    function &getCurrentProject(){
        $url = $this->getCurrentServerUrl();
        if($url === null){
            // There are no projects.
            $null = null; // This var is required to return null in a function that returns a reference.
            return $null;
        }

        $projects =& $this->data[$url];
        $apiKey = array_key_first($projects);
        $project =& $projects[$apiKey];

        if(@$project['log-server-start']){
			$logUrl = $this->module->formatURLForLogs($url);
			$serverStartMessage = "Started import from $logUrl";
	
			// This log mainly exists to show that the sync process has started, since the next log
			// doesn't occur until after the API request to get the project name (which could fail).
			$this->module->log($serverStartMessage);

			$project['log-server-start'] = false;
        }
        
        return $project;
    }

    function getBatchIndex(){
        return $this->getCurrentProject()['batch-index'];
    }

    function incrementBatch(){
        $this->getCurrentProject()['batch-index']++;
    }

    function finishCurrentProject(){
        $url = $this->getCurrentServerUrl();
        $project = $this->getCurrentProject();

        $projects =& $this->data[$url];
        unset($projects[$project['api-key']]);

        if(empty($projects)){
            $logUrl = $this->module->formatURLForLogs($url);
            $this->module->log("Finished import from $logUrl");
            unset($this->data[$url]);
        }
    }

    function serialize(){
        if(empty($this->data)){
            // Returning null will cause the setting to be removed from the db.
            return null;
        }

        return json_encode($this->data, JSON_PRETTY_PRINT);
    }
}