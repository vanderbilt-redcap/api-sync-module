# API Sync

Automates exporting/importing to/from remote REDCap servers via the API.  The Data Dictionaries for the local and remote projects are expected to be either identical or "compatible".  Examples of "compatible" data dictionaries might include the destination having additional fields or dropdown choices that the source doesn't have.  In general, any scenario should work with this module that works without error when manually exporting & importing from one project to another.  Under the hood, the module is essentially automating a full manual CSV export/import, including reporting any errors you would normally receive due to Data Dictionary differences.  Functionality could fairly easily be expanded to support additional scenarios, like automatically syncing the data dictionary as well, or specifying an include/exclude list of fields to sync instead of all fields.

This module stores API keys for remote systems in the module settings table in local REDCap database.  The same level of security applies as would apply to PHI or any other sensitive data stored in the local REDCap project.  This means users with design rights on the project, REDCap system administrators, server administrators, and/or database administrators would potentially have access to those API keys (as they would any other data on the local system).

## Form and Event Translations

Version 1.7.0 of the API Sync module introduces the form and event translations feature. This allows administrators to set translations for projects so that imported or exported data will have their form/event names translated upon import/export.

These translations are editable via the "Configure Translations" module page under the External Modules header in the REDCap project's sidebar.

### Configuring Translations for Import Projects

The first column of a configured form/event table should contain the names of the form/event names used in the destination project -- that is, the project that data is being imported to.

The following columns should contain form/event names used in source projects. When importing data, the module tries to find form/event names listed in the source columns. If a match is found, the name in the data is translated to the name in the first column.

An example project configured with the following table would convert the form names "Primera Forma" or "A 'chiad Fhoirm" to "First Form" and "Otra Form" or "Foirm eile" to "Another Form"

![Configuring import translations](/readme/import_forms.PNG)

Users can select rows by clicking table cells or select columns by clicking the column name. Selected rows and columns can be removed by clicking '- Remove'. Additional rows and columns can be added by clicking '+ Row' or '+ Column' respectively. The 'Export' and 'Import' buttons export or import CSV.

### Configuring Translations for Export Projects

Export tables are similar to import tables except they can contain only two columns. The first being the local form/event name and the second column containing the form or event name the module will translate the local form/event name to upon export.

For this reason, there is no '+ Column' button and columns cannot be removed.

In the following example table, the module is configured to convert the 'First Form' form name to 'Their Form Name' and 'Other Form' to 'Their Other Form' before exporting data.

![Configuring export translations](/readme/export_forms.PNG)

### Note: Form and Event Names in REDCap

REDCap forms and events have two names. A display name and a unique name. The unique name is usually not shown to users but it's how REDCap refers to forms and events internally. You may use either in the 'Configure Translations' tables -- the module can usually determine the correct unique name for a given display name.

The module won't be able to determine the unique name in the following cases:

1. The display name contains only non-Latin characters.

   In this case, REDCap will generate a unique form name using random characters. The module won't be able to guess the unique form name in this case.
2. The remote instance of REDCap contains multiple forms/events with the same display name.

   In this case, REDCap will append some random characters to make the unique name unique for that form/event.

Using the display name in the above cases will cause imports/exports to fail. The workaround is to determine the unique names for the forms/events and use those instead. You can determine these unique names, for instance, by exporting the raw data as CSV and finding the names within the exported file.

### File Sync

This module will handle files in an import or export if the configuration setting to import/export files is checked in the configuration for each given project. Note that files are only transferred **when a filename as changed**. If a filename stays the same, the sync module will assume that the file has stayed the same. When the source file is deleted, the corresponding file on the destination will be deleted. To delete files, the user with the API token must have Delete Record user rights.
