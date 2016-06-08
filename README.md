# marc_harvest
This is a customized Shrew (https://github.com/dswalker/shrew) install that harvests and creates marc record files from a Sierra database. The data is to be used for a discovery tool, and is designed to purge deleted and suppressed records from the data sets.

This setup uses a harvest.cfg configuration file to store last harvested dates and the Sierra connection information. Consider storage and permissions, and edit the incremental.php file appropriately if you are worried about the security of the configuration file.

Newly added in June 2016 - the ability to output XML files instead of MARC for those non-standard marc records, such as eresource records in Sierra.
To use this feature make a call to export records such as 

`$sierra = new Sierra($sierra_info['host'], $sierra_info['user'], $sierra_info['password']);
`$sierra->exportRecords($location,'eresource','xml');	

where $location is a writeable path for the exported files, and the second option is the type of record (typicall 'bib' or 'authority', and now 'eresource')

You can then use some further stylesheet transformations to turn them into add documents for solr, or use elsewhere.

Please visit https://github.com/dswalker/shrew for more on the original Shrew setup.


