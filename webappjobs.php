<?php
/**
* @package ALFO/web application
* @name webappjobs.php, successor of as_nightjobs.php
* @version 1.44.001
* modified 2025-06-10
* @Author Alexander Selifonov
**/
class WebAppJobs {

    const VERSION ='1.44.001';
    const DEFAULT_KEEP_DAYS = 2;
    const M_FREEDISK = 'FREEDISK';

    public static $debug = 0;
    static $emulateDelFiles = FALSE; # TRUE for debug mode with emulated deleting old files
    public $_folder_backups = '';
    private $_bckp_storages = [];
    private $_bckp_files = []; // files to copy from tmp/ folder to "storages"
    private $_tmpfolder = 'tmp/';
    private $_shrinklist = []; # tables to "shrink" list (delete old records based on "date" field value and max days)
    private $_shrinkdays = 30;
    private $_keepbackups = 10; # days to keep backup files
    private $_bkp_prefix = 'db'; # file prefix for backup BIG tables
    private $_bkp_prefix2 = 'dblist'; # prefix for "small" tables backups (lists etc.)
    private $_dblist = false; # database list to compute summary DB size
    private $_encrypt = FALSE; # encrypt or NO backups before saving to cloud/archive folders
    private $_commonBckpParams = [];
    private $do_backup = true;
    private $_bSpaceReported = FALSE;
    protected $sumDeleted = 0;
    protected $fileCleanVerbose = 1; # deleting old file verbose level
    var $_tlist = []; # BIG tables
    var $_tlist2 = []; # small tables
    var $_nobackup = []; # these tables won't be backed up (audit log etc.)
    var $_rootfolder = ''; # root folder (or an array of root folders, if more than one site)
    var $_sitesizetable = ''; # internal table for saving site size statistics (day by day)
    private $_siteSizeMethod = 'FREEDISK'; # FREE - analyze rest free space on the disk
    var $_maxsitesize = 0; # maximal site size (KB), according to Your ISP's hosting plan
    var $_sizethreshold = 10; # Report ATTENTION if site's going out of space in NN days
    var $_apptitle = '';
    var $_cleanfolders = [];
    var $_alarmcode = 0;
    var $_adminemail=''; # job results will be sent to this email address
    private $_emailserver = '';
    var $mailsendresult = false;
    var $_savemailedmsg = '';
    var $_emailcharset = 'iso-8859-1';
    var $_emailfrom = '';
    var $_msgsubj = '';
    var $_errors = [];
    var $_jobs1 = []; # prenended user jobs
    var $_jobs2 = []; # appended user jobs
    var $_lf_itemlist = ''; # name of SQL table that contains all "local" folders to be monitored
    var $_lf_datatable = ''; # table to store by-date "local folders size" snapshots
    var $_autoclean_stats = 120; # clean "statistic" tables from old records (older than NN days)
    private $_verbosemode = 0;
    private $_bckpstorage = NULL; // cloud/folder resources to keep backup copies (waBckp.php used)

    # default file extensions to be backed up as part of a site/web application
    static $bckp_default_extensions = array('html','htm','php','phtml','inc','cgi','pl','py','js','jsp','css','tpl','ini');

    private $_bckpsiteOptions = [];
    private $zipObj = false;

    public static function getVersion() { return self::VERSION; }

    public function __construct($apptitle='', $lang='') {
        global $as_iface, $nightjobs_lang;
        # load localization strings:
        $this->_apptitle = $apptitle;
        if($lang!=='') {
          $folder = dirname(__FILE__);
          if(file_exists("$folder/as_nightjobs_lang.$lang.php")) @include("$folder/as_nightjobs_lang.$lang.php");
        }
        if(isset($nightjobs_lang) && is_array($nightjobs_lang)) {
          if(!isset($as_iface)) $GLOBALS['as_iface'] = $nightjobs_lang;
          else $as_iface += $nightjobs_lang;
          unset($nightjobs_lang);
        }
    }
    public function enableBackup($par = true) {
        $this->do_backup = $par;
    }
    public function SetCommonBackupParameters($opts) {
        $this->_commonBckpParams = $opts;
    }
    /**
    * sets table list for DB operations (optimize, backup)
    *
    * @param array $tblist table list 1 (big tables)
    * @param array $tblist2 table list 2 (small tables)
    */
    public function SetTablesList($tblist, $tblist2=0) {
        global $as_iface;
        if(is_array($tblist)) $this->_tlist = $tblist;
        elseif($tblist==='*') { # '*' means 'ALL tables in current database'
          $this->_tlist = [];
          $tabDta = appEnv::$db->sql_query('SHOW TABLES',1, 0, 1);
          if(is_array($tabDta) && count($tabDta)) {
              foreach($tabDta as $item) $this->_tlist[] = $item[0];
          }
        }
        if(is_array($tblist2)) $this->_tlist2 = $tblist2;
        if (self::$debug) writeDebugInfo("tlist : ", $this->_tlist);
    }

    /**
    * Setting DB backup parameters
    *
    * @param mixed $back_folder folder for saved backup files. If empty, just $nobackup and $prefix,$prefix2 used
    * @param mixed $bckpdays how many days files will be kept
    * @param array $nobackup table list to exclude from backup operation
    */
    public function setBackupParameters($back_folder, $bckpdays=10,$nobackup=null, $prefix='',$prefix2='',$encrypt=NULL)
    {

#        $this->_folder_backups = $back_folder;
        if (!empty($prefix)) $this->_bkp_prefix = $prefix;
        if (!empty($prefix2)) $this->_bkp_prefix2 = $prefix2;
        $this->_keepbackups = $bckpdays;

        if (is_array($nobackup)) $this->_nobackup = $nobackup;

        if ($back_folder) {
            $bckp = array(
                'type'=>'local'
                ,'folder'  => $back_folder
                ,'keepdays'=> $bckpdays
                ,'encrypt' => FALSE
                # ,'nobackup'=> $nobackup
            );
            $this->_bckp_storages = array($bckp); // one and only storage
        }
        if ($encrypt !== NULL) $this->_encrypt = $encrypt;
    }

