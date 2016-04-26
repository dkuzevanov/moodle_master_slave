<?php

/**
 * Extended copy of native mysqli class for mariadb (representing moodle database interface).
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

require_once(__DIR__ . '/mysqli_ms_native_moodle_database.php');

class mariadb_ms_native_moodle_database extends mysqli_ms_native_moodle_database
{
    /**
     * {@inheritdoc}
     */
    public static function get_server_info_static(mysqli $mysqli)
    {
        $version = $mysqli->server_info;
        $matches = null;
        if (preg_match('/^5\.5\.5-(10\..+)-MariaDB/i', $version, $matches)) {
            // Looks like MariaDB decided to use these weird version numbers for better BC with MySQL...
            $version = $matches[1];
        }
        
        return array('description' => $mysqli->server_info, 'version' => $version);
    }

    /**
     * It is time to require transactions everywhere.
     *
     * MyISAM is NOT supported!
     *
     * @return bool
     */
    protected function transactions_supported()
    {
        if ($this->external) {
            return parent::transactions_supported();
        }
        return true;
    }
}
