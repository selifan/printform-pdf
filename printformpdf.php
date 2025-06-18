<?php
/**
* @name printformpdf.php, contains class PrintFormPdf,
* for generating "on the fly" PDF document populated with user data, possibly from source pdf template file,
* loaded configuration from XML file or string.
* @uses TCPDF, FPDF, TCPDI classes for reading/writing pdf body, see http://www.tcpdf.org/
* @Author Alexander Selifonov, <alex [at] selifan {dot} ru>
* @version 1.86.001 2025-06-18
* @Link: https://github.com/selifan/prinformpdf
* @license http://www.opensource.org/licenses/bsd-license.php    BSD
*
**/
require_once('tcpdf/tcpdf.php');
require_once('tcpdi/tcpdi.php'); # New parser with PDF 1.5 support
class PRINTPDFCONST {
   # static $currentPageNo = 0;
   const PF_ADDPAGE = '{NP}'; # pseudo field in grid, command "add New Page" (NP)
}
/**
* Abstract class for PrintFormPDF plugins - modules that will draw by specific algorhytms in desired rectangle regions
* @since 1.1.0016 (2013-01-20)
*/
abstract class PfPdfPlugin {

    protected $_error_message = '';
    protected $_region_pos = [0, 0];
    protected $_region_dim = [0, 0];
    abstract public function __construct($tcpdfobj, $cfg = null, $x=0,$y=0,$w=0,$h=0);
    # Render method should draw something according to passed _data,  inside defined rectangle area (_region_pos, _region_dim)
    abstract public function Render($data);

    public function setAreaPosition($x, $y, $w=0, $h=0) {
        $this->_region_pos = array(floatval($x),floatval($y));
        $this->_region_dim = [ floatval($w),floatval($h) ];
        return $this;
    }
    public function getErrorMessage() { return $this->_error_message; }
}

class PrintFormPdf {
    const VERSION = '1.86.001';
    const DEFAULT_MARGIN = 5;
    const DEFAULT_GRID_ROWS = 30;
    const DEFAULT_GRID_STEPY = 6;
    const BOTTOM_H = 5; # Minimal height for "bottom" area for page numbers etc.
    const PAGINATION_FONTSIZE = 8; # Font size for page numbers
    static public $MIN_ZIGZAG_WEIGHT = 6;
    static $DEBPRINT = FALSE;
    # auto add LF char if text field has with align="J" (avoid stupid spreading of last line)
    static $autoLFJustify = TRUE;

    private $currentPageNo = 1;
    static $debug = 0;

    public $cfgId = 0;
    public $father = NULL;

    static $errorMessages = [];
    protected $childDefs = []; # child definitions appended by AppendPageDefFromXml()
    protected $_data = [];
    protected $_dataBlock = NULL;
    protected $pageNoModifier = 0;
    public $dataentity = []; # data for single document (one element from _data beeing printed)
    protected $pageSubData = []; # data for the page passed as sub-array
    protected $_pageno = 0;
    protected $_pluginData = [];
    protected $_basepar = [];
    protected $_tmpBasepar = FALSE;
    protected $_paginatonMode = FALSE;
    public $_templatefile = []; # source file(s) to be used as template
    public $tpPages = []; # pairs "pdf-file, pageNo" for each page
    protected $_alttemplatefile = []; # alternative template
    protected $_outname = '';
    protected $defaultTextColor = 0;
    protected $pageSpecs = [];
    protected $_pdf = null;
    protected $pgno = 0;
    protected $prnPage = 0; // printed page number
    protected $_tofile  = false;
    protected $_configfile = '';
    public $datasource = FALSE; # if set, array '_datasource_id' will hold N rows data to create N printed documents
    protected $_config = [];
    protected $_pagedefs = [];
    protected $appendPdfs = []; # list of DBF to append pages from, come from appendpdf tags
    protected $_errormessage = '';
    protected $_alttemplate = false;
    protected $_rulerStep = false;
    protected $_rulerColor = array(80,80,200);
    protected $_compression = false; # compress result PDF
    protected $offsets = array(0,0); # for fine tuning field positions, depending on user printer
    protected $_printedfields = false;
    protected $_datagrids = [];
    protected $_flextables = [];
    protected $_curGridRow = [];
    protected $_specialPages = 0;
    protected $_mpgrid = FALSE; # becomes TRUE if multi-paging grid detected
    protected $_hide_pages = [];
    protected $_hide_fields = []; # field list to be hidden
    protected $_field_newattr = []; # "dynamically changed" field attribs array

    protected $_fillHiBlocks = FALSE; # in testing mode, fill text fields with non-empty height
    protected $_fillColors = [ '#DDF','#FDF','#FDD','#DDD' ]; # colors for filling HiBlocks
    # protected $_fillColors = [ [230,230,230],[230,230,255] ]; # colors for filling HiBlocks

    protected $_images_disabled = false;
    public $homeDir = ''; # directory where loaded XML file resides
    protected $_pdf_path = ''; # PDF templates folder
    protected $_img_path = ''; # images folder
    protected $_curSrcPdf = '';
    protected $_curSrcFile = -1, $_curSrcPage=-1, $_curSrcPgcount=-1;

    protected $_apFields = []; # fields to draw on All Pages
    protected $_apFinally = FALSE; # if TRUE, AllFields should be rendered last
    protected $_apValues = []; # values for All Pages fields
    protected $_tmpFldNo = 0;
    # "ALL pages" fields in "added" page definitions:
    protected $_subApFields = [];
    protected $_subApValues = [];

    protected $spot_colors = [];
    protected $_debug = 0;
    protected $_outputMode = 'I';
    static protected $_cached = [];
    protected $adaptive_sizing = 0; # auto-change font size to make text fit in area (width * height)
    protected $signFunc = false; # external function for instant "signing" generated PDF
    protected $userParams = []; # to store data from <userparams> block
    protected $hooks = [];
    protected $callbackObj = NULL;
    public $blockdefs = []; # Data block definitions
    public $dataBlocks = []; # data blocks on the pages
    public static function getVersion() {
        return self::VERSION;
    }

    public function __construct($param='', $callbackObj=FALSE, $father = NULL, $subid = NULL, $arSubst = []) {
        if(is_callable(self::$debug)) self::$debug("__construct call, param: ", $param, "\n   trace: ",debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));
        if (is_object($callbackObj)) {
            $this->callbackObj = $callbackObj;
        }
        if(is_object($father)) $this->father = $father;
        else {
            $this->father = $this;
        }

        $this->_basepar = array(
          'page'     => array('orientation'=>'P', 'size'=>'A4', 'units'=>'mm')
          ,'font'    => array('name'=>'arial', 'size'=>8, 'color'=> '', 'style'=>'')
          # style: TCPDF styles: I-italic, B-bold(if supported in font), U-underline, O-overline
          ,'margins' => array('left'=>0, 'top'=>0, 'right'=>0, 'bottom'=>0)
          ,'pgprefix'=> '_page' # prefix for "specific" data in 'pageNN'
        );
        $this->_config = array(
             'subject' => ''
            ,'author'  => ''
            ,'creator' => ''
            ,'protectfile' => false
        );
        $xmlPath = '';
        if(is_array($param)) {

            if(isset($param['template'])) {
                $this->_templatefile[] = array('src'=>(string) array($param['template']));
                if(is_callable(self::$debug)) {
                    self::$debug("PDF as source: ", $param['template'], "exist:" . (is_file($param['template'])?'Y':'NO'));
                }
            }
            if(isset($param['alttemplate'])) $this->_alttemplatefile[] = (string)$param['template'];
            if(isset($param['outname']))  $this->_outname= $param['outname'];
            if(isset($param['output']))  $this->_outputMode = $param['output'];
            if(!empty($param['compression']))  $this->_compression = true;
            if(isset($param['tofile']))    $this->_tofile  = $param['tofile'];
            if(isset($param['configfile'])) {
               $this->_configfile  = $param['configfile'];
               if(is_callable(self::$debug)) self::$debug("set configfile to ", $param['configfile']);
            }
            if(isset($param['subject'])) $this->_config['subject'] = (string)$param['subject'];
            if(isset($param['description'])) $this->_config['description'] = (string)$param['description'];
            if(isset($param['author'])) $this->_config['author'] = (string)$param['author'];
            if(isset($param['creator'])) $this->_config['creator'] = (string)$param['creator'];
            if(isset($param['stringcharset'])) {
                $this->_config['stringcharset'] = self::parseParam((string)$param['stringcharset']);
            }
            if(isset($param['datasource'])) $this->datasource = (string)$param['datasource'];
            if(isset($param['pdfpath']))
                $this->_pdf_path = (string)$param['pdfpath'];
            if(isset($param['imgpath']))
                $this->_img_path = (string)$param['imgpath'];

            # callback function to be called after last page done:
            if(isset($param['hook_end']))
                $this->hooks['hook_end'] = (string)$param['hook_end'];
        }
        elseif(is_string($param)) { # configuration XML filename or whole XML string was passed
            $this->_configfile = $param;
        }
        if(!empty($this->_configfile)) {
            $this->homeDir = dirname($this->_configfile) .'/';
            if ($this->_pdf_path == '') $this->_pdf_path = $this->homeDir;
            if ($this->_img_path == '') $this->_img_path = $this->homeDir;
            $ok = $this->LoadConfig(NULL, $subid, $arSubst);
        }

