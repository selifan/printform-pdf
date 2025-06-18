<?PHP
/**
* @name: dbaccess.pdo.php
* DB open select/modify/backup/restore wrapper class: CDbEngine
* @author: Alexander Selifonov <alex {at} selifan.ru>
* @link https://github.com/selifan
* PHP required : 7.0+
* @version: 1.23.007
* updated 2025-05-09
**/
if (!class_exists('CDbEngine')) {

define('DBTYPE_MYSQL','mysql');
define('DBTYPE_PGSQL','pgsql'); # PostgreSQL, not supported yet in many functions!

if(!defined('DB_DEFAULTCHARSET')) {
    $cset = (defined('MAINCHARSET') ? constant('MAINCHARSET') : 'UTF-8');
	define('DB_DEFAULTCHARSET', $cset);
}

/**
* if var $as_dbparam('server'=>'hostaddr', 'dbname'=>'mybase','username'=>'login','password'=>'psw') set,
* connection will be created inside this class, and passing these vars to constructor is not nessesary
*/
class CDbEngine {
    const VERSION = '1.23';
    const FMT_DTIME = 'd.m.Y H:i:s';
    const LOCK_FOR_UPDATE = 1;
    const LOCK_ON_SHARE = 2;

    protected $debug = 0;
    static $FATAL_ERRORS = [ 144 ]; # SQL errors that must raise Exception!
    private static $updatesLogFile = './_audit-updates.log'; # where to store update queries log
    private static $_logfile = './_dbaccess.log';
    private static $_instances = array();
    private static $asdb_logerrors = 0;
    private $dbtype = DBTYPE_MYSQL;
    private $_cacheFolder = FALSE; # if non-empty, cache for SELECT queries is turned ON
    private $_cacheTTL = 0;    # expire cached request time (seconds)
    private $host = '';
    private $cset = '';
    private $user = '';
    private $password = '';
    private $db_name = '';
    private $dblink = null;
    private $b_permconn = true; # use permanent connection when possible
    private $connection = FALSE;
    private $connected = FALSE;
    private $qrylink = array(); # link(s) returned by  last sql_query()
    private $lastSelObj = FALSE;
    private $qryNo = 0;
    private $affectedrows = 0;
    private $lastquerytext = ''; # last executed query text
    private $_sqlErrorNo = FALSE; # last executed error No
    private $_sqlErrorText = '' ; # last executed error message
    private $tables = array(); # table list for backup
    private $logUpdates = FALSE;
    public $outputfile = ''; # output backup filename
    var $fhan = 0; # file handle for backup file read/write
    var $bckp_emptyfields = 0; // 1 or true - backup with empty (default) field values
    var $charset = DB_DEFAULTCHARSET;

    var $rto = array("\\x92","\\x60","\\x62");
    var $packmode = FALSE; # 1=gzip, 'zip'=>zip
    var $verbose = 0;
    private $srccharset = '';
    private $errormessage = '';
    private $error_no = '';
    var $errorlog_file = '';
    var $_aborted = FALSE;
    var $extract_ddl = true; // put 'CREATE TABLE...' operators into backup file
    var $tablename = '';
    var $createSql = '';
    var $emulate = FALSE; // restore,sql_query: no real INSERT, just emulating (debug or other purposes)
    var $logging = FALSE; # logging mode (0-don't log anything)
    var $safemode = 1; # converting 'unsafe' chars in text fields method : 0:no conversion, 1:'=>", 2:mysql_real_escape_string()
    var $blobfields = array(); # these fields excluded from "str_replace" before update
    var $bckp_filter=array(); # $bckp_filter['mytable']= "datecreate='2006-12-31'" - backup records filter
    var $fakeinsertid=0;
    var $_log_all_updates = ''; # filename for saving ALL updating SQL queries
    var $_monitored_tables = array(); # any updating query on these tables will be logget for auditing
    private $_compat = 0;
    var $mycharsets = array('ASCII','UTF-8','WINDOWS-1251');
    private $pdoOptions = [ # creating DB link options
       PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, # or PDO::ERRMODE_EXCEPTION
       # PDO::ATTR_EMULATE_PREPARES => FALSE, # turn Off emulation mode
       # PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => FALSE # use buffered results. Set FALSE if gonna take Huge results
    ];
    private $mysqli = FALSE;
    private $packpasswd = '';
    private $toSql = FALSE;
    private $rangeField='';
    private $range = array();
    public $mainDb = '';
    static private $autoconverting = FALSE;
    private $_hooks = array(); # hook functions for updating data calls : update(), insert(), delete()
    private $_semaphors = array(); # avoid recursive hook calls

    public function __construct($host=FALSE,$user=FALSE,$password=FALSE,$dbname=FALSE) {

        global $as_dbparam;
        $this->mainDb = $dbname;
        if($host===FALSE && isset($as_dbparam['server']))       $host = $as_dbparam['server'];
        if($user===FALSE && isset($as_dbparam['username']))     $user = $as_dbparam['username'];
            if($password===FALSE && isset($as_dbparam['password'])) $password = $as_dbparam['password'];
        if($dbname===FALSE   && isset($as_dbparam['dbname']))   $this->mainDb = $dbname = $as_dbparam['dbname'];
        $this->dbtype = isset($as_dbparam['dbtype']) ? $as_dbparam['dbtype'] : DBTYPE_MYSQL;
        # some providers ban persistent connections, so just define this CONST to force using mysql_connect()
        if($host!==FALSE) {
            $this->Connect($host,$user,$password,$this->mainDb);
        }
        self::$_instances[] = $this;
    }
    public static function getVersion() { return self::VERSION; }
    # add critical tables that must be hardly monitored (saving all updating queries history)
    public function AddMonitoredTables($param) {
        if(is_array($param)) foreach($aparam as $tname) $this->_monitored_tables[] = $tname;
        elseif(is_string($param)) $this->_monitored_tables[] = $param;
    }
    public function setAutoConverting($param=true) { self::$autoconverting = $param; }

    /**
    * sets list of field names that are 'BLOB', so do not convert them with addslashes or str_replace
    */
    public function setBlobFields($fldarray) {
        if(is_string($fldarray)) $this->blobfields = preg_split("/[ ,;]/", $fldarray, -1, PREG_SPLIT_NO_EMPTY);
        elseif(is_array($fldarray)) $this->blobfields = $fldarray;
    }
    public function SaveDDLMode($flag=true) { $this->extract_ddl = $flag; }
    public function SetCharSet($charset) { $this->charset = strtoupper($charset); }

    public function AddBackupFilter($prm1,$prm2) { // add an array or one filter
        if(is_array($prm1)) $this->bckp_filter = array_merge($this->bckp_filter,$prm1);
        else $this->bckp_filter[$prm1]= $prm2;
    }

    public function SetVerbose($flag=true) { $this->verbose = $flag; }
    public function SetCompat($strg) { $this->_compat = $strg; }
    /**
    * Turns ON/Off caching feature
    * @param mixed $cachefolder if empty, caching is turned off, otherwise folder to save cached data
    * @param integer $ttl time-to-live for cache (seconds)
    */
    public function EnableCaching($cachefolder, $ttl=0) {
        $this->_cacheFolder = $cachefolder;
        if(!empty($cachefolder) && empty($ttl)) $ttl = 86400; # default one day TTL
        $this->_cacheTTL = $ttl;
    }
    /**
    * Deletes ALL cached data for tables from passed list
    *
    * @param mixed $tablenames table names list as array or comma-separated string
    */
    public function ClearCache($tablenames='') {
        $tlist = is_array($tablenames) ? $tablenames : explode(',',$tablenames);
        foreach($tlist as $tname) {
            $cachefnames = $this->_cacheFolder . $tname . ($tname? '_':'') . '*.cache';
            $filelist = glob($cachefnames);
            if(is_array($filelist)) foreach($filelist as $onefile) { @unlink($onefile); }
        }
    }

    public function GetErrorMessage() {
        return $this->_sqlErrorText;
    }
    /**
    * Sets debug/logging level or logs passed string
    *
    * @param mixed $par integer value sets debug level (-1 - log only one next SQL query, 1- turn logging ON, 0-Off), string - to log string
    */
    public function Log($par=-1) {
        if(is_string($par)) $this->SaveLogString($par);
        else $this->logging = $par;
    } # set debuging/logging level
    # Setting error tolerance for Restoring from backup operation (abort after NNN errors)

    public function Connect($host=FALSE, $user=FALSE, $password=FALSE, $dbname=FALSE, $dbtype = '') {
        if ($this->connected) { echo "already connected!<br>"; return true; }
        global $as_dbparam;

        if(!empty($as_dbparam) && is_array($as_dbparam)) {

            if(empty($host) && !empty($as_dbparam['server'])) $host = $as_dbparam['server'];
            if(empty($user) && !empty($as_dbparam['username'])) $user = $as_dbparam['username'];
            if(empty($password) && !empty($as_dbparam['password'])) $password = $as_dbparam['password'];
            if(empty($dbname) && !empty($as_dbparam['dbname'])) $dbname = $as_dbparam['dbname'];
            if(!empty($dbtype)) $this->dbtype = $dbtype;
            elseif (!empty($as_dbparam['dbtype'])) $this->dbtype = $as_dbparam['dbtype'];

            if (isset($as_dbparam['pdo']) && is_array($as_dbparam['pdo']))
                 $this->pdoOptions = array_merge($this->pdoOptions, $as_dbparam['pdo']);
        }
        $this->host = $host;
        $this->db_name = $dbname;
        $this->user = $user;
        $this->password = $password;

        $ret = FALSE;

        $connString = $this->dbtype . ":" . "host=$host";
        # TODO: for pgsql possible connect w/o localhost, (no TCP overhead) check it!:
        # $dbh = new PDO('pgsql:user=exampleuser;dbname=exampledb;password=examplepass');
        if (!empty($this->db_name)) $connString .= ";dbname=$dbname";

        if (!empty($as_dbparam['charset'])) {
            $this->cset = ($as_dbparam['charset'] ==='UTF-8' ? 'UTF8' : $as_dbparam['charset']);
            $connString .= ";charset=$this->cset";
        }

        try {
            $this->dblink = new PDO($connString, $user, $password, $this->pdoOptions);

            if($this->logging) {
                $this->SaveLogString("Opening DB connection, server=[{$host}]");
            }
            $this->connected = true;
        }
        catch (Exception $e) {
            $this->_sqlErrorText = $e->getMessage();
            $this->logevent('Connecting to Server failed:' . $this->_sqlErrorText);
            if ($this->debug) die ('Connect fatal error:' . $this->_sqlErrorText);
        }
        /*
        if ($this->connected)
            $this->dblink->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        */
        if($this->dblink && isset($as_dbparam['onconnect'])) {
            # useful for executing "SET NAMES `cpXXXX`" right after connect
            if(is_array($as_dbparam['onconnect'])) foreach($as_dbparam['onconnect'] as $sql) { $this->dblink->exec($sql); }
            elseif(is_string($as_dbparam['onconnect'])) {
	            $result = $this->dblink->exec($as_dbparam['onconnect']);
	      }
        }


        $this->connected = $ret;
        return $ret;
    }
  /**
  * saves string into Log file
  * @param mixed $strg string to write
  */
  function SaveLogString($strg) {
    $runinfo = debug_backtrace();
    $pref = isset($runinfo[0]) ? ($runinfo[0]['file'].'['.$runinfo[0]['line']).']' : '';
    for($idt=1;isset($runinfo[$idt]);$idt++) { # find script/line that called as_dbutils
        if($runinfo[$idt]['file'] != $runinfo[0]['file']) {
            $pref = $runinfo[$idt]['file'].'['.$runinfo[$idt]['line'].']';
            break;
        }
    }
    file_put_contents(self::$_logfile, "\n".date('Y-m-d H:i:s')."/$pref \t{$strg}", FILE_APPEND);
    if($this->logging===1) $this->logging = 0;
  }
    # PDO - calling "use DBNAME" does not work, so we have to reopen connection with passed dbname
    public function select_db($dbname) {
        if (empty($dbname) || $this->db_name === $dbname) return TRUE;

        $connString = "$this->dbtype:host=$this->host;dbname=$dbname";
        if (!empty($this->cset)) $connString.= ";charset=$this->cset";
        $this->connected = FALSE;
        try {
            $this->dblink = new PDO($connString, $this->user, $this->password, $this->pdoOptions);
            $this->connected = TRUE;
        }
        catch (Exception $e) {
            $this->_sqlErrorText = $e->getMessage();
            $this->logevent("Opening db $dname failed:" . $this->_sqlErrorText);
        }

        return $this->connected;
    }

    public function getLastErrorMessage() {

        if (is_object($this->lastSelObj)) {
            $errInfo = $this->lastSelObj->errorInfo();
            $this->_sqlErrorText = $errInfo[2];
            $this->_sqlErrorNo   = $errInfo[1];
        }
        return $this->_sqlErrorText;
    }

    public function GetDbVersion($as_string=FALSE) {
        $ret = $this->sql_query('SELECT VERSION()',1);
        $ret = $ret[0];
        if(!$as_string) $ret = floatval($ret);
        return $ret;
    }
    public function CurrentDbName() {
        $curdb = $this->sql_query('select DATABASE()',1,0,0);
        return(is_array($curdb)? $curdb[0]: '');
    }
    public function Disconnect() {
        # if(empty($this->b_permconn) && !empty($this->dblink)) $this->dblink->close(); # PDO - no need!
        $this->connection = $this->connected = FALSE;
        return TRUE;
    }

    # Turn ON/off logging all UPDATE/DELETE/INSERT SQL operations
    # $val can be TRUE/FALSE or user callback function name
    public function logUpdates($val = TRUE) {
        $this->logUpdates = $val;
    }
  # GetTableList() - returns array with all table names
    public function GetTableList($mask = '') {
        $ret = [];
        $dta = $this->sql_query('SHOW TABLES'.($mask? " LIKE '$mask'":''),1,0,TRUE);
        if (is_array($dta)) foreach($dta as $row) { $ret[] = $row[0]; }
        return $ret;
    }
    public function GetFieldList($tablename, $assoc=FALSE) {
        $dta = $this->sql_query("DESCRIBE $tablename",1,0,1);
        # echo 'data:<pre>'.print_r($dta,1) . '</pre>';
        $ret = array();
        if (is_array($dta)) foreach($dta as $r) {
            $fldid = $r[0]; # field name
            if($assoc) $ret[$fldid] = $r;
            else $ret[] = $r;
        }
        return $ret;
    }
    /**
    * returns all primary key fields list
    *
    * @param string table name
    * @return string (on key field) or an array (if more than one PK field)
    */
    public function GetPrimaryKeyField($tablename) {
        $ret = array();
        $flds = $this->GetFieldList($tablename);
        foreach($flds as $no=>$f) { if($f[3]=='PRI') {$ret[]=$f[0]; } }
        if(count($ret)<1) return '';
        return ( (count($ret)>1) ? $ret : $ret[0]);
    }

    public function affected_rows($selectObj = FALSE) {
        if ($selectObj) return ($selectObj->rowCount());
        $ret = ($this->lastSelObj)? $this->lastSelObj->rowCount() : FALSE;
        return $ret;
    }
    public function insert_id($reqId = NULL) {
        if($this->emulate) return $this->fakeinsertid;
        return (($this->dblink)? $this->dblink->lastInsertId($reqId) : FALSE);
        return 0;
    }

    public function sql_errno() {
        return $this->_sqlErrorNo;
    }
    public function sql_error() {
        return $this->_sqlErrorText;
    }

    public function IsTableExist($table) { # works on mysql only
        $result = $this->select("information_schema.TABLES", ['fields'=>'TABLE_NAME', 'where'=>['TABLE_NAME'=>$table]]);
        return (!empty($result[0]['TABLE_NAME']));

    }

    public function sql_query($query,$getresult=FALSE, $assoc=FALSE, $multirow=FALSE) { // universal query execute

        if (!$this->dblink) return FALSE;

        $this->affectedrows = 0;
        $this->_sqlErrorText = '';
        $queries = is_array($query)? $query : array($query);
        $ret = '';
        $qryNo = 0;
        $this->qrylink = [];
        foreach($queries as $name=>$onequery) { #<2>
          if(empty($onequery)) continue;
          $this->_sqlErrorNo = FALSE;
          $this->_sqlErrorText = '';

          $this->lastquerytext = $onequery;
          if($this->emulate) {
              if($this->emulate=='echo')  echo "emulating query: $onequery\r\n<br />";
              else $this->SaveLogString("emulating query: $onequery");
              $this->fakeinsertid = rand(1000,999999999);
              $ret = $this->affectedrows = 0;
          }
          else { #<3>
              $ret = $sellink = $this->qrylink[$qryNo] = $this->lastSelObj = $this->dblink->prepare($onequery);
              $executed = $ret->execute();
              $err = $this->lastSelObj->errorInfo();
              $this->affectedrows = $ret->rowCount();

              if (intval($err[1])) {
                  $this->_sqlErrorNo = intval($err[1]);
                  if (!empty($err[2])) $this->_sqlErrorText = $err[2];
              }
              # if($this->_sqlErrorNo) writeDebugInfo('error '.$this->_sqlErrorNo. ' /'.$this->_sqlErrorText,' on query ',$onequery);
              if ( $this->_sqlErrorNo ) $ret = FALSE;

              if ($getresult && $ret ) {
                # $executed = $ret->execute();
                if ($this->_sqlErrorNo) {
                    $ret = FALSE;
                    $this->affectedrows = 0;
                }
                else {
                    $ret = ($multirow) ? $ret->fetchAll(PDO::FETCH_ASSOC) : $ret->fetch(PDO::FETCH_ASSOC);
                }
                # echo 'ret:<pre>'.print_r($ret,1) . '</pre>';
                # $err = $this->dblink->errorInfo();

                if (!$assoc && !$this->_sqlErrorNo) {
                    if ($multirow) foreach($ret as $k=>&$row) {
                        $row = array_values($row);
                    }
                    else $ret = array_values($ret);
                }
                $this->free_result($sellink);
                # if (!$multirow && isset($ret[0])) $ret = $ret[0];
              }

              /*
              if(stripos(mysql_error(),'server has gone away')!==FALSE) { # MySQL disconnected by timeout: reconnect and try again
                  $this->Connect();
                  $ret = $this->qrylink = @mysql_query($onequery,$this->dblink);
              }
              */

              if(($this->logging) || (self::$asdb_logerrors && $this->_sqlErrorNo)) {
                  $this->SaveLogString("execute|$onequery|err:{$this->_sqlErrorNo}/{$this->_sqlErrorText}|rows: {$this->affectedrows}");
              }
              # saving "transaction log"
              /**
              if($this->_log_all_updates) {
                  if(empty($this->_sqlErrorText) && $this->IsQueryUpdating($onequery,$tbname)) {
                  # save successive updating request into SQL log file
                      $savehan = @fopen($this->_log_all_updates,'a');
                      if($savehan) {
                          $mktime = explode(' ',microtime());
                          $timestamp = date('Y-m-d-His').$mktime[0];
                          @fprintf($savehan,"<SQL TIME=\"%s\">%s</SQL>\n", $timestamp,$onequery); # date('Y-m-d H:i:s')
                          @fclose($savehan);
                      }
                  }
              }
              **/
              if(!$this->_sqlErrorNo && count($this->_monitored_tables)) { # find out if it's monitored table
                  $onetable = '';
                  $upd = $this->IsQueryUpdating($onequery, $onetable);
                  if($upd && ($this->_monitored_tables === 'ALL' || in_array($onetable,$this->_monitored_tables)) && !empty($this->_log_all_updates))
                  { # save SQL query
                      $usrid = $_SESSION['userid'] ?? $_SESSION['userpin'] ?? '';
                      $logStr = sprintf("<sql time=\"%s\" user=\"%s/%s\" script=\"%s\" affected=\"%s\">\n%s\n</sql>\n",
                        date(self::FMT_DTIME),$usrid,$_SERVER['REMOTE_ADDR'],$_SERVER['PHP_SELF'],$this->affectedrows,trim($onequery));
                      @file_put_contents($this->_log_all_updates, $logStr, FILE_APPEND|LOCK_EX);
                  }
              }
          } #<3>
          $this->lastquerytext = $onequery;
        } #<2>
        return $ret;
    }
    public function IsConnected() {
        return ($this->connected or is_object($this->dblink));
    }
    public function sql_explain($query) { // 'explain plan'
        $this->lastquerytext = $query;
        $this->affectedrows = 0;
        $ret = $this->dblink->query("EXPLAIN $query");
        $this->getLastErrorMessage();
        return $ret;
    }

    public function GetLastQuery() { return $this->lastquerytext; }

    public function fetch_row($link) {
        if(!($link)) return FALSE;
        $ret = $link->fetch(PDO::FETCH_NUM); # только неассоц. массив без имен полей
        # echo "ret row is:<pre>".print_r($ret,1).'<pre>';
        return $ret;

    }
    # converts data (string or array) arived from DB, to the "active" table's charset
    public function autoConvert(&$data) {
      if(is_array($data)) foreach($data as $k=>$v) {
          if(is_array($v)) $this->autoConvert($data[$k]);
          else {
            $cset = strtoupper(mb_detect_encoding($v, $this->mycharsets));
            if($cset!='ASCII' && $cset != $this->charset) $data[$k] = mb_convert_encoding($data[$k],$this->charset,$cset);
          }
      }
      elseif(is_string($data)) {
          $cset = strtoupper(mb_detect_encoding($data, $this->mycharsets));
          if($cset!='ASCII' && $cset != $this->charset) $data = mb_convert_encoding($data,$this->charset,$cset);
      }
    }

    public function fetch_assoc($link) {
        if(!$link) return FALSE;
        $rt = $link->fetch(PDO::FETCH_ASSOC);
        if(self::$autoconverting) {
            $this->autoConvert($rt);
        }
        return $rt;
    }

    public function fetch_object($link) {
        if(!$link) return FALSE;
        return $link->fetch(PDO::FETCH_OBJ);
    }

    public function SetSafeMode($val) { $this->safemode = $val; }

    # free query execution resources for
    public function free_result($link) {
        # WriteDebugInfo("free_result, link is:", get_class($link));
        if(is_object($link) && get_class($link)==='PDOStatement') $link->closeCursor();
    }
    public function CleanTables($tables, $condition=FALSE) {
        if(is_string($tables)) $tables = preg_split("/[,;| ]/",$tables);
        if(is_array($tables) && count($tables)>0) foreach($tables as $tbname) {
            if($condition) $this->sql_query("DELETE FROM $tbname WHERE $condition");
            else $this->sql_query("TRUNCATE TABLE $tbname");
        }
    }
    /**
    * @desc returns record count for desired table (with optional WHERE condition, if passed)
    */
    public function GetRecordCount($tblname,$filter='') {
        # $flt = '(1)'.(empty($filter)? '':" AND $filter");
        $result = $this->GetQueryResult($tblname,'COUNT(1)',$filter);
        return $result;
    }

    private static function _buildFieldList($par) {
      if(is_string($par)) return $par;
      if(is_array($par)) {
          $ret = array();
          foreach($par as $k=>$v) {
              $ret[] = $v . (is_numeric($k) ? '': " $k"); # use key as alias for the field
          }
          return implode(',', $ret);
      }
      return '';
    }
    /**
    * returns data from table, selected by passed criteriums
    *
    * @param mixed $table - table name (or table list as comma-separated string or array  of strings, possible with string keys to pass table's aliases)
    * @param mixed $fieldlist - required field list, as string comma separated or array of strings
    * @param mixed $cond WHERE clause (string or assoc.array "field"->value OR "ordinary" array of string conditions : "field1=400","field2<3",...)
    * @param mixed $multirow 0-return only first row, 1 - all rows
    * @param mixed $assoc - 1 - return as associative array
    * @param mixed $safe - "safe" mode, if nonempty, addslashes() will be executed for every returned field value
    * @param mixed $orderby - optional "ORDER BY " clause
    * @param mixed $limit - LIMIT value[s]: for example "80,20" will become ... LIMIT 80,20
    */
    public function GetQueryResult($table,$fieldlist,$cond='',$multirow=FALSE, $assoc=FALSE,$safe=FALSE,$orderby='',$limit='') {
        $uc = FALSE; # Universal Call
        $fldlist = '*';
        if(is_array($fieldlist)) {
            if(isset($fieldlist['where'])) { $cond = $fieldlist['where']; $uc = 1; }
            if(isset($fieldlist['multirow'])) { $multirow = $fieldlist['multirow']; $uc = 1; }
            if(isset($fieldlist['associative'])) { $assoc = $fieldlist['associative']; $uc = 1; }
            if(isset($fieldlist['safe'])) { $safe = $fieldlist['safe']; $uc = 1; }
            if(isset($fieldlist['orderby'])) { $orderby = $fieldlist['orderby']; $uc = 1; }
            if(isset($fieldlist['groupby'])) { $groupby = $fieldlist['groupby']; $uc = 1; }
            if(isset($fieldlist['having'])) { $having = $fieldlist['having']; $uc = 1; }
            if(isset($fieldlist['limit'])) { $limit = $fieldlist['limit']; $uc = 1; }
            if(isset($fieldlist['fields'])) { $fldlist = self::_buildList($fieldlist['fields']); $uc = 1; }
        }
        if(!$uc) $fldlist = self::_buildFieldList($fieldlist);
        if(empty($cond)) $scond='(1)';
        else $scond = $this->BuildSqlCondition($cond);
        $tbname = (string)$table;
        if(is_array($table)) {
            $tbname = '';
            # use element key as table Alias if it's a string
            foreach($table as $kt=>$vt) { $tbname .= ($tbname?',':'') . (string)($vt). (is_string($kt)? " $kt":''); }
        }
        $qry = "SELECT $fldlist" .($tbname? " FROM $tbname":''). ($scond==''? '': " WHERE $scond")
           . (empty($groupby)?'': " GROUP BY $groupby")
           . (empty($orderby)?'': " ORDER BY $orderby")
        ;
        if (!empty($limit)) {
            $qry .= $this->decodeLimit($limit);
        }

        if($this->_cacheFolder) {
            $cachefname = $this->_cacheFolder . $table .'_'.md5($qry)."{$multirow}_{$assoc}_{$safe}.cache";
            if(file_exists($cachefname) and $this->_cacheTTL >= (@(time() - filemtime($cachefname)))) {
                $result = @file_get_contents($cachefname);
                if($result) $reta = @unserialize($result);
                unset($result);
                return $reta;
            }
        }
        $lnk = $this->sql_query($qry);

        if(!($lnk)) return FALSE;

        $reta = FALSE;
        while(($row=($assoc ? $this->fetch_assoc($lnk): $this->fetch_row($lnk) ))) {
            // if(($safe) && !get_magic_quotes_runtime()) { # get_magic_quotes_runtime() -> dont use. It has been removed in php8
          if($safe) {
            foreach($row as $key=>$val) $row[$key] = addslashes($val);
          }
          if(($assoc)) $retvalue = $row;
          else $retvalue = (count($row)==1) ? $row[0] : $row;
          if(empty($multirow)) return $retvalue;
          if(!is_array($reta)) $reta=array();
          $reta[] = $retvalue;
        }
        $errInfo = $this->lastSelObj->errorInfo();

        $this->free_result($lnk);
        if($this->_cacheFolder) { # create cached file
            file_put_contents($cachefname, serialize($reta));
        }
        return $reta;
    }

    private static function _buildList($arr) {
      if (empty($arr)) return '';
      if (is_string($arr)) return $arr;
      $ret = array();
      foreach ($arr as $key => $val) {
          if(is_array($val)) exit(__FILE__ .':'.__LINE__.' bad list array:<pre>'
           . print_r($arr,1) .'<br>error here:<br>'. print_r($val,1).'</pre>');
          $ret[] = $val . (is_numeric($key)? '' : " $key");
      }
      return implode(',', $ret);
    }

    # Builds "JOIN" pheaze from assoc.array containg mandatory 'table','condition' and optional 'type','alias','index' options
    private static function _buildJoinOption($opts) {

      if (is_string($opts)) return $opts;
      if (!is_array($opts)) return '';
      if(isset($opts[0]['table'])) {
          # multiple JOIN's, passed as array rows, create "JOIN t1 ON exp1 JOIN t2 ON exp2 ..."
          $ret = '';
          foreach($opts as $oneOpt) {
              if($oneJoin = self::_buildJoinOption($oneOpt))
                $ret .= $oneJoin . ' ';
          }
          return $ret;
      }
      $joinType = isset($opts['type']) ? ($opts['type'].' ') : 'LEFT'; # 'type' can be 'LEFT'(default),'RIGHT','INNER','OUTER'
      $table = isset($opts['table']) ? $opts['table'] : '';
      $condition = isset($opts['condition']) ? $opts['condition'] : '';
      if (empty($table) or empty($condition)) return '';
      $alias = isset($opts['alias']) ? " AS $opts[alias]" : '';
      $index = !empty($opts['index']) ? " USE INDEX ($opts[index])": '';
      return ("$joinType JOIN $table ". $alias . ' ON '. $condition . $index);
    }
    // build one query from options
    private function _buildQuery($options=array()) {

      if (!isset($options['table'])) return '';
      $table = $options['table'];

      $where = $orderby = $join = '';
      $fldlist = '*';
      if(is_array($options)) {
        $fldlist = isset($options['fields']) ? self::_buildList($options['fields']) : '*';
        if($fldlist==='') $fldlist = '*';
        $where = isset($options['where']) ? $this->BuildSqlCondition($options['where']) : '';
        $distinct = !empty($options['distinct']) ? ' DISTINCT':'';
        $orderby = isset($options['orderby']) ? self::_buildList($options['orderby']) : '';
        $groupby = isset($options['groupby']) ? self::_buildList($options['groupby']) : '';
        $having = isset($options['having']) ? self::_buildList($options['having']) : '';
        if(!empty($options['join']) and is_array($options['join'])) {
            $join = self::_buildJoinOption($options['join']);
        }
        if ($this->logging >= 5) {
            WriteDebugInfo($table.'/select options: ', $options);
            WriteDebugInfo("prepared options : where=[$where] / singlerow=[$singlerow] / groupby=[$groupby] / offset=[$offset] / ");
        }
      }

      $tbname = self::_buildList($table);

      # add LOCK option (FOR UPDATE or LOCK IN SHARE MODE
      $lockStr = '';
      if(!empty($options['lock'])) {
          if($this->dbtype === DBTYPE_MYSQL) {
              if(!empty($options['lock'])) {
                  if($options['lock'] === self::LOCK_FOR_UPDATE || $options['lock'] === 'for update')
                      $lockStr = ' FOR UPDATE';

                  elseif ($options['lock'] == self::LOCK_ON_SHARE)
                      $lockStr = ' LOCK IN SHARE MODE';
              }
          }
      }
      $offset = isset($options['offset']) ? intval($options['offset']) : 0;
      $rows = isset($options['rows']) ? intval($options['rows']) : 0;
      if (!empty($options['singlerow'])) $rows = 1;
      $limit = '';
      if ($rows > 0) $limit .= " LIMIT $rows";
      elseif($offset>0) $limit .= " LIMIT 100"; # avoid OFFSET without LIMIT clause
      if ($offset > 0) $limit .= " OFFSET $offset";

      $qry = "SELECT{$distinct} {$fldlist}" .($tbname? " FROM $tbname":'')
        . ($join ? ' '.$join : '')
        . ($where==''? '': " WHERE $where")
        . ($groupby==='' ? '' : " GROUP BY $groupby" . ($having ? " HAVING ($having)":''))
        . (empty($orderby) ? '': " ORDER BY $orderby")
        . $limit
        . $lockStr
      ;
      return $qry;
    }

    /**
    * Returns "SELECT" result for passed table name and options
    *
    * @param mixed $table
    * @param mixed $options array of options
    * @param mixed $noFetch if TRUE, just return handler of ececuted query, for fetching externally
    */
    public function select($table,$options = array(), $noFetch = FALSE) {
        # if($table === 'alf_agreements') writeDebugInfo("KT-000 agreements select from ", debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));
        $startrow = $rows = $singlerow = $safe = $assoc = $caching = 0;
        $options['table'] = $table;
        $qry = $this->_buildQuery($options);
        if (!$qry) return FALSE;

        # perform all UNION elements if passed
        if (isset($options['union'])) {
            if (is_string($options['union'])) {
                $qry .= "\n  UNION ".$options['union'];
            }
            elseif(is_array($options['union'])) {
                if (isset($options['union'][0]) && is_array($options['union'][0])) {
                    foreach($options['union'] as $uitem) {
                        if (isset($uitem['table'])) $qry .= "\n  UNION ".$this->_buildQuery($uitem);
                    }
                }
                else {
                    if (isset($options['union']['table'])) $qry .= "\n  UNION ".$this->_buildQuery($options['union']);
                }
            }
        }

        $singlerow = isset($options['singlerow']) ? $options['singlerow'] : '';
        $safe = isset($options['safe']) ? $options['safe'] : 0;
        $assoc = isset($options['associative']) ? $options['associative'] : 1;

        $caching = !empty($options['caching']);

        if ($this->_cacheFolder && !empty($caching)) {
            $cachefname = $this->_cacheFolder . $table .'_'.md5($qry.$singlerow.$assoc.$safe).'.cache';
            if(file_exists($cachefname) and $this->_cacheTTL >= (@(time() - filemtime($cachefname)))) {
                $result = @file_get_contents($cachefname);
                if($result) $reta = @unserialize($result);
                unset($result);
                return $reta;
            }
        }
        $lnk = $this->sql_query($qry);
        if(!$lnk) {

            if(function_exists('writeDebugInfo')) {
                writeDebugInfo($qry, " - sql execute error [$errno]: ", $this->sql_error());
                writeDebugInfo("trace ", debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4));
            }
            # if(is_callable('AppAlerts::raiseAlert'))
            #    AppAlerts::raiseAlert("SQL_FATAL-".$table, $this->sql_error());

            if(in_array($this->_sqlErrorNo, self::$FATAL_ERRORS)) {
                throw new Exception("FATAL SQL ERROR [$this->_sqlErrorNo]: ".$this->_sqlErrorText);
            }

            return FALSE;
        }
        # if(is_callable('AppAlerts::resetAlert'))
        #    AppAlerts::resetAlert("SQL_FATAL-".$table, $this->sql_error());

        $executed = $lnk->execute(); # ($assoc ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH)
        $this->affectedrows = $lnk->rowCount();

        if($noFetch) return $lnk;

        if($singlerow) {
            $arDataRow = $lnk->fetch(PDO::FETCH_ASSOC);
            $reta = is_array($arDataRow) ? [ $arDataRow ] : FALSE;
        }
        else
            $reta = $lnk->fetchAll(PDO::FETCH_ASSOC);
        # $reta = $lnk->fetchAll(PDO::FETCH_COLUMN); -
        if (is_array($reta)) foreach($reta as $k=> &$row) {
            // if(($safe) && !get_magic_quotes_runtime()) { # get_magic_quotes_runtime() -> dont use. It has been removed in php8
            if($safe)
                array_walk($row, 'addslashes');
            if (!$assoc) {
                $row = array_values($row);
                if (count($row)==1) $row = $row[0];
            }
        }
        if ($singlerow && is_array($reta) && count($reta)) {
            # if (!isset($reta[0])) WriteDebugInfo("KT-001: singlerow for reta:", $reta);
            if(isset($reta[0])) $reta = $reta[0];
        }
        if($this->_cacheFolder && $caching) { # perfrom caching
            file_put_contents($cachefname, serialize($reta));
        }
        return $reta;
    }

    /**
    * fetch next record with passed result-set
    * @param mixed $fetchHandler record set (handler) returned by previous sql_query()
    * @param mixed $fetchMode
    */
    public function fetchRecord($fetchHandler, $fetchMode = FALSE) {
        if(!$fetchMode) $fetchMode = PDO::FETCH_ASSOC;
        $arRet = $fetchHandler->fetch($fetchMode);
        return $arRet;
    }
    public function decodeLimit($limit) {
        $ret = '';
        $splt = @explode(',', $limit);
        $offset = intval($splt[0]);
        $limsize = isset($splt[1]) ? intval($splt[1]) : 0;
        # list($offset,$limsize) = @explode(',', $limit);
        if ($limsize>0)  $ret .= " LIMIT $limsize";
        if ($offset>0)  $ret .= " OFFSET $offset";
        return $ret;
    }
    /**
    * copies all data from one table to another. Only fields that exist in both tables are copied
    *
    * @param mixed $tableFrom
    * @param mixed $tableTo
    * @param string $filter WHERE condition for source table (selective copiing)
    * @param mixed $flds optional assoc.array with fields to copy: array('oldname1'=>'newname1',...)
    */
    public function CopyRecords($tableFrom,$tableTo,$filter='',$fld_fromto='',$getsql=FALSE) {
        $fld1 = $this->GetFieldList($tableFrom,1);
        $fld2= array();
        if($this->sql_error()) { echo "CopyRecords err:".$this->sql_error(); return -1; } # debug
        if(!is_array($fld_fromto)) {
          $fld2 = $this->GetFieldList($tableTo,1);
          if(!is_array($fld2) || count($fld2)<1) { $this->_sqlErrorText="Unknown or non-exist table $tableTo"; return -1; }
        }
        if(!is_array($fld1) || !is_array($fld2)) return -1;
        $flst1 = $flst2 = '';
        foreach($fld1 as $fname=>$fdef) {
          if(isset($fld2[$fname])) {
            $flst1 .= (($flst1=='')?'':',').$fname;
            $flst2 .= (($flst2=='')?'':',').$fname;
          }
          elseif(isset($fld_fromto[$fname])) {
            $flst1 .=(($flst1=='')?'':',').$fname; # from field "name1" to field "name2"
            $flst2 .=(($flst2=='')?'':',').$fld_fromto[$fname];
          }
        }

        $wcond = empty($filter)? '': "WHERE $filter";
        $cpyqry = "INSERT INTO $tableTo ($flst2) SELECT $flst1 FROM $tableFrom $wcond";
        if($flst1!=='' && $flst2!=='') {
          if(empty($getsql)) {
            $this->sql_query($cpyqry);
            return $this->affected_rows();
          }
          else return $cpyqry;
        }
        return -1; # empty field list
    }

    public function SqlAffectedRows() { return $this->affectedrows; }

        function CloneRecords($tablename,$pk_name,$pk_value,$desttable='') {
        $ret = 0;
        $totable = ($desttable=='')? $tablename:$desttable;
        if(is_array($pk_value)) {
          $ret = array();
          foreach($pk_value as $val) {
            $dta = $this->GetQueryResult($tablename,'*',"$pk_name='$val'",FALSE,true,true);
            if($totable==$tablename) unset($dta[$pk_name]);
            $this->insert($totable,$dta);
            if($this->affected_rows())  $ret[] = $this->insert_id();
          }
        }
        else {
          $dta = $this->GetQueryResult($tablename,'*',"$pk_name='$pk_value'",FALSE,true);
          if($totable==$tablename) unset($dta[$pk_name]);
          $this->insert($totable, $dta);
          if($this->affected_rows()) $ret = $this->insert_id();
        }
        return $ret;
    }
    public function GetTableStructure($table) {
        $qry = "DESC $table";
        $rsrc = $this->sql_query($qry);
        $ret = array();
        switch($this->dbtype) {
        case DBTYPE_MYSQL:;
          while(($row=$this->fetch_row($rsrc))) {
           //  $ret[field_name] =[ type,  Null ,   Key(MUL|PRI) Default Extra (auto-increment)
           $ret[$row[0]] = array($row[1], $row[2],$row[3],$row[4],$row[5]);
          }
          break;
        # case DBTYPE_...
        }
        return $ret;
    }

    public static function getRealTableName($tfname) {

      if(file_exists($tfname.'.tpl')) {
          $fname = $tfname.'.tpl';
          $tlines = file($fname);
          foreach($tlines as $line) {
              $splt = explode("|", trim($line));
              if(strtolower($splt[0])==='id' && !empty($splt[1])) return $splt[1];
          }
          $tfs = preg_split("/\\\//",$tfname);
          return $tfs[count($tfs)-1];
      }
      else return $tfname;
    }

    public function logevent($evtstring) {
        if(function_exists('writedebuginfo')) WriteDebugInfo($evtstring);
    }

    public function GetAllTablesList() {
        $ret = $this->sql_query('SHOW TABLES', TRUE,0,TRUE);
        if($this->affected_rows()<1) {
            $this->_sqlErrorText = "no tables in DB or no DB connection"; return 0;
        }
        foreach($ret as &$item) { $item = $item[0]; }
        return $ret;
    }

    # transaction wrappers : begin, commit, rollback
    public function beginTransaction() {
        if(!$this->dblink) return FALSE;
        if ( $this->dblink->inTransaction() ) {
            return false;
        } else {
            $ret = $this->dblink->beginTransaction();
            return $ret;
       }
    }

    # getting current in transaction state
    public function inTransaction() {
        if(!$this->dblink) return FALSE;
        return $this->dblink->inTransaction();
    }

    public function commit() {
        if(!$this->dblink) return FALSE;
        if($this->dblink->inTransaction()) {
            $ret = $this->dblink->commit();
            return $ret;
        }
        return FALSE;
    }

    public function rollBack() {
        if(!$this->dblink) return FALSE;
        if (!$this->dblink->inTransaction()) return FALSE;
        $this->dblink->rollback();
        return TRUE;
    }

    public function Emulate($param = TRUE) {
      $this->emulate = $param;
    }
    /**
    * Gets all indexes for the table and returns assoc.array holding their expression ('field,field2') and 'type' (BTREE|FULLTEXT)
    *
    * @param mixed $tablename
    * @param mixed $kinds
    * @return empty or associative array
    */
    public function getIndexList($tablename, $kinds=null, $idxname=null) {
      $ret = array();
      $lnk = $this->sql_query('SHOW INDEX FROM '.$tablename);
      $gkinds = empty($kinds)? array() : (is_array($kinds)? $kinds : explode(',',$kinds));
      while(($lnk) && ($r=$this->fetch_assoc($lnk))) {
          $keyname = $r['Key_name'];
          $keytype = isset($r['Index_type']) ? $r['Index_type'] : 'BTREE'; # MySQL 3.x - no Index_type column !
          if(count($gkinds) && !in_array($keytype,$gkinds)) continue; # we want only selected index types (BTREE | FULLTEXT)
          if(!empty($idxname) && $keyname !== $idxname) continue; # we wanted only specific named index
          if(!isset($ret[$keyname])) {
              $ret[$keyname] = array('type'=>$keytype, 'field' => $r['Column_name']);
          }
          else $ret[$keyname]['field'] .= ','.$r['Column_name']; # multi-field index
      }
      return $ret;
    }

    /**
    * Detailed index list in the table
    * @param mixed $tablename name of the table
    * @param mixed $pack set TRUE if You want single row like "field1,field2,field2" for milti-field indexes
    * @since 1.20
    */
    public function getFullIndexList($tablename, $pack=FALSE) {
      $ret = [];
      $rows = $this->sql_query('SHOW INDEX FROM '.$tablename,TRUE,TRUE,TRUE);

      if (is_array($rows)) foreach($rows as $r) {
          $keyname = $r['Key_name'];
          $keytype = isset($r['Index_type']) ? $r['Index_type'] : 'BTREE'; # MySQL 3.x - no Index_type column !
          $arr = [
            'keyname' => $keyname,
            'type' => $keytype,
            'field' => $r['Column_name'],
          ];

          if (isset($r['Non_unique']))
            $arr['Non_unique'] = $r['Non_unique'];
          if (isset($r['Seq_in_index']))
            $arr['Seq_in_index'] = $r['Seq_in_index'];
          if (!empty($r['Sub_part']))
            $arr['Sub_part'] = $r['Sub_part'];

          $ret[] = $arr;
      }
      # pack complex indexes into one index expression
      if ($pack && count($ret)>0) {
          $ret2 = [];
          foreach($ret as $item) {
              $key = $item['keyname'];
              if (!isset($ret2[$key])) {
                  $ret2[$key] = $item;
                  if (!empty($item['Sub_part']))
                    $ret2[$key]['field'] .= "($item[Sub_part])";
              }
              else {
                  $ret2[$key]['field'] .= ",".$item['field'];
                  if (!empty($item['Sub_part']))
                    $ret2[$key]['field'] .= "($item[Sub_part])";

                  $ret2[$key]['Seq_in_index'] .= ",".$item['Seq_in_index'];
              }
              unset($ret2[$key]['keyname']);
          }
          $ret = $ret2;
      }
      return $ret;
    }
    /**
    * executes FULLTEXT searc query and returns result link,
    * UNDER CONSTRUCTION, don't use it !
    * @param mixed $strg
    * @param mixed $indexname
    * @param mixed $mode
    */
    public function FullTextSearch($strg, $indexname, $mode=null) {
      $ret = $this->sql_query("MATCH(alldata,zvtext) AGAINST ('$strg')");
    }
    /**
    * ALL updating queries will be saved into the file, a kind of "transaction log", to be applied to database
    *
    * @param mixed $dest_filename
    */
    public function SaveAllUpdates($log_fname=TRUE) {
      $this->_log_all_updates = ($log_fname===TRUE) ? ('./sql-log-.'.$date('Ymd').'log') : $log_fname;
    }
    /**
    * returns true if query is one of INSERT/DELETE/UPDATE/ALTER ...
    *
    * @param string query to analyze. If empty, internal query will be analyzed
    */
    public function IsQueryUpdating($sqlqry,&$tablename) {
      $tablename = '';
      if(empty($sqlqry)) $sqlqry = $this->lastquerytext;
      $words = preg_split("/[\s,]+/", trim($sqlqry));
      $operator = strtolower($words[0]);
      $op2 = isset($words[1])? strtolower($words[1]) : '';
      if(in_array($operator, array('update','delete','insert','truncate','drop'))) {
          $ipos=1;
          switch($operator) {
              case 'update':
                while(isset($words[$ipos]) && in_array(strtolower($words[$ipos]),array('low_priority','ignore'))) $ipos++;
                break;
              case 'insert': # INSERT [LOW_PRIORITY | DELAYED] [IGNORE] [INTO] tbl_name
                while(isset($words[$ipos]) && in_array(strtolower($words[$ipos]),array('low_priority','ignore','delayed','into'))) $ipos++;
                break;
              case 'delete': # DELETE [LOW_PRIORITY] [QUICK] [IGNORE] FROM table_name ...
                while(isset($words[$ipos]) && in_array(strtolower($words[$ipos]),array('low_priority','quick','ignore','from'))) $ipos++;
                break;
              case 'truncate' : case 'drop':
                while(isset($words[$ipos]) && in_array(strtolower($words[$ipos]),array('table'))) $ipos++;
                break;
          }

          if(isset($words[$ipos])) $tablename = $words[$ipos];
          return true;
      }
      if($operator==='create') {
          if($op2=='table') $tablename = isset($words[2])? $words[2] : '';
          return true;
      }
      if($operator==='alter' && $op2==='table') {
          $tablename = isset($words[2])?$words[2]:'';
          return true;
      }
      return FALSE;
    }

    public function delete($table, $where) {

        if (!empty($this->_hooks[$table][0]) && is_callable($this->_hooks[$table][0])) {
            $canDo = call_user_func($this->_hooks[$table][0], 'delete', $where);
            if ($canDo !== true) return $canDo;
        }
        $wcond = $this->buildSqlCondition($where);
        if(!$wcond) return FALSE; # protect from stupid table content deletion
        $this->sql_query("DELETE FROM $table WHERE $wcond");
        $this->affectedrows = $this->affected_rows();
          # call hook
          if (($this->affectedrows) && !empty($this->_hooks[$table][1])
	        && is_callable($this->_hooks[$table][1])) {
              if (!isset($this->_semaphors["$table:delete"])) {
                $this->_semaphors["$table:delete"] = 1;
      	        call_user_func($this->_hooks[$table][1], 'delete', $where, $this->affectedrows);
      	        unset($this->_semaphors["$table:delete"]);
		        }
          }

        return $this->affectedrows;
    }

    private function buildSqlCondition($conds) {
        if(is_scalar($conds)) return "$conds";
        if(is_array($conds)) {
          $ret = '';
          foreach($conds as $ky=>$vl) { $ret.= ($ret? ' AND ':'') .(is_numeric($ky) ? "($vl)":"($ky='$vl')"); }
          return $ret;
        }
        return ''; # non-array, non-string, return nonthing
    }

    /**
    * Inserting data into table.
    *
    * @param mixed $table table name
    * @param mixed $data data array ['fieldname'=>value,...] or array of such arrays (2+ records to INSERT)
    * @return mixed
    */
    public function insert($table, $data) {
        if (!is_array($data) || count($data)<1) {
            writeDebugInfo("no data to insert!");
            return FALSE;
        }
        $multiRows = (isset($data[0]) && is_array($data[0]));

        $fields = $multiRows ? array_keys($data[0]) : array_keys($data);
        $arrData = $multiRows ? $data : [ $data ];

        if (!empty($this->_hooks[$table][0]) && is_callable($this->_hooks[$table][0])) {
    	    $canDo = call_user_func($this->_hooks[$table][0], 'insert', $data);
      	    if ($canDo !== true) {
                return $canDo;
            }
 	    }
        $arrValues = [];
        foreach($arrData as $onerow) {
            $arTmp = [];
            foreach($fields as $fname) {
                $arTmp[$fname] = $onerow[$fname] ?? '';
            }
            $this->prepareValues($arTmp);
            $arrValues[] = '(' . implode(',', $arTmp) . ')';
        }
        $strfields = implode(',', $fields);

        $result = $this->sql_query("INSERT INTO $table ($strfields) VALUES ". implode(',',$arrValues));
        if($result) {
          $result = $this->insert_id();
          # writeDebugInfo("$table: insert OK, insert_id: ", $result, ' rows:', $this->affectedrows);
          if (!$result) $result = TRUE; // no PK fields
        }

        # call hook "after" opration
	    if (($this->affectedrows) && !empty($this->_hooks[$table][1])
	      && is_callable($this->_hooks[$table][1])) {
      	    if (!isset($this->_semaphors["$table:insert"])) {
                $this->_semaphors["$table:insert"] = 1;
      		    call_user_func($this->_hooks[$table][1], 'insert', $data, $this->affectedrows);
      		    unset($this->_semaphors["$table:insert"]);
		    }
	    }

        return $result;
    }
    /**
    * auto-substitute "macro" values by pre-defiend values : {now} - ISO date & time, {tofay} - ISO date
    * plus adding single quotes around values as if all they are strings
    * @param mixed $arr
    */
    private function prepareValues(&$arr) {
      foreach($arr as $key => $val) {
          if ($val ==='{now}') $arr[$key] = "'" . date('Y-m-d H:i:s') . "'";
          elseif ($val ==='{today}') $arr[$key] = "'" . date('Y-m-d') . "'";
          else # if(!is_numeric($arr[$key]))
              $arr[$key] = "'$val'";
      }
    }

    /**
    * update record(s) in the table
    *
    * @param mixed $table table name
    * @param mixed $data assoc.array in form 'filedname'=>value
    * @param mixed $where WHERE condition. Preferably assoc.array in form ('PIMARYKEY_FIELD'=>value[,'FLD2'=>value2...]). Can be simple string "FLD=100"
    */
    public function update($table, $data, $where) {

        if (!is_array($data) OR count($data)<1 OR empty($where)) return FALSE;

        if (!empty($this->_hooks[$table][0]) && is_callable($this->_hooks[$table][0])) {
    	    $canDo = call_user_func($this->_hooks[$table][0], 'update', $data, $where);
      	    if ($canDo !== true) return $canDo;
 	    }

        $strvalues = array();
        $strcond = '';
        $this->prepareValues($data);
        $values = array();
        if (is_string($where)) $strcond = $where;
        elseif (is_array($where)) {
            $arrcond = array();
            foreach ($where as $k => $v) {
                if (is_numeric($k)) $arrcond[] = "($v)";
                else { # WHERE element in 'field'=>value form
                    $arrcond[] = "($k='$v')";
                }
            }
            $strcond = implode(' AND ',$arrcond);
        }
        foreach ($data as $fld=>$val) {
            $values[]="$fld=$val";
        }
        $strvalues = implode(',', $values);
        $result = $this->sql_query("UPDATE $table SET $strvalues WHERE $strcond");

        # call hook "after" opration
        if ($result) {
		    if (($this->affectedrows) && !empty($this->_hooks[$table][1])
		      && is_callable($this->_hooks[$table][1])
		      && !isset($this->_semaphors["$table:update"])) {
                $this->_semaphors["$table:update"] = 1;
      		    call_user_func($this->_hooks[$table][1], 'update', $data, $where);
      		    unset($this->_semaphors["$table:update"]);
		    }
            return TRUE;
	    }
        return FALSE;
    }

    public static function getInstance($inst_no=0) {
      if (empty(self::$_instances[$inst_no])) {
          self::$_instances[$inst_no] = new self();
      }
      return self::$_instances[$inst_no];
    }
    public function getDbType() { return $this->dbtype; }

    /**
    * Adding hook for updating/inserting/deleteing record
    *
    * @param mixed $tablename table name
    * @param mixed $beforeOpr function name to make checks BEFORE operation (returns true if OK, err-message otherwise)
    * @param mixed $afterOpr function name to execute after succsessful operation
    * @since 1.090
    */
    public function addHook($tablename, $beforeOpr=FALSE, $afterOpr=FALSE) {
      if ($beforeOpr || $afterOpr) $this->_hooks[$tablename] = array($beforeOpr, $afterOpr);
      else unset($this->_hooks[$tablename]);
    }
} // CDbEngine definition end
}