    public function setEmailServer($srv) {
        $this->_emailserver = $srv;
    }
    /**
    * add a folder to be cleaned from old (temp) files
    *
    * @param mixed $folder folder
    * @param mixed $mask file wildcard
    * @param mixed $days delete files mofified more than N days ago (0=delete all)
    * @param mixed $recursive TRUE|1 value turns ON recursive subfolders cleaning
    * @param mixed $excludeFolders folder names to exclude from recursive cleaning process
    */
    function AddFolderToClean($folder,$mask='',$days=0, $recursive=false, $excludeFolders=FALSE) {
        $this->_cleanfolders[] = [ $folder,$mask,$days, $recursive, $excludeFolders ];
        if(self::$debug) writeDebugInfo("added folder to cleanup: $folder,$mask,days=[$days], recurs=[$recursive], exclude=[$excludeFolders]");
    }
    /**
    * Adding a table to "shrinking" process (deleting obsolete records)
    *
    * @param mixed $tablename table name
    * @param mixed $datefield field in the table that contains date to be tested against current date
    * @param int $daysToLive "Days To Live", after that days records should be deleted. If empty, global parameter used.
    * @param int $condition additional "where" phraze for obsolete records to be deleted, to keep "inmportant" records forever
    */
    function AddTableToShrink($tablename, $datefield, $daysToLive=0, $condition ='') {
        $this->_shrinklist[] = array($tablename, $datefield, $daysToLive, $condition);
    }
    function TableShrinkDays($days) { $this->_shrinkdays = $days; }
    function SetEmailParameters($email,$charset='', $emailfrom='',$subj='') {
        $this->_adminemail = $email;
        if(!empty($charset)) $this->_emailcharset = $charset;
        if(!empty($emailfrom)) $this->_emailfrom = $emailfrom;
        if(!empty($subj)) $this->_msgsubj = $subj;
    }
    /**
    * Adds another backup storage - file folder or remote "Cloud"
    *
    * @param mixed $params associative array,
    * <ul>
    *   <li> 'type' - type of storage: "local" OR "yandex" OR other supported type (having respective plugin)
    *   <li> 'folder' - target folder (path)</li>
    *   <li> 'keep_days'- days to keep old files before swipe them</li>
    *   <li> rest params depend on particular cloud and should contain credentials (Oauth tokens etc.)</li>
    * </ul>
    * Other parameters can be specific for that storage type: 'auth_token' for example
    * @since 1.20
    */
    public function addBackupStorage($storageType, $params=[]) {

        if (!$this->_bckpstorage) {
            include_once('waBckp.php');
            if (!isset($this->_commonBckpParams['tmpfolder'])) $this->_commonBckpParams['tmpfolder'] = $this->_tmpfolder;
            if (!isset($this->_commonBckpParams['encrypt'])) $this->_commonBckpParams['encrypt'] = $this->_encrypt;
            $this->_bckpstorage = new waBckp($this->_commonBckpParams);
        }
        $this->_bckpstorage -> addStorage($storageType, $params);
        $this->_bckp_storages[] = $params;
    }
    /**
    * Add user function to be called BEFORE main job sequence
    *
    * @param mixed $udf - user function name, or object of some class that has a method "scheduledTask"
    * @param mixed $params - parameters to pass user to the function,
    * @since 1.11
    */
    function PrependUserFunction($udf, $params=null) { $this->_jobs1[] = array($udf,$params) ; }
    function AppendUserFunction($udf, $params=null) { $this->_jobs2[] = array($udf, $params); }
    function SetUserFunction($udf,$params=null) { $this->_jobs2[] = array($udf, $params); }

