# marc_harvest
This is a customized Shrew (https://github.com/dswalker/shrew) install that harvests and creates marc record files from a Sierra database. The data is to be used for a discovery tool, and is designed to purge deleted and suppressed records from the data sets.

This setup uses a harvest.cfg configuration file to store last harvested dates and the Sierra connection information. Consider storage and permissions, and edit the incremental.php file appropriately if you are worried about the security of the configuration file.

Please visit https://github.com/dswalker/shrew for more on the original Shrew setup.