        $this->_pageno = 0;
        # if hook function name passed, save to call after PDF generation finished
        if (!empty($param['hook_end'])) $this->hooks['hook_end'] = $param['hook_end'];
        # if($printMe) writeDebugInfo("printPdf Object: ", $this);
    }

    public function setCallbackObject($callbackObj) {
        $this->callbackObj = $callbackObj;
    }

    public function setOutput($out = 'I') {
        $this->_outputMode = $out;
    }
    // setting signing function for final PDF body
    public function addSignFunction($funcName) {
        $this->signFunc = $funcName;
    }

    /**
    * Loads configuration from prepared XML file, see it's format in docs and examples
    *
    * @param mixed $cfgname full path-filename to config XML file
    * @Returns true if configuration successfully loaded, false otherwise
    */
    public function LoadConfig($cfgname=null, $operSubid = '', $arSubst=[]) {

        $this->_alttemplate = false;
        if(!$cfgname) $cfgname = $this->_configfile;
        else $this->_configfile = $cfgname;

        $cfgPath = dirname($this->_configfile);

        if(is_callable(self::$debug)) {
            self::$debug(">>> LoadConfig(cfg:'$this->_configfile', subid:'$operSubid') subst: ", $arSubst);
            self::$debug("LoadConfig($cfgname), trace: ", debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3));
        }

        $ret = true;

        if(self::$DEBPRINT) echo ("$this->_configfile, incrementing cfgId from ".$this->father->cfgId.'<br>');
        $this->father->cfgId++;

        if(is_file($cfgname)) {
            if(is_array($arSubst) && count($arSubst)) {
                $xmlBody = strtr(file_get_contents($cfgname), $arSubst);
                $xml = @simplexml_load_string($xmlBody);
            }
            $xml = @simplexml_load_file($cfgname);
        }
        elseif(substr($cfgname,0,5)=='<'.'?xml') {
            $xml = @simplexml_load_string($cfgname);
            $cfgname='';
        }
        else {
            $this->_errormessage = 'Configuration XML file not found:  '.$cfgname;
            return false;
        }

        $homePath = dirname(realpath($this->_configfile)) . '/'; # folder of parsed XML config file

        if(!($xml) OR !@isset($xml->pages)) {
            $this->_errormessage = 'Wrong XML file or XML string syntax, '.$cfgname;
            echo $this->_errormessage ;
            return false;
        }

        if(isset($xml->description)) $this->_config['description'] = (string)$xml->description;
        if(isset($xml->protectfile)) $this->_config['protectfile'] = (int)$xml->protectfile; # protect with password
        if(isset($xml->password)) {
            $psw = $this->_config['password'] = (string)$xml->password; # password for protect PDF
            if(substr($psw,0,1) === '@' && is_callable($pswFn=substr($psw,1))) {
                $this->_config['password'] = call_user_func($pswFn);
            }
        }
        if(isset($xml->title)) $this->_config['title'] = (string)$xml->title;
        if(isset($xml->stringcharset)) $this->_config['stringcharset'] = strtoupper(self::parseParam($xml->stringcharset));

        # if not UTF-8 and not '', use iconv() to make UTF-8 !
        if(isset($xml->baseparameters->page)) {
            if(isset($xml->baseparameters->page['orientation'])) $this->_basepar['page']['orientation'] = (string)$xml->baseparameters->page['orientation'];
            if(isset($xml->baseparameters->page['size'])) $this->_basepar['page']['size'] = (string)$xml->baseparameters->page['size'];
            if(isset($xml->baseparameters->page['units'])) $this->_basepar['page']['units'] = (string)$xml->baseparameters->page['units'];
        }

        # misc "common" parameters
        if(isset($xml->baseparameters->params)) {
            if(isset($xml->baseparameters->params['pgprefix']))
                $this->_basepar['pgprefix'] = (string)$xml->baseparameters->params['pgprefix'];
            if(isset($xml->baseparameters->params['hideap'])) { # HIDE All Pages fields inside XML (for child configs)
                $fileHideAp = $this->_basepar['hideap'] = (string)$xml->baseparameters->params['hideap'];
                # writeDebugInfo($this->_configfile . ": set global hideap to ", $this->_basepar['hideap']);
            }
        }

        # @since 1.42: user defined params in XML block <userparameters>...</userparameters>
        if(isset($xml->userparameters)) {
            foreach($xml->userparameters->children() as $key => $usrPar) {
                $pname = (isset($usrPar['name']) ? (string) $usrPar['name'] : '');
                $pvalue = (isset($usrPar['value']) ? self::parseParam($usrPar['value']) : NULL);
                if ($pname) $this->userParams[$pname] = $pvalue;
            }
        }

        $ownPageNumbers = $ownPagination = FALSE;
        if(isset($xml->baseparameters->pagination)) {

            $this->_basepar['pagination'] = [];
            $this->_basepar['pagination']['align'] = (isset($xml->baseparameters->pagination['align'])) ?
                strtoupper((string)$xml->baseparameters->pagination['align']) : 'C';
            $this->_basepar['pagination']['posy'] = (isset($xml->baseparameters->pagination['posy'])) ?
                strtolower((string)$xml->baseparameters->pagination['posy']): 'bottom';
            $this->_basepar['pagination']['format'] = (isset($xml->baseparameters->pagination['format'])) ?
                trim((string)$xml->baseparameters->pagination['format']) : '%page%';

            $this->_basepar['pagination']['skipfirst'] = (isset($xml->baseparameters->pagination['skipfirst'])) ?
                trim((string)$xml->baseparameters->pagination['skipfirst']) : FALSE;
            # own page numbers inside this page set
            $ownPageNumbers = isset($xml->baseparameters->pagination['own_pageno']) ?
                trim((string)$xml->baseparameters->pagination['own_pageno']) : FALSE;

            if(!empty($ownPageNumbers)) {
                if(!is_numeric($ownPageNumbers)) $ownPageNumbers = 1; # start pageNo in this set
                else $ownPageNumbers = intval($ownPageNumbers);

                $ownPagination = $this->_basepar['pagination'];
            }
        }

        if(isset($xml->baseparameters->pdfpath)) {
            $this->_pdf_path = (string)$xml->baseparameters->pdfpath;
        }
        if(isset($xml->baseparameters->imgpath)) $this->_img_path = (string)$xml->baseparameters->imgpath;

        if(isset($xml->baseparameters->font)) {
            if(!empty($xml->baseparameters->font['name'])) $this->_basepar['font']['name'] = (string)$xml->baseparameters->font['name'];
            if(!empty($xml->baseparameters->font['size'])) $this->_basepar['font']['size'] = (float)$xml->baseparameters->font['size'];
            if(!empty($xml->baseparameters->font['color'])) $this->_basepar['font']['color'] = (string) $xml->baseparameters->font['color'];
            if(!empty($xml->baseparameters->font['style'])) $this->_basepar['font']['style'] = (string) $xml->baseparameters->font['style'];

            if(isset($xml->baseparameters->font['autosize'])) $this->adaptive_sizing = (int)$xml->baseparameters->font['autosize'];
        }

        if(isset($xml->baseparameters->margins)) {
            if(isset($xml->baseparameters->margins['left'])) $this->_basepar['margins']['left'] = (float)$xml->baseparameters->font['left'];
            if(isset($xml->baseparameters->margins['right'])) $this->_basepar['margins']['right'] = (float)$xml->baseparameters->font['right'];
            if(isset($xml->baseparameters->margins['top'])) $this->_basepar['margins']['top'] = (float)$xml->baseparameters->font['top'];
            if(isset($xml->baseparameters->margins['bottom'])) $this->_basepar['margins']['bottom'] = (float)$xml->baseparameters->font['bottom'];
        }

        if (isset($xml->baseparameters->templatefile)) {
            if(!empty($xml->baseparameters->templatefile['src']))
                $this->_templatefile[] = $this->readTemplateDef($xml->baseparameters->templatefile);
            if(!empty($xml->baseparameters->templatefile['altsrc']))
                $this->_alttemplatefile[] = (string)$xml->baseparameters->templatefile['altsrc'];
        }
        if ( isset($xml->templatefiles) ) {
            foreach($xml->templatefiles->children() as $key=>$item) {
                if(!empty($item['src'])) {
                    $this->_templatefile[] =  $this->readTemplateDef($item);
                }
            }
        }
        # read data block definitions
        if ( isset($xml->blockdefs) ) {
            foreach($xml->blockdefs->children() as $key=>$item) {
                if(!empty($item['id'])) {
                    $blkid = (string)$item['id'];
                    if(!$this->father->blockdefs) $this->father->blockdefs = [];
                    $this->father->blockdefs[$blkid] =  $this->readBlockDef($item);
                    if(is_callable(self::$debug)) self::$debug("$key, called readBlockDef for item ", $item);
                }
            }
        }

        if(isset($xml->allpages)) { # All Pages Fields exist, load them!
            $this->_apFinally = !empty($xml->allpages['finally']);
            foreach($xml->allpages->children() as $key=>$item) {
                if($key === 'field' OR $key ==='image') {
                    $value = isset($item['value'])? trim("{$item['value']}") : null;
                    if($value !=='' and strtoupper($this->_config['stringcharset']) !== 'UTF-8')
                        $value = iconv('UTF-8',$this->_config['stringcharset'],$value);
                    $this->addAllPagesField($item, $value);
                }
            }
        }
        $fileversion = isset($xml->version) ? $xml->version : 1; # for future needs

        if(!empty($this->_config['templatefile'])) $this->_templatefile[] = [
           'src'=> (string)$this->_config['templatefile']
        ];

        $this->_pagedefs = [];
        $fldcnt = 0;
        foreach($xml->pages->children() as $key => $pageitem) {
            # $pageno = isset($pageitem['number']) ? (int) $pageitem['number'] : $ipage;
            $hide_it = isset($pageitem['hide']) ? (int) $pageitem['hide'] : 0;
            $hide_ap = isset($pageitem['hideap']) ? (string) $pageitem['hideap'] : FALSE;
            if($hide_ap === FALSE) {
                $hide_ap = $this->_basepar['hideap'] ?? FALSE; # take from "baseparameters->params['hideap'] value
            }
            $pg_orient = isset($pageitem['orientation']) ? $pageitem['orientation'] : $this->_basepar['page']['orientation'];
            $gridpage = $gridFields = false;

            $ifCond = (isset($pageitem['if']) ? (string)$pageitem['if'] : '');
            if(!empty($ifCond)) {
                $bAddPage = $this->_evalAttribute($ifCond);
                # writeDebugInfo("called $ifCond, result: [$bAddPage]");
                if(!$bAddPage) continue;
            }

            if ($key == 'importdef') { # Append pages definition from another XML cfg
                $addXml = (isset($pageitem['src']) ? (string)$pageitem['src'] : '');
                if(!empty($operSubid)) $subid = $operSubid; # passed from caller
                else $subid  = (isset($pageitem['datasubid']) ? (string)$pageitem['datasubid'] : '');
                /*
                $ifCond = (isset($pageitem['if']) ? (string)$pageitem['if'] : FALSE);
                if (substr($ifCond,0,1) === '@') $ifCond = substr($ifCond,1);
                $b_import = TRUE;
                if (!empty($ifCond)) {
                    if(is_callable($ifCond)) {
                        $b_import = call_user_func($ifCond, $this->dataentity);
                    }
                    elseif(is_object($this->callbackObj) && method_exists($this->callbackObj, $ifCond)) {
                        $b_import = $this->callbackObj->$ifCond($this->dataentity);
                    }
                    # else writeDebugInfo("if=[$ifCond] is not callable nor method of obj ", $this->callbackObj);
                }
                */
                if ( strlen($addXml) ) {
                    $realXml = $this->_evalAttribute($addXml);
                    # writeDebugInfo("importdef src from [$addXml]: ", $realXml);
                    if ($realXml) {
                        $filePath = self::getRelativeFilename($this->homeDir, $realXml);
                        # writeDebugInfo("importdef, homedir={$this->homeDir}, xmlsrc:[$addXml] -> realtive path: [$filePath]");
                        if ($filePath) $this->AppendPageDefFromXml($filePath, $subid);
                        else die('Importdef: config XML file not found: '.$addXml);
                    }
                }
                continue;
            }
            elseif ($key == 'appendpdf') { # Append all pages from PDF file w/o printing data over them
                $pdfSrc = (string) ($pageitem['src'] ?? '');
                $pgnt = isset($pageitem['pagination']) ? (string)$pageitem['pagination'] : FALSE;
                $allpages = isset($pageitem['allpages']) ? (string)$pageitem['allpages'] : FALSE;
                if(!empty($pdfSrc)) {
                    # ignore <appendfef> with empty src attrib
                    $this->father->appendPdfs[] = array(
                      'afterpage' => $ipage,
                      'src' => $pdfSrc,
                      'pagination' => $pgnt,
                      'allpages' => $allpages,
                    );
                }
                continue;
            }

            $pageno = $ipage = $this->father->currentPageNo++;

            if (isset($pageitem['datasource']) ) { # "conditionally printed" data-grid page
                $gridFields = isset($pageitem['fields']) ? explode(',', (string)$pageitem['fields']) : false;
                if (is_array($gridFields)) $gridpage = array(
                    'datasource' => trim((string)$pageitem['datasource'])
                    ,'fields' => $gridFields
                    ,'rows'   => (isset($pageitem['rows']) ? (integer)$pageitem['rows'] : self::DEFAULT_GRID_ROWS)
                    ,'posx' => (isset($pageitem['posx']) ? (float)$pageitem['posx'] : 0)
                    ,'posy' => (isset($pageitem['posy']) ? (float)$pageitem['posy'] : 0)
                    ,'step_y' => (isset($pageitem['rows']) ? (float)$pageitem['step_y'] : self::DEFAULT_GRID_STEPY)
                    ,'step_x' => (isset($pageitem['step_x']) ? (float)$pageitem['step_x'] : 0)
                    ,'order' => (isset($pageitem['order']) ? (string)$pageitem['order'] : 'R') # "Row first"
                    ,'cols' => (isset($pageitem['cols']) ? (integer)$pageitem['cols'] : 1)
                    ,'fillempty' => (isset($pageitem['fillempty']) ? (string)$pageitem['fillempty'] : FALSE)
                );
            }
            if($hide_it) $this->_hide_pages[$pageno] = true;
            if(is_callable(self::$debug)) self::$debug("loading page ", $ipage, ' homepath: ', $homePath);

            $this->father -> _pagedefs[$ipage] = array('pageno'=>$pageno, 'fields'=>[]
                ,'homepath' => $homePath
                ,'cfgid' => $this->father->cfgId
                ,'repeat'=>[]
                ,'hide'=>$hide_it
                ,'hide_ap' => $hide_ap
                ,'gridpage'=>$gridpage
                ,'datasubid' => $operSubid
                ,'orientation' => $pg_orient
                ,'ruler' => ( isset($pageitem['ruler']) ? (int)$pageitem['ruler'] : FALSE )
                ,'copies' => ( isset($pageitem['copies']) ? (int)$pageitem['copies'] : FALSE )
                ,'pageevent' => ( isset($pageitem['pageevent']) ? (string)$pageitem['pageevent'] : FALSE )
                ,'datagrids' => []
                ,'flextables' => []
                ,'own_pageno' => ($ownPageNumbers ? $ownPageNumbers++ : FALSE)
                ,'own_pagination' => $ownPagination
                # multipages: ID of sub-array, to print N pages by one page per row from single page definition:
                ,'multipages' => ( isset($pageitem['multipages']) ? (string)$pageitem['multipages'] : FALSE )
            );
            # if($ownPageNumbers) writeDebugInfo("own page fix: $ownPageNumbers");
            # if ($gridpage) { echo '<pre>' . print_r($gridpage,1) .'</pre>'; exit; } # debug echo
            foreach($pageitem->children() as $key=>$item) {
                if($key=='template') { # there is specific template for this page
                    $this->father->_pagedefs[$ipage]['template'] = array(
                        'src'    => (isset($item['src']) ? (string) $item['src'] : false)
                       ,'altsrc' => (isset($item['altsrc']) ? (string) $item['altsrc'] : false)
                       ,'page'   => (!empty($item['page']) ? (int) $item['page'] : 0)
                       ,'orientation' => (!empty($item['orientation']) ? $item['orientation'] : $this->_basepar['page']['orientation'])
                       # ,'own_pageno' => ($ownPageNumbers ? $ownPageNumbers++ : FALSE)
                       # ,'own_pagination' => $ownPagination
                    );
                    continue;
                }
                elseif(in_array($key, ['field', 'image', 'plugin'])) {
                    $fldname = isset($item['name'])? trim($item['name']) : '';
                    if(!$fldname) {
                        if(is_callable(self::$debug)) self::$debug("bad (no-name) item: ", $item);
                        continue;
                    }
                    $newar = $this->readFieldDef($item);
                    if($key=='image') $newar['type'] = 'image';
                    if($key=='plugin' || $newar['type']=='plugin') { # drawing specific data plugin
                        $newar['plugintype'] = isset($item['plugintype']) ? $this->_evalAttribute((string)$item['plugintype']) : '';
                        if($newar['plugintype']=='' && $newar['type']!='plugin') $newar['plugintype'] = $this->_evalAttribute($newar['type']);
                        $newar['type'] = 'plugin';
                    }
                    $this->father->_pagedefs[$ipage]['fields'][] = $newar;
                    $fldcnt++;
                }
                elseif($key=='datagrid') {
                    $dtfields = isset($item['fields']) ? (string) $item['fields'] : '';
                    $dtfields = str_replace(' ','',$dtfields);
                    $fldArr = []; # field definitions are inside <datagrid> tag
                    foreach($item->Children() as $chKey => $chItem) {
                        if($chKey === 'field') {
                            $chfldName = isset($chItem['name'])? trim($chItem['name']) : '';
                            if(empty($chfldName)) continue;
                            $fldArr[] = $this->readFieldDef($chItem);
                        }
                    }
                    unset($chItem);

                    $fields = FALSE;
                    if(count($fldArr)) $fields = $fldArr;
                    elseif(!empty($dtfields)) $fields = explode(',',$dtfields);
                    if(!$fields) continue; # ignore empty datagrid tag (w/o field list)

                    $gridId = !empty($item['name']) ?
                        (string)$item['name'] : 'datagrid'.(count($this->father->_datagrids)+1);

                    $this->father->_datagrids[$gridId] = [
                         'page'  => $ipage
                        ,'datasource' => (isset($item['datasource']) ? (string) $item['datasource'] : '')
                        ,'fields'=> $fields # field names OR FieldDef array defined inside <datagrid>
                        ,'posx'  => (isset($item['posx']) ? (string)$item['posx'] : '0')
                        ,'posy'  => (isset($item['posy']) ? (string)$item['posy'] : '0')
                        ,'step_y'=> (isset($item['step_y']) ? (float)$item['step_y'] : 0)
                        ,'rows'  => (isset($item['rows']) ? (int)$item['rows'] : 2)
                        ,'startrow' => (isset($item['startrow']) ? (int)$item['startrow'] : 0)
                        ,'step_x' => (isset($item['step_x']) ? (float)$item['step_x'] : 0)
                        ,'order' => (isset($item['order']) ? (string)$item['order'] : 'R')
                        ,'cols' => (isset($item['cols']) ? (int)$item['cols'] : 1)
                        ,'fillempty' => (isset($item['fillempty']) ? (string)$item['fillempty'] : FALSE)
                    ];

                    $pageno = count($this->father->_pagedefs)-1;
                    # if ($datasource) {
                        $this->father->_pagedefs[$ipage]['datagrids'][] = $gridId;
                        # WriteDebugInfo("register datagrid $gridId for pagedef $ipage: data source=", $datasource);
                    # }
                    if (count($this->father->_datagrids[$gridId]['fields'])) {
                        foreach($this->father->_datagrids[$gridId]['fields'] as $gridField) {
                            if(!is_string($gridField)) continue;
                            foreach($this->father->_pagedefs[$ipage]['fields'] as &$fdef) {
                                if($fdef['name'] === $gridField) {
                                    $fdef['ingrid'] = 1;
                                    # to avoid print empty grid row
                                }
                            }
                        }
                    }
                    # writeDebugInfo("datagrid: ",$this->_datagrids[$gridId]);
                    if (is_array($this->father->_datagrids[$gridId]['fields'])
                      && count($this->father->_datagrids[$gridId]['fields'])) {
                        foreach($this->father->_datagrids[$gridId]['fields'] as $gridField) {
                            if(!is_string($gridField)) continue;
                            foreach($this->father->_pagedefs[$ipage]['fields'] as &$fdef) {
                                if($fdef['name'] === $gridField) {
                                    $fdef['ingrid'] = 1;
                                    # to avoid print empty grid row
                                }
                            }
                        }
                    }
                }
                elseif($key=='flextable') {
                    $fldArr = []; # field definitions are inside <datagrid> tag
                    foreach($item->Children() as $chKey => $chItem) {
                        if($chKey === 'field') {
                            $chfldName = isset($chItem['name'])? trim($chItem['name']) : '';
                            if(empty($chfldName)) continue;
                            $fldArr[] = $this->readFieldDef($chItem);
                        }
                    }
                    unset($chItem);
                    if(!count($fldArr)) continue; # ignore empty flextable (w/o field list)
                    $headers = [];
                    if(isset($item->headers)) {
                        # headers for fields in the table
                        $headersFontName = isset($item->headers['font']) ? (string)$item->headers['font'] : $this->_basepar['font']['name'];
                        $headersFontSize = isset($item->headers['size']) ? (float)$item->headers['size'] : $this->_basepar['font']['size'];
                        $headersBgColor = isset($item->headers['bgcolor']) ? (string)$item->headers['bgcolor'] : '';
                        # exit(__FILE__ .':'.__LINE__." headersFontName:[$headersFontName] headersFontSize=[$headersFontSize] <pre>".print_r($item->headers,1).'<.pre>');
                        foreach($item->headers->Children() as $chKey => $chItem) {
                            if($chKey!=='header') continue;
                            $headFld = isset($chItem['field']) ? (string) ($chItem['field']) : '';
                            $headValue = isset($chItem['value']) ? (string) ($chItem['value']) : '';
                            if(!empty($headFld) && !empty($headValue))
                                $headers[$headFld] = $headValue;
                        }
                    }

                    $gridId = !empty($item['name']) ?
                        (string)$item['name'] : 'flextab_'.(count($this->father->_flextables)+1);

                    $this->father->_flextables[$gridId] = [
                         'page'  => $ipage
                        ,'datasource' => (isset($item['datasource']) ? (string) $item['datasource'] : '')
                        ,'fields'=> $fldArr # field names OR FieldDef array defined inside <datagrid>
                        ,'posx'  => (isset($item['posx']) ? (string)$item['posx'] : '0')
                        ,'posy'  => (isset($item['posy']) ? (string)$item['posy'] : '0')
                        ,'height'  => (isset($item['height']) ? (string)$item['height'] : '0')
                        ,'border'  => (isset($item['border']) ? (string)$item['border'] : '1')
                        ,'bordercolor'  => (isset($item['bordercolor']) ? (string)$item['bordercolor'] : '')
                        ,'padding'  => (isset($item['padding']) ? (string)$item['padding'] : '1')
                        ,'headers' => $headers
                        ,'header_font' => $headersFontName
                        ,'header_fontsize' => $headersFontSize
                        ,'header_bgcolor' => $headersBgColor
                        ,'rowbgcolor' => (isset($item['rowbgcolor']) ? (string)$item['rowbgcolor'] : '')
                        # TODO: joinby,joinfields - special colimn names for joining adjacent rows
                    ];
                    $pageno = count($this->father->_pagedefs)-1;
                    # if ($datasource) {
                        $this->father->_pagedefs[$ipage]['flextables'][] = $gridId;
                        # WriteDebugInfo("register datagrid $gridId for pagedef $ipage: data source=", $datasource);
                    # }
                    # writeDebugInfo("datagrid: ",$this->_datagrids[$gridId]);
                    /*
                    if (is_array($this->father->_datagrids[$gridId]['fields'])
                      && count($this->father->_datagrids[$gridId]['fields'])) {
                        foreach($this->father->_datagrids[$gridId]['fields'] as $gridField) {
                            if(!is_string($gridField)) continue;
                            foreach($this->father->_pagedefs[$ipage]['fields'] as &$fdef) {
                                if($fdef['name'] === $gridField) {
                                    $fdef['ingrid'] = 1;
                                    # to avoid print empty grid row
                                }
                            }
                        }
                    }
                    */
                }
                elseif($key=='datablock') { # related fields block
                    $datasource = isset($item['datasource']) ? (string) $item['datasource'] : '';
                    $defid = isset($item['defid']) ? (string) $item['defid'] : '';
                    if(empty($defid)) continue;
                    $tuneArr = []; # field definitions are inside <datagrid> tag
                    foreach($item->Children() as $chKey => $chItem) {
                        if($chKey === 'tune') {
                            $chfldName = isset($chItem['name'])? trim($chItem['name']) : '';
                            if(empty($chfldName)) continue;
                            $tuneData = $this->readTuneDef($chItem);
                            if(!empty($tuneData['name'])) {
                                $fldid = $tuneData['name'];
                                unset($tuneData['name']);
                                $tuneArr[$fldid] = $tuneData;
                            }
                        }
                    }

                    $this->father->dataBlocks[] = [
                      'page'  => $ipage,
                      'defid' => $defid,
                      'datasource' => $datasource,
                      'posx' => (isset($item['posx']) ? (float)$item['posx'] : 0),
                      'posy' => (isset($item['posy']) ? (float)$item['posy'] : 0),
                      'tune'=> $tuneArr,
                      'fillempty' => (isset($item['fillempty']) ? (string)$item['fillempty'] : FALSE)
                    ];

                    $pageno = count($this->father->_pagedefs)-1;
                    # if ($datasource) {
                        # $this->father->_pagedefs[$ipage]['datagrids'][] = $gridId;
                        # WriteDebugInfo("register datagrid $gridId for pagedef $ipage: data source=", $datasource);
                    # }
                }
                elseif($key=='repeat') { # repeat all data on the sheet, with x/y shifting
                    $off_x = isset($item['offset_x']) ? (float)$item['offset_x'] : 0;
                    $off_y = isset($item['offset_y']) ? (float)$item['offset_y'] : 0;
                    $enabled = isset($item['enabled']) ? (string)$item['enabled'] : 1;
                    if(!empty($enabled) && is_callable($enabled)) $enabled = call_user_func($enabled, count($this->father->_pagedefs[$ipage]['repeat']));
                    if($off_x != 0 || $off_y != 0) $this->father->_pagedefs[$ipage]['repeat'][] = array($off_x,$off_y, $enabled);
                }

            }
            if(count($this->_datagrids)) foreach($this->_datagrids as $gid => $dtgrid) {
                // fill datagrid by prepared "numbered" fields: name1,age1; name2,age2,...
                if ($dtgrid['page'] != $ipage) continue;
                $posyarr = explode(',', $dtgrid['posy']);
                $farr = [];

                $gridFldDefs = $this->getFieldDefs($dtgrid['fields'], $ipage);

                if(!count($gridFldDefs)) continue; # Skip grid: no one field has a name listed in datagrid
                if (!empty($dtgrid['datasource'])){
                    # save $gridId for current pdf page def
                    continue; # named datasource will be handled w/o creating "pseudo-fields"
                }
                $rows = $dtgrid['rows'] ? $dtgrid['rows'] : count($posyarr);
                $cols = $dtgrid['cols'] ? $dtgrid['cols'] : 1;
                $posx = $dtgrid['posx'] ? floatval($dtgrid['posx']) : 0;
                $fillEmpty = $dtgrid['fillempty'] ? $dtgrid['fillempty'] : false;
                $sumItems = $rows * $cols;
                # \writeDebugInfo("rows in grid = $rows * $cols = $sumItems");
                for($kk=1; $kk <= $sumItems; $kk++) {
                    $poy_off = $kk-1;
                    $posy = isset($posyarr[$poy_off]) ? $posyarr[$poy_off] : ($posyarr[0]+($poy_off * $dtgrid['step_y']));
                    foreach($gridFldDefs as $fdef) {
                        $newdef = ($fdef);
                        $newdef['name'] = $fdef['name'].$kk;
                        $newdef['posy'] = $fdef['posy'] + $posy;
                        if($posx!=0) foreach($newdef['posx'] as $k=>$v) {
                            $newdef['posx'][$k] += $posx;
                        }
                        $newdef['_gridtmp_'] = 1; # not a real field, just a clone
                        if ($newdef['type']!='checkbox') $newdef['fillempty'] = $fillEmpty;
                        $this->father->_pagedefs[$ipage]['fields'][] = $newdef;
                        # if ($fillEmpty) \writeDebugInfo("grid fillempty $newdef[name]");
                    }
                }
            }
            # $ipage++;
        }

        if (isset($xml->import)) {
            foreach($xml->import->children() as $key => $item) {
                if ($key === 'importdef') {
                    $addXml = (isset($item['src']) ? (string)$item['src'] : '');
                    if($operSubid) $subid = $operSubid;
                    else $subid  = (isset($item['datasubid']) ? (string)$item['datasubid'] : '');
                    if (strlen($addXml)) {
                        $filePath = self::getRelativeFilename($this->homeDir, $addXml);
                        if ($filePath) $this->father->AppendPageDefFromXml($filePath, $subid);
                    }
                }
            }
        }
        if(!$fldcnt) {
            $this->_errormessage = 'No valid workpage definitions found (no fields defined)!';
            $ret = false;
        }

        return $ret;
    }
    # render one flextable
    public function drawFlexTable($flexId) {
        # $this->dataentity as field data content
        $sourceid = (string)$this->_flextables[$flexId]['datasource'];
        # writeDebugInfo("drawFlexTable($flexId) - $sourceid");
        $startX = floatval($this->_evalAttribute($this->_flextables[$flexId]['posx']));
        $startY = floatval($this->_evalAttribute($this->_flextables[$flexId]['posy']));
        $height = floatval($this->_evalAttribute($this->_flextables[$flexId]['height'])); # not used for now...
        $border = ($this->_evalAttribute($this->_flextables[$flexId]['border']));
        $padding = floatval($this->_evalAttribute($this->_flextables[$flexId]['padding']));
        $bordercolor = ($this->_evalAttribute($this->_flextables[$flexId]['bordercolor']));
        $borderRGB = empty($bordercolor) ? [0,0,0] : $this->_parseColor($bordercolor);
        $arColumns = [$startX]; # X positions of all start columns

        $rowBgColors = [];
        if(!empty($this->_flextables[$flexId]['rowbgcolor'])) {
            $arTmp = explode(",",$this->_flextables[$flexId]['rowbgcolor']);
            foreach($arTmp as $oneBg) {
                $rowBgColors[] = $this->_parseColor($oneBg);
            }
        }
        $multiPage = FALSE; # TODO: support multi-page generation for big data array
        $headers =& $this->_flextables[$flexId]['headers'];
        # exit(__FILE__ .':'.__LINE__.' _flextables:<pre>' . print_r($this->_flextables[$flexId],1) . '</pre>');
        $headersFontName = $this->_flextables[$flexId]['header_font'];
        if(empty($headersFontName)) $headersFontName = $this->_basepar['font']['name'];
        $headersFontSize = $this->_flextables[$flexId]['header_fontsize'];
        $headersBgColor = $this->_flextables[$flexId]['header_bgcolor'];
        if(empty($headersFontSize)) $headersFontSize = 10;

        # exit(__FILE__ .':'.__LINE__." headersFontName=$headersFontName, headersFontSize=$headersFontSize:<pre>" . print_r($this->_flextables[$flexId],1) . '</pre>');
        $endX = $startX;
        $arFields =& $this->_flextables[$flexId]['fields']; # field definitions list
        foreach($arFields as $flDef) {
            $width = max(floatval($flDef['width']), 2);
            $endX += $width;
            $arColumns[] = $endX;
        }
        $maxRight = round($this->_pdf->getPageWidth() - $startX, 2);
        if($maxRight < $endX) {
            # auto shrink X positions to fit on page
            $kShrink = $maxRight / $endX;
            foreach($arColumns as $no => &$colPosX) {
                # echo "shrink $no for ", print_r(
                if($no > 0) $colPosX = round($colPosX * $kShrink, 1);
            }
            $endX = $maxRight;
        }
        # echo (__FILE__ .':'.__LINE__." page max Right border:[$maxRight] <pre>". print_r($arFields,1) . "arColumns: "  . print_r($arColumns,1) . '</pre>' );
        # exit(__FILE__ .':'.__LINE__.' dataentity:<pre>' . print_r($this->dataentity,1) . '</pre>');
        if(!isset($this->dataentity[$sourceid]) || !is_array($this->dataentity[$sourceid])
          || !count($this->dataentity[$sourceid]))
            return $startY;

        # $this->_pdf->SetLineWidth(0.4);
        if($border>0 && $border == '1') $border = 0.2;
        if($border>0)
            $this->_pdf->SetLineStyle(['width'=>$border, 'join'=>'miter', 'dash'=>0, 'color'=>$borderRGB]);

        $curPosY = $startY;
        if(count($headers)) {
            # draw headers row
            # 1. Count max height neede for header row
            $maxHeight = 0;

            $fsize = $headersFontSize;
            $font = $headersFontName;
            $this->_pdf->setFont($font,'', $fsize);

            foreach($arFields as $no => $fDef) {
                $fldPosX = $arColumns[$no];
                $fldid = $fDef['name'];
                $fldWidth = $arColumns[$no+1] - $fldPosX - (2 * $padding);
                # echo ("col width: $fldWidth = ".$arColumns[$no+1]. " - $fldPosX - (2 * $padding)<br>");
                $fvalue = $headers[$fldid] ?? "[ $fldid ]";
                $thisHeight = round($this->_pdf->getStringHeight($fldWidth, $fvalue, $reseth=TRUE, $autopadding=true),2);
                $maxHeight = max($maxHeight, $thisHeight);
            }
            if(!empty($headersBgColor)) {
                $rgbBg = $this->colorToDec($headersBgColor);
                $this->_pdf->Rect($startX, $startY, ($endX-$startX), ($maxHeight+2*$padding), 'F', [], array_values($rgbBg));
            }
            if($border>0) $this->_pdf->Line($startX,$startY,$endX, $startY); # upper border
            # print headers in row
            foreach($arFields as $no => $fDef) {
                $fldPosX = $arColumns[$no];
                $fldid = $fDef['name'];
                $fldWidth = $arColumns[$no+1] - $fldPosX - (2 * $padding);
                # echo ("col width: $fldWidth = ".$arColumns[$no+1]. " - $fldPosX - (2 * $padding)<br>");
                $fvalue = $headers[$fldid] ?? "[ $fldid ]";
                $headDef = ['name'=> "_head_{$fldid}", 'posx'=>[$fldPosX+$padding], 'posy'=>($curPosY+$padding), 'height'=>$maxHeight,
                  'width'=>$fldWidth, 'align'=>'C','valign'=>'M',
                  'font'=>$headersFontName, 'size'=> $headersFontSize
                ];
                $this->_valueToPdf($fvalue, $headDef);
            }
            $curPosY += $maxHeight + $padding * 2;
            if($border>0) {
                $this->_pdf->Line($startX,$curPosY,$endX, $curPosY); # low border
                foreach($arColumns as $colX) {
                    $this->_pdf->Line($colX,$startY,$colX, $curPosY); # vertival lines (column delimiters)
                }
            }
        }
        else {
            if($border>0) $this->_pdf->Line($startX,$startY,$endX, $startY); # upper border
        }

        # Draw data rows
        $bgColOff = 0;
        foreach($this->dataentity[$sourceid] as $rowid => $dataRow) {
            $colNo = 0;
            $maxHeight = 0;
            # echo "datarow $rowid... <hr>";
            foreach($arFields as $no => $fDef) {
                $fldPosX = $arColumns[$no];
                $fldid = $fDef['name'];
                $fsize = $fDef['size'];
                $ftype = $fDef['type'];
                if(empty($fsize)) $fsize = $this->_basepar['font']['size'];

                $fldWidth = $arColumns[$no+1] - $fldPosX - (2 * $padding);
                # echo ("col width: $fldWidth = ".$arColumns[$no+1]. " - $fldPosX - (2 * $padding)<br>");
                $fvalue = $dataRow[$fldid] ?? '';
                if($ftype === 'money') {
                    $fvalue = number_format(floatval($fvalue),2,'.',' ');
                }
                $this->_pdf->SetFontSize($fsize);
                $thisHeight = round($this->_pdf->getStringHeight($fldWidth, $fvalue, $reseth=TRUE, $autopadding=true),2);
                $maxHeight = max($maxHeight, $thisHeight);
                # echo ("$fldid: $fvalue/$fldWidth: height is $thisHeight<br>");
                $colNo++;
            }
            if(($curPosY + $maxHeight + $padding * 2)>$this->_pdf->getPageHeight()) {
                # page bottom will be reached
                # TODO: multipage print
                break;
            }
            if($height > 0 && ($curPosY-$startY + $maxHeight + $padding * 2)>$height) {
                # height limit will be reached
                break;
            }
            if(count($rowBgColors)) {
                # paint row background
                $thisRowBg = $rowBgColors[$bgColOff];
                if( (++$bgColOff) >= count($rowBgColors)) $bgColOff = 0;
                $this->_pdf->Rect(($startX+$border/2), ($curPosY+$border), ($endX-$startX-$border/2),
                  ($maxHeight+2*$padding - $border/2), 'F', [], array_values($thisRowBg));
            }
            # print fields in data row
            foreach($arFields as $no => $fDef) {
                $fldPosX = $arColumns[$no];
                $fldid = $fDef['name'];
                $fsize = $fDef['size'];
                $ftype = $fDef['type'];
                if(empty($fsize)) $fsize = $this->_basepar['font']['size'];
                $fldWidth = $arColumns[$no+1] - $fldPosX - (2 * $padding);
                $fDef['width'] = $fldWidth;
                $fDef['height'] = $maxHeight;
                $fDef['posx'][0] = $fldPosX + $padding;
                $fDef['posy'] = $curPosY + $padding;
                if(empty($fDef['valign'])) $fDef['valign'] = 'M'; # center vertivcally
                $this->_valueToPdf($dataRow[$fldid], $fDef);
            }
            # $maxHeight calculated. draw a row...
            # echo "risk $rowid: maxHeight = $maxHeight<br>";
            $nextPosY = $curPosY + $maxHeight + $padding * 2;
            if($border>0) {
                $this->_pdf->Line($startX,$nextPosY,$endX, $nextPosY); # low border
                foreach($arColumns as $colX) {
                    $this->_pdf->Line($colX,$curPosY,$colX, $nextPosY); # vertival lines (column delimiters)
                }
            }
            $curPosY = $nextPosY;
        }
        return $curPosY;
    }
    /**
    * returns all fields definitions on page
    *
    * @param mixed $pageno page no to retrieve
    * @return mixed
    */
    public function getFieldList($pageno) {
        # writeDebugInfo("pagedefs: ", $this->_pagedefs);
        if(isset($this->_pagedefs[$pageno])) return $this->_pagedefs[$pageno]['fields'];
        return [];
    }
    public function readBlockDef($blk) {
        $fldArr = [];
        foreach($blk->children() as $key=>$childItem) {
            if($key === 'field') {
                $fldArr[] = $this->readFieldDef($childItem);
            }
        }
        return [
          'fields' => $fldArr,
        ];
    }
    # get real PDF filename baesd on short name in src tag
    public function findRealPdfFile($pdfName, $pagedef = FALSE, $homepath='') {

        # writeDebugInfo(">> Check file ".$homepath.$pdfName . " is exist: [" . is_file($homepath . $pdfName).'] ');
        if(!empty($pagedef['homepath'])) $home = $pagedef['homepath'];
        else $home = '';
        if (is_file($pdfName)) $pdfTpl = $pdfName;
        elseif(is_file($homepath.$pdfName))  {
            $pdfTpl = $homepath.$pdfName;
        }
        elseif(is_file($this->_pdf_path . $pdfName))
            $pdfTpl = $this->_pdf_path . $pdfName;
        elseif(!empty($home) && is_file($home . $pdfName))
            $pdfTpl = $home . $pdfName;
        elseif(is_file($this->homeDir . $pdfName))
            $pdfTpl = $this->homeDir . $pdfName;
        elseif(is_file($this->father->homeDir . $pdfName))
            $pdfTpl = $this->father->homeDir . $pdfName;
        elseif(!empty($pagedef['sourcepath']) && is_file($pagedef['sourcepath'] . $pdfName))
            $pdfTpl = $pagedef['sourcepath'] . $pdfName;
        else $pdfTpl = 'no-file!';
        # $pdfTpl = realpath($pdfTpl);
        return $pdfTpl;
    }
    # read "template" sub-element to assoc.array
    protected function readTemplateDef($item) {
        $ret = [
            'src' => $this->_evalAttribute($item['src'])
           ,'altsrc'=> (isset($item['altsrc']) ? (string)$item['altsrc'] : '')
           ,'pagination'=> (isset($item['pagination']) ? (string)$item['pagination'] : FALSE)
           ,'pages'=> (isset($item['pages']) ? (string)$item['pages'] : '')
        ];

        $pdfPages = $this->getPdfStructure($ret['src'],0,dirname($this->_configfile).'/');
        if(is_callable(self::$debug)) self::$debug("add pdf structure: ", $pdfPages);
        # if(!$this->father) exit("father NOT init");
        if(count($pdfPages)) {
            $cfgid = $this->father->cfgId;
            if(isset($this->father->tpPages[$cfgid]))
                $this->father->tpPages[$cfgid] = array_merge($this->father->tpPages[$cfgid], $pdfPages);
            else
                $this->father->tpPages[$cfgid] = $pdfPages;
        }
        return $ret;
    }

    public function getPdfStructure($pdfShortName, $pageNo = FALSE, $homepath='') {
        $fileName = $this->findRealPdfFile($pdfShortName, 0, $homepath);
        $arRet = [];
        if(is_file($fileName)) {
            $pdfTest = new TCPDI('P','mm','A4');
            $pagecount = $pdfTest->setSourceFile($fileName);

            if($pageNo>0) {
                # We need just one page info
                if($pageNo > $pagecount)
                    throw new Exception(__FILE__ . ':' . __LINE__ . "-Desired page $pageNo more than $pagecount in $fileName !");
                $tplidx = $pdfTest->importPage($pageNo);
                $specs = $pdfTest->getTemplateSize($tplidx);
                $orientation = ($specs['h'] > $specs['w'] ? 'P' : 'L');
                $arRet = ['pdf'=>$fileName, 'page'=>$pageNo, 'orientation'=>$orientation];
                if(is_callable(self::$debug)) self::$debug(">>getPdfStructure($pdfShortName, $pageNo) returns: ", $arRet);
                return $arRet;
            }

            for($page=1;$page<=$pagecount; $page++) {
                $tplidx = $pdfTest->importPage($page);
                $specs = $pdfTest->getTemplateSize($tplidx);
                $orientation = ($specs['h'] > $specs['w'] ? 'P' : 'L');
                $arRet[] = ['pdf'=>$fileName, 'page'=>$page, 'orientation'=>$orientation];
            }
            unset($pdfTest);
        }

        return $arRet;
    }
    /**
    * Disables/enables all images import (debug needs)
    * @since 1.3.0020
    * @param mixed $par
    */
    public function disableImages($par=true) {
        $this->_images_disabled = $par;
    }
    public function setResourcePaths($pdfPath=null, $imgPath=null) {
        if($pdfPath!==null) $this->_pdf_path = $pdfPath;
        if($imgPath!==null) $this->_img_path = $imgPath;
    }
    /**
    * Add page definition programmatically
    * Programmer can add some PDF templates to output document without editing XML config-file
    * @since 1.2
    * @param mixed $opts
    */
    public function addPageDef($opts=[]) {
        $ipage = count($this->_pagedefs);
        $pg = [];
        $pg['orientation'] = (isset($opts['orientation']) ? $opts['orientation'] : $this->_basepar['page']['orientation']);
        $pg['pageno'] = (isset($opts['pageno']) ? $opts['pageno'] : $ipage);
        $pg['fields'] = (isset($opts['fields']) ? $opts['fields'] : []);
        $pg['repeat'] = (isset($opts['repeat']) ? $opts['repeat'] : []);
        $pg['hide'] = (isset($opts['hide']) ? $opts['hide'] : FALSE);
        $pg['hide_ap'] = (isset($opts['hide_ap']) ? $opts['hide_ap'] : FALSE);
        $pg['template'] = array(
           'src' => (isset($opts['src']) ? (string)$opts['src'] : '')
          ,'altsrc' => (isset($opts['altsrc']) ? (string)$opts['altsrc'] : '')
          ,'page' => (isset($opts['page']) ? max(1,(int)$opts['page']) : 1)
        );
        $ipage = $this->father->currentPageNo++;
        $this->father -> _pagedefs[$ipage] = $pg;
    }
    /**
    * Overrides template PDF file for specified page
    *
    * @param mixed $pageno, 0-based!
    * @param mixed $srcPdf new PDF template file name
    * @param mixed $srcPage page to use from PDF template
    * @since: 1.5
    */
    public function setPageTemplateFile($pageno, $srcPdf, $srcPage=1) {
        if (isset($this->_pagedefs[$pageno])) {
            if (!empty($srcPdf)) $this->_pagedefs[$pageno]['template']['src'] = $srcPdf;
            if (!empty($srcPage)) $this->_pagedefs[$pageno]['template']['page'] = $srcPage;
            return true;
        }
        else {
            return false;
        }
    }

    /**
    * Get contents of base config, to be able programmatically saving XML def.
    *
    */
    public function GetBaseConfig() { return $this->_config; }
    public function GetBaseParams() { return $this->_basepar; }

    public function SetOffsets($offset_x=0, $offset_y=0) {
        $this->offsets = is_array($offset_x) ? $offset_x : array(floatval($offset_x),floatval($offset_y));
    }
    /**
    * Switches to using alternative" PDF template file(s) in all pages
    *
    * @param mixed $alt 1 or true to turn alternate template ON, false otherwise
    */
    public function SetAltTemplate($alt=true) {
        $this->_alttemplate = $alt;
    }
    /**
    * "Manually" add one field definition
    *
    * @param int $ipage page index (offset)
    * @param array $parm associative arrya with all definitions : 'name','col','row','type' etc.
    */
    public function AddFieldDefinition($ipage, $parm) {
        if(!isset($this->_pagedefs[$ipage]))
            $this->_pagedefs[$ipage] = array('offset'=>count($this->_pagedefs), 'fields'=>[]);
        if(empty($parm['name'])) return false; # array MUST contain 'name' element
        $tp = (isset($parm['type']) ? strtolower((string) $parm['type']) : '');
        $posy = (isset($parm['posy']) ? (string) $parm['posy'] : '1');
        $fldDef = [
           'name'   => strtolower(trim($parm['name']))
          ,'type'    => $tp
          ,'posx'    => (isset($parm['posx']) ? explode(',', (string)$parm['posx']) : array(0))
          ,'posy'    => ($tp=='poly'? floatval($pos) : explode(',',$posy))
          ,'charstep'=> (isset($parm['charstep']) ? (float)$parm['charstep'] : 0)
          ,'maxlength'=> (isset($parm['maxlength']) ? (int)$parm['maxlength'] : 0)
          ,'width'   => (isset($parm['width']) ? floatval($parm['width']) : 0)
          ,'font'    => (isset($parm['font']) ? (string)($parm['font']) : '')
          ,'fontstyle' => (isset($parm['fontstyle']) ? (string)($parm['fontstyle']) : '')

          ,'size'    => (isset($parm['size']) ? floatval($parm['size']) : 0)
          ,'align'   => (isset($parm['align']) ? (string) $parm['align'] : '')
          # ,'header'  => (isset($parm['header']) ? (string) $parm['header'] : ucfirst(trim($parm['name'])))
          ,'valign'  => (isset($parm['valign']) ? (string) $parm['valign'] : '')
          ,'convert' => (isset($parm['convert']) ? (string) $parm['convert'] : '')
          ,'color'   => (isset($parm['color']) ? (string) $parm['color'] : '')
          ,'bgcolor' => (isset($parm['bgcolor']) ? (string) $parm['bgcolor'] : '')
          ,'forient' => (isset($parm['forient']) ? (string) $parm['forient'] : '') # for pages thet oriented 'P' or 'L'
          ,'rotate'  => (isset($parm['rotate']) ? (float) $parm['rotate'] : 0)
          ,'norepeat'=> (isset($parm['norepeat']) ? (int) $parm['norepeat'] : 0)
          ,'border'  => (isset($parm['border']) ? (string) $parm['border'] : 0)
          ,'options' => (isset($parm['options']) ? (string) $parm['options'] : '')
          ,'opacity' => (isset($parm['opacity']) ? (float) $parm['opacity'] : 1)
          ,'thickness' => (isset($parm['thickness']) ? (float) $parm['thickness'] : 0)
          ,'src'  => (isset($parm['src']) ? (string) $parm['src'] : '')
        ];
        if ($fldDef['fontstyle']!='') exit("$parm[name] has fontstyle");
        $this->_pagedefs[$ipage]['fields'][] = $fldDef;
    }
    public function setTitle($strg) { $this->_config['title'] = $strg; }
    public function setSubject($strg) { $this->_config['subject'] = $strg; }
    public function setAuthor($strg) { $this->_config['author'] = $strg; }
    public function setCreator($strg) { $this->_config['creator'] = $strg; }

    public function protectFile($protect, $password=null, $options = false) {
        $this->_config['protectfile'] = $protect;
        if($password!==null) $this->_config['password'] = $password;
        $this->_config['protectoptions'] = $options;
    }
    /**
    * Pass whole data array for printing one "PDF entity"
    *
    * @param array $param associative array with all values. Don't call this more than once for one page/page set,
    * because next calling will create new page/page set (creating multi-paged formed PDF)
    */
    public function AddData($entitydata, $datacharset='') {
        if(!is_array($entitydata)) return false;
        if(!empty($datacharset) && !empty($this->_config['stringcharset']) && $datacharset != $this->_config['stringcharset']) {
            #\writeDebugInfo("try to convert data from ",$this->_config['stringcharset'], ' to ', $datacharset);
            mb_convert_variables($this->_config['stringcharset'], $datacharset, $entitydata);
        }
        $pageData = [];
        foreach($entitydata as $key=>$value) {
            if(substr($key,0,5) == 'grid:' && is_array($value)) {
                $gridid = substr($key,5);
                foreach($value as $gridrow) {
                    $pageData = array_merge($pageData,$this->AddDataGridRow($gridid,$gridrow,true));
                }
            }
            else $pageData[$key] = $value;
        }
        $this->_data[] = $pageData;
    }
    /**
    * Change printing coordinates, font size etc before generating PDF,
    * for "tuning/testing" needs...
    * @param mixed $params assoc.array containing new page/fields parameters as $param[0]['fields'] - new fields for page1, etc.
    */
    public function ModifyParameters($param) {
        if(!is_array($param)) return false;
        $ipage = 0;
        foreach($param as $key=>$fielddefs) {
             # overwrite $this->_pagedefs[$ipage]['fields'] with values from array
             if(!isset($this->_pagedefs[$ipage])) break;
             if(!is_array($fielddefs['fields'])) break;

             # $this->_pagedefs[$ipage]['fields'] = $fielddefs['fields'];
             for($kk=0; $kk<count($this->_pagedefs[$ipage]['fields']);$kk++) { # each($item as $itkey=>$fldef)
                 if(!isset($fielddefs['fields'][$kk])) break; # passed array is shorter than our field list
                 if(isset($fielddefs['fields'][$kk]['posx'])) {
                     for($kj=0;$kj<count($fielddefs['fields'][$kk]['posx']); $kj++) $this->_pagedefs[$ipage]['fields'][$kk]['posx'][$kj] = floatval($fielddefs['fields'][$kk]['posx'][$kj]);
                 }
                 if(isset($fielddefs['fields'][$kk]['posy'])) $this->_pagedefs[$ipage]['fields'][$kk]['posy'] = floatval($fielddefs['fields'][$kk]['posy']);
                 if(isset($fielddefs['fields'][$kk]['size'])) $this->_pagedefs[$ipage]['fields'][$kk]['size'] = floatval($fielddefs['fields'][$kk]['size']);
                 if(isset($fielddefs['fields'][$kk]['charstep'])) $this->_pagedefs[$ipage]['fields'][$kk]['charstep'] = floatval($fielddefs['fields'][$kk]['charstep']);
                 if(isset($fielddefs['fields'][$kk]['width'])) $this->_pagedefs[$ipage]['fields'][$kk]['width'] = floatval($fielddefs['fields'][$kk]['width']);
                 if(isset($fielddefs['fields'][$kk]['height'])) $this->_pagedefs[$ipage]['fields'][$kk]['height'] = floatval($fielddefs['fields'][$kk]['height']);
                 # charstep, size, etc...
             }
             $ipage++;

        }
    }

    /**
    * Adding fields to be hidden (not printed) in final document
    * @since 1.8
    * @param mixed $fldnames comma(space) separated string or array containing field names to hide
    */
    public function hideFields($fldnames) {
        if (is_string($fldnames))
            $fldnames = preg_split( "/\s,]/", $fldnames, -1, PREG_SPLIT_NO_EMPTY );
        if (is_array($fldnames))
            $this->_hide_fields = array_merge($this->_hide_fields,$fldnames);
        return $this;
    }
    /**
    * Change any attributes for one field
    * @since 1.8
    *
    * @param mixed $fieldname field name
    * @param mixed $attribs assoc array in form "key" => new_value.
    * Supported keys: "src"
    */
    public function setFieldAttribs($fieldname, $attribs) {
        if (is_array($attribs)) {
            if (!isset($this->_field_newattr[$fieldname])) $this->_field_newattr[$fieldname] = [];
            $this->_field_newattr[$fieldname] = array_merge($this->_field_newattr[$fieldname],$attribs);
        }
        return $this;
    }
    # obsolete function name, use drawRuler() !
    public function DrawMeasuringGrid($step = 10, $color = false) {
        $this->drawRuler($step, $color);
    }
    /**
    * Turns ON creating of measuring grid (will be drawn on the first page only)
    *
    * @param mixed $step
    */
    public function drawRuler($step = 10, $color = false) {
        $this->_rulerStep = ($step==1) ? 10 : $step;
        if($color && is_array($color) && count($color)>=3) $this->_rulerColor = $color;
        else $this->_rulerColor = array(20,20,200);
    }
    public function fillHiBlocks($fillValue=1) {
        $this->_fillHiBlocks = $fillValue;
    }
    /**
    * Draws "measuring" grid on current pdf page
    * @since 1.12
    */
    protected function _renderMeasuringGrid() {

        if($this->_rulerStep > 0 ) { # let's draw a measuring grid
            $pageWidth  = $this->_pdf->getPageWidth();
            $pageHeight = $this->_pdf->getPageHeight();
            $result = $this->_pdf->SetDrawColor($this->_rulerColor[0],$this->_rulerColor[1],$this->_rulerColor[2]);
            $this->_pdf->SetTextColor($this->_rulerColor[0],$this->_rulerColor[1],$this->_rulerColor[2]);
            $this->_pdf->SetFontSize(6.0);
            # $this->_pdf->SetLineWidth(0.2);
            for($posx=0; $posx<$pageWidth; $posx+=$this->_rulerStep) {
                $this->_pdf->Line($posx,0,$posx, $pageHeight, array('dash'=>'1,3'));
                if($posx>0) {
                    $this->_pdf->Text($posx+0.05, 1.0, "$posx");
                    $this->_pdf->Text($posx+0.05, $pageHeight-5, "$posx");
                }
            }
            for($posy=0; $posy<$pageHeight; $posy+=$this->_rulerStep) {
                $this->_pdf->Line(0,$posy,$pageWidth, $posy);
                if($posy>0) {
                    $this->_pdf->Text(1.0, $posy+0.1, "$posy");
                    $this->_pdf->Text($pageWidth-5, $posy+0.1, "$posy");
                }
            }
            $this->_pdf->setFontSize($this->_basepar['font']['size']);
        }

    }
    /**
    * Adds field definition (and possible value) to ALL PAGES (AP) in document.
    * For drawing "DRAFT" sign, bar-code, logo image and so on, on every PDF page
    * @param mixed $fdef full field definition array: name,type, posx,posy,rotate,color,...
    * @param mixed $value optional initial value for the field
    * @since 1.4
    */
    public function addAllPagesField($fdef, $value=null) {

        $new_fld = $this->readFieldDef($fdef);
        $name = $new_fld['name'];
        $this->_apFields[] = $new_fld;
        if($value!==null) $this->_apValues[$name] = $value;
    }

    /**
    * Sets value for one "AP" field
    * @param mixed $fldname field name
    * @param mixed $value new value
    * @since 1.4
    */
    public function setAllPagesFieldValue($fldname, $value) {
        $this->_apValues[$fldname] = $value;
    }
    /**
    * Reads user (possibly not full) field definition and return a full definition for working
    *
    * @param mixed $item
    */
    public function readFieldDef($item) {
        $fldname = isset($item['name'])? trim("{$item['name']}") : ('_field_'. (++$this->father->_tmpFldNo));
        $ret = array(
           'name'    => strtolower($fldname)
          ,'type'    => (isset($item['type']) ? strtolower((string)$item['type']) : 'text')
          ,'posx'    => (isset($item['posx']) ? explode(',', (string) $item['posx']) : array(0))
          ,'posy'    => (isset($item['posy']) ? (float) $item['posy'] : 0)
          ,'charstep'=> (isset($item['charstep']) ? (float)$item['charstep'] : 0)
          ,'maxlength'=> (isset($item['maxlength']) ? (int)$item['maxlength'] : 0)
          ,'width'   => (isset($item['width']) ? (float) $item['width'] : 0)
          ,'height'  => (isset($item['height']) ? (float) $item['height'] : 0)
          ,'font'    => (isset($item['font']) ? (string) $item['font'] :'')
          ,'fontstyle' => (isset($item['fontstyle']) ? (string) $item['fontstyle'] :'')
          ,'size'    => (isset($item['size']) ? (float) $item['size'] : 0)
          ,'convert' => (isset($item['convert']) ? (string) $item['convert'] : '')
          ,'if' => (isset($item['if']) ? (string) $item['if'] : NULL)
          ,'color'   => (isset($item['color']) ? (string) $item['color'] : '')
          ,'forient'   => (isset($item['forient']) ? (string) $item['forient'] : '')
          ,'bgcolor' => (isset($item['bgcolor']) ? (string) $item['bgcolor'] : '')
          ,'rotate'  => (isset($item['rotate']) ? (float) $item['rotate'] : 0)
          ,'norepeat'=> (isset($item['norepeat']) ? (int) $item['norepeat'] : 0)
          ,'align'   => (isset($item['align']) ? (string) $item['align'] : '')
          ,'valign'  => (isset($item['valign']) ? (string) $item['valign'] : '')
          ,'options' => (isset($item['options']) ? (string) $item['options'] : '')
          ,'opacity' => (isset($item['opacity']) ? (float) $item['opacity'] : 1)
          ,'thickness' => (isset($item['thickness']) ? (float) $item['thickness'] : 0)
          ,'src'     => (isset($item['src'])? (string)$item['src'] : '')
          ,'fillempty' => (isset($item['fillempty'])? (int)$item['fillempty'] : FALSE)
        );
        if($ret['type'] === 'rectangle' || $ret['type'] === 'rect') { # draw a rectangle, 'width' & 'height' sets its width/height
            $ret['type'] = 'rect';
        }
        elseif($ret['type'] === 'poly' || $ret['type'] === 'polygone') { # draw a polygone, and posy must contain at least 2 values: x0,x1, y0,y1
            $ret['type'] = 'poly';
            $ret['posy'] = (isset($item['posy']) ? explode(',', (string) $item['posy']) : array(0));
        }
        return $ret;
    }

    public function readTuneDef($item) {
        $fldname = isset($item['name'])? trim("{$item['name']}") : '';
        $ret = [ 'name' => strtolower($fldname) ];
        if(!empty($item['shiftx'])) $ret['shiftx'] = (float) $item['shiftx'];
        if(!empty($item['shifty'])) $ret['shifty'] = (float) $item['shifty'];
        return $ret;
    }

    # make array of field definitions for datagrid
    public function getFieldDefs($arParam, $ipage) {
        if(is_array($arParam[0])) return $arParam; # array of field definitions (in datagrid)
        $ret = [];
        foreach($this->_pagedefs[$ipage]['fields'] as $fno => $ftdef) {
            if(in_array($ftdef['name'],$arParam)) {
                $ret[] = $ftdef;
            }
        }
        return $ret;
    }
    /**
    * Activates "only selected fields" printing mode
    *
    * @param mixed $filter assoc.array with pages/fields to print
    */
    public function PrintFieldsFilter($filter) {
        $this->_printedfields = $filter;
    }
    /**
    * Renders PDF document [and sends to the browser or saves on the disc]
    *
    * @params $output if true (default) PDF contents will be sent to client (or saved to file),
    * otherwise You can do something after rendering and before sendind final document
    * @return true if pdf file generated, false if some errors
    */
    public function Render($output=true, $debug=false) {
        if ($debug) self::$debug = $debug;
        if(is_callable(self::$debug)) self::$debug(">>>>> start renering pages\n---------------------------------------");
        if(count($this->_pagedefs)<1 && $this->_specialPages==0) {
            $this->_errormessage = 'Configuration not loaded, Rendering impossible !';
            return false;
        }

        @ini_set('max_execution_time', 600);
        if (is_callable('WebApp::getAppState')) {
            if (100 <= WebApp::getAppState()) {
                while(ob_get_level()) {
                    ob_end_flush();
                }
                return;
            }
        }
        # if(count($this->_flextables)) exit(__FILE__ .':'.__LINE__.' _flextables:<pre>' . print_r($this->_flextables,1) . '</pre>');
        # writeDebugInfo("_outname: ", $this->_outname);
        if(empty($this->_outname)) {
            $this->_outname = 'generated.pdf';
            $off = max(strrpos($this->_outname, '/'), strrpos($this->_outname, '\\'));
            if($off!==false) $this->_outname = substr($this->_outname, $off+1);
        }
        if(!$this->_pdf) $this->_createPdfObject();

        if(!($this->_createPdfObject())) {
            if (is_callable(self::$debug)) self::$debug("PDF object not created");
            return false;
        }

        $this->_pdf->SetAutoPageBreak(false,0); # disable auto creating new pages if data doesn't fit on page

        $creator = empty($this->_config['creator']) ? 'Printform-pdf module by Alexander Selifonov, using TCPDF/FPDF classes' : $this->_convertCset($this->_config['creator']);
        $this->_pdf->SetCreator($creator);
        $author = empty($this->_config['author']) ? 'PrintFormPdf, TCPDF wrapper PHP class' : $this->_convertCset($this->_config['author']);
        $this->_pdf->SetAuthor($author);

        if(!empty($this->_config['title'])) $this->_pdf->SetTitle($this->_convertCset($this->_config['title']));
        if(!empty($this->_config['subject'])) $this->_pdf->Setsubject($this->_convertCset($this->_config['subject']));

        if($this->_rulerStep > 0 ) { # we'll draw a measuring grid
            $grcolor = array('R'=>200,'G'=>200,'B'=>200);
            if($this->_rulerColor) {
                $grcolor = $this->_parseColor($this->_rulerColor);
                # \WriteDebugInfo('grid color from ', $this->_rulerColor, ' is ', $grcolor);
            }
        }
        # Populating with data...
        foreach($this->_data as $entno=>$onedatablock) { #<3>
            $this->_dataBlock = $onedatablock;
            $this->dataentity = $onedatablock;
            $this->pageNoModifier = 0;
            $this->pgno = $this->prnPage = 0;
            $this->_curSrcFile = $this->_curSrcPage = $this->_curSrcPgcount = -1;
            $this->pageSubData = [];
            $curCfgId = 'INIT';
            # Main loop for generating one multi-paged document entity
            foreach($this->_pagedefs as $no=>$pagedef) { #<4>
                # skip the page if no printed fields in TEST mode:
                $cfgid = $pagedef['cfgid'] ?? "_cfgid-$no";
                if($cfgid !== $curCfgId) {
                    $curCfgId = $cfgid;
                    $usedTpPage = 0; # reset page template page!
                }
                # if(self::$DEBPRINT) echo ("page $no: homepath = ".($pagedef['homepath']??'none').", cfgid=[$cfgid]<br>");
                $homepath = $pagedef['homepath'] ?? '';
                # $this->pageSubData = []; # particular data for this page addresserd by subdataid
                if(is_callable(self::$debug)) self::$debug(">>> printing page [$no] ... ");
                $this->pgno++;
                if(!empty($pagedef['hide'])) {
                    continue; # page is temporary hidden by attrib "hide"
                }
                $hideApFld = $pagedef['hide_ap'] ?? FALSE;
                if (!empty($pagedef['basepar'])) {
                    $this->_tmpBasepar = $pagedef['basepar'];
                }
                else
                    $this->_tmpBasepar = $this->_basepar;

                if (!empty($this->_tmpBasepar['font']['color'])) {
                    $this->defaultTextColor = $this->colorToDec($this->_tmpBasepar['font']['color']);
                }
                else $this->defaultTextColor = [0,0,0];

                if(is_array($this->_printedfields) && (!isset($this->_printedfields[$no+1]) OR count($this->_printedfields[$no+1])<=0)) {
                    continue;
                }

                if (!empty($pagedef['gridpage'])) {
                    $this->_drawGridPage($entno, $pagedef);
                    continue;
                    # $dataid = $pagedef['gridpage']['datasource'];
                }

                # $this->_pdf->addPage($orientation);
                # writeDebugInfo("added page, orientation: [$orientation], pageSpecs:", $this->pageSpecs);
                $this->prnPage++;
                if (!empty($pagedef['pageevent'])) {
                    if(is_callable($pagedef['pageevent'])) {
                        @call_user_func($pagedef['pageevent'], $this->prnPage); # run callback page-event hook
                    }
                    elseif(is_object($this->callbackObj) && method_exists($this->callbackObj, $pagedef['pageevent'])) {
                        $callFnc = $pagedef['pageevent'];
                        $this->callbackObj->$callFnc();
                    }
                }
                $pgPref = $this->_tmpBasepar['pgprefix'] . $this->pgno;
                if (isset($onedatablock[$pgPref]) && is_array($onedatablock[$pgPref])) {
                # there is specific [_pageNN] data sub-array for this page
                    $this->dataentity = array_merge($this->dataentity, $onedatablock[$pgPref]);
                }
                if (!empty($pagedef['datasubid'])) {
                    $pgPref = $pagedef['datasubid'];
                    if (isset($onedatablock[$pgPref]) && is_array($onedatablock[$pgPref])) {
                        # there is specific [subid] data sub-array for this page
                        $this->pageSubData = $onedatablock[$pgPref];
                        # $this->dataentity = array_merge($this->dataentity, $onedatablock[$pgPref]); # commented!
                    }
                }

                $pdfTpl = $pdfPage = false;
                # use explicit PDF file if set inside "<page">, otherwise - load page from "basic PDF template listed in XML "templatefiles" section
                $pageSpec = '';
                $realFileName = FALSE;
                if(!empty($pagedef['template']['src'])) {
                    # page has own pdf template file and page no
                    $pageNo = $pagedef['template']['page'] ?? 1;
                    $pageSpec = $this->getPdfStructure($pagedef['template']['src'],$pageNo,$homepath);
                    $orientation = $pageSpec['orientation'] ?? 'P';
                    $realFileName = $pageSpec['pdf'] ?? '';
                    $realPage = $pageSpec['page'] ?? $pageNo;
                    if(self::$DEBPRINT) echo "Using own template: $realFileName / page[$realPage]<br>";
                    # writeDebugInfo("[$this->pgno]: from page template $realFileName/$realPage, from ", $pageSpec);
                }
                elseif(isset($this->tpPages[$cfgid][$usedTpPage]['pdf'])) {
                    # try to use next page from global pdf template pages list
                    $orientation = $this->tpPages[$cfgid][$usedTpPage]['orientation'] ?? 'P';
                    $realFileName = $this->tpPages[$cfgid][$usedTpPage]['pdf'] ?? '';
                    $realPage = $this->tpPages[$cfgid][$usedTpPage]['page'] ?? 1;
                    if(self::$DEBPRINT) echo "Using next page [$usedTpPage] from [$cfgid] collection<br>";

                    $usedTpPage++;
                }
                else {
                    $orientation = isset($pagedef['orientation'])? $pagedef['orientation'] : $this->_tmpBasepar['page']['orientation'];
                    $realFileName = $realPage = FALSE; # page without pdf template
                    if(self::$DEBPRINT) echo "Printing page w/out PDF template<br>";
                }
                $tplidx = NULL;
                $this->_pdf->addPage($orientation,'mm','A4');

                if(!empty($realFileName)) {
                    $this->_pdf->setSourceFile($realFileName);
                    $tplidx = $this->_pdf->importPage($realPage);
                    $this->_pdf->useTemplate($tplidx);
                }

                if($this->_rulerStep > 0 ) { # draw a measuring grid
                    $this->_renderMeasuringGrid();
                }
                elseif (!empty($pagedef['ruler'])) {
                    # personal ruler grid for this page
                    $saveStep = $this->_rulerStep;
                    $this->_rulerStep = ($pagedef['ruler'] <=2) ? 10 : $pagedef['ruler'];
                    $this->_renderMeasuringGrid();
                    $this->_rulerStep = $saveStep;
                }

                # Common fields existing on ALL pages
                if(!$this->_apFinally  && !$hideApFld) {
                    if(count($this->_apFields)) {
                        $this->_renderFieldSet($this->_apFields, array_merge($this->_apValues, $this->dataentity), $debug, null, $orientation);
                    }
                    # "ALL PAGES" fields from nested loaded XML, if current page is "nested":
                    if (!empty($pagedef['pageid'])) {
                        $pgid = $pagedef['pageid'];
                        if (isset($this->_subApFields[$pgid]) && count($this->_subApFields[$pgid])>0)
                            $this->_renderFieldSet($this->_subApFields[$pgid], array_merge($this->_subApValues[$pgid], $this->dataentity), $debug, null, $orientation);
                    }
                }

                $this->_renderFieldSet($pagedef['fields'], $this->dataentity, $debug, $pagedef);

                if (isset($pagedef['datagrids']) && is_array($pagedef['datagrids']) && count($pagedef['datagrids'])>0)
                  foreach ($pagedef['datagrids'] as $gridid) {
                    # only grids with assigned datasource array
                    $sourceid = trim(($this->_datagrids[$gridid]['datasource'] ?? ''));
                    $isempty = empty($sourceid);

                    if (!empty($sourceid) && (!array_key_exists($sourceid, $this->dataentity))) {
                        # writeDebugInfo("skip grid/No data in page values: sourceid: $sourceid, ");
                        continue; // no named data sub-array for datagrid, skip it
                    }
                    # if($sourceid) writeDebugInfo("grid sub-array[$sourceid]: ", $this->dataentity[$sourceid]);
                    # writeDebugInfo("datasource data for grid: ", $this->_data[$no][$sourceid]);
                    $step_y =  $this->_datagrids[$gridid]['step_y'] ?? 0;
                    $step_x =  $this->_datagrids[$gridid]['step_x'] ?? 0;
                    $max_x = $this->_datagrids[$gridid]['cols'] ?? 1;
                    $max_y = $this->_datagrids[$gridid]['rows'] ?? 1;
                    $order = $this->_datagrids[$gridid]['order'] ?? '';
                    $startRow = $this->_datagrids[$gridid]['startrow'] ?? 0; # skip first (startrow-1) data rows
                    $fillEmpty = $this->_datagrids[$gridid]['fillempty'] ?? FALSE;
                    $gridFields = $this->_datagrids[$gridid]['fields'] ?? '';
                    # writeDebugInfo("gridFields: ", $gridFields);
                    # WriteDebugInfo("step_y=$step_y step_x=$step_x max_y=$max_y max_x=$max_x order=[$order], fillEmpty = $fillEmpty!");
                    $page_xoff = $page_yoff = 0;
                    # $endRow = ($fillEmpty)  ? $max_y: (count($this->dataentity[$sourceid]);
                    $endRow = $max_y + $startRow;
                    $fromRow = ($startRow>0) ? ($startRow-1) : 0;
                    for ($krow = $fromRow; $krow < $endRow; $krow++) {
                        $rowExist = FALSE;

                        foreach ( $gridFields as $fldItem ) {
                            $fldid = is_array($fldItem) ? $fldItem['name'] : $fldItem;
                            $nRow = $krow+1;
                            if(!empty($sourceid))
                                $fldvalue = (isset($this->dataentity[$sourceid][$krow][$fldid])) ? $this->dataentity[$sourceid][$krow][$fldid] : NULL;
                            else
                                $fldvalue = (isset($this->dataentity[$fldid.$nRow])) ? $this->dataentity[$fldid.$nRow] : NULL;
                            # if (!in_array($fldid, $gridFields)) continue;
                            if($fldvalue !== NULL) $rowExist = TRUE;

                            if ($fldid === '_rowno_' && !empty($sourceid) && isset($this->dataentity[$sourceid][$krow]))
                                $fldvalue = $nRow; # line no. TODO: set for field_1 case

                            $myFdef = FALSE;
                            if(is_array($fldItem)) $myFdef = $fldItem;
                            else # seek field definition
                              foreach($pagedef['fields'] as $no => $fdef) {
                                if ($fdef['name'] !== $fldid) {
                                    # writeDebugInfo("$fdef[name] - no what we seek");
                                    continue;
                                }
                                $myFdef = $fdef;
                                break;
                                # print shifted value and break;

                            }

                            if($myFdef) { # filed definition found
                                $myFdef['posy'] += ($step_y * $page_yoff) + $this->_datagrids[$gridid]['posy'];
                                if($fillEmpty) $myFdef['fillempty'] = $fillEmpty;
                                foreach($myFdef['posx'] as &$oposx) {
                                    $oposx += $this->_datagrids[$gridid]['posx'] + $page_xoff * $step_x;
                                }
                                # if(empty($fldvalue) && $myFdef['type'] === 'cross') continue;
                                # writeDebugInfo("empty $myFdef[name]: type: $myFdef[type]");
                                $this->_valueToPdf($fldvalue, $myFdef);
                                $rowExist = 1;
                            }

                        }


                        if(!$rowExist) { # no more data, should we fill empty rows?
                            # if (self::$debug)  writeDebugInfo("TODO: $gridid / will empty row data $krow");
                            if($fillEmpty) foreach ( $gridFields as $fldid) {
                                if(is_array($fldid)) $fdef = $fldid;
                                else $fdef = $this->findFieldDef($pagedef['fields'], $fldid);
                                if(!$fdef) {
                                    if($krow <=1) writeDebugInfo("grid field $fldid not found in page field list ", $pagedef['fields']);
                                    continue;
                                }
                                if ($fldid === '_rowno_')
                                    $fldvalue = '--'; # line no.
                                else $fldvalue = '---------';
                                if(!empty($fdef['width'])) $fldvalue = str_repeat('-',ceil($fdef['width']*0.7));
                                # writeDebugInfo("$fdef[width] / $fldid: $fldvalue");
                                # seek field definition
                                # foreach($pagedef['fields'] as $no => $fdef) {
                                    # print shifted value and break;
                                    $fdef['posy'] += ($step_y * $page_yoff) + $this->_datagrids[$gridid]['posy'];
                                    foreach($fdef['posx'] as &$oposx) {
                                        $oposx += $this->_datagrids[$gridid]['posx'] + $page_xoff * $step_x;
                                    }

                                    $this->_valueToPdf($fldvalue, $fdef);
                                # }
                            }
                        }

                        # if ($krow < $max_y) {
                        if (is_callable(self::$debug)) self::$debug("$krow/$sourceid: less than max rows in grid row greater than fact rows in grid $max_y");
                        # }

                        if ($order == 'R') { # row fill first
                            if (++$page_yoff >=$max_y) {
                                $page_yoff = 0;
                                if (++$page_xoff >= $max_x) break;
                            }
                        }
                        else { #'C' - column fills first
                            if (++$page_xoff >=$max_x) {
                                $page_xoff = 0;
                                if (++$page_yoff >= $max_y) break;
                            }
                        }

                    }
                }
                # Now render datablocks if exist
                if(is_array($this->dataBlocks) && count($this->dataBlocks))
                foreach($this->dataBlocks as $dbNo => $arBlock) {
                    if($arBlock['page'] != $no) continue;
                    $defid = $arBlock['defid'];
                    if(!isset($this->blockdefs[$defid])) continue;
                    # atasource can be in form "dataid[1]" - so use it right
                    list($datasource, $dsRowNo) = self::parseDataSource($arBlock['datasource']);
                    $defBlk =& $this->blockdefs[$defid];
                    $offX = $arBlock['posx'];
                    $offY = $arBlock['posy'];
                    $blkFldSet = [];

                    foreach($defBlk['fields'] as $flNo => $blkField) {
                        $fldId = $blkField['name'];
                        if(isset($arBlock['tune'][$fldId])) {
                          # # there is tuned filed position inside block
                          if(isset($arBlock['tune'][$fldId]['shiftx'])) {
                              foreach($blkField['posx'] as &$oneX) {
                                  $oneX += $arBlock['tune'][$fldId]['shiftx'];
                              }
                          }
                          if(isset($arBlock['tune'][$fldId]['shifty'])) {
                              $blkField['posy'] += $arBlock['tune'][$fldId]['shifty'];
                          }
                        }
                        foreach($blkField['posx'] as &$oneX) {
                          $oneX += $offX;
                        }
                        $blkField['posy'] += $offY;
                        $blkFldSet[] = $blkField;
                    }
                    if(!empty($datasource)) {
                        if($dsRowNo !== NULL) {
                            if(isset($this->dataentity[$datasource][$dsRowNo]))
                                $this->_renderFieldSet($blkFldSet, $this->dataentity[$datasource][$dsRowNo], $debug);
                        }
                        else
                            $this->_renderFieldSet($blkFldSet, $this->dataentity[$datasource], $debug);
                    }
                    else
                        $this->_renderFieldSet($blkFldSet, $this->dataentity, $debug);
                }
                # draw flextable(s) on the page
                if (count($pagedef['flextables'])>0) foreach ($pagedef['flextables'] as $flexId) {
                    $this->drawFlexTable($flexId);
                }

                # Common fields existing on ALL pages - if order is "Finally" (after all printed fields)
                if($this->_apFinally) {
                    if(count($this->_apFields)) {
                        $this->_renderFieldSet($this->_apFields, array_merge($this->_apValues, $this->dataentity), $debug, 0, $orientation);
                    }
                    # "ALL PAGES" fields from nested loaded XML, if current page is "nested":
                    if (!empty($pagedef['pageid'])) {
                        $pgid = $pagedef['pageid'];
                        if (isset($this->_subApFields[$pgid]) && count($this->_subApFields[$pgid])>0)
                            $this->_renderFieldSet($this->_subApFields[$pgid], array_merge($this->_subApValues[$pgid], $this->dataentity), $debug, 0, $orientation);
                    }
                }

                if ($this->prnPage > 1) {
                    $ownPagination = $pagedef['own_pagination'] ?? FALSE;
                    if(empty($pagedef['own_pageno']))
                        $realPage =  $this->prnPage;
                    else {
                        $realPage = $pagedef['own_pageno'];
                        $this->prnPage--; # back to previous pageNo
                    }
                    $this->_drawPageNo(($realPage+$this->pageNoModifier), $ownPagination);
                }
                if(count($this->appendPdfs)>0) foreach($this->appendPdfs as $apDef) {
                    if($apDef['afterpage'] == $no) {
                        $pdfSrc = $this->_evalAttribute($apDef['src']);
                        $fullPdfName = $this->_pdf_path . $pdfSrc;
                        if(!is_file($fullPdfName)) $fullPdfName = $pdfSrc;
                        if(is_callable(self::$debug)) self::$debug("appendPdf $pdfSrc / finally full path: $fullPdfName, is_file:[".is_file($fullPdfName).']');
                        $orientation = 'P'; # TODO: get from every page parameters
                        if(is_file($fullPdfName)) {
                            # add all pages from PDF
                            # TODO: save current Source file and restore it after adding all pages from $fullPdfName
                            $pdfPageCount = $this->_pdf->setSourceFile($fullPdfName);
                            if(is_callable(self::$debug)) self::$debug("appendPdfs - set src file: $fullPdfName: pages: $pdfPageCount");
                            for($pdfPage=1; $pdfPage<=$pdfPageCount; $pdfPage++ ) {

                                $tmpPage = $this->_pdf->importPage($pdfPage);
                                $specs = $this->_pdf->getTemplateSize($tmpPage);
                                $orientation = ($specs['h'] > $specs['w'] ? 'P' : 'L');

                                $this->_pdf->addPage($orientation); # $orientation
                                $this->_pdf->useTemplate($tmpPage);

                                # TODO: print page if paginatioon is ON , ALLPAGES block if needed...`
                                if(!empty($apDef['allpages']) && count($this->_apFields)) {
                                    $this->_renderFieldSet($this->_apFields, array_merge($this->_apValues, $this->dataentity), $debug, null,$orientation);
                                }
                                if(!empty($apDef['pagination'])) {
                                    $this->pageNoModifier++;
                                    $this->_drawPageNo($this->prnPage + $this->pageNoModifier);
                                }
                            }
                        }
                    }
                }
            } #<4>
        } #<3>

        if(self::$DEBPRINT) exit("Debug End, collection:<pre>". print_r($this->tpPages,1).'</pre>'); # IF DEBUG PRINTING

        $this->_pdf->SetCompression($this->_compression); # will work if 'gzcompress' function enabled ! (see TCPDF docs)

        # send to callback final page count in generated PDF
        # writeDebugInfo("appendPdfs: ", $this->appendPdfs);
        if (!empty($this->hooks['hook_end'])) {
            if(is_callable($this->hooks['hook_end'])) {
                $callResult = call_user_func($this->hooks['hook_end'], [ 'lastPage'=> $this->prnPage ]);
            }
            elseif(is_object($this->callbackObj) && method_exists($this->callbackObj, $this->hooks['hook_end'])) {
                $callFnc = $this->hooks['hook_end'];
                $callResult = $this->callbackObj->$callFnc();
            }
        }
        if(!empty($this->_config['protectfile'])) { # file protecting
            $pwd = isset($this->_config['password']) ? (string)$this->_config['password'] : '00000';
            $this->_pdf->SetProtection(array(),$pwd); # ATTENTION: protecting (encrypting) can take ve-e-ery long, could cause PHP timeout
        }

        $ret = true;
        if($output) {
            $ret = $this->Output($this->_outname);
        }
        return $ret;
    }
    # for datasource like "key[Number]", parse it and return as array [key, Number]
    public static function parseDataSource($sDatasource) {
        $items = preg_split("/[\[\]]/", $sDatasource, -1, PREG_SPLIT_NO_EMPTY);
        if(isset($items[1])) return $items;
        return [$items[0], NULL];
    }

    /**
    * print pagination block,
    * @param mixed $realPageNo - substitute current page with this own number (inside specific XML block or ...)
    */
    protected function _drawPageNo($realPageNo=FALSE, $ownPagination=FALSE) {
        # TODO: define _basepar or _tmpBasepar use
        if($ownPagination) {
            # writeDebugInfo("$realPageNo own pagination: ", $ownPagination);
            $pgConfig = $ownPagination;
        }
        else $pgConfig = $this->_basepar['pagination'] ?? FALSE;

        if(!empty($pgConfig['skipfirst']) && $realPageNo == 1) return; # пропуск печати номера первой страницы

        if (!empty($pgConfig) && empty($this->_paginatonMode)) {
            $pageWidth  = $this->_pdf->getPageWidth();
            $pageHeight = $this->_pdf->getPageHeight();
            $margins = $this->_pdf->getMargins();

            $width = $pageWidth - 20;
            $posx = 10;
            $height = 10;
            $align = $pgConfig['align'];
            if ($align==='EDGE' || $align ==='E') # align page no to "outer" edge of page
                $align = ( $this->prnPage % 2) ? 'R' : 'L';
            if(!$realPageNo) $realPageNo = $this->prnPage;
            $value = str_replace('%page%', $realPageNo, $pgConfig['format']);
            $posy = ($pgConfig['posy'] === 'top') ?
                max(5,$margins['top']) : min( ($pageHeight-self::BOTTOM_H), ($pageHeight-$margins['bottom']));
            $this->_pdf->setFont($this->_basepar['font']['name'],'', self::PAGINATION_FONTSIZE);
            # $this->_pdf->SetTextColorArray([0,0,0]);
            $this->_pdf->MultiCell($width,$height,$value,0 , $align, 0, 1, $posx, $posy );
        }
    }

    protected function setPaginationMode($mode) {
        $this->_paginatonMode = $mode;
        # WriteDebugInfo("Page $this->prnPage, paginationMode set to [$mode]" );
        if ($mode === 'reset') $this->prnPage = 0; # TODO: 0 or 1?
    }
    /**
    * Printing  one or more (or none, if no data) data-grid filled pages
    *
    * @param mixed $entno adata array offset for current document
    * @param mixed $pagedef page definition
    */
    protected function _drawGridPage($entno, $pagedef) {
        # writeDebugInfo("_drawGridPage : ",$pagedef);
        $debug = false;
        $listid = $pagedef['gridpage']['datasource'];
        $fillEmpty = $pagedef['gridpage']['fillempty'];
        # $fillEmpty = $this->_datagrids[
        $fieldlist = $pagedef['gridpage']['fields'];
        $gridFldDefs = $stdFldDefs = [];
        foreach($pagedef['fields'] as $fldno => $fldef) {
            if (in_array($fldef['name'], $pagedef['gridpage']['fields']) || $fldef['name'] === '_rowno_')
                $gridFldDefs[$fldef['name']] = $fldef;
            else
                $stdFldDefs[$fldef['name']] = $fldef;
        }
        # if ($debug) WriteDebugInfo('defs for grid fields:', $gridFldDefs);
        # if ($debug) WriteDebugInfo('defs for std fields:', $stdFldDefs);
        if (empty($this->_data[$entno][$listid])) return false; // No data for grid pages!
        # if ($debug) WriteDebugInfo('to continue: $this->_data : ', $this->_data[$entno]);

        $orientation = isset($pagedef['orientation'])? $pagedef['orientation'] : '';

        $pdfTpl = $pdfPage = false;
        # use explicit PDF file if set inside "<page">, otherwise - load page from "basic PDF template listed in XML "templatefiles" section
        $startTplPage = 1;
        if(!empty($pagedef['template'])) {

            if(!empty($pagedef['template']['src'])) {
                $pdfTpl = (string)$pagedef['template']['src'];
                $pdfPage = isset($pagedef['template']['page']) ? ($pagedef['template']['page']) : 1;
                $startTplPage = $pdfPage;
            }
            if ( !empty($pdfTpl) && !empty($pdfPage) ) try {
                if (is_file($pdfTpl)) $fileFound = 1;
                elseif(is_file($this->_pdf_path . $pdfTpl)) $pdfTpl = $this->_pdf_path . $pdfTpl;
                elseif(is_file($pagedef['homepath'] . $pdfTpl)) $pdfTpl = $pagedef['homepath'] . $pdfTpl;
                elseif(!empty($pagedef['sourcepath']) && is_file($pagedef['sourcepath'] . $pdfTpl))
                    $pdfTpl = $pagedef['sourcepath'] . $pdfTpl;
                if (!is_file($pdfTpl)) {
                    # writeDebugInfo("ERR-02: PDF nnot found ", $pdfTpl, ' _pdf_path: ',$this->_pdf_path);
                    die ("Wrong config: template file $pdfTpl not found !");
                }
                if (is_callable(self::$debug)) self::$debug("pdf path: ",$pdfTpl);
            }
            catch (Exception $e) {
                $this->_errormessage = 'Loading template PDF error, cause : '.$e->getMessage();
                if (is_callable(self::$debug)) self::$debug($this->_errormessage," pagedef $no:",$this->pagedef);
            }
        }

        # output multi-paged grid data
        $itemoff = 0;
        $_rowno_ = 1;
        $printedrow = 0;
        $base_y = $cur_y = $pagedef['gridpage']['posy'];
        $base_x = $pagedef['gridpage']['posx'];
        $step_y =  $pagedef['gridpage']['step_y'];
        $step_x =  $pagedef['gridpage']['step_x'];
        $max_x = $pagedef['gridpage']['cols'];
        $max_y = $pagedef['gridpage']['rows'];
        $order = $pagedef['gridpage']['order'];

        $gdata = $this->_data[$entno][$listid];
        # writeDebugInfo("data to grid($max_y/$listid): ", $gdata);
        $lastItem = $max_x * $max_y;
        $stopper = 0;
        while (++$stopper <=20) { # was: count($gdata)>0
            $startTplPage = $pdfPage;
            $this->_addGridPage($orientation,$pdfTpl,$startTplPage, $stdFldDefs, $entno, $debug);

            # populate with grid rows
            $page_xoff = $page_yoff = 0;
            for($krow = 0; $page_xoff<$max_x && $page_yoff < $max_y; $krow++) {

                if ($step_x <= 0 && $step_y <= 0) break; # avoid endless loop
                $rowdata = (count($gdata) ? array_shift($gdata) : []);
                # foreach ($gridFldDefs as $flid => $fldef) { # draw one "row" of grid data
                foreach($pagedef['gridpage']['fields'] as $flid) {
                    if (strtoupper($flid) === PRINTPDFCONST::PF_ADDPAGE) {
                        #  pseudo field: add page before continue printing (one data row spreads to 2 or more pages)
                        $startTplPage++;
                        $this->_addGridPage($orientation,$pdfTpl,$startTplPage, $stdFldDefs, $entno, $debug);
                        continue;
                    }
                    if(!isset($gridFldDefs[$flid])) {
                        # writeDebugInfo("grid field $flid not in gridFldDefs, list is ", implode(',', array_keys($gridFldDefs)));
                        continue;
                    }
                    $fldef = $gridFldDefs[$flid];

                    foreach($fldef['posx'] as &$oposx) {
                        $oposx += $base_x + $page_xoff * $step_x;
                    }
                    $fldef['posy'] += $base_y + $page_yoff*$step_y;
                    $value = (isset($rowdata[$flid]) ? $rowdata[$flid] : '');
                    if ($flid === '_rowno_' && $value ==='' && count($rowdata)) $value = $_rowno_;

                    if ($fldef['type'] === 'plugin') {
                        $this->renderPlugin($fldef, $rowdata, ($page_xoff * $step_x), ($page_yoff*$step_y));
                    }
                    else {
                        if($fillEmpty) $fldef['fillempty'] = 1;
                        # if($value !=='' || $value!=0)
                        $this->_valueToPdf($value, $fldef);
                        # elseif($fldef['name'] == 'fullname') writeDebugInfo("KT-002B $fldef[name] empty grid field :" , $fldef);
                        /*
                        elseif($fillEmpty && count($rowdata)==0) {
                            if(!empty($fldef['height'])) $this->strokeEmptyValue($fldef);
                            else {
                                $value = ( empty($fldef['width']) ? '---------' : str_repeat('-',ceil($fldef['width']*0.7)) );
                                $this->_valueToPdf($value, $fldef);
                            }
                        }
                        */
                    }
                }

                $_rowno_++;
                if ($order == 'R') { # row fill first
                    if (++$page_yoff >=$max_y) {
                        $page_yoff = 0;
                        if (++$page_xoff >= $max_x) {
                            if (count($gdata)<1) break 2;
                            break;
                        }
                    }
                }
                else { #'C' - column fills first
                    if (++$page_xoff >=$max_x) {
                        $page_xoff = 0;
                        if (++$page_yoff >= $max_y) {
                            if (count($gdata)<1) break 2;
                            break 2;
                        }
                    }
                }
                if (count($gdata)<1 && !$fillEmpty) {
                    if (is_callable(self::$debug)) self::$debug("3 $page_xoff/$page_yoff: breaking gridpawe, data count:", count($gdata));
                    break 2;
                }
            }
        }
    }

    # add (another one) page before drawing "grid/datasourced" page
    private function _addGridPage($orientation,$pdfTpl,$pdfPage, $stdFldDefs, $entno,$debug) {

        $this->_pdf->addPage($orientation);
        if ($this->_paginatonMode!=='stop') $this->prnPage++;
        if ($pdfTpl) { # apply PDF template
            $pc = $this->_pdf->setSourceFile($pdfTpl);
            $pg = $this->_pdf->importPage($pdfPage);
            $this->_pdf->useTemplate($pg);
        }

        if ($this->_rulerStep > 0) $this->_renderMeasuringGrid();

        if(count($this->_apFields)) { # strings printed on all pages
            $this->_renderFieldSet($this->_apFields, array_merge($this->_apValues, $this->_data[$entno]), $debug, 0, $orientation); # first print "ALL PAGES" fields
        }
        if(count($stdFldDefs)) { # "standard" fields (no grid data)
            $this->_renderFieldSet($stdFldDefs, array_merge($this->_apValues, $this->_data[$entno]), $debug); # first print "ALL PAGES" fields
        }
        $this->_drawPageNo();
    }
    /**
    * Stroke non-filled array by Horizonytal line, to avoid "hand-made" adding text
    *
    * @param mixed $fldef field definition
    * @param mixed $step_y verticval step in current print units
    */
    public function strokeEmptyValue($fldef, $thickness = 1) {

        if ($fldef['type'] == 'checkbox') return;
        $x1 = $fldef['posx'][0] + 1;
        $fntWeight = empty($fldef['size']) ? 1.7 : $fldef['size']*0.2;
        $ypos = $fldef['posy'] + $fntWeight;
        $x2 = $x1 + (empty($fldef['width']) ? 10 : max(floatval($fldef['width'])-2, 8));
        // $this->_pdf->SetDrawColor($this->defaultTextColor);
        $color = !empty($fldef['color']) ? $fldef['color'] : $this->defaultTextColor;
        $dcolor = $this->colorToDec($color);
        if ($fldef['fillempty']!== 1) $thickness = max(0.1, floatval($fldef['fillempty']));
        $style = ['width' => floatval($thickness/2), 'cap' => 'butt', 'join' => 'miter',
          'dash' => 0, 'color' => $dcolor
        ];
        $this->_pdf->SetLineStyle($style);


        $polyArr = [$x1, $ypos, $x2, $ypos];
        if (!empty($fldef['height']) && $fldef['height'] >= self::$MIN_ZIGZAG_WEIGHT) {
            # make "zigzag" over printing field area
            $polyArr[] = $x1;
            $polyArr[] = ($ypos + $fldef['height'] - 2.4);
            $polyArr[] = $x2;
            $polyArr[] = ($ypos + $fldef['height'] - 2.4);
        }
        $this->_pdf->Polygon($polyArr,'', [], [], FALSE);
        $this->_pdf->SetLineStyle(['width'=>0.1, 'color'=>$this->defaultTextColor]);
    }
    /**
    * Adds block of data for "datagrid" block
    *
    * @param string $gridId  - existing datagrid definitions's ID (name)
    * @param mixed $data assoc.array containing all data values for one grid row
    * @param $returnval if true, function generates assoc/array and returns it, otherwise, adds these values to the current _data[] block.
    * TODO: implement "multipage" grid feature: when added row exceeds grid capacity, add new page and start filling from the top.
    */
    public function AddDataGridRow($gridId, $data, $returnval=false) {
        if(!is_array($data)) return [];
        if(!isset($this->_datagrids[$gridId])) return [];

        if(!isset($this->_curGridRow[$gridId])) $this->_curGridRow[$gridId] = 0;
        $this->_curGridRow[$gridId] +=1;
        if($this->_curGridRow[$gridId] > $this->_datagrids[$gridId]['rows']) {
            if(empty($this->_datagrids[$gridId]['multipage'])) return false; # no more rows allowed!
            # TODO: add new page, re-print "header" fields and begin new grid
        }
        $griddata = [];
        foreach($this->_datagrids[$gridId]['fields'] as $fldid) {
            # writeDebugInfo("grid $gridId  field: ", $fldid);
            $thisfield = $fldid . $this->_curGridRow[$gridId];
            if(isset($data[$fldid])) $griddata[$thisfield] = $data[$fldid];
        }
        if($returnval) return $griddata;
        $pageNo = count($this->_data)-1; # AddData() should be called first !
        if($pageNo<0) return false;
        $this->_data[$pageNo] = array_merge($this->_data[$pageNo],$griddata);
        return true;
    }
    public static function getFieldNames($arFld) {
        $names = [];
        foreach($arFld as $item) {
            $names[] = $item['name'] ?? '-';
        }
        return implode(',', $names);
    }
    # $orientation P or L - to avoid wrong placed fields tuned for Portrait on Landscape page and vice versa
    protected function _renderFieldSet($fldset, $dataentity, $debug=false, $pagedef=null, $orientation='') {
        if(is_callable(self::$debug)) self::$debug("_renderFieldSet: ", self::getFieldNames($fldset));
        if(count($fldset)) foreach($fldset as $no=>$fcfg) {
            if(is_array($this->_printedfields) && !in_array($no, $this->_printedfields[$this->pgno])) continue; // print only selectd fields
            $fldname = $fcfg['name'];
            $fldtype = $fcfg['type'];
            if(!empty($fcfg['forient']) && !empty($orientation) && $fcfg['forient'] !== $orientation)
                continue;

            $value = NULL;
            # if (!empty($fcfg['fillempty'])) writeDebugInfo("to fillempty for $fcfg[name]!");
            if(in_array($fldtype,array('alwais_rect','poly','image'))) $dataentity[$fldname] = 1;
            if ($fcfg['if']!==NULL) {
                $ifFunc = (substr($fcfg['if'],0,1)==='@') ? substr($fcfg['if'],1) : $fcfg['if'];
                $bDo = FALSE;
                if(is_callable($ifFunc)) {
                # perform user "signing" function over created PDF file
                    $bDo = call_user_func($ifFunc, $dataentity);
                }
                elseif(is_object($this->callbackObj) && method_exists($this->callbackObj, $ifFunc)) {
                    $bDo = $this->callbackObj->$ifFunc();
                }
                if (!$bDo) continue;
            }
            if($fldtype!=='plugin') {
                if(!isset($this->pageSubData[$fldname]) && !isset($dataentity[$fldname]) && empty($fcfg['fillempty']))
                    continue;
                if (isset($this->pageSubData[$fldname])) {
                    $value = $this->pageSubData[$fldname];
                }
                elseif (isset($dataentity[$fldname])) {
                    $value = $dataentity[$fldname];
                }
                elseif (isset($this->dataentity[$fldname])) $value = $this->dataentity[$fldname];
                else $value = '';
                if(!is_scalar($value)) {
                    # if (self::$debug) writeDebugInfo("non-scalar value, skip", $value);
                    continue;
                }
            }
            else {
               $debugValue = ($fcfg['type']=='checkbox') ? 'X' : "X $fldname";
            }
            $strval = ($value !== NULL) ? $value : ($debug ? $debugValue : '');

            if(!empty($fcfg['convert'])) {

                if(is_callable($fcfg['convert'])) # user converter function
                $strval = call_user_func($fcfg['convert'], $strval, $dataentity); # second param - the whole data array
            }
            /****
            if($strval === '' && !empty($fcfg['fillempty'])) {
                if(!empty($fldef['height'])) {
                    $this->strokeEmptyValue($fcfg);
                    continue;
                }
                else {
                    $strval = ( empty($fldef['width']) ? '---------' : str_repeat('-',ceil($fldef['width']*0.7)) );
                }
            }
            if($strval==='' && $fldtype!=='plugin') {
                continue;
            }
            ****/

            $initval = '';
            $fontsize = empty($fcfg['fontsize'])? 0 : $fcfg['fontsize'];

            $this->_valueToPdf($strval, $fcfg);

            # repeat printing with offsets defined in 'repeat' block
            if (empty($fcfg['norepeat']) && isset($pagedef['repeat']) && count($pagedef['repeat'])>0) foreach($pagedef['repeat'] as $repeat) {
                if (empty($repeat[2])) continue;
                $tmpcfg = $fcfg;
                foreach(array_keys($tmpcfg['posx']) as $kk) { $tmpcfg['posx'][$kk] += $repeat[0]; }
                if(is_array($tmpcfg['posy'])) foreach(array_keys($tmpcfg['posy']) as $kk) { $tmpcfg['posy'][$kk] += $repeat[1]; }
                else $tmpcfg['posy'] += $repeat[1];
                $this->_valueToPdf($strval, $tmpcfg);
            }
        }
        else {
            if (is_callable(self::$debug)) self::$debug("empty fieldset, none to print ", $fldset);
        }
    }
    /**
    * Outputs generated PDF according to _tofile parameter (sends to te browser or saves to disc)
    * @param $toMemory - pass true if Ypu want to return PDF in memory
    */
    public function Output($outfilename=false, $toMemory = false) {

        if($outfilename) $this->_outname = $outfilename;

        $result = '';
        if($this->_tofile && !$toMemory) {
            # writeDebugInfo("_templatefile: ", $this->_templatefile);
            if (isset($this->_templatefile[0]['src']) && is_string($this->_templatefile[0]['src']) &&
              strtolower($this->_templatefile[0]['src'])==strtolower($this->_outname)) $this->_outname .= '.pdf'; # protect accidental template overwriting !
            $result = $this->_pdf->Output($this->_outname,'F');
            if (!empty($this->signFunc)) {
                if (is_callable(self::$debug)) self::$debug("calling sign func: $this->signFunc ...");
                $callResult = 'no call';
                if(is_callable($this->signFunc)) {
                # perform user "signing" function over created PDF file
                    $callResult = call_user_func($this->signFunc, $this->_outname);
                }
                elseif(is_object($this->callbackObj) && method_exists($this->callbackObj, $this->signFunc)) {
                    $callFnc = $this->signFunc;
                    $callResult = $this->callbackObj->$callFnc($this->_outname);
                }

                if (!empty($callResult['errorMessage'])) self::$errorMessages[] = $callResult['errorMessage'];
            }
        }
        else {
            while(ob_get_level()) { ob_end_clean(); }

            $outDest = ($toMemory ? 'S' : 'D');
            $mdest = $outDest;
            if (!empty($this->signFunc) ) {
                $mdest = 'S'; # to sign file, we need it's body!
            }
            $result = $this->_pdf->Output($this->_outname, $mdest);
            $callFnc = $this->signFunc;

            if (!empty($callFnc)) {
                if( $result && is_callable($callFnc) ) {
                    $result = call_user_func($callFnc, $result);
                }
                elseif( $result && is_object($this->callbackObj) && method_exists($this->callbackObj, $callFnc) ) {
                    $result = $this->callbackObj->$callFnc($this->_outname);
                }
                if (is_callable(self::$debug)) self::$debug("signFunc call result: ", (substr($result,0,4) ==='%PDF' ? '(PDF bofy)' : $result));
            }
            if ($toMemory) return $result;

            else {
            # send to client response
                # WriteDebugInfo("sending signed PDF body to client...");
                if (!headers_sent()) {

                    header('Content-Description: File Transfer');
                    header('Cache-Control: protected, must-revalidate, post-check=0, pre-check=0, max-age=1');
                    //header('Cache-Control: public, must-revalidate, max-age=0'); // HTTP/1.1
                    header('Pragma: public');
                    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
                    header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
                    // force download dialog
                    if (strpos(php_sapi_name(), 'cgi') === false) {
                        header('Content-Type: application/force-download');
                        header('Content-Type: application/octet-stream', false);
                        header('Content-Type: application/download', false);
                        header('Content-Type: application/pdf', false);
                    } else {
                        header('Content-Type: application/pdf');
                    }
                    // use the Content-Disposition header to supply a recommended filename
                    header('Content-Disposition: attachment; filename="'.basename($this->_outname).'";');
                    header('Content-Transfer-Encoding: binary');
                }
                echo $result;
                # WriteDebugInfo("PDF body sent to client");
                exit;
            }

        }

        $this->_pdf = null;
        $this->_data = [];
        if ($toMemory) return $result;
    }

    public function findFieldDef($fldList, $fldid) {
        foreach($fldList as $no =>$fdef) {
            if($fdef['name'] === $fldid) return $fdef;
        }
        return FALSE;
    }

    public static function getErrorMessages() {
        return self::$errorMessages;
    }

    protected function _evalAttribute($attr) {
        if(!$attr) return $attr;
        $attr = (string)$attr;
        if(substr($attr,0,1)=='@') {

            $atfunc = substr($attr,1);
            if(is_callable($atfunc)) return call_user_func($atfunc, $this->dataentity);
            elseif( is_object($this->callbackObj) && method_exists($this->callbackObj, $atfunc) ) {
                return $this->callbackObj->$atfunc($this->dataentity);
            }
            return '';
        }
        else {
            return $attr;
        }
    }

    # TCPDF 6.x moves convertHTMLColorToDec() to static method of TCPDF_COLORS class
    protected function colorToDec($clr) {
        if (is_array($clr)) return $clr;
        if (is_numeric($clr) && $clr<=255)
            return ['R'=>$clr, 'G'=>$clr, 'B'=>$clr];

        if(class_exists('TCPDF_COLORS')) $ret = TCPDF_COLORS::convertHTMLColorToDec($clr,$this->spot_colors);
        else $ret = $this->_pdf->convertHTMLColorToDec($clr);
        return $ret;
    }

    protected function _valueToPdf($value, $fcfg) {
        static $fillHiOff = 0;

        if(!isset($fcfg['name'])) return;
        $fldname = $fcfg['name'];

        if ( in_array($fldname, $this->_hide_fields) ) return;
        if (empty($value) && $fcfg['fillempty']) {
            if(!empty($fcfg['height'])) {
                $this->strokeEmptyValue($fcfg);
                return;
            }
            else {
                $value = ( empty($fcfg['width']) ? '---------' : str_repeat('-',ceil($fcfg['width']*0.7)) );
            }
        }
        # $locDeb = ($fldname === 'sessign_date');
        # if ($locDeb) writeDebugInfo("$fldame value is : ", $value);

        $origwidth  = $width  = empty($fcfg['width']) ? 0 : (float)$fcfg['width'];
        $origheight = $height = empty($fcfg['height']) ? 0 : (float)$fcfg['height'];
        $fldtype = isset($fcfg['type']) ? $fcfg['type'] : '';
        $posx = empty($fcfg['posx']) ? array(0) : $fcfg['posx'];
        $posy = empty($fcfg['posy']) ? 0 : $fcfg['posy'];

        # auto-adjust zero width and height
        if($width<=0 && $fldtype!=='image') {
            if (!is_scalar($posx[0])) \writeDebugInfo("strange posx : ", $posx, ' fcfg:', $fcfg);
            $width = $this->_pdf->getPageWidth() - $posx[0] - self::DEFAULT_MARGIN;
        }
        if($height<=0 && $fldtype!=='image') {
            $height = $this->_pdf->getPageHeight() - $posy - self::DEFAULT_MARGIN;
        }

        $cstep = empty($fcfg['charstep']) ? 0 : $fcfg['charstep'];
        $maxlen = empty($fcfg['maxlength']) ? 0 : $fcfg['maxlength'];
        $fntsize = empty($fcfg['size']) ? $this->_basepar['font']['size'] : (float)$fcfg['size'];
        $fntname = empty($fcfg['font']) ? $this->_basepar['font']['name'] : $fcfg['font'];

        $fntStyle = empty($fcfg['fontstyle']) ? $this->_basepar['font']['style'] : $fcfg['fontstyle'];
        $myColor = $fcfg['color'] ?? '';
        if (!empty($myColor) || $myColor === '0')
            $color = $this->_evalAttribute($myColor);
        else
            $color = $this->_basepar['font']['color']; # $this->defaultTextColor;

        $bgcolor = $this->_evalAttribute($fcfg['bgcolor'] ?? '');
        $opacity  = $fcfg['opacity'] ?? 1;

        # testing mode for fields with height+width - add test background
        if(empty($bgcolor) && $origheight > 0 && $origwidth>0 && $this->_fillHiBlocks) {
            if(in_array($fldtype, array('','text','money','date'))) {
                # writeDebugInfo("$fldname: [w=$origwidth,h=$origheight] no bgcolor");

                $bgcolor = $this->_fillColors[$fillHiOff++];
                if($fillHiOff >= count($this->_fillColors)) $fillHiOff = 0;
                if($opacity == 1) $opacity = 0.7;
            }
        }
        $src = empty($fcfg['src']) ? '' : $fcfg['src'];
        if (isset($this->_field_newattr[$fldname]['src']))
            $src = $this->_field_newattr[$fldname]['src'];
        $rotate = empty($fcfg['rotate']) ? 0 : floatval($fcfg['rotate']);
        $border = empty($fcfg['border']) ? 0 : (floatval($fcfg['border'])? floatval($fcfg['border']) : $fcfg['border']);
        $align  = isset($fcfg['align']) ? $fcfg['align'] : '';
        $valign  = isset($fcfg['valign']) ? $fcfg['valign'] : '';
        if ($valign ==='C') $valifn = 'M';

        $foptions = $this->_evalAttribute($fcfg['options'] ?? '');
        if($fntname === 'arialb') $fntname = 'arialbd';
        $this->_pdf->SetFont($fntname, $fntStyle, $fntsize);

        if($color) {
            $rgb = is_array($color) ? $color : $this->colorToDec($color);
        }
        else $rgb = array(0,0,0);

        $this->_pdf->SetTextColorArray($rgb);# writeDebugInfo(">SetTextColorArray(rgb):", $rgb);
        if ($opacity < 1) {
            $this->_pdf->setAlpha($opacity);
            # writeDebugInfo("setAlpha($opacity)");
        }

        if($rotate) {
            $this->_pdf->StartTransform();
            $this->_pdf->Rotate($rotate,$posx[0],$posy);
        }

        if($bgcolor) { # draw "background" filled ractangle
            $brgb = $this->colorToDec($bgcolor);
            if($brgb && $width>0 && $height>0) {
                $this->_pdf->Rect($posx[0], $posy, $width, $height, 'F', [], array_values($brgb));
                # Rect($x, $y, $w, $h, $style='', $border_style=array(), $fill_color=array())
            }
        }

        if ($fldtype==='image') {
            if( $this->_images_disabled ) return;
            if(is_file($this->_img_path . $src)) $src = $this->_img_path . $src;
            elseif(is_file($this->homeDir . $src)) $src = $this->homeDir . $src;
            if (!is_file($src)) {
                return; # image not found!
            }
            $isize = self::getImageInfo($src);
            if ($width==0 || $height ==0 ) { // get skipped parameter (height|width) from real image file
                $isize = self::getImageInfo($src);
                if (empty($isize[1])) return;
                $wtoh = $isize[0] / $isize[1];
                if ($height <=0 ) $height = round($width / $wtoh, 4);
                elseif ($width <= 0) $width = round($width * $wtoh, 4);
            }
            $this->_pdf->Image($src,$posx[0],$posy,$width,$height);
        }
        elseif(in_array($fldtype, array('','text','money','date'))) {
            if($maxlen>0 && mb_strlen($vUtf)>$maxlen) {
                $vUtf = mb_substr($vUtf,0,$maxlen,'UTF-8');
            }

            $fitCell = ($this->adaptive_sizing && !empty($origheight) && !empty($origwidth));
            if ($fntsize > 0) {
                $this->_pdf->setFontSize($fntsize);
            }

            if(count($posx)>1) { # output by char to predefined x positions
                for($kk=0;$kk<mb_strlen($vUtf);$kk++) {
                    if(!isset($posx[$kk])) break;
                    if($posx[$kk]>0)
                        $this->_pdf->MultiCell($width,$height,mb_substr($vUtf,$kk,1,'UTF-8'), $border, $align, 0, 1, floatval(($posx[$kk]+$this->offsets[0])), floatval(($posy+$this->offsets[1])));
                }
            }
            elseif($cstep) { # TODO: use emptyfill attr Char to fill "empty" boxes (maxlength attrib must be set!)
                for($kk=0; $kk<mb_strlen($vUtf); $kk++) {
                    $this->_pdf->MultiCell($width,$height,mb_substr($vUtf,$kk,1,'UTF-8'), $border, $align, 0, 1, floatval($posx[0]+$this->offsets[0]+($cstep*$kk)), floatval($posy+$this->offsets[1]) );
                }
            }
            else {
                $b_border = 0;
                if ($border) {
                    $brdclr = $this->_parseColor($border);
                    $b_border = 1;
                    $this->_pdf->setLineStyle(array('width'=>0.2, 'color'=>$brdclr));
                    $this->_pdf->Rect($posx[0]+$this->offsets[0], $posy+$this->offsets[1], $width, $height,'',array('L'=>1,'T'=>1,'B'=>1,'R'=>1));
                }
                if($fldtype === 'money' && is_numeric($value)) {
                    $vUtf = number_format(floatval($value),2,'.',' ');
                }
                else $vUtf = $this->_convertCset($value);
                if(!is_numeric($posx[0]) ||!is_numeric($this->offsets[0]) || !is_numeric($posy) ||!is_numeric(+$this->offsets[1]))
                    exit(__FILE__ .':'.__LINE__.' non-numeric coords:<pre>posx: ' . print_r($posx[0],1) .'<br>offsets:'. print_r($this->offsets[0],1)
                      .'<br>$posy '. print_r($posy,1) .'<br>$this->offsets[1]: '. print_r($this->offsets[1][0],1) . '</pre>');

                if($align==='J' &&  self::$autoLFJustify) {
                    $lastChar = mb_substr($vUtf,-1,NULL,'UTF-8');
                    if($lastChar != "\n") $vUtf .= "\n"; # avoid stupid shrinking of last line in Justified text
                }
                $this->_pdf->MultiCell($width,$height,$vUtf, $border, $align, 0, 1, ($posx[0]+$this->offsets[0]),
                  ($posy+$this->offsets[1]), TRUE,0,false,TRUE,$height,$valign, $fitCell);
                # writeDebugInfo("out $fcfg[name] = $vUtf");
            }
            # Get back to "std" font & text color
            # if($this->_tmpBasepar['font']['name'])
            # $this->_pdf->SetFont($this->_tmpBasepar['font']['name'],'',(float)$this->_basepar['font']['size']);
            $this->_pdf->SetFont($this->_tmpBasepar['font']['name'],$this->_tmpBasepar['font']['style'],
              (float)$this->_basepar['font']['size']);

            $this->_pdf->SetTextColorArray($this->defaultTextColor); # back to default text color
        }

        elseif ($fldtype === 'html') { # render string as HTML code
            $vUtf = $this->_convertCset($value);
            if($fntname !='' && $this->_tmpBasepar['font']['name'] != $fntname) {
                $this->_pdf->SetFont($fntname, $fntStyle, $fntsize);
            }
            if ($fntsize !=0 && $this->_tmpBasepar['font']['size'] != $fntsize) {
                $this->_pdf->setFontSize($fntsize);
            }
            # writeHTMLCell($w, $h, $x, $y, $html='', $border=0, $ln=0, $fill=0, $reseth=true, $align='', $autopadding=true)
            $this->_pdf->writeHTMLCell($width, $height, $posx[0], $posy, $vUtf, 0, 0, 0, true, $align, true);
            // get back to defaults:
            # if($this->_tmpBasepar['font']['name'])
                $this->_pdf->SetFont($this->_tmpBasepar['font']['name'],$this->_tmpBasepar['font']['style'],
                  (float)$this->_tmpBasepar['font']['size']);

            $this->_pdf->setFontSize($this->_tmpBasepar['font']['size']);
        }

        elseif($fldtype==='checkbox' or $fldtype==='check') {
            if ( $fntsize !=0 ) {
                $this->_pdf->setFontSize($fntsize);
            }
            if(!empty($value)) $this->_pdf->MultiCell($width,$height,'X', $border, $align, 0, 1, ($posx[0]+$this->offsets[0]), ($posy+$this->offsets[1]) );
        }

        elseif(substr($fldtype,0,7)==='barcode') { # barcode field marked as "barcode:<BCTYPE>"
            $bctype = substr($fldtype,8);
            if(empty($bctype)) $bctype = 'C39';
            #bctype is one of TCPDF supported: C39 | C39+ | C39E | C39E+ |C93|S25|S25+|I25|I25+|C128|C128A|C128B|C128C|EAN2|EAN5|EAN8 ...
            $xres = '';
            $arr = explode(',',$foptions);
            $style= array(
                'fgcolor'=>$rgb
               ,'text'=>in_array('text',$arr)
               ,'border'=>in_array('border',$arr)
            );
            foreach($arr as $item) {
                $splt = explode('=',$item);
                if(count($splt)>1) { # attribute=value pair, try to use numbers as numbers, not strings
                    $val = is_int($splt[1]) ? intval($splt[1]) : $splt[1];
                    $style[$splt[0]] = $val; # so 'fontsize=8' will become $style['fontsize']=8
                }
            }
            if(!isset($style['stretchtext'])) $style['stretchtext'] = 0; # text without stretching by default!
            if(!empty($style['fontsize']) && empty($style['font'])) $style['font'] = $this->_tmpBasepar['font']['name']; # without 'font', fontsize is ignored in TCPDF!
            # if($rgb) $style['fgcolor'] = $rgb;
            $this->_pdf->write1DBarcode($value, $bctype, ($posx[0]+$this->offsets[0]), ($posy+$this->offsets[1]), $width, $height, $xres, $style, $align);
        }

        elseif(substr($fldtype,0,6)=='qrcode') { # Printing QRCode "qrcode:QRCODE,L"
            $qrtype = strtoupper(substr($fldtype,7));
            if(in_array($qrtype, ['L','M','Q','H']))
                $qrtype = 'QRCODE,' .$qrtype; # supported: "QRCODE"="QRCODE,L","QRCODE,M","QRCODE,Q","QRCODE,H"
            else $qrtype = 'QRCODE,L'; # default L (low ECC)

            $style=[];
            if($rgb) $style['fgcolor'] = $rgb;
            $this->_pdf->write2DBarcode($value, $qrtype, ($posx[0]+$this->offsets[0]), ($posy+$this->offsets[1]), $width, $height, $style, $align, false);
        }

        elseif($fldtype=='rect') {
            if (!empty($value)) { # draw only if value non empty!
                # $line_style = array('all'=> array('width' => 1, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => $rgb));
                $this->_pdf->SetDrawColorArray($rgb);
                # $this->_pdf->Polygon($p, $style, array(),array(),true);
                $style = '';
                $border_style = [];
                $fill_color = [];
                if ($bgcolor) $fill_color = $this->colorToDec($bgcolor);
                if ($fcfg['thickness']>0) {
                    # $border_style['width'] = $fcfg['thickness'];
                    $this->_pdf->SetLineWidth($fcfg['thickness']);
                    # $border_style['join'] = 'bevel';
                }

                $this->_pdf->Rect($posx[0], $posy, $width, $height, $style, $border_style, $fill_color);
                $this->_pdf->SetLineWidth(0.2);
            }
            # Polygon($p, $style='', $line_style=array(), $fill_color=array(), $closed=true)
        }

        elseif($fldtype=='poly') {
            $p = [];
            for($kk=0;$kk<min(count($posx),count($posy));$kk++) {
                $p[] = $posx[$kk]; $p[] = $posy[$kk];
            }
            $style = ['dash'=>0, 'join' => 'miter', 'color' => $rgb];
            if ($fcfg['thickness']>0) {
                $style['width'] = $fcfg['thickness'];
            }
            $this->_pdf->SetDrawColorArray($rgb);
            $this->_pdf->Polygon($p, $style, [],[], false);
        }
        elseif($fldtype==='cross') {
            if(empty($value)) return;
            $style = ['dash'=>0, 'join' => 'miter', 'color' => $rgb];
            if ($fcfg['thickness']>0) {
                $style['width'] = $fcfg['thickness'];
            }
            $this->_pdf->Line($posx[0], $posy,$posx[0]+$width, $posy+$height, $style);
            $this->_pdf->Line($posx[0], $posy+$height,$posx[0]+$width, $posy, $style);
        }

        elseif($fldtype==='plugin') { # Render specific area by calling plugin.Render()

            $plgclass = isset($fcfg['plugintype']) ? $fcfg['plugintype'] : '';
            if(isset($this->dataentity[$fldname])) {
                $plgData = $this->dataentity[$fldname];
            }
            elseif(isset($this->pageSubData[$fldname])) $plgData = $this->pageSubData[$fldname];
            else $plgData = $this->dataentity;
            # exit(__FILE__ . '/'.__LINE__." 001-data fro plugin [$fldname]<pre>" . print_r($plgData,1) . '</pre>');
            $this->renderPlugin($fcfg,$plgData);
        }
        if($rotate) $this->_pdf->StopTransform();
        if ($opacity < 1) {
            $this->_pdf->setAlpha(1); # back to normal (no transparency)
        }

        $this->_pdf->setFontSize($this->_tmpBasepar['font']['size']);
        $this->_pdf->SetFont(($this->_basepar['font']['name']??'arial'), '', (float)$this->_basepar['font']['size']);

        $this->_pdf->SetDrawColorArray(0);
    }
    # TODO: render Plugin
    public function renderPlugin($fcfg, $data, $xOff=0, $yOff=0) {
        $fldname = $fcfg['name'];
        $plgclass = isset($fcfg['plugintype']) ? $fcfg['plugintype'] : '';
        $foptions = $this->_evalAttribute($fcfg['options']);
        if($plgclass!='' && !class_exists($plgclass)) {
            # try auto-load class file
            $clsName = strtolower($plgclass);
            if (substr($clsName,0,6) === 'pfpdf_') $clsName = substr($clsName,6);
            $clsFile = __DIR__ . '/' . strtolower($clsName) . '.php';
            if (self::$debug) {
                error_reporting(E_ALL & ~E_STRICT & ~E_NOTICE);
                ini_set('display_errors', 1);
                ini_set('log_errors', 1);
            }
            if (is_file($clsFile)) {
                include_once($clsFile);
            }
            else {
                if (is_callable(self::$debug)) self::$debug("No plugin file found for $clsName");
            }

        }

        if($plgclass!='' && class_exists($plgclass)) {
            $opt_arr = [];
            if (!empty($foptions)) {
                $arr = explode(',',$foptions);
                foreach($arr as $elem) {
                    $optpair = explode('=',$elem);
                    if(count($optpair)>=2) $opt_arr[$optpair[0]] = $optpair[1];
                }
            }
            if (!empty($fcfg['color'])) $opt_arr['color'] = $fcfg['color'];
            else $opt_arr['font'] = $this->defaultTextColor;
            if (!empty($fcfg['size'])) $opt_arr['size'] = $fcfg['size'];
            else $opt_arr['size'] = $this->_basepar['font']['size'];
            if (!empty($fcfg['font'])) $opt_arr['font'] = $fcfg['font'];
            else $opt_arr['font'] = $this->_basepar['font']['name'];
            if (!empty($fcfg['bgcolor'])) $opt_arr['bgcolor'] = $fcfg['bgcolor'];
            if (!empty($fcfg['thickness'])) $opt_arr['thickness'] = $fcfg['thickness'];

            $posy = $fcfg['posy'];
            $width = $fcfg['width'];
            $height = $fcfg['height'];
            $xReal = $fcfg['posx'][0] + $xOff;
            $yReal = $posy+$yOff;

            $pdf_plg = new $plgclass($this->_pdf, array_merge($this->_config, $opt_arr), $xReal,$yReal,$width,$height);

            if($pdf_plg instanceof PfPdfPlugin) {
                # plugin-specific options can be passed in plugin attribute options="name=value1,..."
                # $pdf_plg->setConfig(array_merge($this->_config, $opt_arr));
                # if(count($opt_arr)) $pdf_plg->setConfig($opt_arr);
                if(is_array($data) && count($data)>0) $renderData = $data;
                else
                    $renderData = $this->_pluginData[$fldname] ?? $this->_dataBlock[$fldname] ?? $this->_dataBlock;

                # exit(__FILE__ . '/'.__LINE__." data for render<pre>" . print_r($renderData,1) . '</pre>');
                if(is_callable(self::$debug)) self::$debug("data for reneder in [$plgclass] plugin ", $renderData);
                $result = $pdf_plg->Render($renderData);
                if(!$result) {
                    $this->_errormessage = $pdf_plg->getErrorMessage();
                    if(is_callable(self::$debug) )
                        self::$debug("plugin $plgclass::Render() error : ", $this->_errormessage);
                }
            }
            else {
                $this->_errormessage = "Unknown plugin class [$plgclass] or not instance of PfPdfPlugin, rendering skipped";
                if (is_callable(self::$debug)) self::$debug($this->_errormessage);
            }
            unset($pdf_plg);
        }
        $this->_pdf->SetTextColorArray([0,0,0]);

    }
    /**
    * Appends a page to the PDF document
    *
    * @param mixed $orientation optional orientation ('P' for portrait, 'L' for Landscape', default - current configured value)
    * @param mixed $units optional units for the page ('mm' for millimeters etc., default - current configured value)
    */
    public function AddPage($orientation=false, $units=false) {
        if(!$this->_pdf) $this->_createPdfObject();
        $this->_pdf->AddPage(($orientation ? $orientation : $this->_config['page']['orientation']));
        $this->_pdf->setPageUnit(($units ? $units : $this->_config['page']['units']));
    }

    /**
    * Appends all page definitions from another XML config.file
    * All "base" parameters ignored (excluding <templatefiles>)
    * @param mixed $xmlCfg path to XML configuration file
    * @param mixed $subid id of element in data, that is sub-array of data specific to this page
    * @since 1.9
    * $arSubst = substitite array in form ['formString => 'ToString', ...]
    */
    public function AppendPageDefFromXml($xmlCfg, $subid='', $relpath = NULL, $arSubst = [], $dopCfg=FALSE) {
        if(self::$DEBPRINT) echo 'AppendPageDefFromXml: <pre>' . print_r($xmlCfg,1). '</pre>';
        if(is_callable(self::$debug)) self::$debug("AppendPageDefFromXml($xmlCfg, subid:'$subid', relpath:'$relpath'");
        if(!$this->father)
            $this->father = $this;

        $tmpCfg = new PrintFormPdf($xmlCfg, $this->callbackObj, $this->father, $subid, $arSubst);
        if(self::$DEBPRINT) echo "AppendPageDefFromXml($xmlCfg) after loading xml, cfgId is: ".$this->father->cfgId . "<br>";
        # \writeDebugInfo("tmpCfg: ", $tmpCfg);
        if (!$relpath) $relpath = $tmpCfg->homeDir;
        if (substr($relpath,-1) !== '/' && substr($relpath,-1) !== '\\')
           $relpath .= '/';
        $orientation = $tmpCfg->_basepar['page']['orientation'];

        if (count($tmpCfg->_templatefile)) {
            $tplFiles = [];
            foreach($tmpCfg->_templatefile as $oneTpl) {
                # echo ("template :<pre>". print_r($oneTpl, 1).'</pre>');
                if (!is_string($oneTpl['src'])) continue; # array - WTF?!
                $realXmlname = self::getRelativeFilename($relpath, (string)$oneTpl['src']);
                $tplpath = dirname($realXmlname);
                if ($tplpath) $tplpath .= '/';
                $tplname = basename($oneTpl['src']);
                # $oneTpl['src'] = $tplpath . $tplname;

                # $this->_templatefile[] = array('src'=>array($tplpath, $tplname, $tmpCfg->_img_path));
                $tplFiles[] = ['src'=>array($tplpath, $tplname, $tmpCfg->_img_path)];
            }

            $cfgId = $this->father->cfgId;
            if(isset($this->father->_templatefile[$cfgId]))
                $this->father->_templatefile[$cfgId] = array_merge($this->father->_templatefile[$cfgId] ,$tplFiles);
            else
                $this->father->_templatefile[$cfgId] = $tplFiles;
        }
        # writeDebugInfo("cnt of pagedefs: ", count($tmpCfg->_pagedefs));
        # merge all user  parameters
        $childPars = $tmpCfg->getUserParameters();
        if (count($childPars)) $this->userParams = array_merge($this->userParams,$childPars);

        unset($tmpCfg);
        if (is_callable(self::$debug)) self::$debug("my own basepar after AppendPageDefFromXml: ", $this->_basepar);
    }
    # returns last error message
    public function GetErrorMessage() { return $this->_errormessage; }

    /**
    * Printing music staff page for writing music. Misicians will like it:)
    *
    * @param string $title page title
    * @param array $options - optional parameters associative array
    *  'measures' - measures per line: if 2 or greater, piano roll will have "measure border" vertical bars.
    *  'step_roll' - distance between piano-roll blocks (mm), default is 27mm
    *  'step_line' -  distance between lines in the piano roll (mm), default is 2mm
    *  'color' -  drawing color (RGB array), default is black - [0,0,0]
    */
    public function AddPageMusicStaff($title='',$options=null) {
        $margin_l = $margin_r = 18;
        $margin_t = 20; $margin_b = 10;
        # $merged_staves=0, $measures=0, $step_y=0, $stepLine=0
        $merged_staves = isset($options['merged_staves']) ? intval($options['merged_staves']) : 0;
        $measures = isset($options['measures']) ? $options['measures'] : 0;
        $step_y = isset($options['step_roll']) ? intval($options['step_roll']) : 27;
        $stepLine  = isset($options['step_line']) ? intval($options['step_line']) : 2;
        $color = isset($options['color']) ? $this->_parseColor($options['color']) : array(0,0,0);
        $accolade = isset($options['accolade'])? $options['accolade'] : false;
        $blkheight = $step_y + ($stepLine*4);

        $this->AddPage('P','mm');

        $y_lowest = $this->_pdf->getPageHeight()-$margin_b;
        $rightpos = $this->_pdf->getPageWidth() - $margin_r;
        $beatstep = ($measures>0) ? round(($rightpos-$margin_l)/$measures,2) : 0;

        if($title) $this->_valueToPdf($title,
           array(
              'name'=>'pagetitle'
             ,'type'=>'text'
             ,'posx'=> $margin_l
             ,'posy'=> min(2,($margin_t-16))
             ,'width' => ($this->_pdf->getPageWidth() - $margin_l - $margin_r)
             ,'align'=>'C'
             ,'color'=>''
             ,'bgcolor'=>''
             ,'options'=>''
             ,'size'=> 10)
        );

        $this->_pdf->SetDrawColorArray($color);

        for($ypos=$margin_t; $ypos+(4*$stepLine) <= $y_lowest; $ypos+=$step_y) {
            for($line=0;$line<=4;$line++) {
                $this->_pdf->Line($margin_l,$ypos+($line*$stepLine),$rightpos,$ypos+($line*$stepLine));
            }
            $this->_pdf->Line($margin_l,$ypos,$margin_l,$ypos+(4*$stepLine)); # vertical bars
            $this->_pdf->Line($rightpos,$ypos,$rightpos,$ypos+(4*$stepLine));
            if($beatstep) for($beatpos = $margin_l; $beatpos<$rightpos; $beatpos += $beatstep)
                $this->_pdf->Line($beatpos,$ypos,$beatpos,$ypos+(4*$stepLine)); # vertical bars
        }
        if($merged_staves>1) {
            $yStart = $margin_t;
            $yEnd = $yStart + ($merged_staves-1)*$step_y;
            while( $yEnd<$y_lowest ) {
                $this->_pdf->Line($margin_l,$yStart,$margin_l,$yEnd);
                $this->_pdf->Line($rightpos,$yStart,$rightpos,$yEnd);
                if($accolade) $this->drawAccolade($margin_l-4.4,$yStart,$margin_l-0.5,($yEnd+4*$stepLine), $color);
                if($beatstep) for($beatpos = $margin_l; $beatpos<$rightpos; $beatpos +=$beatstep)
                    $this->_pdf->Line($beatpos,$yStart,$beatpos,$yEnd); # vertical bars
                $yStart += ($merged_staves)*$step_y;
                $yEnd = $yStart + ($merged_staves-1)*$step_y;
            }
        }
        $this->_specialPages++;
    }

    /**
    * Printing lined sheet page
    *
    * @param string $title page title
    * @param array $options - optional parameters associative array
    *  'step_y' - distance between horizontal lines, (mm). Default is 5mm
    *  'step_x' - distance between vertical lines, (mm). Default equal to step_y
    *  'color' -  drawing color (RGB array), default is light gray - [180,180,180]
    */
    public function AddPageLined($title='',$options=null) {
        $margin_l = $margin_r = $margin_t = $margin_b = 5;
        $step_y = isset($options['step_y']) ? intval($options['step_y']) : 5;
        $step_x = isset($options['step_x']) ? intval($options['step_x']) : $step_y;
        $color = $color1 = isset($options['color']) ? $this->_parseColor($options['color']) : array(180,180,180);
        $millimetrovka = ($options==='mm' OR !empty($options['mm'])); # millimeter sheet mode
        if($millimetrovka) {
            $step_x = $step_y = 1;
            $color1  = isset($options['color']) ? $this->_parseColor($options['color']) : array(90,90,90); # thick lines color
            $color = array(ceil($color[0]+(255-$color[0])*0.5),ceil($color[0]+(255-$color[1])*0.5),ceil($color[2]+(255-$color[0])*0.5)); # thin lines color
        }
        $this->AddPage('P','mm');

        $y_lowest = $step_y>0 ? $this->_pdf->getPageHeight()-$margin_b - ($this->_pdf->getPageHeight()-$margin_t-$margin_b)%$step_y : $this->_pdf->getPageHeight()-$margin_b;
        $rightpos = ($step_x>0) ? $this->_pdf->getPageWidth() - $margin_r - ($this->_pdf->getPageWidth()-$margin_l-$margin_r)%$step_x : $this->_pdf->getPageWidth()-$margin_r;
        if($title) {
            $this->_valueToPdf($title,
            array(
              'name'=> 'page_title'
             ,'type'=>'text'
             ,'posx'=> $margin_l
             ,'posy'=> min(2,($margin_t))
             ,'width' => ($this->_pdf->getPageWidth() - $margin_l - $margin_r)
             ,'align'=>'C'
             ,'size'=> 10)
             );
             $margin_t+=5;
        }

        $this->_pdf->SetDrawColorArray($color);
        $xx=0;
        if($step_y>0) for($ypos=$margin_t; $ypos<= $y_lowest; $ypos+=$step_y) {
            if($millimetrovka) {
                if(($ypos-$margin_t)%5==0) { # think line every 5 mm
                    $width = (($ypos-$margin_t)%10) ? 0.15 : 0.25; # 10mm-thicker
                    $this->_pdf->SetLineWidth($width);
                    $this->_pdf->SetDrawColorArray($color1);
                }
                else { # thin line
                    $this->_pdf->SetDrawColorArray($color);
                    $this->_pdf->SetLineWidth(0.05);
                }
            }
            $this->_pdf->Line($margin_l,$ypos,$rightpos,$ypos); # horiz.lines
        }
        if($step_x>0) for($xpos=$margin_l; $xpos<= $rightpos; $xpos+=$step_x) {
            if($millimetrovka) {
                if(($xpos-$margin_l)%5==0) { # think line every 5 mm
                    $width = (($xpos-$margin_l)%10) ? 0.15 : 0.25; # 10mm-thicker
                    $this->_pdf->SetLineWidth($width);
                    $this->_pdf->SetDrawColorArray($color1);
                }
                else { # thin line
                    $this->_pdf->SetDrawColorArray($color);
                    $this->_pdf->SetLineWidth(0.05);
                }
            }
            $this->_pdf->Line($xpos, $margin_t,$xpos, $y_lowest); # vert.lines
        }
        $this->_pdf->SetDrawColorArray(array(0,0,0)); # back to normal
        $this->_pdf->SetLineWidth(0.1);
        $this->_specialPages++;

    }

    /**
    * Fetching TCPDF object to manipulate beyong of this class functionality
    * @returns TCPDF object ref.
    */
    public function getPdfObject() {
        if(!$this->_pdf) $this->_createPdfObject();
        return $this->_pdf;
    }

    public function GetPageDefs() { return $this->_pagedefs; }

    /**
    * Draws music "Accolade" sign. Accolade will fit inside rectangle limited by x0,y0 - xk,$yk coordinates.
    * @param mixed $x0  start X pos
    * @param mixed $y0  start Y pos
    * @param mixed $xk  ending X pos
    * @param mixed $yk  ending Y pos
    * @param mixed $color color, black by default
    */
    public function drawAccolade($x0,$y0,$xk,$yk, $color=[]) {
        $koefx = 1.2; $kxd = 0.4;
        $koefy=0.05;
        $y_mid = ($y0+$yk)/2;
        $segments = array();

        $segments[] = array($xk-($xk-$x0)*($koefx-0), $y0+($yk-$y0)*$koefy, $x0+($xk-$x0)*($koefx-0),$y_mid-($yk-$y0)*$koefy, $x0, $y_mid);
        $segments[] = array($x0+($xk-$x0)*($koefx-0), $y_mid+($yk-$y0)*$koefy, $xk-($xk-$x0)*($koefx-0),$yk-($yk-$y0)*$koefy, $xk, $yk);
        $segments[] = array($xk-($xk-$x0)*($koefx-$kxd), $yk-($yk-$y0)*$koefy, $x0+($xk-$x0)*($koefx+$kxd),$y_mid+($yk-$y0)*$koefy, $x0, $y_mid);
        $segments[] = array($x0+($xk-$x0)*($koefx+$kxd), $y_mid-($yk-$y0)*$koefy, $xk-($xk-$x0)*($koefx-$kxd),$y0+($yk-$y0)*$koefy, $xk, $y0);
        $this->_pdf->Polycurve($xk, $y0, $segments, 'F', array(), $color);
    }
    /**
    * @return true if PDF object created (and template loaded from $this->_templatefile)
    */
    protected function _createPdfObject() {

        if(is_object($this->_pdf)) return true;
        try {
            $this->_pdf = new TCPDI($this->_basepar['page']['orientation'],$this->_basepar['page']['units'],$this->_basepar['page']['size']);
            # $this->_pdf = new FPDI($this->_basepar['page']['orientation'],$this->_basepar['page']['units'],$this->_basepar['page']['size']);
            $this->_pdf->setPrintHeader(false);
            $this->_pdf->setPrintFooter(false);
            $this->_pdf->SetFont($this->_basepar['font']['name'], $this->_basepar['font']['style'], $this->_basepar['font']['size']);
        } catch (Exception $e) {
            $this->_errormessage = 'Creating PDF object error: '. $e->getMessage();
            return false;
        }
        $this->_pdf->setMargins(0,0,0,true);
        $this->_pdf->SetRightMargin(0);

        return true;
    }
    # convert to UTF8 before drawing
    protected function _convertCset($strval) {
        $srcCharset = (isset($this->_config['stringcharset']) ? $this->_config['stringcharset'] : 'UTF-8');
        $ret = ($srcCharset!='' && $srcCharset!='UTF-8') ?
          @iconv($this->_config['stringcharset'],'UTF-8',$strval) : $strval;
        return $ret;
    }

    protected function _parseColor($param) {
        if(is_array($param)) $color = $param;
        elseif(is_int($param)) $color = array($param,$param, $param);
        else {
            if(!$this->_pdf) $this->_createPdfObject();
            $color = $this->colorToDec((string)$param);
        }
        return $color;
    }

    public static function getRelativeFilename($basepth, $filename) {
        # writeDebugInfo("getRelativeFilename: ", $basepth, $filename);
        if(!is_string($filename)) {
            echo ('bad filename <pre>'.print_r($filename,1).'</pre>');
            echo '<pre>'; debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,4); echo '</pre>';
            exit;
        }
        if (is_file($filename)) return $filename;
        $path = $basepth;
        if (substr($filename,0,1)==='@' && ($fncname = susbtr($filename,2) && is_calleble($fncname))) {
            $srcfile = call_user_func($fncname, $this->_data);
            return $srcfile;
        }
        if ($path === './') $path = getcwd() . '/';
        if (substr($filename,0,2)==='./') $filename = substr($filename,2);
        while(substr($filename,0,3) === '../') {
            $path = dirname($path) ;
            if ($path!='') $path .= '/';
            $filename = substr($filename,3);
        }

        return ($path . $filename);
    }

    /**
    * Passes data that will be visualized by plugin
    *
    * @param mixed $name plugin data block unique name. There maight be more than one plugin-rendered block on the PDF page(s)
    * @param mixed $data data to be visualized
    */
    public function setPluginData($name, $data) {
        $this->_pluginData[$name] = $data;
    }
    public static function getImageInfo($imgsrc) {
        if (!isset(self::$_cached[$imgsrc])) self::$_cached[$imgsrc] = @getimagesize($imgsrc);
        return self::$_cached[$imgsrc];
    }
    public function parseParam($par, $data=0) {
        $ret = (string)$par;
        if (substr($ret,0,1) === '@') {
            $ret = substr($ret,1);
            if (is_callable($ret))
                $ret = call_user_func($ret, $data);
            elseif( is_object($this->callbackObj) && method_exists($this->callbackObj, $ret) ) {
                return $this->callbackObj->$ret($data);
            }

        }
        return $ret;
    }

    public function getUserParameters() {
        return $this->userParams;
    }
} # class PrintFormPdf definition end