    /**
    * Sets local file name to  save mailed administrative message body (debug purposes)
    * PHP5 required for this feature !
    * @param mixed $fname file name or empty string
    */
    function SaveMessageToFile($fname='') {$this->_savemailedmsg = $fname; }
    /**
    * passes parameters for computing site size and making growth forecast
    *
    * @param string/array $rootfolder Your site "root" folder(s), to count files's size
    * @param string $tablename table name where to save "today values"
    * @param array $dblist if Your site has more than one database, pass it's names list
    */
    function SiteSizeParameters($rootfolder, $sitesizetable='',$maxsitesize=0, $dblist=false) {
        $this->_rootfolder = $rootfolder;
        $this->_sitesizetable = $sitesizetable;
        $this->_maxsitesize = $maxsitesize*1024; # KB to MB
        $this->_dblist = $dblist;
    # TODO $dblist, not used yet
    }
    /**
    * run all tasks and send a report
    */
    function Exec() {
        global $as_iface;
        $this->_errors = [];
        $this->sumDeleted = 0;
        $stitle = isset($as_iface['nj_starttime'])? $as_iface['nj_starttime']: 'Start time';

        $msg = $stitle . ' : '.date('d.m.Y H:i:s')."<hr />";

        $msg .= $this->__RunUDJ($this->_jobs1); # prepended user jobs - run BEFORE main block

        if ($this->_autoclean_stats !=0) $this->__ShrinkStats(); #clean statistic table from "old" records
        if (count($this->_shrinklist)>0) $msg .= $this->ShrinkTables();
        if (count($this->_tlist)>0 || count($this->_tlist2)>0) $msg .= $this->OptimizeTables();

        if (count($this->_cleanfolders)>0) $msg .= $this->CleanFolders();

        if ($this->do_backup && is_object($this->_bckpstorage)) {
            if (count($this->_tlist)>0)
              $msg .= $this->BackupDataTables($this->_tlist, $this->_bkp_prefix);
            if (count($this->_tlist2)>0)
              $msg .= $this->BackupDataTables($this->_tlist2,$this->_bkp_prefix2);
        }

        if(!empty($this->_bckpsiteOptions['rootfolder']) && isset($this->_bckpsiteOptions['extensions'])
          && count($this->_bckpsiteOptions['extensions'])>0) {
            $msg .= $this->BackupSiteFiles();
        }
        if (count($this->_bckp_files)) {
            $this->_saveBackuped();
        }

        if(!empty($this->_rootfolder)) {
            $stitle = isset($as_iface['nj_sitespaceinfo'])? $as_iface['nj_sitespaceinfo']: 'Site space information';
            if($this->_siteSizeMethod === self::M_FREEDISK) {
                # estimate days to overflow eat all disk space
                $forecast = $this->diskSpaceForecast();
                $msg .= "<h4>$stitle</h4>\n$forecast<br>\n";
            }
            else {
                # hosting-provider mode: estimate current disk space vs "allowed space"
                $sitesize = $this->GetCurrentSiteSize();
                if(empty($this->_sitesizetable)) {
                    $stitle = isset($as_iface['nj_sumsitespace'])? $as_iface['nj_sumsitespace']: 'Summary Space occupied by Site (DB and/or files)';
                    $msg .= $stitle.' : '.number_format($sitesize,1)." KB<br>\n";
                }
                else { # get average site growth and forecast "out of space"
                    $sspace = $this->SiteGrowthStatistics();
                    $stitle = isset($as_iface['nj_sitespaceinfo'])? $as_iface['nj_sitespaceinfo']: 'Site space information';
                    if($sspace!='') $msg .= "<h4>$stitle</h4>\n$sspace<br>\n";
                }
            }
        }
        if(!empty($this->_lf_itemlist) && !empty($this->_lf_datatable)) $msg .= $this->LocalFoldersSize();

        $msg .= $this->__RunUDJ($this->_jobs2); # appended jobs - run AFTER main block

        if(count($this->_errors)) {
          $stitle = isset($as_iface['nj_founderrors'])? $as_iface['nj_founderrors'] : 'Found errors while performing job';
          $msg .="$stitle :\n".implode("<br>",$this->_errors);
        }

        $stitle = isset($as_iface['nj_finishtime'])? $as_iface['nj_finishtime']: 'Finished';
        $msg .= "\n<hr />{$stitle} : ".date('d.m.Y H:i:s');

        if(empty($this->_msgsubj)) {
          $subj = $this->_apptitle.(empty($this->_apptitle)?'Site ':', ').' Maintenance job log';
        }
        else {
          $curdt = date('d.m.Y');
          $curtm = date('H-i');
          $subj = str_replace(array('%DATE%','%TIME%'), array($curdt,$curtm), $this->_msgsubj);
        }

        if(!empty($this->_adminemail) && (empty($_SERVER['REMOTE_ADDR']) || !empty($_GET['email']))) {
          if($this->_alarmcode==1) $subj .= '(WARNINGS)';
          elseif($this->_alarmcode>=2) $subj .= '(ALARMS !)';

          $sendmsg = nl2br($msg); # not HTML text, convert CR to <br/>

          if (is_callable('WebApp::sendEmailMessage')) {
              $this->mailsendresult = WebApp::sendEmailMessage(array(
                 'to' => $this->_adminemail
                ,'subj' => $subj
                ,'message' => $msg
              ));
          }
          elseif(!empty($this->_emailserver)) { // send by PHPMailer class

              include_once('class.phpmailer.php');
              $mail = new PHPMailer();
              $mail->Mailer = 'smtp';
              $mail->Host = $this->_emailserver;
              $mail->CharSet = $this->_emailcharset;
              $mail->SetFrom($this->_emailfrom, $this->_emailfrom);
              $mail->AddReplyTo($this->_emailfrom, $this->_emailfrom);

              $tolst = explode(',', $this->_adminemail);
              foreach ($tolst as $toaddr) {
                  $mail->AddAddress($toaddr, $toaddr);
              }

              $mail->Subject = $subj;

              $mail->MsgHTML($sendmsg);
              $this->mailsendresult = $mail->Send();
          }
          elseif (class_exists('Zend_Email')) {
            $zfmail = new Zend_Mail($this->_emailcharset);
            $zfmail->addTo($this->_adminemail);
            $zfmail->setSubject($subj);
            $zfmail->setBodyHtml($sendmsg);
            if($this->_emailfrom) $zfmail->setFrom($this->_emailfrom);
            $this->mailsendresult = $zfmail->send();
          }
          else { // last resort - standard mail() function
            $headers  = 'MIME-Version: 1.0'."\r\nContent-type: text/html; charset={$this->_emailcharset}\r\n";
            if($this->_emailfrom) $headers .= "From: {$this->_emailfrom}\r\n";
            if(stripos($sendmsg,'<html')===false) $sendmsg = "<html><body>$msg</body></html>";
            $this->mailsendresult = @mail($this->_adminemail,$subj,$sendmsg,$headers);
          }

          if(!empty($this->_savemailedmsg) && function_exists('file_put_contents')) {
              file_put_contents($this->_savemailedmsg,$sendmsg);
          }
          if(!$this->mailsendresult) {
            $err = "Sending email to {$this->_adminemail} error !<br>\n"; #debug
            $this->_errors[] = $err;
            echo $err;
          }
        }
        if(!empty($_SERVER['REMOTE_ADDR'])) echo "<h1>$subj</h1>".$msg;
        else {
            echo (date('Y-m-d H:i:s') . ": job done\n"); # CRON/SSH console  will show this text only
            return $msg;
        }
    }
    function GetErrorMessage() {
        return implode('<br>',$this->_errors);
    }
    function cleanFolders() {
      global $as_iface;
      $ret = '';
      foreach($this->_cleanfolders as $ofld) {
          $ret .= $this->cleanFolder($ofld[0],$ofld[1],$ofld[2], $ofld[3], $ofld[4]);
      }
      $stitle = isset($as_iface['nj_cleanfolders'])? $as_iface['nj_cleanfolders']: 'Cleaning folders';
      if($ret!='') {
          $ret = "<h4>$stitle</h4>$ret";
      }
      $sumTitle = $as_iface['syummary_deleted'] ?? 'Freed disk space';
      if($this->sumDeleted > 0) $ret .= "<br>$sumTitle: ".RusUtils::intMoneyView($this->sumDeleted/1024). " KB";
      return $ret;
    }
    function ShrinkTables() {
        global $as_iface;
        $ret = '';
        foreach($this->_shrinklist as $sht) {
            # echo 'shrink element:<pre>'.print_r($sht,1) . '</pre>';
            $ldays = empty($sht[2]) ? $this->_shrinkdays : intval($sht[2]);
            $where = array("TO_DAYS($sht[1])+$ldays<TO_DAYS(NOW())");
            if (!empty($sht[3])) {// additional conditions exists
              if (is_array($sht[3])) $where = array_merge($where,$sht[3]);
              elseif(is_string($sht[3])) $where[] = $sht[3];
            }
            appEnv::$db->delete($sht[0], $where);
            if(appEnv::$db->sql_error()) $this->_errors[] = "ShrinkTables error for $sht[0]: ".appEnv::$db->sql_error();
            else {
              $cnt = appEnv::$db->affected_rows();
              $stitle = isset($as_iface['nj_deletedoldrecords'])? $as_iface['nj_deletedoldrecords']: 'Deleting obsolete records from';
              $sdeleted = isset($as_iface['nj_deleted'])? $as_iface['nj_deleted']: 'deleted';
              $ret .= "$stitle $sht[0], $sdeleted: $cnt.\n";
            }
        }
        return $ret;
    }
    function __ShrinkStats() {
        global $as_iface;
        if(!empty($this->_sitesizetable)) {
          appEnv::$db->sql_query("DELETE FROM {$this->_sitesizetable} WHERE TO_DAYS(logdate)<TO_DAYS(sysdate())-{$this->_autoclean_stats}");
          if(appEnv::$db->affected_rows()) $this->_tlist2[]=$this->_sitesizetable;
        }
        if(!empty($this->_lf_datatable)) {
          appEnv::$db->sql_query("DELETE FROM {$this->_lf_datatable} WHERE TO_DAYS(logdate)<TO_DAYS(sysdate())-{$this->_autoclean_stats}");
          if(appEnv::$db->affected_rows()) $this->_tlist2[]=$this->_lf_datatable;
        }
    }
    # Run User Defined Job - calls functions, whose names passed in array. For internal use
    function __RunUDJ($arr) {
        global $as_iface;
        $ret = '';
        foreach($arr as $fncitem) {
            $fncname = $fncitem[0];
            if(!empty($fncname)) {
            if(is_object($fncname)) {
                if(method_exists($fncname,'scheduledTask')) $ret .= $fncname->scheduledTask($fncitem[1]);
            }
            elseif(is_string($fncname) && is_callable($fncname))
                $ret.= call_user_func($fncname, $fncitem[1])."<br>\n";
        }
        }
        return $ret;
    }
    /**
    * performs data backup from all tables listed in _tlist excluding _nobackup members
    *
    * @param string $prefix - backup filename prefix
    */
    function BackupDataTables($tblist, $prefix='') {

        global $as_iface;
        if (is_file(__DIR__ . '/class.dbbackup.php'))
            include_once(__DIR__ . '/class.dbbackup.php');
        else return FALSE;

        $gzip = 1; # try to make gzipped backup
        $LF = '<br>'; # isset($_SERVER['REMOTE_ADDR']) ? '<br>' : "\n";

        $savefolder = $this->_tmpfolder;
        if (!is_dir($savefolder)) @mkdir($savefolder,0777,true);

        $savename = $savefolder.$prefix.date('Y-m-d').'.xml';
        $stitle = isset($as_iface['nj_backup_data'])? $as_iface['nj_backup_data'] : 'Backup data';
        $ret = "$stitle ...$LF";
        #  appEnv::$db->SetVerbose(true);
        ob_start();
        $bckplist = [];
        foreach($tblist as $tbname) {
          if(!is_array($this->_nobackup) || !in_array($tbname, $this->_nobackup)) $bckplist[] = $tbname;
        }

        $backer = new DbBackup(0, appEnv::$db);

        $bckpOptions = [
            'createcontents' => TRUE,
            'extract_ddl' => TRUE,
        ];
        $result = $backer->backupTables($bckplist,$savename, $bckpOptions);
        # WriteDebugInfo("backup result: ", $result);
        $echoed = ob_get_clean();
        if ($echoed) WriteDebugInfo("echoed output:", $echoed);

        if(file_exists($savename) ) {
          $stitle = isset($as_iface['nj_backup_ok'])? $as_iface['nj_backup_ok'] : 'Backup file created OK';
          $stitle .= ' ('.number_format(filesize($savename)) .' B)';
          $ret .= basename($savename) . " - $stitle $LF";
        }
        else {
          $stitle = isset($as_iface['nj_backup_err'])? $as_iface['nj_backup_err'] : 'Backup file was not created';
          $ret .= basename($savename) . " - $stitle !$LF";
          $this->_alarmcode = max(1,$this->_alarmcode);
        }
        if (is_file($savename)) $this->_bckp_files[] = $savename;
        return $ret;
    }
    /**
    *  cleans up backup folder from files older than $days
    *
    * @param integer $days - days to keep backup files
    */
    function DeleteOldBackups() {
        global $as_iface;
        $ret = '';
        foreach ($this->_bckp_storages as $storage) {
            if (!empty($storage['keepdays'])) {
                $ret .= $logtext = $this->cleanFolder($storage);
            }
        }

        return $ret;
    }
    /**
    * Deletes files in specified folder, by mask (all files if mask empty), older than NN days (if days>0)
    *
    * @param string $storage - folder to clean OR array with backup params
    * @param string $mask - file mask(s) if many - comma delimited: *.bak,*.tmp,...
    * @param mixed $days - delete only files modified more than $days ago (0 means delete ALL)
    * @param mixed $recurs - sets recursive subdirectories cleaning (if integer, sets max.depth: 1=only one level down etc.)
    * @param mixed $exclude - folder names to exclude from recursive cleaning
    * @since 1.20
    */
    function cleanFolder($storage, $mask='', $days=FALSE, $recursive = FALSE, $exclude=FALSE) {

        global $as_iface;
        $deleted = 0;
        $excList = [];
        if(!empty($exclude)) {
            if(is_string($exclude)) $excList = preg_split("/[ ,;]/",$exclude, -1, PREG_SPLIT_NO_EMPTY);
            elseif(is_array($exclude)) $excList = $exclude;
        }
        $titleok = isset($as_iface['nj_deleted'])? $as_iface['nj_deleted'] : 'deleted';
        $titleer = isset($as_iface['nj_delete_err'])? $as_iface['nj_delete_err'] : 'deleting error !';
        if (is_string($storage)) { # just a folder name
            $folder = $storage;
            $stype = 'local';
            if ($days===FALSE) $days = self::DEFAULT_KEEP_DAYS;
        }
        elseif (isset($storage['folder'])) {
             $folder = $storage['folder'];
             $days = isset($storage['keepdays']) ? $storage['keepdays'] : 0;
             $stype = $storage['type'];
        }
        if ($days== FALSE) $days = isset($storage['keepdays']) ? $storage['keepdays'] : self::DEFAULT_KEEP_DAYS;

        if ($days <=0) return '';
        $ret = '';

        if (empty($mask)) $mask = '*';
        if(floor($days) != $days) $dateString = '-' . round($days*24,2). ' hours'; # non-integer days convert to hours
        else $dateString = "-$days days";
        $watermark = date('Y-m-d H:i', strtotime($dateString));
        if(self::$debug) writeDebugInfo("clean folder $folder, clean date: $watermark");
        if($this->fileCleanVerbose) $ret .= "Clean folder: $folder, recursive:[$recursive], max.date $watermark<br>\n";
        $ret = '';
        if ($stype === 'local') {
            $darr = [];
            if(substr($folder,-1) == '/'){ $folder = substr($folder,0,-1);  }
            if(empty($mask)) {
                $mask = $this->_bkp_prefix .'*.*';
                if (!empty($this->_bkp_prefix2) && $this->_bkp_prefix2 !== $this->_bkp_prefix)
                    $mask .= ',' . $this->_bkp_prefix2 . '*.*'; # {gl1*.*,gl2*.*} - both masks included
            }

            if (is_dir($folder)) {
              foreach (glob($folder.'/{'.$mask.'}', GLOB_BRACE) as $filename) {
                if ( is_file($filename) && date('Y-m-d H:i', filemtime($filename)) < $watermark ) {
                  $darr[] = $filename;
                }
              }
            }

            if(count($darr)) foreach($darr as $fname) {
              $fSize = filesize($fname);
              if(self::$emulateDelFiles) $ok = TRUE; # temp emulate clean
              else $ok = unlink($fname);
              if($ok) $this->sumDeleted += $fSize;
              if($this->fileCleanVerbose >= 4 || !$ok)
                $ret .= $fname . ' ' . ($ok? $titleok : $titleer)."<br>\n";
            }
            # if ($ret) $ret = "$folder : <br>$ret";
            # recursive cleanup in sub-folders
            if ($stype = 'local' && $recursive) foreach(glob("$folder/*") as $dirElem) {
                if (is_dir($dirElem)) {
                    $baseDirName = basename($dirElem);
                    if(in_array($baseDirName, $excList)) {
                        if($this->fileCleanVerbose>0) $ret .= "<br>skipped subfolder : $dirElem";
                        continue;
                    }

                    $ret .= $this->cleanFolder($dirElem, $mask, $days, $recursive, $excList);
                }
            }
        }

        /*
        else {

            $storageObj = waBckp::createClient($storage['type'], $storage);
            WriteDebugInfo("gonna clean by WaBckp type ".$storage['type']);
            $storageObj -> cleanFolder();
        }
        */
        return $ret;
    }

