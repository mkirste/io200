<?php
error_reporting(0); // E_ERROR
ini_set('display_errors', false);
ini_set('max_execution_time', 120); // 120 seconds
define('CMS_TABLES', ['cms_articles', 'cms_articles_categories', 'cms_articles_tags', 'cms_categories', 'cms_collections', 'cms_collections_coverphotos', 'cms_collections_photos', 'cms_comments', 'cms_links', 'cms_pages', 'cms_photos', 'cms_photos_categories', 'cms_photos_tags', 'cms_tags']);
define('ENDPOINT_URL', 'https://www.service.io200.com/api/v1/');
define('DOWNLOAD_URL', 'https://www.io200.com/storage/downloads/');


/*
Copyright (c) Michael Kirste, https://www.io200.com/terms

The frontend system and all associated themes and templates (the “Software”) is licensed under the following conditions:

You are permitted to:
 - Edit, alter, modify, adapt, translate or otherwise change the whole or any part of the Software.
 - Use the software to publish your website and related content engaging in personal, commercial, non-commercial or non-profit activity.

You are not permitted to:
 - Reproduce, copy, distribute, transfer, license or sublicense the whole or any part of the Software.
 - Sell, resell, rent, lease or assign the whole or any part of the Software.
 - Remove, alter or obscure any proprietary notice.
 - Use the Software in any way which breaches any applicable local, national or international law
 - Use the whole or any part of the software after the termination of this contract.

The software may contain subprojects for which the respective own license terms apply. 

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL WE OR ANY COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/


//#### REDIRECT ########################################################
function connection_has_ssl() {
	if (isset($_SERVER['HTTPS'])) {
		if (strtolower($_SERVER['HTTPS']) == "on" || $_SERVER['HTTPS'] == "1") {
			return true;
		}
	} elseif (isset($_SERVER['SERVER_PORT'])) {
		if ($_SERVER['SERVER_PORT'] == "443") {
			return true;
		}
	}
	return false;
}
function domain_has_ssl_certificate($domain) {
    $ssl_check = @fsockopen( 'ssl://' . $domain, 443, $errno, $errstr, 30 );
    $res = !! $ssl_check;
    if ($ssl_check) { fclose( $ssl_check ); }
    return $res;
}
if (!isset($_GET) || empty($_GET)){
	if(connection_has_ssl() === false){
		if(domain_has_ssl_certificate($_SERVER['HTTP_HOST']) === true){
			header("Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']. "?ssl");			
		}		
	}
}

//#### POLYFILL ########################################################
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return \strncmp($haystack, $needle, \strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || $needle === \substr($haystack, -\strlen($needle));
    }
}
if (!function_exists('array_key_first')) {
    function array_key_first(array $array) {
        foreach ($array as $key => $value) {
            return $key;
        }
        return null;
    }
}
if (!function_exists('array_key_last')) {
    function array_key_last($array) {
        if (!is_array($array) || empty($array)) {
            return null;
        }
        return array_keys($array)[count($array) - 1];
    }
}

//#### CLASSES ########################################################
class DatabaseConnection {
    private $_connection = null;
    private $_status = null; // true or ErrorInfo

    public function __construct($db_hostname, $db_username, $db_password, $db_database, $db_port = null, $db_socket = null) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $errorlevel = error_reporting();
        error_reporting(0);
        if ($db_port === null && $db_socket === null) {
            $this->_connection = new mysqli($db_hostname, $db_username, $db_password, $db_database);
        } else {
            $this->_connection = new mysqli($db_hostname, $db_username, $db_password, $db_database, $db_port !== null ? $db_port : ini_get("mysqli.default_port"), $db_socket !== null ? $db_socket : ini_get("mysqli.default_socket"));
        }
        error_reporting($errorlevel);

        if ($this->_connection->connect_error === null) {
            $this->_connection->set_charset('utf8');
            $this->_status = true;
        } else {
            $error_message = $this->_connection->connect_error;
            $error_number = $this->_connection->connect_errno;
            $this->_status = new ErrorInfo('database_connection', $error_message, $error_number);
        }
    }

    public function __destruct() {
        $this->CLOSE();
    }

    public function STATUS() { // returns true or ErrorInfo
        return $this->_status;
    }

    public function CLOSE() {
        if ($this->_status === true) {
            $this->_connection->close();
        }
    }


    // ### QUERY Functions ########################################
    public function QUERY($query) { // returns true or mysqli_result object otherwise ErrorInfo
        // can only excecute one statement (otherwise no statement is excecuted)
        if ($this->_status === true) {
            $query_result = $this->_connection->query($query);
            if ($query_result !== false) {
                return $query_result;
            } else {
                $error_message = $this->_connection->error;
                $error_number = $this->_connection->errno;
                return new ErrorInfo('database_query', $error_message, $error_number);
            }
        } else {
            return $this->_status;
        }
    }

    public function TRANSACTION($QUERIES, $rollback = true) { // returns array with true or mysqli_result object otherwise ErrorInfo for each query
        // multiple queries as transaction (rolls back if one query fails)
        // rollback does not work with non transactional table types (like MyISAM or ISAM)
        if ($this->_status === true) {
            $status = true;

            $QUERIES_RESULT = [];
            $this->_connection->begin_transaction();
            foreach ($QUERIES as $query) {
                $query_result = $this->QUERY($query);
                array_push($QUERIES_RESULT, $query_result);
                if (ErrorInfo::isError($query_result)) {
                    $status = false;
                }
            }

            if ($status === true || $rollback === false) {
                $this->_connection->commit();
            } else {
                $this->_connection->rollback();
            }

            return $QUERIES_RESULT;
        } else {
            return $this->_status;
        }
    }

    public function MULTIQUERY($multiquery) {
        // excecutes all statements until first failed
        if ($this->_status === true) {
            if ($this->_connection->multi_query($multiquery)) {
                $RESULT = [];
                do {
                    $query_result = $this->_connection->store_result();
                    if ($this->_connection->errno === 0) {
                        if ($query_result) {
                            array_push($RESULT, $this->RESULT2ARRAY($query_result));
                            $query_result->free();
                        } else {
                            array_push($RESULT, null); // query didn't return a result (e.g. INSERT)
                        }
                    } else {
                        array_push($RESULT, new ErrorInfo('database_query', $this->_connection->error, $this->_connection->errno));
                    }
                } while ($this->_connection->next_result());
            } else {
                return [new ErrorInfo('database_query', $this->_connection->error, $this->_connection->errno)];
            }
        } else {
            return $this->_status;
        }
    }

    public function QUERY2ARRAY($query, $option_single = false) {
        return $this->RESULT2ARRAY($this->QUERY($query), $option_single);
    }


    // ### CRUD Functions ########################################
    public function SELECT($table, $SELECT, $condition = null, $ordering = null, $limit = null, $offset = null, $option_single = false) {  // returns result; otherwise ErrorInfo
        //-> multiple: SELECT($table, $SELECT, $condition);
        //-> single: SELECT($table, $SELECT, $condition, null, 1, null, true);
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        foreach ($SELECT as &$val) {
            $val = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $val)) . '`';
        }
        $SELECT = implode(', ', $SELECT);
        $where = $this->GetWhere($condition);
        if ($ordering !== Null) {
            $order = ' ORDER BY ' . $ordering;
        } else {
            $order = '';
        }
        if ($limit !== Null) {
            $limit = ' LIMIT ' . intval($limit);
        } else {
            $limit = '';
        }
        if (($limit !== Null) and ($offset !== Null)) {
            $offset = ' OFFSET ' . intval($offset);
        } else {
            $offset = '';
        }

        $RESULT = $this->QUERY('SELECT ' . $SELECT . ' FROM ' . $table . $where . $order . $limit . $offset);
        return $this->RESULT2ARRAY($RESULT, $option_single);
    }

    public function GET($table, $field, $condition) { // returns result; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = ' WHERE ' . $condition;

        $RESULT = $this->QUERY('SELECT ' . $field . ' as fieldvalue FROM ' . $table . $where . ' LIMIT 1');
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        }
        if ($RESULT->num_rows === 0) {
            return new ErrorInfo('database_nomatch', "No item found");
        }

        $datatype = self::GetType($RESULT->fetch_field()->type);
        $fieldvalue = $RESULT->fetch_object()->fieldvalue;
        settype($fieldvalue, $datatype);

        return $fieldvalue;
    }

    public function ADD($table, $ADD = null) { // returns insertid as int; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';

        if ($ADD !== Null) {
            $FIELDS = array();
            $VALUES = array();
            foreach ($ADD as $key => $val) {
                array_push($FIELDS, '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $key)) . '`');
                array_push($VALUES, $this->ValueToEscapedString($val));
            }
            $VALUES = '(' . implode(', ', $FIELDS) . ') VALUES (' . implode(', ', $VALUES) . ')';
        } else {
            $VALUES = '() VALUES ()';
        }

        $RESULT = $this->QUERY('INSERT INTO ' . $table . ' ' . $VALUES);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $newid = $this->_connection->insert_id;
            return intval($newid);
        }
    }

    public function UPDATE($table, $UPDATE, $condition = null) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $SET = array();
        foreach ($UPDATE as $key => $val) {
            array_push($SET, '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $key)) . '`=' . $this->ValueToEscapedString($val));
        }
        $SET = implode(', ', $SET);
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $SET . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function SET($table, $field, $value, $condition = null) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        return $this->UPDATE($table, array($field => $value), $condition);
    }

    public function INCREASE($table, $field, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $field . '=' . $field . '+1' . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function DECREASE($table, $field, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $field = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $field)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('UPDATE ' . $table . ' SET ' . $field . '=' . $field . '-1' . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function DELETE($table, $condition) { // returns number affectedrows as int/string (can be zero if no changes in records); otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('DELETE FROM ' . $table . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            return $this->_connection->affected_rows;
        }
    }

    public function COUNT($table, $condition = null) { // returns count as integer; otherwise ErrorInfo
        $table = '`' . $this->ESCAPESTRING(preg_replace('/[^\w]/', '', $table)) . '`';
        $where = $this->GetWhere($condition);

        $RESULT = $this->QUERY('SELECT COUNT(*) as count FROM ' . $table . $where);
        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $count = $RESULT->fetch_object()->count;
            return intval($count);
        }
    }


    // ### Helper Functions ########################################     
    public function ESCAPESTRING($value) {
        if ($this->_status === true) {
            return $this->_connection->real_escape_string($value);
        } else {
            return null;
        }
    }

    public function ValueToEscapedString($val) {
        switch (true) {
            case ($val === null):
                return 'null';
                break;
            case is_bool($val):
                return $val ? '1' : '0';
                break;
            case is_int($val):
                return $this->ESCAPESTRING($val);
                break;
            case is_string($val):
                return '\'' . $this->ESCAPESTRING($val) . '\'';
                break;
            default:
                return '\'' . $this->ESCAPESTRING($val) . '\'';
        }
    }

    private function RESULT2ARRAY($RESULT, $option_single = false) { // processes mysqli_result and returns result; otherwise ErrorInfo
        // tinyint is always interpreted as boolean (0 => false; 1 => true)

        // columns/rows*    raw data                    single=false                single=true
        // 1/0              ()			                ()			                null
        // 1/1              ((a=1))			            (1)			                1
        // 1/2              ((a=1), (a=2))		        (1, 2)			            (1, 2)
        // 2/0              ()		                    ()		                    ()
        // 2/1              ((a=1, b=11))		        ((a=1, b=11))		        (a=1, b=11)
        // 2/2              ((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))	((a=1, b=11), (a=2, b=22))
        // *(fields/items)

        if (ErrorInfo::isError($RESULT)) {
            return $RESULT;
        } else {
            $tableset = null;

            if ($RESULT->num_rows > 0) {
                // get data types
                $datatypes = array();
                foreach ($RESULT->fetch_fields() as $field) {
                    $datatypes[$field->name] = self::GetType($field->type);
                }

                // cast data
                if (method_exists($this, 'fetch_all')) {
                    $tableset = $RESULT->fetch_all(MYSQLI_ASSOC);
                } else {
                    $tableset = [];
                    while ($row = $RESULT->fetch_assoc()) {
                        $tableset[] = $row;
                    }
                }
                foreach ($tableset as &$row) {
                    foreach ($row as $colkey => &$colval) {
                        if ($colval !== null) {
                            settype($row[$colkey], $datatypes[$colkey]);
                        }
                    }
                }
                if ($RESULT->field_count === 1) {
                    $tableset = array_map('current', $tableset);
                }

                //consider option_single
                if ($option_single == true) {
                    if ($RESULT->num_rows === 1) {
                        $tableset = current($tableset);
                    }
                }
            } else {
                if ($RESULT->field_count === 1 && $option_single == true) {
                    $tableset = null;
                } else {
                    $tableset = array();
                }
            }

            return $tableset;
        }
    }

    private static function GetWhere($condition) {
        if ($condition !== Null) {
            return ' WHERE ' . $condition;
        } else {
            return '';
        }
    }

    private static function GetType($field_type) {
        $result = null;

        switch ($field_type) {
            case MYSQLI_TYPE_NULL:
                $result = 'null';
                break;
            case MYSQLI_TYPE_BIT:
                $result = 'boolean';
                break;
            case MYSQLI_TYPE_TINY:
            case MYSQLI_TYPE_SHORT:
            case MYSQLI_TYPE_LONG:
            case MYSQLI_TYPE_INT24:
            case MYSQLI_TYPE_LONGLONG:
                $result = 'int';
                break;
            case MYSQLI_TYPE_FLOAT:
            case MYSQLI_TYPE_DOUBLE:
            case MYSQLI_TYPE_DECIMAL:
            case MYSQLI_TYPE_NEWDECIMAL:
                $result = 'float';
                break;
            default:
                $result = 'string';
                break;
        }
        if ($field_type === MYSQLI_TYPE_TINY) {
            $result = 'boolean';
        }

        return $result;
    }

    public static function AddQueryLimiter($limit = null, $start = null) {
        $limiter = '';
        if ($limit !== null) {
            $limiter = ' LIMIT ' . intval($limit);
        }
        if (($limit !== null) and ($start !== null)) {
            $limiter = ' LIMIT ' . intval($start) . ',' . intval($limit);
        }
        return $limiter;
    }
}
class ErrorInfo {
    public $type; //string
    public $message; //string or array
    public $data;

    public function __construct($type, $message = null, $data = null) {
        $this->type = $type;
        $this->message = $message;
        $this->data = $data;
    }

    public function toArray() {
        return ['type' => $this->type, 'message' => $this->message, 'data' => $this->data];
    }

    public function getErrorMessage() {
        $result = $this->type;

        if ($this->message !== null) {
            if (is_array($this->message)) {
                $result = "";
                foreach ($this->message as $key => $value) {
                    if (is_array($value)) {
                        if (array_key_exists('msg', $value)) {
                            $result .= $value['msg'];
                        }
                    } elseif (is_string($value)) {
                        $result .= $value;
                    }
                }
            } elseif (is_string($this->message)) {
                $result = $this->message;
            }
        }

        return $result;
    }

    public static function isError($variable) {
        return $variable instanceof ErrorInfo;
    }
}

//#### FUNCTIONS ########################################################
function xcopy($src, $dest) {
    foreach (scandir($src) as $object) {
        if (!in_array($object, ['.', '..'])) {
            if (is_dir($src . '/' . $object)) {
                if(!is_dir($dest . '/' . $object)){mkdir($dest . '/' . $object);}
                xcopy($src . '/' . $object, $dest . '/' . $object);
            } else {
                copy($src . '/' . $object, $dest . '/' . $object);
            }
        }
    }
}
function rrmdir($dir) {
    if (is_dir($dir)) {
        foreach (scandir($dir) as $object) {
            if (!in_array($object, ['.', '..'])) {
                if (filetype($dir . '/' . $object) === 'dir') {
                    rrmdir($dir . '/' . $object);
                } else {
                    unlink($dir . '/' . $object);
                }
            }
        }
        rmdir($dir);
    }
}

//#######################################################################
//#### SCRIPT ###########################################################
//#######################################################################
function getScriptBaseURL() {
    return str_replace('/install.php', '', (empty($_SERVER['HTTPS']) ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
}
function getPHPVersion() {
    return PHP_VERSION;
}
function checkPHPVersion() {
    return version_compare(PHP_VERSION, '7.3') >= 0;
}
function printImageProcessingLibrarys() {
    if (checkImageProcessing() === false) {
        $class_error = 'error';
    } else {
        $class_error = '';
    }

    $librarys = [];
    if (function_exists('gd_info')) {
        $class = checkImageProcessingGD() ? 'success' : $class_error;
        $version = '';
        $formats = [];
        if (function_exists('gd_info')) {
            $version = gd_info()['GD Version'];
            if (gd_info()['JPEG Support']) {
                array_push($formats, 'JPEG');
            }
            if (gd_info()['WebP Support']) {
                array_push($formats, 'WebP');
            }
        }

        array_push($librarys, '<span class="textsmall ' . $class . '"><b>GD ' . $version . '</b> ' . (count($formats) > 0 ? '(' . implode(', ', $formats) . ')' : '') . '</span>');
    }
    if (class_exists("Imagick")) {
        $class = checkImageProcessingImagick() ? 'success' : $class_error;
        $formats = [];
        if (class_exists('Imagick')) {
            $version = str_replace('ImageMagick ', '', Imagick::getVersion()['versionString']);
            $version = explode(' ', $version)[0];
            if (count(Imagick::queryformats('JPEG')) > 0) {
                array_push($formats, 'JPEG');
            }
            if (count(Imagick::queryformats('WEBP')) > 0) {
                array_push($formats, 'WebP');
            }
        }
        array_push($librarys, '<span class="textsmall ' . $class . '"><b>ImageMagick ' . $version . '</b> ' . (count($formats) > 0 ? '(' . implode(', ', $formats) . ')' : '') . '</span>');
    }

    if (count($librarys) > 0) {
        $result = '<br/> ' . implode(',<br/> ', $librarys);
    } else {
        $result = '<b class="textsmall ' . $class_error . '">none</b>';
    }
    return $result;
}
function checkImageProcessing() {
    return checkImageProcessingGD() || checkImageProcessingImagick();
}
function checkImageProcessingGD() {
    return function_exists('gd_info') && gd_info()['JPEG Support'] && gd_info()['WebP Support'];
}
function checkImageProcessingImagick() {
    return class_exists('Imagick') && count(Imagick::queryformats('JPEG')) > 0 && count(Imagick::queryformats('WEBP')) > 0;
}
function checkInstallFolder() {
    return count(array_diff(scandir(__DIR__), ['.', '..', 'install.php', 'dist.zip', '_koken'])) === 0;
}
function checkInstallFile() {
    return file_exists(__DIR__ . "/dist.zip");
}
/*Installation*/
function InstallCheck($DATA) {
    $DatabaseConnection = new DatabaseConnection($DATA['databasesettings']['db_hostname'], $DATA['databasesettings']['db_username'], $DATA['databasesettings']['db_password'], $DATA['databasesettings']['db_database']);

	// Database
    if (ErrorInfo::isError($DatabaseConnection->STATUS())) {
        return new ErrorInfo('', 'No database connection!');
    } else {
        $required_cms_tables = CMS_TABLES;
        $available_cms_tables = [];
        foreach ($required_cms_tables as $table) {
            if (ErrorInfo::isError($DatabaseConnection->QUERY("DESCRIBE `{$table}`")) === false) {
                array_push($available_cms_tables, $table);
            }
        }
        if (count($available_cms_tables) > 0) {
            return new ErrorInfo('', "CMS tables already existing (<i>" . implode(', ', $available_cms_tables) . "</i>).<br/><b>Please delete the tables before continuing (press F5)!</b>");
        }
    }

	// Files	
	$test_file = fopen(__DIR__ . '/test.json', 'w');
	if($test_file === false){
		return new ErrorInfo('', "Cannot open files. Try to assign install.php file more access permissions (CHMOD) or contact us!");
	}
    $result = fwrite($test_file, json_encode(['test' => 'test']));
	if($result === false){
		return new ErrorInfo('', "Cannot write files. Try to assign install.php file more access permissions (CHMOD) or contact us!");
	}else{
		fclose($test_file);
	}
	if (file_exists(__DIR__ . '/test.json')) {
		$result = unlink(__DIR__ . '/test.json');
		if($result === false){
			return new ErrorInfo('', "Cannot delete files. Try to assign install.php file more access permissions (CHMOD) or contact us!");
		}
	}
	
    return true;
}
function InstallSystem($DATA) {
    // download dist.zip
    if (!file_exists(__DIR__ . '/dist.zip')) {
        $fh = fopen(__DIR__ . '/dist.zip', 'w');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=UTF-8'));
        curl_setopt($ch, CURLOPT_URL, ENDPOINT_URL . 'download:distribution?install');
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);

        if ($response_code === 200) {
            clearstatcache();
            if (!filesize(__DIR__ . "/dist.zip")) {
                if (file_exists(__DIR__ . '/dist.zip')) {
                    unlink(__DIR__ . '/dist.zip');
                }
                return new ErrorInfo('', 'System install error (cannot download dist.zip)!');
            }
        } else {
			if (file_exists(__DIR__ . '/dist.zip')) {
				unlink(__DIR__ . "/dist.zip");
			}
            return new ErrorInfo('', 'System install error (cannot download dist.zip)!');
        }
    }

    // extract files
    if (file_exists(__DIR__ . '/dist.zip')) {
        $zip = new ZipArchive;
        if ($zip->open(__DIR__ . '/dist.zip') === true) {
            $zip->extractTo(__DIR__);
            $zip->close();

            xcopy(__DIR__ . '/system-distribution', __DIR__);
            rrmdir(__DIR__ . '/system-distribution');
            unlink(__DIR__ . '/dist.zip');
        } else {
            return new ErrorInfo('', 'System install error (cannot extract dist.zip)!');
        }
    } else {
        return new ErrorInfo('', 'System install error (missing dist.zip)!');
    }

    // database
    if (file_exists(__DIR__ . '/storage/temp/cms_db_schema.sql')) {
        $DatabaseConnection = new DatabaseConnection($DATA['databasesettings']['db_hostname'], $DATA['databasesettings']['db_username'], $DATA['databasesettings']['db_password'], $DATA['databasesettings']['db_database']);
        $DatabaseConnection->MULTIQUERY(file_get_contents(__DIR__ . '/storage/temp/cms_db_schema.sql'));
        unlink(__DIR__ . '/storage/temp/cms_db_schema.sql');
        if ($DATA['_migratekoken'] === false) {
            unlink(__DIR__ . '/storage/temp/cms_koken_migration.sql');
        }
        return true;
    } else {
        return new ErrorInfo('system_error', 'System install error (database)!');
    }
}
function ConfigurateSystem($DATA) {
    // /storage/system/config.php
    if (file_exists(__DIR__ . '/storage/system/config.php')) {
        $new_config = file_get_contents(__DIR__ . '/storage/system/config.php');
        $new_config = str_replace("define('CMS_DB_HOSTNAME', '???');", "define('CMS_DB_HOSTNAME', '" . $DATA['databasesettings']['db_hostname'] . "');", $new_config);
        $new_config = str_replace("define('CMS_DB_USERNAME', '???');", "define('CMS_DB_USERNAME', '" . $DATA['databasesettings']['db_username'] . "');", $new_config);
        $new_config = str_replace("define('CMS_DB_PASSWORD', '???');", "define('CMS_DB_PASSWORD', '" . $DATA['databasesettings']['db_password'] . "');", $new_config);
        $new_config = str_replace("define('CMS_DB_DATABASE', '???');", "define('CMS_DB_DATABASE', '" . $DATA['databasesettings']['db_database'] . "');", $new_config);
        $new_config = str_replace("define('CMS_SECRETKEY', '???');", "define('CMS_SECRETKEY', '" . substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*(){}[],./?', ceil(32 / strlen($x)))), 1, 32) . "');", $new_config);
        $new_config = str_replace("define('WEBSITE_SECRETKEY', '???');", "define('WEBSITE_SECRETKEY', '" . substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*(){}[],./?', ceil(16 / strlen($x)))), 1, 16) . "');", $new_config);
        $new_config = str_replace("define('WEBSITE_URL', '???');", "define('WEBSITE_URL', '" . $DATA['websitesettings']['url'] . "');", $new_config);

        $config_file = fopen(__DIR__ . '/storage/system/config.php', 'w');
        $result = fwrite($config_file, $new_config);
        fclose($config_file);
        if ($result === false) {
            return new ErrorInfo('', 'Theme configuration error (no permissions to write config.php)!');
        }
    } else {
        return new ErrorInfo('', 'Theme configuration error (missing config.php)!');
    }

    // /storage/system/service.json
    $SERVICE = [];
    $SERVICE['service_id'] = null;
    $SERVICE['endpoint_url'] = ENDPOINT_URL;
    $service_file = fopen(__DIR__ . '/storage/system/service.json', 'w');
    $result = fwrite($service_file, json_encode($SERVICE));
    fclose($service_file);

    // /storage/system/user.json
    $USER = [];
    $USER['mail'] = $DATA['adminsettings']['mail'];
    $USER['passwordhash'] = password_hash($DATA['adminsettings']['password'], PASSWORD_DEFAULT);
    $USER['locked'] = false;
    $USER['resetpasswordhash'] = null;
    $USER['autologinhash'] = null;
    $USER['numberauthenticationattempts'] = 0;
    $USER['login_on'] = null;
    $user_file = fopen(__DIR__ . "/storage/system/user.json", 'w');
    $result = fwrite($user_file, json_encode($USER));
    fclose($user_file);

    // /storage/system/sitesettings.json
    $SITESETTINGS = [];
    $SITESETTINGS['WEBSITE_TITLE'] = $DATA['websitesettings']['title'];
    $SITESETTINGS['WEBSITE_MAIL'] =  $DATA['adminsettings']['mail'];
    $SITESETTINGS['WEBSITE_THEMENAME'] = $DATA['websitesettings']['theme'];
    $settings_file = fopen(__DIR__ . "/storage/system/sitesettings.json", 'w');
    $result = fwrite($settings_file, json_encode($SITESETTINGS));
    fclose($settings_file);

    // .htaccess
    $url_parts = parse_url($DATA['websitesettings']['url']);
    if (array_key_exists('path', $url_parts)) {
        $basepath = $url_parts['path'];
    } else {
        $basepath = '';
    }
    if ($basepath !== '') {
        if (file_exists(__DIR__ . '/.htaccess')) {
            $new_htaccess = file_get_contents(__DIR__ . '/.htaccess');
            $new_htaccess = str_replace("RewriteBase /", "RewriteBase {$basepath}/", $new_htaccess);

            $htaccess_file = fopen(__DIR__ . '/.htaccess', 'w');
            fwrite($htaccess_file, $new_htaccess);
            fclose($htaccess_file);
        } else {
            return new ErrorInfo('', 'Theme configuration error!');
        }
    }
	
	// /storage/system/lang.php
	if($DATA['websitesettings']['lang'] !== 'en'){
		$lang_downloadfile = 'lang-' . $DATA['websitesettings']['lang'] .'.zip';
		
		// download
		$fh = fopen(__DIR__ . '/lang.zip', 'w');
        $ch = curl_init();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=UTF-8'));
        curl_setopt($ch, CURLOPT_URL, DOWNLOAD_URL . $lang_downloadfile);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);
        if ($response_code === 200) {
            clearstatcache();
		}
		if (!filesize(__DIR__ . '/lang.zip')) {
			if (file_exists(__DIR__ . '/lang.zip')) {
				unlink(__DIR__ . '/lang.zip');
			}
		}		 

		// extract
		if (file_exists(__DIR__ . '/lang.zip')) {
			$zip = new ZipArchive;
			if ($zip->open(__DIR__ . '/lang.zip') === true) {
				$zip->extractTo(__DIR__);
				$zip->close();
				copy(__DIR__ . '/lang.php', __DIR__ . '/storage/system/lang.php');
				unlink(__DIR__ . '/lang.php');
				unlink(__DIR__ . '/lang.zip');
			}
		}
	}
	

    return true;
}
/*Migration*/
function DetectKokenInstallation($koken_directory) {
    // check folders/files
    $required_files = ['/admin', '/app', '/storage', '/storage/originals', '/storage/configuration', '/storage/configuration/database.php'];
    foreach ($required_files as $file) {
        if (!file_exists($koken_directory . $file)) {
            return new ErrorInfo('', 'Missing Koken ' . (str_contains($file, '.') ? 'file' : 'directory') . ' ("' . $koken_directory . $file . '")!');
        }
    }

    // check database connection and koken tables
    $KOKEN_DB_SETTINGS = GetKokenDatabaseSettings($koken_directory);
    if (ErrorInfo::isError($KOKEN_DB_SETTINGS)) {
        return $KOKEN_DB_SETTINGS;
    } else {
        $DatabaseConnection = new DatabaseConnection($KOKEN_DB_SETTINGS['hostname'], $KOKEN_DB_SETTINGS['username'], $KOKEN_DB_SETTINGS['password'], $KOKEN_DB_SETTINGS['database']);
        if (ErrorInfo::isError($DatabaseConnection->STATUS())) {
            return new ErrorInfo('', 'Error connecting to Koken database!<br/>Check your Koken database settings in <span style="word-break:break-all;">"' . $koken_directory . '/storage/configuration/database.php"</span>.<br/><br/><b>Error Number:</b> ' . $DatabaseConnection->STATUS()->data . '<br/><b>Error Message:</b> ' . $DatabaseConnection->STATUS()->message);
        } else {
            $required_koken_tables = ['text', 'join_categories_text', 'join_tags_text', 'categories', 'albums', 'join_albums_covers', 'join_albums_content', 'content', 'join_categories_content', 'join_content_tags', 'tags'];
            foreach ($required_koken_tables as $table) {
                if (ErrorInfo::isError($DatabaseConnection->QUERY("DESCRIBE `{$KOKEN_DB_SETTINGS['prefix']}{$table}`"))) {
                    return new ErrorInfo('cms_service_error', "Missing Koken table (\"{$KOKEN_DB_SETTINGS['prefix']}{$table}\")!");
                }
            }
        }
    }

    return true;
}
function GetKokenDatabaseSettings($koken_directory) { // returns ErrorInfo or array with 'driver', 'hostname', 'database', 'username', 'password', 'prefix', 'socket'
    $database_configuration_file = $koken_directory . '/storage/configuration/database.php';
    if (file_exists($database_configuration_file)) {
        require($database_configuration_file);
        if (!isset($KOKEN_DATABASE)) {
            $KOKEN_DATABASE = require($database_configuration_file);
        }
        return $KOKEN_DATABASE;
    } else {
        return new ErrorInfo('', 'Missing Koken database configuration file!');
    }
}
function MoveKokenToSubfolder($koken_directory) {
    if (!file_exists($koken_directory . '/_koken')) {
        mkdir($koken_directory . '/_koken');
    }
    foreach (scandir($koken_directory) as $object) {
        if (!in_array($object, ['.', '..', 'install.php', 'dist.zip', '_koken'])) {
            rename($koken_directory . '/' . $object, $koken_directory . '/_koken/' . $object);
        }
    }
}
function CopyKokenDatabase($KOKEN_DATABASE) {
    if (file_exists(__DIR__ . '/storage/temp/cms_koken_migration.sql')) {
        $script_file_content = file_get_contents(__DIR__ . '/storage/temp/cms_koken_migration.sql');
        $script_file_content = str_replace('koken_', $KOKEN_DATABASE['prefix'], $script_file_content);

        $DatabaseConnection = new DatabaseConnection($KOKEN_DATABASE['hostname'], $KOKEN_DATABASE['username'], $KOKEN_DATABASE['password'], $KOKEN_DATABASE['database']);
        $DatabaseConnection->MULTIQUERY($script_file_content);

        unlink(__DIR__ . '/storage/temp/cms_koken_migration.sql');

        return true;
    } else {
        return new ErrorInfo('system_error', 'Koken Database Migration Error');
    }
}
function CopyKokenOriginals($src, $dest) {
    //$src => koken_photo_directory, $dest => target_photo_directory

    foreach (scandir($src) as $object) {
        if (!in_array($object, ['.', '..'])) {
            if (is_dir($src . '/' . $object)) {
                if (!file_exists($dest . '/' . $object)) {
                    mkdir($dest . '/' . $object);
                }
                CopyKokenOriginals($src . '/' . $object, $dest . '/' . $object);
            } else {
                if (substr_count($object, '.') === 1) {
                    if (!file_exists($dest . '/' . mb_strtolower($object))) {
                        copy($src . '/' . $object, $dest . '/' . mb_strtolower($object));
                    }
                }
            }
        }
    }
}
function CopyKokenCustom($src, $dest) {
    xcopy($src, $dest);
}
function FixNestedCollectionStructure($KOKEN_DATABASE) {
    $DatabaseConnection = new DatabaseConnection($KOKEN_DATABASE['hostname'], $KOKEN_DATABASE['username'], $KOKEN_DATABASE['password'], $KOKEN_DATABASE['database']);

    $OBJECTS = $DatabaseConnection->SELECT('cms_collections', array('id', 'level', 'left_id', 'right_id', 'type'), null, 'left_id ASC');
    if (ErrorInfo::isError($OBJECTS)) {
        return $OBJECTS;
    }
    // Load and fix nested collection structure
    $structure_check = true;
    $top_level = 1;
    $RELATIONS = array();
    if (!empty($OBJECTS)) {
        foreach ($OBJECTS as $OBJECT) {
            // find parent
            $PARENT = &$RELATIONS;
            for ($l = $top_level; $l < $OBJECT['level']; $l++) {
                if (!is_array($PARENT)) {
                    break;
                } // corrupted structure
                $PARENT = &$PARENT[array_key_last($PARENT)];
            }
            if (!is_array($PARENT)) {
                $structure_check = false; // corrupted structure
                array_unshift($RELATIONS, $OBJECT['id']);
            } else {
                // add set/album
                if ($OBJECT['type'] === 2) { //set
                    array_push($PARENT, array($OBJECT['id']));
                } else { //album
                    array_push($PARENT, $OBJECT['id']);
                }
            }
        }
    }

    // Fix nested collection structure
    if ($structure_check === false) {
        $sql_fix = 'UPDATE `cms_collections` SET `level`=1;
        UPDATE `cms_collections` SET `total_count`=0 WHERE `type`=2;
        SET @i:=-1;
        SET @j:=0;
        UPDATE `cms_collections` SET `left_id`= @i:=(@i+2), `right_id`= @j:=(@j+2);';
        $DatabaseConnection->MULTIQUERY($sql_fix);
    }
    return true;
}
function FixOriginalPhotos($listener_url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    curl_setopt($ch, CURLOPT_URL, $listener_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);

    return true;
}



