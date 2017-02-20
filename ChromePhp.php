<?php
/**
 * Copyright 2010-2013 Craig Campbell, 2016 Erik Krause
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Server Side Chrome PHP debugger class
 *
 * @package ChromePhp
 * @author Craig Campbell <iamcraigcampbell@gmail.com>
 * @author Erik Krause <erik.krause@gmx.de>
 */
class ChromePhp
{
    /**
     * @var string (ek)
     */
    const VERSION = '4.3.0';

    /**
     * @var string
     */
    const HEADER_NAME = 'X-ChromeLogger-Data';

    /**
     * @var string
     */
    const BACKTRACE_LEVEL = 'backtrace_level';

    /**
     * @var string (ek)
     */
    const LOG_STYLE = 'log_style';

    /**
     * @var string
     */
    const BASE_PATH = 'base_path';

    /**
     * @var string
     */
    const LOG_TYPE_LOG = 'log';

    /**
     * @var string
     */
    const LOG_TYPE_WARN = 'warn';

    /**
     * @var string
     */
    const LOG_TYPE_ERROR = 'error';

    /**
     * @var string
     */
    const LOG_TYPE_GROUP = 'group';

    /**
     * @var string
     */
    const LOG_TYPE_INFO = 'info';

    /**
     * @var string
     */
    const LOG_TYPE_GROUP_END = 'groupEnd';

    /**
     * @var string
     */
    const LOG_TYPE_GROUP_COLLAPSED = 'groupCollapsed';

    /**
     * @var string
     */
    const LOG_TYPE_TABLE = 'table';

    /**
     * @var string
     */
    protected $_php_version;

    /**
     * @var int
     */
    protected $_timestamp;

    /**
     * @var array
     */
    protected $_json = array(
        'version' => self::VERSION,
        'columns' => array('log', 'backtrace', 'type'),
        'rows' => array()
    );

    /**
     * @var array
     */
    protected $_backtraces = array();

    /**
     * @var bool
     */
    protected $_error_triggered = false;

    /**
     * @var array
     */
    protected $_settings = array(
        self::BACKTRACE_LEVEL => 1
    );

    /**
     * @var ChromePhp
     */
    protected static $_instance;

    /**
     * Prevent recursion when working with objects referring to each other
     *
     * @var array
     */
    protected $_processed = array();

    /**
     * provide enabled / disabled state
     *
     * @var bool
     */
    protected $_enabled = true;

    /**
     * constructor
     */
    private function __construct()
    {
        $this->_php_version = phpversion();
        $this->_timestamp = $this->_php_version >= 5.1 ? $_SERVER['REQUEST_TIME'] : time();
        $this->_json['request_uri'] = $_SERVER['REQUEST_URI'];
    }

    /**
     * Enable and disable logging (ek)
     *
     * @param boolean $Enabled TRUE to enable, FALSE to disable
     * @param string  $style 'FirePHP' to switch to FirePHP behaviour
     * @return void
     */
    public function setEnabled($Enabled, $style = '')
    {
       $this->_enabled = $Enabled;
       if ($style)
         $this->addSetting(self::LOG_STYLE, $style);
    }

    /**
     * Check if logging is enabled
     *
     * @return boolean TRUE if enabled
     */
    public function getEnabled()
    {
        return $this->_enabled;
    }


