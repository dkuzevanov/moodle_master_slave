<?php

/**
 * Extended copy of native mysqli class (representing moodle database interface).
 * This class provide simple read/write splitting by substitute mysqli object.
 *
 * It contains parts from yii2\db\Connection.
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 *
 *
 * @package    core_dml
 * @copyright  2016 Dmitriy Kuzevanov
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/mysqli_native_moodle_database.php');

class mysqli_ms_native_moodle_database extends mysqli_native_moodle_database
{
    /**
     * @var mysqli the currently active master connection
     */
    private $_master;
    /**
     * @var mysqli the currently active slave connection
     */
    private $_slave = false;
    /**
     * @var cache the cache object or the ID of the cache application component that is used to store
     * the health status of the DB servers specified in [[masters]] and [[slaves]].
     */
    private $serverStatusCache;
    /**
     * @var bool whether is there currently active master connection
     */
    private $active = false;
    /**
     * @var bool whether is there currently active transaction
     */
    private $transaction = false;
    /**
     * @var boolean whether to enable read/write splitting by using [[slaves]] to read data.
     * Note that if [[slaves]] is empty, read/write splitting will NOT be enabled no matter what value this property takes.
     */
    protected $enableSlaves = true;
    /**
     * @var bool
     */
    protected $onlySlave = false;
    /**
     * @var int The database reads on slave (performance counter).
     */
    protected $readsOnSlave = 0;
    /**
     * @var integer the retry interval in seconds for dead servers listed in [[masters]] and [[slaves]].
     * This is used together with [[serverStatusCache]].
     */
    public $serverRetryInterval = 600;
    /**
     * @var integer Last query type.
     */
    public $queryType;

    /**
     * {@inheritdoc}
     */
    public function __construct($external)
    {
        $this->serverStatusCache = cache::make_from_params(cache_store::MODE_APPLICATION, __CLASS__, 'cache');
        parent::__construct($external);
    }

    /**
     * {@inheritdoc}
     */
    public function connect($dbhost, $dbuser, $dbpass, $dbname, $prefix, array $dboptions = null)
    {
        try {
            parent::connect($dbhost, $dbuser, $dbpass, $dbname, $prefix, $dboptions);
            $this->_master = $this->mysqli;
            unset($this->mysqli);
            $this->active = true;
        } catch (moodle_exception $e) {
            $this->processMoodleException($e);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     * @param mysqli $mysqli
     */
    public function get_server_info(mysqli $mysqli = null)
    {
        if ($mysqli) {
            return ['description' => $mysqli->server_info, 'version' => $mysqli->server_info];
        } else {
            return parent::get_server_info();
        }
    }

    /**
     * Connects to the database and return mysqli object.
     * @param string $dbhost The database host.
     * @param string $dbuser The database user to connect as.
     * @param string $dbpass The password to use when connecting to the database.
     * @param string $dbname The name of the database being connected to.
     * @param array $dboptions driver specific options
     * @return bool mysqli
     * @throws dml_exception if error
     */
    private function makeMysqli($dbhost, $dbuser, $dbpass, $dbname, array $dboptions = null)
    {
        $driverstatus = $this->driver_installed();

        if ($driverstatus !== true) {
            throw new dml_exception('dbdriverproblem', $driverstatus);
        }

        // dbsocket is used ONLY if host is NULL or 'localhost',
        // you can not disable it because it is always tried if dbhost is 'localhost'
        if (!empty($dboptions['dbsocket'])
            and (strpos($dboptions['dbsocket'], '/') !== false or strpos($dboptions['dbsocket'], '\\') !== false)
        ) {
            $dbsocket = $dboptions['dbsocket'];
        } else {
            $dbsocket = ini_get('mysqli.default_socket');
        }

        if (empty($dboptions['dbport'])) {
            $dbport = (int)ini_get('mysqli.default_port');
        } else {
            $dbport = (int)$dboptions['dbport'];
        }

        // verify ini.get does not return nonsense
        if (empty($dbport)) {
            $dbport = 3306;
        }

        if ($dbhost and !empty($dboptions['dbpersist'])) {
            $dbhost = "p:$dbhost";
        }

        $mysqli = @new mysqli($dbhost, $dbuser, $dbpass, $dbname, $dbport, $dbsocket);

        if ($mysqli->connect_errno !== 0) {
            $dberr = $mysqli->connect_error;
            throw new dml_connection_exception($dberr);
        }

        $mysqli->set_charset('utf8');

        // If available, enforce strict mode for the session. That guaranties
        // standard behaviour under some situations, avoiding some MySQL nasty
        // habits like truncating data or performing some transparent cast losses.
        // With strict mode enforced, Moodle DB layer will be consistently throwing
        // the corresponding exceptions as expected.
        $si = $this->get_server_info($mysqli);
        if (version_compare($si['version'], '5.0.2', '>=')) {
            $sql = "SET SESSION sql_mode = 'STRICT_ALL_TABLES'";
            //$this->query_start($sql, null, SQL_QUERY_AUX);
            $mysqli->query($sql);
            //$result = $mysqli->query($sql);
            //$this->query_end($result);
        }

        return $mysqli;
    }

    /**
     * Magic method to substitute [[mysqli]] (master/slave) based on [[last_type]]
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        if ($this->active && 'mysqli' === $name) {
            if (!$this->enableSlaves) {
                return $this->getMaster();
            } elseif ($this->onlySlave) {
                return $this->getSlave(false);
            }

            if ($this->transaction) {
                return $this->getMaster();
            }

            switch ($this->queryType) {
                case SQL_QUERY_SELECT:
                case SQL_QUERY_AUX:
                    $mysqli = $this->getSlave();

                    if ($this->_slave !== null) {
                        $this->readsOnSlave++;
                        if ($this->reads > 0) {
                            $this->reads--;
                        }
                    }

                    return $mysqli;
                    break;
                case SQL_QUERY_INSERT:
                case SQL_QUERY_UPDATE:
                case SQL_QUERY_STRUCTURE:
                    return $this->getMaster();
                    break;
                default:
                    return $this->getMaster();
            }
        }

        trigger_error('Undefined property: ' . __CLASS__ . '::' . $name, E_USER_NOTICE);
        return null;
    }

    public function query_start($sql, array $params = null, $type, $extrainfo = null)
    {
        $this->queryType = $type;

        parent::query_start($sql, $params, $type, $extrainfo);
    }

    public function query_end($result)
    {
        $this->queryType = null;

        parent::query_end($result);
    }

    /**
     * Method to process raised moodle_exception while connecting to MySQL server.
     * @param moodle_exception $exception
     */
    private function processMoodleException(moodle_exception $exception)
    {
        global $CFG;

        if (empty($CFG->noemailever) and !empty($CFG->emailconnectionerrorsto)) {
            $body = "Connection error: " . $CFG->wwwroot .
                "\n\nInfo:" .
                "\n\tError code: " . $exception->errorcode .
                "\n\tDebug info: " . $exception->debuginfo .
                "\n\tServer: " . $_SERVER['SERVER_NAME'] . " (" . $_SERVER['SERVER_ADDR'] . ")";
            if (file_exists($CFG->dataroot . '/emailcount')) {
                $fp = @fopen($CFG->dataroot . '/emailcount', 'r');
                $content = @fread($fp, 24);
                @fclose($fp);
                if ((time() - (int)$content) > 600) {
                    //email directly rather than using messaging
                    @mail($CFG->emailconnectionerrorsto,
                        'WARNING: Database connection error: ' . $CFG->wwwroot,
                        $body);
                    $fp = @fopen($CFG->dataroot . '/emailcount', 'w');
                    @fwrite($fp, time());
                }
            } else {
                //email directly rather than using messaging
                @mail($CFG->emailconnectionerrorsto,
                    'WARNING: Database connection error: ' . $CFG->wwwroot,
                    $body);
                $fp = @fopen($CFG->dataroot . '/emailcount', 'w');
                @fwrite($fp, time());
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function begin_transaction()
    {
        if ($this->transactions_supported()) {
            $this->transaction = true;
        }

        parent::begin_transaction();
    }

    /**
     * {@inheritdoc}
     */
    public function commit_transaction()
    {
        if ($this->transactions_supported()) {
            $this->transaction = false;
        }

        parent::commit_transaction();
    }

    /**
     * {@inheritdoc}
     */
    public function rollback_transaction()
    {
        if ($this->transactions_supported()) {
            $this->transaction = false;
        }

        parent::rollback_transaction();
    }

    /**
     * @param bool $enable
     */
    public function enableSlaves(bool $enable = true)
    {
        $this->enableSlaves = $enable;
        if (!$enable) {
            $this->onlySlave = false;
        }
    }

    /**
     * @param bool $enable
     */
    public function onlySlave(bool $enable = true)
    {
        $this->onlySlave = $enable;
    }

    /**
     * Returns the mysqli instance for the currently active master connection.
     * @return mysqli the mysqli instance for the currently active master connection.
     */
    public function getMaster()
    {
        return $this->_master;
    }

    /**
     * Returns the mysqli instance for the currently active slave connection.
     * When [[enableSlaves]] is true, one of the slaves will be used for read queries, and its mysqli instance
     * will be returned by this method.
     * @param boolean $fallbackToMaster whether to return a master mysqli in case none of the slave connections is available.
     * @return mysqli the mysqli instance for the currently active slave connection. Null is returned if no slave connection
     * is available and `$fallbackToMaster` is false.
     */
    public function getSlave($fallbackToMaster = true)
    {
        global $CFG;

        if (!$this->enableSlaves) {
            return $fallbackToMaster ? $this->_master : null;
        }

        if ($this->_slave === false) {
            $pool = isset($CFG->dbslaves) && is_array($CFG->dbslaves) ? $CFG->dbslaves : [];
            $this->_slave = $this->openFromPool($pool);
        }

        if ($this->_slave !== null) {
            return $this->_slave;
        } else {
            return $fallbackToMaster ? $this->_master : null;
        }
    }

    /**
     * Opens the connection to a server in the pool.
     * This method implements the load balancing among the given list of the servers.
     * @param array $pool the list of connection configurations in the server pool
     * @return mysqli|null
     * @throws moodle_exception
     */
    protected function openFromPool(array $pool)
    {
        if (empty($pool)) {
            return null;
        }

        $cache = $this->serverStatusCache;

        shuffle($pool);

        foreach ($pool as $config) {
            $config = (object)$config;
            $key = crc32(serialize($config));
            if ($cache instanceof cache && $timestamp = $cache->get($key)) {
                if ($timestamp > time()) {
                    // should not try this dead server now
                    continue;
                } else {
                    $cache->delete($key);
                }
            }

            try {
                $mysqli = $this->makeMysqli($config->dbhost, $config->dbuser, $config->dbpass, $config->dbname, $config->dboptions);
                return $mysqli;
            } catch (moodle_exception $e) {
                $this->processMoodleException($e);

                if ($cache instanceof cache) {
                    // mark this server as dead and only retry it after the specified interval
                    $cache->set($key, time() + $this->serverRetryInterval);
                }

            }
        }

        return null;
    }

    /**
     * Executes the provided callback by using the master connection.
     *
     * This method is provided so that you can temporarily force using the master connection to perform
     * DB operations even if they are read queries. For example,
     *
     * ```php
     * $result = $db->useMaster(function ($db) {
     *     return $db->get_records_sql('SELECT * FROM user LIMIT 1');
     * });
     * ```
     *
     * @param callable $callback
     * @return mixed
     */
    public function useMaster(callable $callback)
    {
        $enableSlaves = $this->enableSlaves;
        $this->enableSlaves(false);
        $result = call_user_func($callback, $this);
        $this->enableSlaves($enableSlaves);

        return $result;
    }

    /**
     * Executes the provided callback by using the slave connection.
     *
     * This method is provided so that you can temporarily force using the slave connection to perform
     * DB operations even if they are write queries. For example,
     *
     * ```php
     * $result = $db->useSlave(function ($db) {
     *     return $db->get_records_sql('SELECT * FROM user LIMIT 1');
     * });
     * ```
     *
     * @param callable $callback
     * @return mixed
     */
    public function useSlave(callable $callback)
    {
        $this->onlySlave();
        $result = call_user_func($callback, $this);
        $this->onlySlave(false);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function dispose()
    {
        moodle_database::dispose();
        if ($this->_master) {
            $this->_master->close();
            $this->_master = null;
        }

        if ($this->_slave) {
            $this->_slave->close();
            $this->_slave = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function perf_get_reads()
    {
        return $this->readsOnSlave + $this->reads;
    }

    /**
     * {@inheritdoc}
     */
    public function perf_get_queries() {
        return $this->writes + $this->perf_get_reads();
    }
}