//#### START ########################################################
$DATA = [];
$DATA['_step'] = null;
$DATA['_migratekoken'] = null;
$DATA['systemcheck'] = null;
$DATA['databasesettings']  = null;
$DATA['adminsettings'] = null;
$DATA['websitesettings'] = null;
$DATA['install'] = null;
if (isset($_POST['data_transfer'])) { // load passed data
    $data_json = json_decode($_POST['data_transfer'], true);
    if ($data_json !== null) {
        foreach ($data_json as $key => $value) {
            if (is_array($value) && array_key_exists('type', $value)  && array_key_exists('message', $value) && array_key_exists('data', $value)) {
                $DATA[$key] = new ErrorInfo($value['type'], $value['message'], $value['data']);
            } else {
                $DATA[$key] = $value;
            }
        }
    }
}

$DATA['_step'] = 0;
if (!($DATA['systemcheck'] === null || ErrorInfo::isError($DATA['systemcheck']))) {
    $DATA['_step'] = 1;
    if (!($DATA['databasesettings'] === null || ErrorInfo::isError($DATA['databasesettings']))) {
        $DATA['_step'] = 2;
        if (!($DATA['adminsettings'] === null || ErrorInfo::isError($DATA['adminsettings']))) {
            $DATA['_step'] = 3;
            if (!($DATA['websitesettings'] === null || ErrorInfo::isError($DATA['websitesettings']))) {
                $DATA['_step'] = 4;
            }
        }
    }
}
if (isset($_GET['migratekoken'])) {
    $DATA['_step'] = -1;
}