    /**
     * gets instance of this class
     *
     * @return ChromePhp
     */
    public static function getInstance()
    {
        if (self::$_instance === null) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Invoked when calling a static method that does not exist.
     *
     * @param  string $name The name of the called method.
     * @param  array  $args The aguments passed.
     * @return ChromePhp    The instance of ChromePhp (for method chaining)
     */
    public static function __callStatic($name, $args)
    {
        $const = 'self::LOG_TYPE_' . self::_fromCamelCase($name);

        if (defined($const))
        {
            return self::_log(constant($const), $args);
        }
        else
        {
            return self::getInstance();
        }
    }

    /**
     * Invoked when calling a method that does not exist.
     *
     * @param  string $name The name of the called method.
     * @param  array  $args The aguments passed.
     * @return ChromePhp    The instance of ChromePhp (for method chaining)
     */
    public function __call($name, $args)
    {
        return forward_static_call_array(array(self, $name), $args);
    }

    /**
     * internal logging call
     *
     * @param string $type
     * @return ChromePhp
     */
    protected static function _log($type, array $args)
    {
        $logger = self::getInstance();

        // nothing passed in, don't do anything
        if (empty($args) && $type != self::LOG_TYPE_GROUP_END) {
            return $logger;
        }




        // not enabled, don't do anything (ek)
        if (!($logger->_enabled))
            return;

        $logger->_processed = array();

        // FirePHP passes the object name second but displays it first (ek)
        if (($logger->getSetting(self::LOG_STYLE) == 'FirePHP') && (count($args) == 2)) {
            $args = array_reverse($args);
            $args[0] .= ":";
        }


        $logs = array_map(array($logger, '_convert'), $args);

        $backtrace = debug_backtrace(false);
        $level = $logger->getSetting(self::BACKTRACE_LEVEL);
        $basepath = $logger->getSetting(self::BASE_PATH);

        $backtrace_message = 'unknown';
        if (isset($backtrace[$level]['file'], $backtrace[$level]['line'])) {
            $file = $backtrace[$level]['file'];

            if ($basepath && strpos($file, $basepath) === 0) {
                $file = substr($file, strlen($basepath));
            }

            $backtrace_message = $file . ' : ' . $backtrace[$level]['line'];
        }

        $logger->_addRow($logs, $backtrace_message, $type);

        return $logger;
    }

    /**
     * converts an object to a better format for logging
     *
     * @param Object
     * @return array
     */
    protected function _convert($object)
    {
        // if this isn't an object then just return it
        if (!is_object($object)) {
            return $object;
        }

        //Mark this object as processed so we don't convert it twice and it
        //Also avoid recursion when objects refer to each other
        $this->_processed[] = $object;

        $object_as_array = array();

        // first add the class name
        $object_as_array['___class_name'] = get_class($object);

        // loop through object vars
        $object_vars = get_object_vars($object);
        foreach ($object_vars as $key => $value) {

            // same instance as parent object
            if ($value === $object || in_array($value, $this->_processed, true)) {
                $value = 'recursion - parent object [' . get_class($value) . ']';
            }
            $object_as_array[$key] = $this->_convert($value);
        }

        $reflection = new ReflectionClass($object);

        // loop through the properties and add those
        foreach ($reflection->getProperties() as $property) {

            // if one of these properties was already added above then ignore it
            if (array_key_exists($property->getName(), $object_vars)) {
                continue;
            }
            $type = $this->_getPropertyKey($property);

            if ($this->_php_version >= 5.3) {
                $property->setAccessible(true);
            }

            try {
                $value = $property->getValue($object);
            } catch (ReflectionException $e) {
                $value = 'only PHP 5.3 can access private/protected properties';
            }

            // same instance as parent object
            if ($value === $object || in_array($value, $this->_processed, true)) {
                $value = 'recursion - parent object [' . get_class($value) . ']';
            }

            $object_as_array[$type] = $this->_convert($value);
        }
        return $object_as_array;
    }

    /**
     * takes a reflection property and returns a nicely formatted key of the property name
     *
     * @param ReflectionProperty
     * @return string
     */
    protected function _getPropertyKey(ReflectionProperty $property)
    {
        $static = $property->isStatic() ? ' static' : '';
        if ($property->isPublic()) {
            return 'public' . $static . ' ' . $property->getName();
        }

        if ($property->isProtected()) {
            return 'protected' . $static . ' ' . $property->getName();
        }

        if ($property->isPrivate()) {
            return 'private' . $static . ' ' . $property->getName();
        }
    }

    /**
     * adds a value to the data array
     *
     * @var mixed
     * @return void
     */
    protected function _addRow(array $logs, $backtrace, $type)
    {
        // if this is logged on the same line for example in a loop, set it to null to save space
        if (in_array($backtrace, $this->_backtraces)) {
            $backtrace = null;
        }

        // for group, groupEnd, and groupCollapsed
        // take out the backtrace since it is not useful
        if (in_array($type, array(self::LOG_TYPE_GROUP, self::LOG_TYPE_GROUP_END, self::LOG_TYPE_GROUP_COLLAPSED))) {
            $backtrace = null;
        }

        if ($backtrace !== null) {
            $this->_backtraces[] = $backtrace;
        }

        $row = array($logs, $backtrace, $type);

        $this->_json['rows'][] = $row;
        $this->_writeHeader($this->_json);
    }

    protected function _writeHeader($data)
    {
        $encodedData = $this->_encode($data);
        if ($encodedData)
          header(self::HEADER_NAME . ': ' . $encodedData);
    }



    /**
     * recursively converts data for json_encode (ek)
     *
     * @param mixed ref $dat
     */

    protected static function _filterArray(&$dat)
    {
        if (is_resource($dat))
            $dat = print_r($dat, true).", ".get_resource_type($dat);
        else
            $dat = print_r($dat, true); // also fixes issue #35
    }

    /**
     * encodes the data to be sent along with the request
     *
     * @param array $data
     * @return string
     */

    protected function _encode($data)
    {

        array_walk_recursive($data, 'self::_filterArray'); //(ek)

        return base64_encode(utf8_encode(json_encode($data)));
    }

    /**
     * Converts a string from CamelCase to uppercase underscore
     * Based on: http://stackoverflow.com/questions/1993721/how-to-convert-camelcase-to-camel-case#1993772
     *
     * @param  string $input A string in CamelCase
     * @return string
     */
    public static function _fromCamelCase($input) {
        preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
        return implode('_', array_map('strtoupper', $matches[0]));
    }

    /**
     * adds a setting
     *
     * @param string key
     * @param mixed value
     * @return void
     */
    public function addSetting($key, $value)
    {
        $this->_settings[$key] = $value;
    }

    /**
     * add ability to set multiple settings in one call
     *
     * @param array $settings
     * @return void
     */
    public function addSettings(array $settings)
    {
        foreach ($settings as $key => $value) {
            $this->addSetting($key, $value);
        }
    }

    /**
     * gets a setting
     *
     * @param string key
     * @return mixed
     */
    public function getSetting($key)
    {
        if (!isset($this->_settings[$key])) {
            return null;
        }
        return $this->_settings[$key];
    }
}

