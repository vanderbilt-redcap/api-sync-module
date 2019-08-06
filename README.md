# API Sync

Overwrites the data in the current project daily from a remote REDCap server via the API.  The Data Dictionaries for both projects are expected to be either identical or compatible.  This module could easily be expanded to support other multi-server synchronization tasks as well.  Very large projects may not work properly.  In one case an import took 15 minutes for a project with about 10,000 records and 1,500 fields.",
	
This module stores API keys for remote systems in the module settings table in local REDCap database.  The same level of security applies as would apply to PHI or any other sensitive data stored in the local REDCap project.  This means users with design rights on the project, REDCap system administrators, server administrators, and/or database administrators would potentially have access to those API keys (as they would any other data on the local system).
