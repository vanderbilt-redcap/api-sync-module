# API Sync

Automates exporting/importing to/from remote REDCap servers via the API.  The Data Dictionaries for the local and remote projects are expected to be either identical or compatible.  This module could easily be expanded to support additional scenarios (like automatically syncing the data dictionary as well).
	
This module stores API keys for remote systems in the module settings table in local REDCap database.  The same level of security applies as would apply to PHI or any other sensitive data stored in the local REDCap project.  This means users with design rights on the project, REDCap system administrators, server administrators, and/or database administrators would potentially have access to those API keys (as they would any other data on the local system).
