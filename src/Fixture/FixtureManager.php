<?php namespace Sintattica\Atk\Fixture;
/**
 * This file is part of the ATK distribution on GitHub.
 * Detailed copyright and licensing information can be found
 * in the doc/COPYRIGHT and doc/LICENSE files which should be
 * included in the distribution.
 *
 * @package atk
 * @subpackage fixture
 *
 * @copyright (c)2007 Ibuildings.nl BV
 * @license http://www.achievo.org/atk/licensing ATK Open Source License
 *
 * @version $Revision: 6309 $
 * $Id$
 */

/**
 * ATK fixture manager.
 *
 * This class can be used to load fixture data for (for example) test-cases
 * into a database instance.
 *
 * @author Peter C. Verhage <peter@ibuildings.nl>
 * @package atk
 * @subpackage fixture
 */
class FixtureManager
{
    /**
     * Loader class names by extension.
     *
     * @var array
     */
    var $m_loadersByExtension = array();

    /**
     * Loader instances (by class name)
     *
     * @var array
     */
    var $m_loaders = array();

    /**
     * Returns the only atkFixtureManager instance.
     *
     * @return atkFixtureManager
     */
    function &getInstance()
    {
        static $instance = null;
        if ($instance == null) {
            $instance = new FixtureManager();
        }
        return $instance;
    }

    /**
     * Register fixture loader.
     *
     * @param string $class ATK class path
     * @param mixed $extensions array of extension names (without dot) this loader supports
     */
    function registerLoader($class, $extensions)
    {
        foreach ($extensions as $extension) {
            $this->m_loadersByExtension[$extension] = $class;
        }
    }

    /**
     * Get loader instance (by extension).
     *
     * @param string $extension fixture filename extension
     * @return atkFixtureLoader fixture loader instance
     */
    function &getLoader($extension)
    {
        if (!isset($this->m_loadersByExtension[$extension])) {
            return null;
        }

        $class = $this->m_loadersByExtension[$extension];

        if (!isset($this->m_loaders[$class])) {
            $this->m_loaders[$class] = new $class();
        }

        return $this->m_loaders[$class];
    }

    /**
     * Get the fixture path for the given fixture (full-)name.
     *
     * @param string $fullname full fixture name
     * @param string $testCasePath test case path
     * @return string fixture path
     * @static
     */
    function getPath($fullname, $testCasePath = null)
    {
        $parts = explode('.', $fullname);

        $path = null;

        // beneath test-case directory
        if (count($parts) == 1 && !empty($testCasePath)) {
            $path = $testCasePath . 'fixtures/' . $fullname;
        } // beneath module directory
        else {
            if (
                count($parts) == 2 &&
                !is_dir(Config::getGlobal("atkroot") . $parts[0] . '/' . $parts[1]) &&
                Module::moduleExists($parts[0])
            ) {
                $module = Module::atkGetModule($parts[0]);
                $path = $module->getFixturePath($fullname);
            } // full path (without testcases/fixtures directory?)
            else {
                $tmpFullname = implode('.',
                        array_slice($parts, 0, count($parts) - 1)) . '.testcases.fixtures.' . $parts[count($parts) - 1];
                $path = Tools::getClassPath($tmpFullname, false);

                // full path
                if (count(glob("{$path}.*")) == 0) {
                    $path = Tools::getClassPath($fullname, false);
                }
            }
        }

        $files = glob("{$path}.*");

        return count($files) == 0 ? null : $files[0];
    }

    /**
     * Returns the fixture type for the given fixture path.
     *
     * @param string $path fixture path
     * @return string fixture type
     */
    function getType($path)
    {
        return substr($path, strrpos($path, '.') + 1);
    }

    /**
     * Returns the fixture table for the given full fixture name.
     *
     * @param string $fullname full fixture name
     * @return string table name
     */
    function getTable($fullname)
    {
        return end(explode('.', $fullname));
    }

    /**
     * Load data for the given fixture into the test database.
     *
     * @param string $fullname full fixture name
     * @param Db $database database instance
     * @param string $searchPath search path (for fixtures without path/module specification)
     *
     * @return mixed if successful an array with fixture table and data else false
     *
     * @access public
     */
    function load($fullname, &$database, $searchPath = null)
    {
        // get the fixture path
        $path = $this->getPath($fullname, $searchPath);
        if ($path == null) {
            Tools::atkdebug("No fixture data file found for fixture '{$fullname}'!");
            return false;
        }

        // determine fixture type based on the file extension
        $type = $this->getType($path);

        // based on the type get the loader instance
        $loader = $this->getLoader($type);
        if ($loader == null) {
            Tools::atkdebug("Don't know how to load fixture data of type '{$type}' for fixture '{$fullname}'!");
            return false;
        }

        // load the fixture data
        $data = $loader->load($path);

        // check if the fixture's table exists
        $table = $this->getTable($fullname);
        if (!$database->tableExists($table)) {
            Tools::atkdebug("Table '{$table}' not found in database for fixture '$fullname'!");
            return false;
        }

        // finally save the fixture data in the database
        if (!$this->save($database, $table, $data)) {
            Tools::atkdebug("Could not save (all) fixture data for fixture '$fullname'!");
            return false;
        }

        // also return the fixture table and data to the caller
        return array('table' => $table, 'data' => $data);
    }

    /**
     * Save fixture data to the database in the given table.
     *
     * @param Db $database database instance
     * @param string $table table name
     * @param array $data fixture data
     *
     * @access private
     */
    function save(&$database, $table, $data)
    {
        $savepoint = get_class($this);
        $database->savepoint($savepoint);

        // save data
        foreach ($data as $item => $record) {
            // insert data into database
            $query = &$database->createQuery();
            $query->addTable($table);

            foreach ($record as $field => $value) {
                if ($value === null) {
                    $query->addField($field, 'null', '', '', false);
                } else {
                    $query->addField($field, $value);
                }
            }

            if (!$query->executeInsert()) {
                Tools::atkerror("Could not insert fixture '{$item}' into table {$table}");
                Tools::atk_var_dump($record, "Invalid fixture");
                $database->rollback($savepoint);
                return false;
            }
        }

        // set sequence value(s)
        $metadata = $database->metadata($table);
        foreach ($metadata as $field) {
            if (Tools::hasFlag($field['flags'],
                    MF_AUTO_INCREMENT) && strtolower(@$field['table_type']) != 'innodb'
            ) {
                $sequence = isset($field['sequence']) ? $field['sequence'] : Config::getGlobal("database_sequenceprefix") . $table;
                $query = "SELECT MAX(" . $field['name'] . ") AS value FROM $table";
                list($result) = $database->getRows($query);
                $database->setSequenceValue($sequence, $result['value']);
            }
        }

        return true;
    }

}

// register ATK default loaders
$manager = FixtureManager::getInstance();
$manager->registerLoader('atk.fixture.atkyamlfixtureloader', array('yml', 'yaml'));
$manager->registerLoader('atk.fixture.atkphpfixtureloader', array('php'));