    /**
    * calls OPTIMIZE TABLE/ANALYZE TABLE for all tables listed in tlist or in internal vars _tlist,_tlist2
    *
    * @param mixed $tlist - if array passed, tables from it will be optimized, otherwise - from class variable _tlist
    */
    function OptimizeTables($tlist=FALSE){
        global $as_iface;
        $cnt = 0;
        if(is_array($tlist)) foreach($tlist as $tname) {
          appEnv::$db->sql_query("OPTIMIZE TABLE $tname");
          appEnv::$db->sql_query("ANALYZE TABLE $tname");
          $cnt++;
        }
        else {
          foreach($this->_tlist as $tname) {
            appEnv::$db->sql_query("OPTIMIZE TABLE $tname");
            appEnv::$db->sql_query("ANALYZE TABLE $tname");
            if (self::$debug) writeDebugInfo("$tname optimized/analyzed");
            $cnt++;
          }
          foreach($this->_tlist2 as $tname) {
            appEnv::$db->sql_query("OPTIMIZE TABLE $tname");
            appEnv::$db->sql_query("ANALYZE TABLE $tname");
            if (self::$debug) writeDebugInfo("$tname optimized/analyzed");
            $cnt++;
          }
        }
        $stitle = isset($as_iface['nj_optimized_tables'])? $as_iface['nj_optimized_tables']: 'Optimized tables';
        return "$stitle : $cnt<br>";
    }
    /**
    * cleanup sessions folder from old session data files
    * Files older than 1 days are deleted
    */
    function CleanupSessions() {
        global $as_iface;
        $foldsess = ini_get('session.save_path');
        $ret = '';
        if(!empty($foldsess) && is_dir($foldsess)) {
          $this->CleanFolder($foldsess,'',1);
          $ret = (isset($as_iface['nj_sessions_cleaned'])? $as_iface['nj_sessions_cleaned']: 'Sessions folder cleaned') . "<br>\n";
        }
        return $ret;
    }
    /**
    * Computes current site size (KB in MySQL and KB in files)
    * and saves into special table (for analysis needs)
    * @returns number - summary site size (KiloBytes)
    */
    function GetCurrentSiteSize() {
        global $as_iface;
        if($this->_siteSizeMethod === self::M_FREEDISK) {
            # estimate based on free disk space
            if(is_string($this->_rootfolder)) $sz_file = disk_free_space($this->_rootfolder);
            elseif(is_array($this->_rootfolder)) {
                $sz_file = 0;
                foreach($this->_rootfolder as $folder) { $sz_file += disk_free_space($folder); }
            }
            $sz_file = round($sz_file/1024,1); # make KBytes
        }
        else {
            # estimate based on max "site size" and curent occupierd size (hosting provider case)
            if(is_string($this->_rootfolder)) $sz_file = $this->GetFolderSize($this->_rootfolder);
            elseif(is_array($this->_rootfolder)) {
                $sz_file = 0;
                foreach($this->_rootfolder as $folder) { $sz_file += $this->GetFolderSize($folder); }
            }
            $sz_file=round($sz_file/1024,1); # make KBytes
        }

        # count current database(s) size
        $sz_db = 0;

        if(is_array($this->_dblist) && count($this->_dblist)>0) {
          foreach($this->_dblist as $dbname) { $sz_db += $this->GetDatabaseSize($dbname); }
        }
        else { $sz_db = $this->GetDatabaseSize($this->_dblist); }
        $sz_db = round($sz_db/1024,1); # convert to KB

        if(!empty($this->_sitesizetable)) {

            $tExist = appEnv::$db->IsTableExist($this->_sitesizetable);
            if(!$tExist) { # auto-create table if not exist
                appEnv::$db->sql_query("CREATE TABLE {$this->_sitesizetable}
                ( rid BIGINT NOT NULL AUTO_INCREMENT,
                rdate DATE not null default 0,
                sizefile DECIMAL(14,1) NOT NULL DEFAULT 0,
                sizedb DECIMAL(14,1) NOT NULL DEFAULT 0,
                freespace DECIMAL(14,1) NOT NULL DEFAULT 0,
                primary key(rid))
                ");
                # writeDebugInfo("create table SQL:", appEnv::$db->getLastQuery());
                if($err = appEnv::$db->sql_error()) {
                    $this->_errors[] = "Error creating site statistic table {$this->_sitesizetable}: ".$err;
                    return 0;
                }
            }
            $today = date('Y-m-d');
            $recid = appEnv::$db->GetQueryResult($this->_sitesizetable,'rid',"rdate='$today'");

            if($this->_siteSizeMethod === self::M_FREEDISK) {
                $arData = [
                    'rdate' => $today,
                    'freespace' => $sz_file,
                    'sizedb' => $sz_db,
                ];
            }
            else {
                $arData = [
                    'rdate' => $today,
                    'sizefile' => $sz_file,
                    'sizedb' => $sz_db,
                    'sizefile' => $sz_file,
                ];
            }

            if(!empty($recid)) appEnv::$db->update($this->_sitesizetable, $arData, ['rid'=>$recid]);
            else appEnv::$db->insert($this->_sitesizetable, $arData);
            if($errTxt = appEnv::$db->sql_error()) {
                # writeDebugInfo("add info SQL: ", appEnv::$db->getLAstQuery(), ' ERR: ', $errTxt);
                $this->_errors[] = "GetCurrentSiteSize update data error : ".$errTxt;
            }
        }
        if($this->_siteSizeMethod === self::M_FREEDISK) return $sz_file; # returns current free disk space, KB
        else return ($sz_file+$sz_db);
    }
    /**
    * calculates estimated time to full disk alarm
    * @since 1.30 2023-11-29
    * based on free space statistics
    * @param mixed $curSpace - curent free space
    */
    public function diskSpaceForecast($curSpace= 0) {
        global $as_iface;
        if(!$curSpace)
            $curSpace = $this->GetCurrentSiteSize();
        $today = date('Y-m-d');
        $wseekAgo = date('Y-m-d', strtotime('-7 days'));
        $arData0 = AppEnv::$db->select($this->_sitesizetable, ['where'=>"rdate<'$today'", 'orderby'=>'rdate','singlerow'=>1]);
        $arDataWk = AppEnv::$db->select($this->_sitesizetable, ['where'=>"rdate<='$wseekAgo'", 'orderby'=>'rdate DESC','singlerow'=>1]);

        $found = FALSE;
        $spaceKBf = number_format($curSpace, 0, '.', ' ');
        $minRestDays = FALSE;
        $ret = [ "Свободное место на диске: $spaceKBf KB." ];

        if(isset($arData0['rdate']) ) {
            $found = TRUE;
            if($arData0['freespace'] > $curSpace) {
                $days = DiffDays($arData0['rdate'], $today);
                $deltaKB = $arData0['freespace'] - $curSpace;
                $perDay = $deltaKB / $days;
                $minRestDays = $restDays = round($curSpace / $perDay);
                # $ret[] = ("perDay: $perDay = : deltaKB($deltaKB) / days($days)");
                # $ret[] = ("rest days: $restDays = cueSpace ($curSpace) / perDay ($perDay)");
                $deltaKBf = number_format($deltaKB, 0, '.', ' ');
                $ret[] = "По динамике (- $deltaKBf KB) с ".to_char($arData0['rdate'])
                  . ' место закончится через ' . \RusUtils::verboseTimeInterval($restDays);
                $this->_bSpaceReported = TRUE; # avoid freediskspace
            }
            elseif($arData0['freespace'] < $curSpace) {
                $deltaKB = $curSpace - $arData0['freespace'];
                $deltaKBf = number_format(abs($deltaKB), 0, '.', ' ');
                $ret[] = "C ".to_char($arData0['rdate'])
                  . " свободное место увеличилось на $deltaKBf KB";
            }
        }
        # если есть вторая оценка, по росту в последнюю неделю
        if(isset($arDataWk['rdate']) && $arDataWk['rdate'] > $arData0['rdate']) {
            $found = TRUE;
            if($arDataWk['freespace'] > $curSpace) {
                $days = DiffDays($arDataWk['rdate'], $today);
                $deltaKB = $arDataWk['freespace'] - $curSpace;
                $perDay = $deltaKB / $days;
                $restDays = round($curSpace / $perDay);
                if($minRestDays === FALSE)
                    $minRestDays = $restDays;
                else $restDays = min($restDays, $restDays);
                $deltaKBf = number_format($deltaKB, 0, '.', ' ');
                $ret[] = "По данным за неделю (- $deltaKBf KB)"
                  . " место закончится через ". RusUtils::verboseTimeInterval($restDays);
            }
            elseif($arData0['freespace'] < $curSpace) {
                $deltaKB = $curSpace - $arDataWk['freespace'];
                $deltaKBf = number_format($deltaKB, 0, '.', ' ');
                $ret[] = "За неделю свободное место увеличилось на $deltaKBf KB";
            }
        }
        if($minRestDays !== FALSE && $minRestDays <= 10) {
            $alarmDays = RusUtils::skloNumber($minRestDays, 'день');
            $ret[] = "<b>Внимание! Место на диске может закончиться через $alarmDays !</b>";
            AppAlerts::raiseAlert('DISKSPACE',"Срочно нужно освободить место на диске, осталось $alarmDays!");
        }
        else
            AppAlerts::resetAlert('DISKSPACE',"Спасибо, свободное место на диске было увеличено");

        if(!$found) return "Для оценки динамики использования диска не накоплено данных";

        if(count($ret)<2) $ret[] = 'Занятое место на диске за последний период не уменьшилось';
        return implode("<br>\n", $ret);
        # return "TODO: estimate time to Disk Alarm ($curSpace) first data:<pre>".print_r($arData0,1).print_r($arDataWk,1).'</pre>';
    }

    /**
    * Shows current site space, "day" grouth and estimated days to reach space limit
    */
    function SiteGrowthStatistics() {
        global $as_iface;
        # compute estimated days before site (database + files) reaches provider's limit
        $lnk= appEnv::$db->sql_query("SELECT rdate, TO_DAYS(rdate) days, sizefile, sizedb FROM {$this->_sitesizetable} ORDER BY rdate DESC LIMIT 0,5");
        $dt=[]; $ii=0;
        while(($lnk) && ($dta=appEnv::$db->fetch_assoc($lnk))) { $dt[$ii++] = $dta; }
        if(count($dt)<1) return ''; # no data for estimation
        $lastsize = $dt[0]['sizefile']+$dt[0]['sizedb'];
        $loctxt = isset($as_iface['nj_site_space_kb']) ? $as_iface['nj_site_space_kb'] : 'Space occupied by site, KB';
        $ret = $loctxt . ' : ' . number_format($lastsize,1)."<br>\n";
        if($lastsize>$this->_maxsitesize) {
          $this->_alarmcode = max($this->_alarmcode,2);
          $ret .= (isset($as_iface['nj_alarm_site_out_of_space']) ? $as_iface['nj_alarm_site_out_of_space'] :
            'ALARM : site is out of space : current limit is ').number_format($this->_maxsitesize)." KB !<br>\n";
        }
        if(count($dt)<2) return $ret; # no data for estimation
        $delta_f = $dt[0]['sizefile'] - $dt[1]['sizefile'];
        $delta_d = $dt[0]['sizedb'] - $dt[1]['sizedb'];
        $delta = $delta_f+$delta_d;
        $ret .= (isset($as_iface['nj_growth_files']) ? $as_iface['nj_growth_files'] :'Growth in files, KB: ').number_format($delta_f,1)."<br>\n";
        $ret .= (isset($as_iface['nj_growth_db']) ? $as_iface['nj_growth_db'] :'Growth in DB, KB: ').number_format($delta_d,1)."<br>\n";
        $ret .= (isset($as_iface['nj_growth_sum']) ? $as_iface['nj_growth_sum'] :'Summary Growth, KB: ').number_format($delta,1)."<br>\n";
        if($this->_maxsitesize>0 && $lastsize < $this->_maxsitesize) {
          $lastn = count($dt)-1;
          $sumkb = $dt[0]['sizefile']+$dt[0]['sizedb'] - $dt[$lastn]['sizefile'] - $dt[$lastn]['sizedb'];
          $sumd = $dt[0]['days'] - $dt[$lastn]['days'];
          $kb_per_day = ($sumd>0)? $sumkb/$sumd : 0; # average growth per day
          if($kb_per_day>0) {
            $remainspace = $this->_maxsitesize - $lastsize;
            $remaindays = round($remainspace/$kb_per_day,0);
            if($remaindays <= $this->_sizethreshold) {
              $this->_alarmcode = max($this->_alarmcode,1);
              $ret .="WARNING : ";
            }
            $templt = isset($as_iface['nj_site_remain_days']) ? $as_iface['nj_site_remain_days'] :'Your site may go out of space (%maxspace% KB) in %remaindays% days';
            $ret .= str_replace(array('%maxspace%','%remaindays%'),array($this->_maxsitesize,$remaindays),$templt)."<br>\n";
        #        "Your site may go out of space (".number_format($this->_maxsitesize)." KB) in $remaindays days\n";
        #        if(date('Y-m-d')!=$dt[0]['rdate']) $ret .= "(Stats computed for ".$dt[0]['rdate'].")\n";
            $cnt = count($dt);
            $date0 = $dt[$cnt-1]['rdate'];

            $templt = isset($as_iface['nj_site_avg_growth']) ? $as_iface['nj_site_avg_growth'] :'Average growth per day since %sincedate% : %avggrowth% KB/day';
            $ret .= str_replace(array('%sincedate%','%avggrowth%'),array($date0,number_format($kb_per_day,1)),$templt)."<br>\n";
        #        $ret .= "(Average growth per day since $date0, KB/day : ".number_format($kb_per_day,1).")\n";
          }
        }
        return $ret;
    }
    /**
    * counts current database size in KB, by summing all tables sizes (MySQL only !)
    * @returns number (Bytes)
    */
    function GetDatabaseSize($dbname = '') {
        if($dbname === '*') {
            # count summary of ALL existing databases (if i have select from information_schema.tables privilege
            # SELECT SUM(data_length + index_length) summa FROM information_schema.tables
            $result = AppEnv::$db->select('information_schema.tables',
               ['fields'=>'SUM(data_length + index_length) summa',
                 'singlerow'=>1, 'associative'=>0]);
            return $result;
        }
        $curdb = appEnv::$db->CurrentDbName();
        if(!empty($dbname)) {
          $result=appEnv::$db->select_db($dbname);
          if(!$result) {
            $this->_errors[] = "GetDatabaseSize error while changing to DB $dbname: ".appEnv::$db->sql_error();
            return 0;
          }
        }
        $lnk =appEnv::$db->sql_query("SHOW TABLE STATUS");
        $ret = 0;
        while(!empty($lnk) && ($r=appEnv::$db->fetch_assoc($lnk))) {
            if(isset($r['Data_length'])) $ret +=intval($r['Data_length']);
        }
        if(!empty($lnk)) appEnv::$db->free_result($lnk);
        if(!empty($dbname)) appEnv::$db->select_db($curdb); # get back !
        $db2 = appEnv::$db->CurrentDbName();
        return $ret;
    }

    /**
    * counts summary size for passed folder, or for root folder no parameter passed
    * @returns number (Bytes)
    * based on source from on http://www.weberdev.com/get_example-4561.html
    */
    function GetFolderSize($d ='.') {
        # © kasskooye and patricia benedetto
        $sf=0;
        $h = @opendir($d);
        if($h==0)return 0;
        while ($f=readdir($h)){
          if ( $f!= '..') {
            $sf+=filesize($nd=$d.'/'.$f);
            if($f!='.' && is_dir($nd)){
              $sf += CNightJobs::GetFolderSize($nd);
            }
          }
        }
        closedir($h);
        return $sf ;
    }
    /**
    * Activates "Local Folders/files size monitoring
    *
    * @param string $cfgtable
    * @param string $datatable
    */
    function MonitorLocalFolders($cfgtable,$datatable) {

        if(is_array($cfgtable)) $this->_lf_itemlist=$cfgtable;
        elseif(is_string($cfgtable)) {
          $this->_lf_itemlist = appEnv::$db->GetQueryResult($this->_lf_itemlist,'id,itemname,filepath,diskpath','(1) ORDER BY id',1,0);
        }
        $this->_lf_datatable = $datatable;
    }
    /**
    * computes and makes a snapshot of a folder(s) size on local or network host(s)
    * configuration table name must be set in $_lf_itemlist member variable
    */
    function LocalFoldersSize() {
        global $as_iface;
        #    appEnv::$db->Log(1);
        if(!is_array($this->_lf_itemlist) || count($this->_lf_itemlist)<1) return;
        $hdr = isset($as_iface['nj_title_localfolder']) ? $as_iface['nj_title_localfolder'] : 'Local/network folders monitoring';
        $ret = "<h3>$hdr</h3>\n";
        foreach($this->_lf_itemlist as $fobj) {
          if(count($fobj)<3) continue;
          $itemid = $fobj[0];
          $itemname = $fobj[1];
          $filesize = $this->LocalDirSize($fobj[2]);
          $freespace = empty($fobj[2])? 0 : disk_free_space($fobj[3])/1024;
          $today = date('Y-m-d');
          if(!appEnv::$db->IsTableExist($this->_lf_datatable)) {
            appEnv::$db->sql_query("CREATE TABLE ".$this->_lf_datatable.
            "(recid BIGINT not null auto_increment, itemid INT(12) not null default 0, logdate DATE not null default 0,
             filesize BIGINT default 0, freespace BIGINT default 0, primary key(recid),KEY ix_itemid (itemid),KEY ix_logdate (logdate))");
             $ret .= "internal table {$this->_lf_datatable} created<br>\n";
          }
          $existid = appEnv::$db->GetQueryResult($this->_lf_datatable,'recid',"itemid='$itemid' AND logdate='$today'",0);
          if($existid>0) appEnv::$db->sql_query("UPDATE {$this->_lf_datatable} SET filesize='$filesize',freespace='$freespace' WHERE recid='$existid'");
          else appEnv::$db->sql_query("INSERT INTO {$this->_lf_datatable} (itemid,logdate,filesize,freespace) VALUES ('$itemid','$today','$filesize','$freespace')");
          $firstdate = appEnv::$db->GetQueryResult($this->_lf_datatable,'MIN(logdate)',"itemid='$itemid' AND TO_DAYS(logdate)>=TO_DAYS(NOW())-10",0);
          if(intval($firstdate)==0) $firstdate = appEnv::$db->GetQueryResult($this->_lf_datatable,'MIN(logdate)',"itemid='$itemid' AND logdate<'$today'",0);
        #      echo "firstdate: $firstdate<br>"; #debug
          if(!empty($firstdate) && $firstdate !=$today) {
            $dta = appEnv::$db->GetQueryResult($this->_lf_datatable,'TO_DAYS(now())-TO_DAYS(logdate) days,filesize,freespace',"itemid='$itemid' AND logdate='$firstdate'",0,1);
            $dyn_file = $filesize-$dta['filesize']; # filesize change, KB
            $dyn_space = $freespace-$dta['freespace']; # freespace change, KB
            $days = $dta['days'];
            $stitle = isset($as_iface['nj_lf_curfoldersize'])? $as_iface['nj_lf_curfoldersize'] : 'Current folder/file size: %foldersize% KB, Space growth is %growth% KB since %sincedate%';
            $ret .="[<b>$itemname]</b> : \n".str_replace(array('%foldersize%','%growth%','%sincedate%'),
              array(number_format($filesize),number_format($dyn_file),$firstdate),
              $stitle);
            # Current folder/file size: ".number_format($filesize)." KB, Space growth is ".number_format($dyn_file)." KB since $firstdate\n";
            if($dyn_file>0) {
              $perday = $dyn_file/$days;
              $restdays = floor($dta['freespace']/$perday);
              $stitle = isset($as_iface['nj_lf_days_until_full'])? $as_iface['nj_lf_days_until_full'] :
                'Time before Disk is full %restdays% days (now free space is %freespace% KB, avg speed is %speed% KB/day)';
              $retitem = str_replace(array('%restdays%','%freespace%','%speed%'),
                array($restdays,number_format($dta['freespace']),number_format($perday)),
                $stitle);
              $retitem .= "\n";
              # " Time before Disk is full is <b>$restdays</b> days (now free space is ".number_format($dta['freespace'])." KB, avg speed is ".number_format($perday)." KB/day)\n";
              if($restdays < $this->_sizethreshold) {
                $this->_alarmcode = max($this->_alarmcode,1);
                $retitem = "WARNING : $retitem<br>\n";
              }
              $ret .= $retitem;
            }
          }
        }
        return $ret;
    }
    function LocalDirSize($pathmask) {
        $ret = 0;
        foreach (glob($pathmask, GLOB_BRACE) as $filename) {
          $size = $this->__HugeFileSize($filename);
          $ret +=$this->__HugeFileSize($filename);
        }
        return $ret;
    }
    /**
    * computes filesize even for HUGE files (4GB and greater)
    *
    * @param string $file full file name (can be UNC in Windows environment: \\host\folder$\path\file.ext
    * @return number, size in KB (bytes/1024) or false
    */
    function __HugeFileSize($file){
        if (file_exists($file)){
          if (!(strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'))
            $size = trim(`stat -c%s $file`);
            if($size>0) $size /=1024;
          else{
            $fsobj = new COM("Scripting.FileSystemObject");
            $file = $fsobj->GetFile($file);
            $size = ($file->Size)/1024;
            unset($fsobj);
            return $size;
          }
        }
        return false;
    }

    /**
    * Activates "backup site" task and passes parameters for that
    *
    * @param mixed $options associative array
    * @since 1.10
    */
    function SetBackupSiteOptions($options=null) {
        if(is_array($options)) $this->_bckpsiteOptions = $options;
        else $this->_bckpsiteOptions = [];
        if(!isset($this->_bckpsiteOptions['rootfolder'])) $this->_bckpsiteOptions['rootfolder'] = './';
        if(!isset($this->_bckpsiteOptions['extensions'])) $this->_bckpsiteOptions['extensions'] = self::$bckp_default_extensions;
        if(!empty($this->_bckpsiteOptions['zipbasename'])) $this->_bckpsiteOptions['zipfilename'] = $this->_bckpsiteOptions['zipbasename'].date('Y-m-d').'.zip';
        elseif(empty($this->_bckpsiteOptions['zipfilename'])) {
            $this->_bckpsiteOptions['zipfilename'] = 'site-backup-'.date('Y-m-d-His').'.zip';
            $this->_bckpsiteOptions['zipbasename'] = 'site-backup-';
        }

        if(!isset($this->_bckpsiteOptions['incremental'])) $this->_bckpsiteOptions['incremental'] = false;
    }
    /**
    * Creates zip file containing all site files matching $this->_fileexts extensions list
    *
    */
    function BackupSiteFiles() {
        if(count($this->_bckpsiteOptions['extensions'])<1) return false;
        if(!class_exists('ZipArchive')) {
            $this->_errors[] = 'BackupSiteFiles() failed : ZipArchive not supported !';
            return false;
        }
        $zipname = $this->_tmpfolder . $this->_bckpsiteOptions['zipfilename'];
        $this->zipObj = new ZipArchive;

        $lastdate = ''; # last zip backup creation date/time will be here (incremental backup)
        $opened = $this->zipObj->open($zipname,(ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE));
        if($opened!==true) {
            $errmsg = 'Error : creating Zip file failed, BackupSiteFiles aborted.<br>';
            if($this->_verbosemode) echo $errmsg;
            return $errmsg;
        }

        $files = self::enumFilesByMask($this->_bckpsiteOptions['rootfolder'],$this->_bckpsiteOptions['extensions'],$lastdate);
        if(!count($files)) {
            return 'No site files to backup, task skipped<br>';
        }
        $result = $this->_zipItems($rootfolder,'',$files);
#        echo 'Found files:<pre>'; print_r($files); echo '</pre>';
        # TODO: implement function!

        $comments = <<< EOCOMMENT
************************************************************
* Site modules backup, created by as_nightjobs.php module  *
*        (c) Alexander Selifonov <alex@selifan.ru>         *
*                      www.selifan.ru                      *
************************************************************
EOCOMMENT;

        $this->zipObj->setArchiveComment($comments);
        $this->zipObj->close();

        if (is_file($zipname)) $this->_bckp_files[] = $zipname;

        if(is_string($result)) return $result;
        return 'Site file backup created: '.basename($zipname) . ' ' . number_format(filesize($zipname)) . ' Bytes';
    }

    # Copies created backup files to all registered storages (local folders, clouds) and cleans tmp/
    private function _saveBackuped() {

        $this->_bckpstorage->rotateFiles();
        foreach($this->_bckp_files as $flname) {
            $this->_bckpstorage->addFile($flname);
        };
        $this->_bckpstorage->backup();

        # ... and delete from tmp folder
        foreach ($this->_bckp_files as $bfile) {
            @unlink($bfile);
        }

    }
    private function _zipItems($rootfolder, $relfolder, $items) {
        foreach($items as $item) {
            if(!empty($item['items']) && is_array($item['items']) && count($item['items'])) {
                $newzipfolder = substr($item['name'], strlen($rootfolder));
                if(substr($newzipfolder,0,2)=='./') $newzipfolder = substr($newzipfolder,2);
                $this->zipObj->addEmptyDir($newzipfolder);
                $savefolder = ($rootfolder==='./' ? '': $rootfolder);
                $result = $this->_zipItems($savefolder, $item['name'], $item['items']);
                if(is_string($result)) return $result;
            }
            else {
                if($relfolder!='' && !in_array(substr($relfolder,-1), array('/','\\'))) $relfolder.='/';
                $zipfolder = $relfolder;
                $zipfolder =  substr($zipfolder, strlen($rootfolder));
                if(substr($zipfolder,0,2)=='./') $zipfolder = substr($zipfolder,2);
                $result = $this->zipObj->addFile($relfolder.$item['name'], $zipfolder.$item['name']);
                if(!$result) {
                    $errmsg = 'Adding file '.$relfolder.$item['name']. ' to Zip failed, task aborted';
                    if($this->_verbosemode) echo $errmsg.'<br>';
                    return $errmsg;
                }
                if($this->_verbosemode) echo $zipfolder.$item['name'] . ' added to ZIP<br>';
            }
        }
        return true;
    }

    static function enumFilesByMask($rootfolder,$extensions=false, $startdate='') {
        $ret = [];
        $extlist = is_array($extensions) ? $extensions : [];
        if( !is_dir($rootfolder) OR count($extlist)<1 ) {
            $this->_errors[] = "enumFilesByMask: $rootfolder not a folder";
            return $ret;
        }

        $folder = $rootfolder;
        $dir = new DirectoryIterator($folder);
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot()) continue;
            if($fileinfo->isDir()) {
#                if(in_array($fileinfo->getFilename(), self::$_excludedDirs)) continue;
                $subfolder = $folder . ((substr($folder,-1)=='/' or substr($folder,-1)=='\\')?'':'/').$fileinfo->getFilename();
                $items = self::enumFilesByMask($subfolder,$extensions,$startdate);
                if(is_array($items) && count($items)) $ret[] = array('name'=>$subfolder, 'items'=>$items);
            }
            elseif($fileinfo->isFile()) {
                $fname = $fileinfo->getFilename();
                $fileext = strtolower(substr(strrchr($fname, '.'), 1));
                $filestrtime = date('Y-m-d H:i:s',$fileinfo->getMTime());
                if(!in_array($fileext, $extlist)) continue;
                if($filestrtime>=$startdate)
                  $ret[] = array('name'=>$fname, 'date'=>$filestrtime,'size'=>$fileinfo->getSize());
            }
        }
#        echo '<pre style="padding:4px; border:1ps solod #ccc;">';print_r($ret);echo '</pre>';
        return $ret;
    }

    /**
    * Deleting obsolete files from registered cloud storages
    *
    */
    public function cleanClouds() {
        $ret = [];

        foreach ($this->_cloud_storages as $cldef) {
            # TODO: implement:
        }
        return $ret;
    }
} # end of CNightJobs class def.
