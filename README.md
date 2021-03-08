# API Sync

Automates exporting/importing to/from remote REDCap servers via the API.  The Data Dictionaries for the local and remote projects are expected to be either identical or compatible.  This module could easily be expanded to support additional scenarios (like automatically syncing the data dictionary as well).
	
This module stores API keys for remote systems in the module settings table in local REDCap database.  The same level of security applies as would apply to PHI or any other sensitive data stored in the local REDCap project.  This means users with design rights on the project, REDCap system administrators, server administrators, and/or database administrators would potentially have access to those API keys (as they would any other data on the local system).

## Form and Event Translations

Version 1.7.0 of the API Sync module introduces the form and event translations feature. This allows administrators to set translations for projects so that imported or exported data will have their form/event names translated upon import/export.
These translations are editable via the "Configure Translations" module page under the External Modules header in the REDCap project's sidebar.