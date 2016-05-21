# Moodle master-slave replication support (for mysql&mariadb)
Extended copy of native mysqli (and mariadb) class that provide master-slave replication support (by substitute mysqli object).

Required Moodle version 3 (and above).

## License
[GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html)
## Install
1. Put files to the **/lib/dml** directory;
2. Set **$CFG->dbtype** (in config.php) to 'mysqli_ms' or 'mariadb_ms' (without quotes);
3. Slaves configurations may be placed in **$CFG->dbslaves** array (of slave config arrays);

Part of your confing file have to be like this:
```php
$CFG->dbtype    = 'mysqli_ms'; // or 'mariadb_ms'
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
    array(
        'dbhost' => 'slave.hostname',
        'dbname' => 'dbname',
        'dbuser' => 'root',
        'dbpass' => 'password',
        'dboptions' => array(
            'dbpersist' => 0,
            'dbport' => '3306',
            'dbsocket' => '',
        ),
    ),
);
```

## Explain & Usage
All queries in original **mysqli_native_moodle_database** (and in **mariadb_ms_native_moodle_database**) are starts with:
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

Based on this information, we automatically substitute necessary (master or slave) mysqli connection in the `$mysqli` property, no additional actions needed:
```php
public function __get($name)
    {
        if ($this->active && 'mysqli' === $name) {
            if ($this->transaction || !$this->enable_slaves) {
                return $this->get_master();
            } elseif ($this->only_slave) {
                return $this->get_slave(false);
            }

            switch ($this->query_type) {
                case SQL_QUERY_SELECT:
                case SQL_QUERY_AUX:
                    return $this->get_slave();
                    break;
                case SQL_QUERY_INSERT:
                case SQL_QUERY_UPDATE:
                case SQL_QUERY_STRUCTURE:
                    return $this->get_master();
                    break;
                default:
                    return $this->get_master();
            }
        }

        trigger_error('Undefined property: ' . __CLASS__ . '::' . $name, E_USER_NOTICE);
        return null;
    }
```

Just use `$DB` as you use it every time.
Queries SQL_QUERY_AUX and SQL_QUERY_SELECT will be processed by slave, all another by master.
If you start transcation — all queries will be processed by master untill you end it by commit or rollback.

If you want force using the master connection to perform DB operations even if they are read queries, use:
 ```php
global $DB;

$result = $DB->use_master(function ($db) {
    return $db->get_records_sql('SELECT * FROM user LIMIT 1');
});
```

If you want force using the slave connection to perform DB operations even if they are write queries, use:
 ```php
global $DB;

$result = $DB->use_slave(function ($db) {
    return $db->get_records_sql('SELECT * FROM user LIMIT 1');
});
```

You also can disable slaves by:
```php
$DB->enable_slaves(false);
```
And enable it again:
```php
$DB->enable_slaves();
```

If you want to use only slaves (turn off master), just call:
```php
$DB->only_slave();
```
To turn on master again:
```php
$DB->only_slave(false);
```
