# moodle_mysqli_ms
Extended copy of native mysqli class that provide simple read/write splitting (by substitute mysqli object).

Required:
1. Moodle version 3 and above;
2. PHP 7.
## License
[GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html)
## Install
1. Put **mysqli_ms_native_moodle_database.php** to the **/lib/dml** directory;
2. Set **$CFG->dbtype** (in config.php) to 'mysqli_ms' (without quotes);
3. Slaves configurations may be placed in **$CFG->dbslaves** array (of slave config arrays);

Part of your confing file have to be like this:
```php
$CFG->dbtype    = 'mysqli_ms';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'master.hostname';
$CFG->dbname    = 'dbname';
$CFG->dbuser    = 'root';
$CFG->dbpass    = 'password';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
  'dbpersist' => 0,
  'dbport' => '3306',
  'dbsocket' => '',
);
$CFG->dbslaves = array(
    [
        'dbhost' => 'slave.hostname',
        'dbname' => 'dbname',
        'dbuser' => 'root',
        'dbpass' => 'password',
        'dboptions' => [
            'dbpersist' => 0,
            'dbport' => '3306',
            'dbsocket' => '',
        ],
    ],
    [...]
);
```

## Usage
All queries in original **mysqli_native_moodle_database** are starts with:
```php
$this->query_start($sql, $params, $type);
```
Where ```$type``` is:
```php
/** SQL_QUERY_SELECT - Normal select query, reading only. */
define('SQL_QUERY_SELECT', 1);

/** SQL_QUERY_INSERT - Insert select query, writing. */
define('SQL_QUERY_INSERT', 2);

/** SQL_QUERY_UPDATE - Update select query, writing. */
define('SQL_QUERY_UPDATE', 3);

/** SQL_QUERY_STRUCTURE - Query changing db structure, writing. */
define('SQL_QUERY_STRUCTURE', 4);

/** SQL_QUERY_AUX - Auxiliary query done by driver, setting connection config, getting table info, etc. */
define('SQL_QUERY_AUX', 5);
```

Based on this information, we substitute necessary (master or slave) mysqli connection in the `$mysqli` property.

If you want force using the master connection to perform DB operations even if they are read queries, use:
 ```php
$result = $db->useMaster(function ($db) {
    return $db->get_records_sql('SELECT * FROM user LIMIT 1');
});
```

If you want force using the slave connection to perform DB operations even if they are write queries, use:
 ```php
$result = $db->useSlave(function ($db) {
    return $db->get_records_sql('SELECT * FROM user LIMIT 1');
});
```