$output = null;

//#### Step 1 - Check System ########################################################
if ($DATA['_step'] + 1 === 1) {
    $koken_installation_deteted = DetectKokenInstallation(__DIR__);

    $message_systemcheck = '';
    $message_systemcheck_system = '';

    $CHECK['system'] = true;
    if (checkPHPVersion() === false) { // check php version
        $CHECK['system'] = new ErrorInfo('', 'IO200 supports only PHP Version >= 7.3 (PHP Version ' . PHP_VERSION . ' detected)');
    }
    if (checkImageProcessing() === false) { // check image processing
        $CHECK['system'] = new ErrorInfo('', 'IO200 requires image processing (GD or ImageMagick library with JPEG and WebP support).');
    }
    if (ErrorInfo::isError($CHECK['system'])) {
        $message_systemcheck_system = '<p class="message error"><b>System check failed!</b><br/>' . $CHECK['system']->message . '</p>';
    } else {
        $message_systemcheck_system = '<p class="message success"><b>System check passed!</b></p>';
    }

    $CHECK['folder'] = false;
    if (isset($_GET['systemcheck'])) {
        $DATA['_migratekoken'] = (isset($_POST['migratekoken']) &&  $_POST['migratekoken'] === 'migratekoken');
        //if (checkInstallFile() === false) { // check install file
        //    $message_systemcheck = '<p class="message error">Install distribution file (dist.zip) missing in folder "<i>' . __DIR__ . '/</i>"! Please upload the provided dist.zip file.</p>';
        //} else {
        if ($DATA['_migratekoken'] === false && !checkInstallFolder()) {
            $message_systemcheck = '<p class="message error">Installation folder "<i>' . __DIR__ . '</i>" is not empty! Please remove all files and folders in the installation folder (except install.php).</p>';
        } else {
            $message_systemcheck = '<p class="message success"><b>All checks passed!</b></p>';
            $CHECK['folder'] = true;
            $DATA['systemcheck'] = true;
            $DATA['_step']++;
        }
        //}
    }

    $message_kokenmigrationcheck = '';
    if (isset($_GET['checkkokenmigration'])) {
        if ($koken_installation_deteted !== true) {
            $message_kokenmigrationcheck = '<p class="message error">' . $koken_installation_deteted->message . '</p>';
        }
    }

    $output = '
<form action="' . getScriptBaseURL() . '/install.php?systemcheck" method="post">
<fieldset>
    <h2>System Check</h2>
    <p>PHP Version: <b class=' . (checkPHPVersion() === false ? 'error' : 'success') . '>' . getPHPVersion() . '</b></p>
    <p>Image Processing: ' . printImageProcessingLibrarys() . '</p>
    <p class="checkbox' . ($koken_installation_deteted !== true ? ' checkbox-disabled' : '') . '">
        <input type="checkbox" name="migratekoken" id="migratekoken" value="migratekoken"' . ($koken_installation_deteted !== true ? ' disabled' : '') . '/>
        <label class="optionlabel" for="migratekoken">Migrate Koken installation' . ($koken_installation_deteted !== true ? ' [<a href="' . getScriptBaseURL() . '/install.php?checkkokenmigration">check</a>]' : '') . '</label>
    </p>
</fieldset>
' . $message_systemcheck_system . '
<p>
    <input type="hidden" name="data_transfer" value="' . htmlspecialchars(json_encode($DATA)) . '">
    <input type="submit" value="Continue"' . ($CHECK['system'] !== true ? ' disabled' : '') . '/>
</p>
' . $message_systemcheck . '
' . $message_kokenmigrationcheck . '
</form>
<p>
    <span class="textsmall">By clicking "Continue", you agree to our<br> <a href="https://www.io200.com/privacypolicy" target="_blank">Privacy Policy</a> and <a href="https://www.io200.com/terms" target="_blank">Terms and Conditions</a>.</span>
</p>';
}

