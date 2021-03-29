# API Sync

Automates exporting/importing to/from remote REDCap servers via the API.  The Data Dictionaries for the local and remote projects are expected to be either identical or compatible.  This module could easily be expanded to support additional scenarios (like automatically syncing the data dictionary as well).
	
This module stores API keys for remote systems in the module settings table in local REDCap database.  The same level of security applies as would apply to PHI or any other sensitive data stored in the local REDCap project.  This means users with design rights on the project, REDCap system administrators, server administrators, and/or database administrators would potentially have access to those API keys (as they would any other data on the local system).

## Form and Event Translations
Version 1.7.0 of the API Sync module introduces the form and event translations feature. This allows administrators to set translations for projects so that imported or exported data will have their form/event names translated upon import/export.

These translations are editable via the "Configure Translations" module page under the External Modules header in the REDCap project's sidebar.
### Configuring Translations for Import Projects
The first column of a configured form/event table should contain the names of the form/event names used in the destination project -- that is, the project that data is being imported to.

The following columns should contain form/event names used in source projects. When importing data, the module tries to find form/event names listed in the source columns. If a match is found, the name in the data is translated to the name in the first column.

An example project configured with the following table would convert the form names "Primera Forma" or "Первая форма" to "First Form" and "Otra Form" or "Другая форма" to "Another Form"

![Configuring import translations](/readme/import_forms.PNG)

Users can select rows by clicking table cells or select columns by clicking the column name. Selected rows and columns can be removed by clicking '- Remove'. Additional rows and columns can be added by clicking '+ Row' or '+ Column' respectively. The 'Export' and 'Import' buttons export or import CSV.
### Configuring Translations for Export Projects
Export tables are similar to import tables except they can contain only two columns. The first being the local form/event name and the second column containing the form or event name the module will translate the local form/event name to upon export.

For this reason, there is no '+ Column' button and columns cannot be removed.

In the following example table, the module is configured to convert the 'First Form' form name to 'Their Form Name' before exporting data.

![Configuring export translations](/readme/export_forms.PNG)