//#### Step 2 - Database Settings ########################################################
if ($DATA['_step'] + 1 === 2) {
    if ($DATA['_migratekoken'] === true) {
        $KOKEN_DB_SETTINGS = GetKokenDatabaseSettings(__DIR__);
        $NEW_DB_SETTINGS = [];
        $NEW_DB_SETTINGS['db_hostname'] = $KOKEN_DB_SETTINGS['hostname'];
        $NEW_DB_SETTINGS['db_username']  = $KOKEN_DB_SETTINGS['username'];
        $NEW_DB_SETTINGS['db_password']  = $KOKEN_DB_SETTINGS['password'];
        $NEW_DB_SETTINGS['db_database']  = $KOKEN_DB_SETTINGS['database'];
        $DATA['databasesettings'] = $NEW_DB_SETTINGS;
        $DATA['_step']++;
    }
}
if ($DATA['_step'] + 1 === 2) {
    $message_databasesettings = '';

    if (isset($_GET['databasesettings'])) {
        $NEW_DB_SETTINGS = [];
        $NEW_DB_SETTINGS['db_hostname'] = $_POST['db_hostname'];
        $NEW_DB_SETTINGS['db_username']  = $_POST['db_username'];
        $NEW_DB_SETTINGS['db_password']  = $_POST['db_password'];
        $NEW_DB_SETTINGS['db_database']  = $_POST['db_database'];

        $DatabaseConnection = new DatabaseConnection($NEW_DB_SETTINGS['db_hostname'], $NEW_DB_SETTINGS['db_username'], $NEW_DB_SETTINGS['db_password'], $NEW_DB_SETTINGS['db_database']);

        if (ErrorInfo::isError($DatabaseConnection->STATUS())) {
            $message_databasesettings = '<p class="message error">Wrong database credentials!</p>';
            switch (true) {
                case $DatabaseConnection->STATUS()->data === 1045:
                    $custom_message = "There was an problem connecting to the database, due to an invalid username/password!";
                    break;
                case $DatabaseConnection->STATUS()->data === 2002:
                    $custom_message = "There was an problem connecting to the database, due to an invalid hostname!";
                    break;
                default:
                    $custom_message = "Wrong database credentials!";
                    break;
            }
            $message_databasesettings = '<p class="message error">' . $custom_message . '<br><i class="textsmall">' . $DatabaseConnection->STATUS()->message . ' (Error Code #' . $DatabaseConnection->STATUS()->data . ')</i></p>';
            $DATA['databasesettings'] = null;
        } else {
            $message_databasesettings = '<p class="message success">Database connected!<p>';
            $DATA['databasesettings'] = $NEW_DB_SETTINGS;
            $DATA['_step']++;
        }
    }

    $output = '
<form action="' . getScriptBaseURL() . '/install.php?databasesettings" method="post">
<fieldset>
    <h2>Database Connection</h2>
    <p>
        <label class="visible" for="db_hostname">Host<br><span class="textsmaller">(hostname, servername, or IP address of your database)</span></label>
        <input name="db_hostname" type="text" placeholder="" value="' . (isset($_POST['db_hostname']) ? $_POST['db_hostname'] : '') . '"/>
    </p>
    <p>
        <label class="visible" for="db_username">Username</label>
        <input name="db_username" type="text" placeholder="" value="' . (isset($_POST['db_username']) ? $_POST['db_username'] : '') . '"/>
    </p>
    <p>
        <label class="visible" for="db_password">Password</label>
        <input name="db_password" type="password" placeholder="" autocomplete="new-password" value="' . (isset($_POST['db_password']) ? $_POST['db_password'] : '') . '"/>
    </p>
    <p>
        <label class="visible" for="db_database">Database</label>
        <input name="db_database" type="text" placeholder="" value="' . (isset($_POST['db_database']) ? $_POST['db_database'] : '') . '"/>
    </p>
</fieldset>
<p>
    <input type="hidden" name="data_transfer" value="' . htmlspecialchars(json_encode($DATA)) . '">
    <input type="submit" value="Continue" />
</p>
' . $message_databasesettings . '
</form>
<p>
    <span class="textsmall">Usually, you can manage existing databases and create new ones in the administration interface of your web hosting provider.</span>
</p>';
}

//#### Step 3 - Admin Settings ########################################################
if ($DATA['_step'] + 1 === 3) {
    $message_adminsettings = '';

    if (isset($_GET['adminsettings'])) {
        $NEW_ADMIN_SETTINGS = [];
        $NEW_ADMIN_SETTINGS['mail']  = $_POST['admin_mail'];
        $NEW_ADMIN_SETTINGS['password']  = $_POST['admin_password'];
        $NEW_ADMIN_SETTINGS['passwordrepetition']  = $_POST['admin_passwordrepetition'];

        if (
            empty($NEW_ADMIN_SETTINGS['mail']) ||
            !preg_match('/^[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+(?:\.[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+)*\@[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+(?:\.[^\x00-\x20()<>@,;:\\".[\]\x7f-\xff]+)+$/i', $NEW_ADMIN_SETTINGS['mail'])
        ) {
            $message_adminsettings = '<p class="message error">Please enter a valid email address!</p>';
        }
        if (
            empty($NEW_ADMIN_SETTINGS['password']) ||
            strlen($NEW_ADMIN_SETTINGS['password']) < 6
        ) {
            $_POST['admin_password'] = "";
            if ($message_adminsettings === '') {
                $message_adminsettings = '<p class="message error">Password must be at least 6 characters long!</p>';
            }
        }
        if (
            empty($NEW_ADMIN_SETTINGS['passwordrepetition']) ||
            $NEW_ADMIN_SETTINGS['password'] !==  $NEW_ADMIN_SETTINGS['passwordrepetition']
        ) {
            if ($message_adminsettings === '') {
                $message_adminsettings = '<p class="message error">Passwords do not match!</p>';
            }
        }

        if ($message_adminsettings === '') {
            $message_adminsettings = '<p class="message success">Admin settings ok!<p>';
            $DATA['adminsettings'] = $NEW_ADMIN_SETTINGS;
            $DATA['_step']++;
        }
    }

    $output = '
<form action="' . getScriptBaseURL() . '/install.php?adminsettings" method="post">
<fieldset>
    <h2>Admin Settings</h2>
    <p>
        <label class="visible" for="admin_mail">Email</label>
        <input name="admin_mail" type="email" placeholder="" value="' . (isset($_POST['admin_mail']) ? $_POST['admin_mail'] : '') . '"/>
    </p> 
    <p>
        <label class="visible" for="admin_password">Password</label>
        <input name="admin_password" type="password" placeholder="" value="' . (isset($_POST['admin_password']) ? $_POST['admin_password'] : '') . '"/>
    </p>
    <p>
        <label class="visible" for="admin_passwordrepetition">Password Repetition</label>
        <input name="admin_passwordrepetition" type="password" placeholder="" value=""/>
    </p>
</fieldset>
<p>
    <input type="hidden" name="data_transfer" value="' . htmlspecialchars(json_encode($DATA)) . '">
    <input type="submit" value="Continue" />
</p>
' . $message_adminsettings . '
</form>';
}


//#### Step 4 - Website Settings ########################################################
if ($DATA['_step'] + 1 === 4) {
    $message_websitesettings = '';

    if (isset($_GET['websitesettings'])) {
        $NEW_WEBSITE_SETTINGS = [];
        $NEW_WEBSITE_SETTINGS['title'] = $_POST['website_title'];
        $NEW_WEBSITE_SETTINGS['theme'] = $_POST['website_theme'];
        $NEW_WEBSITE_SETTINGS['url'] = getScriptBaseURL();
        $NEW_WEBSITE_SETTINGS['lang'] = $_POST['website_lang'];

        if (empty($NEW_WEBSITE_SETTINGS['title'])) {
            $message_websitesettings = '<p class="message error">Please enter a website title!</p>';
        }

        if ($message_websitesettings === '') {
            $message_websitesettings = '<p class="message success">Website settings ok!<p>';
            $DATA['websitesettings'] = $NEW_WEBSITE_SETTINGS;
            $DATA['_step']++;
        }
    }

    $output = '
<form action="' . getScriptBaseURL() . '/install.php?websitesettings" method="post">
<fieldset>
    <h2>Website Settings</h2>
    <p>
        <label class="visible" for="website_title">Website Title</label>
        <input name="website_title" type="text" placeholder="" value="' . (isset($_POST['website_title']) ? $_POST['website_title'] : '') . '"/>
    </p>
    <p>
        <label class="visible" for="website_theme">Website Theme</label>
        <select name="website_theme">
            <option value="aspect"' . ((isset($_POST['website_theme']) && $_POST['website_theme'] === 'aspect') ? ' selected' : '') . '>Aspect</option>
            <option value="skyline"' . ((isset($_POST['website_theme']) && $_POST['website_theme'] === 'skyline') ? ' selected' : '') . '>Skyline</option>
            <option value="minimal"' . ((isset($_POST['website_theme']) && $_POST['website_theme'] === 'minimal') ? ' selected' : '') . '>Minimal</option>
            <option value="contrast"' . ((isset($_POST['website_theme']) && $_POST['website_theme'] === 'contrast') ? ' selected' : '') . '>Contrast</option>
            <option value="ratio"' . ((isset($_POST['website_theme']) && $_POST['website_theme'] === 'ratio') ? ' selected' : '') . '>Ratio</option>
            <option value="classic"' . ((isset($_POST['website_theme']) && $_POST['website_theme'] === 'classic') ? ' selected' : '') . '>Classic</option>
        </select>
    </p>
    <p>
        <label class="visible" for="website_lang">Website Language</label>
        <select name="website_lang">
            <option value="en"' . ((isset($_POST['website_lang']) && $_POST['website_lang'] === 'en') ? ' selected' : '') . '>English</option>
            <option value="de"' . ((isset($_POST['website_lang']) && $_POST['website_lang'] === 'de') ? ' selected' : '') . '>German</option>
        </select>
    </p>
</fieldset>
<p>
    <input type="hidden" name="data_transfer" value="' . htmlspecialchars(json_encode($DATA)) . '">
    <input type="submit" value="Start Installation" />
</p>
<p>

</p>
' . $message_websitesettings . '
</form>
<p>
    <span class="textsmall">After installation, you can completely adapt the language of your website by editing the language file ("/storage/system/lang.php").</span>
</p>';
}

//#### Step 5 - Install ########################################################
if ($DATA['_step'] + 1 === 5) {
    $message_install = '';

    $result = InstallCheck($DATA);
    if (ErrorInfo::isError($result) === false) {
        if ($DATA['_migratekoken'] === true) {
            MoveKokenToSubfolder(__DIR__);
        } else {
            echo "<div class=\"waitmessage\">Please wait, the installation may take some time!</div>";
            flush();
        }
        if (checkInstallFolder()) {
            $result = InstallSystem($DATA);
            if (ErrorInfo::isError($result) === false) {
                $result = ConfigurateSystem($DATA);
                if (ErrorInfo::isError($result) === false) {
                    $DATA['install'] = true;
                    $DATA['_step']++;
                    $message_install = '<p class="message success">Congratulations, your <a href="' . getScriptBaseURL() . '" target="_blank">new portfolio website</a> is online. Get started by uploading content and by setting-up your website\'s navigation menu in your Admin Panel (CMS) at <a href="' . getScriptBaseURL() . '/admin/login?mail=' . $DATA['adminsettings']['mail'] . '" target="_blank">' . getScriptBaseURL() . '/admin/</a>.</p>';
                }
            }
        } else {
            $message_install = '<p class="message error">Install folder must be empty except "install.php" and "/_koken"!</p>';
        }
    }
    if ($message_install === '') {
        $message_install = '<p class="message error">' . $result->message . '</p>';
    }

    $output = '
<form>
<h2>Installation</h2>
' . $message_install . '
</form>';
}

if ($DATA['_step'] + 1 === 6) {
    if ($DATA['_migratekoken'] === false) {
        unlink(__DIR__ . '/install.php');
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?migratekoken");
    }
}


//#### Migrate Koken ########################################################
if (isset($_GET['migratekoken'])) {
    // migrate tables
    $KOKEN_DB_SETTINGS = GetKokenDatabaseSettings(__DIR__ . '/_koken');
    CopyKokenDatabase($KOKEN_DB_SETTINGS);
    FixNestedCollectionStructure($KOKEN_DB_SETTINGS);
    header("Location: " . $_SERVER['PHP_SELF'] . "?migratekoken2");
}
if (isset($_GET['migratekoken2'])) {
    // migrate files
    echo "<div class=\"waitmessage\"><b>Please wait, the migration may take some time!</b><br/>Reload the site (press F5 or <a href=\"" . getScriptBaseURL() . "/install.php?migratekoken2\">click here</a>) to continue migration, if you get a \"maximum excecution time exceeded\" error! Most servers have a limited excecution time (usually up to 120 seconds). You may have to reload this script multiple times.</div>";
    flush();
    CopyKokenCustom(__DIR__ . '/_koken/storage/custom', __DIR__ . '/storage/custom');
    CopyKokenOriginals(__DIR__ . '/_koken/storage/originals', __DIR__ . '/storage/originals');
    FixOriginalPhotos(str_replace('install.php', 'listener/FixOriginalPhotos.php', (empty($_SERVER['HTTPS']) ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']));
    unlink(__DIR__ . '/install.php');
    $message_migratekoken = '<p class="message success">Congratulations, your Koken data has been migrated and your <a href="' . getScriptBaseURL() . '" target="_blank">new portfolio website</a> is online. Check if all your photos have been migrated using the <a href="' . getScriptBaseURL() . '/listener/FixOriginalPhotos.php" target="_blank">FixOriginalPhotos script</a>. Please recreate your website\'s navigation menu in your <a href="' . getScriptBaseURL() . '/admin/" target="_blank">Admin Panel (CMS)</a>.<br>Take a look at our <a href="https://www.io200.com/documentation#migration-koken" target="_blank">documentation</a>, if there are any problems with the migration (i.e. photos are not loading after logging in your admin panel).</p>';

    $output = '
<form>
<h2>Installation & Koken Migration</h2>
' . $message_migratekoken . '
</form>';
}
?>


<?php
//#### OUTPUT ########################################################
?>
<!DOCTYPE html>
<html lang="EN-en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <title>IO200 CMS - Installation</title>
    <meta name="description" content="" />
    <meta name="keywords" content="" />
    <meta name="robots" content="all" />
    <script>
        document.addEventListener("touchstart", function() {}, true);
    </script>
    <style>
    /*----------FONTS--------------------------------------------------------------------------*/
	@import url('https://fonts.googleapis.com/css2?family=Inter:wght@100;200;300;400;500;600;700;800;900&display=swap');

	/*----------RESET-------------------------------------------------------------------------*/
	html,body,div,span,applet,object,iframe,h1,h2,h3,h4,h5,h6,p,blockquote,pre,a,abbr,acronym,address,big,cite,code,del,dfn,em,img,ins,kbd,q,s,samp,small,strike,strong,sub,sup,tt,var,b,u,i,center,dl,dt,dd,ol,ul,li,fieldset,form,label,legend,table,caption,tbody,tfoot,thead,tr,th,td,article,aside,canvas,details,embed,figure,figcaption,footer,header,hgroup,menu,nav,output,ruby,section,summary,time,mark,audio,video {margin:0;padding:0;border:0;font-size:100%;font:inherit;vertical-align:baseline;}
	body {line-height:normal;} ol,ul {list-style:none;} table {border-collapse:collapse;border-spacing:0;} a{text-decoration:none;color:inherit;} img{display:block;} :focus{outline:0;}
	/*----------BASE--------------------------------------------------------------------------*/
	/*elements:h1,h2,h3,h4,h5,h6*/
	h2{font-size:1.8em;font-weight:300;text-transform:uppercase;}
	h3{font-size:1.6em;font-weight:300;text-transform:uppercase;}
	h4{font-size:1.3em;font-weight:400;}
	h5{font-size:1.15em;font-weight:400;}
	h6{font-size:1.0em;font-weight:600;}
	/*elements:i,em,b,strong,strike*/
	i,em{font-style:italic;}
	b,strong{font-weight:600;}
	strike{text-decoration:line-through;}
	/*elements:p,blockquote*/
	p,blockquote{text-align:justify;}
	blockquote{font-style:italic;}
	p,blockquote{line-height:1.8em;}
	/*elements:ul,ol*/
	ul,ol{line-height:1.8em;}
	ul{list-style:square;}
	ol{list-style:decimal;}
	ul.unstyled,ol.unstyled{list-style:none;}
	/*elements:dl*/
	dl{width:100%;overflow:hidden;line-height:1.5em;}
	dl dt{display:inline-block;width:40%;}
	dl dd{display:inline-block;width:60%;}
	/*elements:table*/
	table caption{margin-bottom:0.25em;padding:0 1em;font-weight:600;white-space:nowrap;}
	table thead th{padding:0.8em 0.6em;font-size:0.98em;font-weight:600;}
	table tbody th,table tbody td{padding:0.6em 0.6em;font-size:0.96em;}
	table tfoot td{padding:0.6em;font-size:0.9em;font-style:italic;text-align:justify;max-width:0;}
	table tbody th,table tbody td{text-align:left;}
	table tbody th{font-weight:600;}
	table th,table td{vertical-align:middle;}
	div.tablewrapper{overflow-x:auto}
	/*elements:form*/
	form p {display:block;margin-bottom:1em;}
	form p:last-child {margin-bottom:0;}
	form label:not(.optionlabel){display:block;}
	form input:not([type="submit"]):not([type="reset"]),form select,form textarea{padding:0.6em;font-size:0.9em;box-sizing:border-box;}
	form textarea{width:100%;height:12em;}
	form input[type="submit"],form input[type="reset"]{font-size:0.95em;padding:0.75em 1em;border:0;}
	form input[type="submit"]:hover,form input[type="reset"]:hover{cursor:pointer;}
	form input[type="submit"]:disabled,form input[type="reset"]:disabled{cursor:initial;}
	/*elements:img,figure*/
	figure{text-align:center;}
	figure figcaption{font-size:0.95em;margin-top:0.25em;}


	/*#########################################################*/
	/*##########MAIN###########################################*/
	/*#########################################################*/
	/*----------DESKTOP----------------------------------------*/
	body {background:#f4f4f6;color:#242424;font-size:16px;font-family:"Inter",-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;}

	/*----------Layout----------*/
	div#container, header, main, footer, section{width:100%;text-align:center;}
	header{padding-top:2rem;padding-bottom:1rem;}
	main{padding:0;margin-top:3rem;}
	footer{padding:0.25em 0;}
	@media (max-width:900px){
	  main{margin-top:0;}
	}

	html, body {height:100%;}
	div#container{display:flex;flex-direction:column;height:100%;width:100%;}
	main {flex:auto;}footer {flex:none;}

	/*----------Header----------*/
	header h1{color:#636a78;font-size:2.1em;font-weight:200;text-transform:none;}
	/*----------Content----------*/
	main a{color:#246dff;}
	main a:hover{text-decoration:underline;}
	/*----------Footer----------*/
	footer nav ul{}
	footer nav ul li{font-size:0.85em;display:inline-block;padding:1em 0;}
	footer nav ul li a{color:#6c7180;opacity:0.9;}
	footer nav ul li a:hover{opacity:1;text-decoration:underline;}
	footer nav ul li:not(:last-child)::after{content:"•";color:#636a78;padding-left:0.5em;padding-right:0.5em;}


	/*#########################################################*/
	/*##########CLASSES########################################*/
	/*#########################################################*/
	.padding{padding:0.5em;}
	.marginbottom{margin-bottom:0.75em;}
	.textsmall{font-size:0.9em;}
	.textsmaller{font-size:0.8em;}

	/*section*/
	section{padding:2em 0;box-sizing:border-box;}
	section > p{font-size:0.9em;margin-top:0.4em;font-weight:300;text-align:center;}
	section form {width:16em;padding:1em;background:#fff;font-weight:300;display:inline-block;margin:0 auto;}
	section form h2{font-size:1.7em;font-weight:200;margin-top:0;margin-bottom:1em;}
	section form fieldset{margin-bottom:1em;}
	section form p{margin-bottom:0.5em;display:block;text-align:center;}
	section form label.hidden{display:none;}
	section form label.optionlabel{font-size:0.95em;}
	section form p.message{font-size:0.9em;width:100%;padding:0.4em;margin-top:0.75em;line-height:1.5em;display:inline-block;box-sizing:border-box;}
	section form p.checkbox-disabled{opacity:0.6;}
	section form input:not([type="submit"]):not([type="reset"]),.section form select,.section form textarea{text-align:center;color:#454545;font-weight:300;border:1px solid #eeeeee;}
	section form input:read-only:not([type="submit"]):not([type="reset"]){border:1px solid #fff;}
	section form input:not([type="checkbox"]){width:100%;padding:0.8em!important;}
	section form input[type="checkbox"]{position:relative;top:0.05em;}
	section form input[type="submit"]{font-size:1em;padding:0.8em!important;background:#262626;color:#fff;}
	section form input[type="submit"]:hover{background:#000;color:#fff;}
	section form input.hoverdanger[type="submit"]:hover{background:#cb0000;}
	section form input[type="submit"]:disabled{background:#d8d8d8;color:#fcfcfc;}
	section form .error{color:#cb0000;}
	section form .error a{color:#cb0000;text-decoration:underline;}
	section form .error a:hover{color:#9c0000;}
	section form .success{color:#009920;}
	section form .success a{color:#0a8924;text-decoration:underline;}
	section form .success a:hover{color:#002f0a;}
	section form .message.error{background-color:rgba(203, 0, 0, 0.2);}
	section form .message.success{background-color:rgba(0, 153, 32, 0.15);}
    </style>
    <style>
        section form {
            width: 24em;
        }

        section p span.textsmall {
            display: inline-block;
            line-height: 1.6em;
        }

        .waitmessage {
            display: none;
        }
    </style>
</head>

<body>
    <div id="container">
        <!--  Header -->
        <header>
            <h1>IO200 CMS - Installation</h1>
        </header>
        <!--  Content -->
        <main>
            <section>
                <?= $output ?>
            </section>
        </main>
        <!--  Footer -->
        <footer>
            <nav>
                <ul>
                    <li><a href="https://www.io200.com" title="IO200 Website" target="_blank" rel="noopener">IO200 Website</a></li>
                    <li><a href="https://www.io200.com/documentation" title="Documentation" target="_blank" rel="noopener">IO200 Documentation</a></li>
                </ul>
            </nav>
        </footer>
    </div>
</body>

</html>
