<?PHP
/**
* @name printform-pdf.php, contains class CPrintFormPdf,
* for generating "on the fly" PDF document populated with user data, possibly from source pdf template file,
* loaded configuration from XML file or string.
* @uses TCPDF, FPDFI classes used for reading/writing pdf body, see http://www.tcpdf.org/
* @Author Alexander Selifonov, <alex [at] selifan {dot} ru>
* @version 1.25.0046 2018-12-30
* @Link: https://github.com/selifan
* @license http://www.opensource.org/licenses/bsd-license.php    BSD
*
**/
if(!class_exists('TCPDF',false)) {
    # You should add this block in your module BEFORE include printform-pdf.php,
    # OR define PRINTPDF_LANGUAGE with Your base language prefix
    # ( include your TCPDF language module in tcpdf/config/lang folder, my case is lang/rus.php )
    $lang = (defined('PRINTPDF_LANGUAGE') ? constant('PRINTPDF_LANGUAGE') : 'rus');
    require_once("tcpdf/config/lang/$lang.php");
    require_once('tcpdf/tcpdf.php');
}
if (!class_exists('FPDF')) {
    require_once('fpdf/fpdi2tcpdf_bridge.php');
    require_once('fpdf/fpdi.php'); # 1.4.2 had parazite "g" echoing ! Use latest FPDI !!!
}

/**
* Abstract class for PrintFormPDF plugins - modules that will draw by specific algorhytms in desired rectangle regions
* @since 1.1.0016 (2013-01-20)
*/
abstract class PfPdfPlugin {

    protected $_error_message = 'no error';
    protected $_region_pos = [0, 0];
    protected $_region_dim = [0, 0];
    abstract public function __construct($tcpdfobj, $cfg = null, $x=0,$y=0,$w=0,$h=0);
    # Render method should draw something according to passed _data,  inside defined rectangle area (_region_pos, _region_dim)
    abstract public function Render($data);

    public function setAreaPosition($x, $y, $w=0, $h=0) {
        $this->_region_pos = array(floatval($x),floatval($y));
        $this->_region_dim = array(floatval($w),floatval($h));
        return $this;
    }
    public function getErrorMessage() { return $this->_error_message; }
}

class CPrintFormPdf {

    const DEFAULT_MARGIN = 5;
    const DEFAULT_GRID_ROWS = 30;
    const DEFAULT_GRID_STEPY = 6;

    protected $_data = array();
    protected $_pageno = 0;
    protected $_pluginData = array();
    protected $_basepar = array();
    protected $_paginatonMode = FALSE;
    protected $_templatefile = array(); # source file(s) to be used as template
    protected $_alttemplatefile = array(); # alternative template
    protected $_outname = '';
    protected $_pdf = null;
    protected $pgno = 0;
    protected $prnPage = 0; // printed page number
    protected $_tofile  = false;
    protected $_configfile = '';
    protected $_config = array();
    protected $_pagedefs = array();
    protected $_errormessage = '';
    protected $_alttemplate = false;
    protected $_rulerStep = false;
    protected $_rulerColor = array(80,80,200);
    protected $_compression = false; # compress result PDF
    protected $offsets = array(0,0); # for fine tuning field positions, depending on user printer
    protected $_printedfields = false;
    protected $_datagrids = array();
    protected $_curGridRow = array();
    protected $_specialPages = 0;
    protected $_mpgrid = FALSE; # becomes TRUE if multi-paging grid detected
    protected $_hide_pages = array();
    protected $_hide_fields = array(); # field list to be hidden
    protected $_field_newattr = array(); # "dynamically changed" field attribs array

    protected $_images_disabled = false;
    protected $_homedir = ''; # directory where loaded XML file resides
    protected $_pdf_path = ''; # PDF templates folder
    protected $_img_path = ''; # images folder
    protected $_curSrcPdf = '';
    protected $_curSrcFile = -1, $_curSrcPage=-1, $_curSrcPgcount=-1;

    protected $_apFields = array(); # fields to draw on All Pages
    protected $_apValues = array(); # values for All Pages fields

    # "ALL pages" fields in "added" page definitions:
    protected $_subApFields = array();
    protected $_subApValues = array();

    protected $spot_colors = array();
    protected $_debug = 0;
    protected $_outputMode = 'I';
    static protected $_cached = array();
    protected $adaptive_sizing = 0; # auto-change font size to make text fit in area (width * height)
    protected $signFunc = false; # external function for instant "signing" generated PDF

    public function __construct($param='') {
        $loadData = NULL;
        $this->_basepar = array(
          'page'     => array('orientation'=>'P', 'size'=>'A4', 'units'=>'mm')
          ,'font'    => array('name'=>'arial', 'size'=>4)
          ,'margins' => array('left'=>0, 'top'=>0, 'right'=>0, 'bottom'=>0)
          ,'pgprefix'=> '_page' # prefix for "specific" data in 'pageNN'
        );
        $this->_config = array(
             'subject' => ''
            ,'author'  => ''
            ,'creator' => ''
            ,'protectfile' => false
        );
        if(is_array($param)) {

            if(isset($param['template'])) $this->_templatefile[] = array('src'=>(string) array($param['template']));
            if(isset($param['alttemplate'])) $this->_alttemplatefile[] = (string)$param['template'];
            if(isset($param['outname']))  $this->_outname= $param['outname'];
            if(isset($param['output']))  $this->_outputMode = $param['output'];
            if(!empty($param['compression']))  $this->_compression = true;
            if(isset($param['tofile']))    $this->_tofile  = $param['tofile'];
            if(isset($param['configfile'])) {
               $this->_configfile  = $param['configfile'];
            }
            if(isset($param['subject'])) $this->_config['subject'] = (string)$param['subject'];
            if(isset($param['description'])) $this->_config['description'] = (string)$param['description'];
            if(isset($param['author'])) $this->_config['author'] = (string)$param['author'];
            if(isset($param['creator'])) $this->_config['creator'] = (string)$param['creator'];
            if(isset($param['stringcharset'])) {
                $this->_config['stringcharset'] = self::parseParam((string)$param['stringcharset']);
            }
            if(isset($param['pdfpath']))
                $this->_pdf_path = (string)$param['pdfpath'];
            if(isset($param['imgpath']))
                $this->_img_path = (string)$param['imgpath'];
        }
        elseif(is_scalar($param)) { # configuration XML filename or whole XML string was passed
            $this->_configfile = $param;
        }
        if(!empty($this->_configfile)) {
            $this->_homedir = dirname($this->_configfile) .'/';
            if ($this->_pdf_path == '') $this->_pdf_path = $this->_homedir;
            if ($this->_img_path == '') $this->_img_path = $this->_homedir;

            $ok = $this->LoadConfig();
        }

        $this->_pageno = 0;
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
    public function LoadConfig($cfgname=null) {
        $this->_alttemplate = false;
        if(!$cfgname) $cfgname = $this->_configfile;
        else $this->_configfile = $cfgname;
        $ret = true;

        if(is_file($cfgname)) { $xml = simplexml_load_file($cfgname); }
        elseif(substr($cfgname,0,5)=='<'.'?xml') {
            $xml = @simplexml_load_string($cfgname);
            $cfgname='';
        }
        else {
            $this->_errormessage = 'Configuration XML file not found:  '.$cfgname;
            return false;
        }
#        echo '<pre> xml obj:<br>'; print_r($xml); echo '</pre>';
        if(!($xml) OR !@isset($xml->pages)) {
            $this->_errormessage = 'Wrong XML file or XML string syntax, '.$cfgname;
            echo $this->_errormessage ;
            return false;
        }

        if(isset($xml->description)) $this->_config['description'] = (string)$xml->description; # protect workpages by password
        if(isset($xml->protectfile)) $this->_config['protectfile'] = (int)$xml->protectfile; # protect workpages by password
        if(isset($xml->password)) $this->_config['password'] = (string)$xml->password; # password to protect with
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
        }
        if(isset($xml->baseparameters->pagination)) {

            $this->_basepar['pagination'] = array();
            $this->_basepar['pagination']['align'] = (isset($xml->baseparameters->pagination['align'])) ?
                strtoupper((string)$xml->baseparameters->pagination['align']) : 'C';
            $this->_basepar['pagination']['posy'] = (isset($xml->baseparameters->pagination['posy'])) ?
                strtolower((string)$xml->baseparameters->pagination['posy']): 'bottom';
            $this->_basepar['pagination']['format'] = (isset($xml->baseparameters->pagination['format'])) ?
                trim((string)$xml->baseparameters->pagination['format']) : '%page%';
        }
        if(isset($xml->baseparameters->pdfpath)) {
            $this->_pdf_path = (string)$xml->baseparameters->pdfpath;
        }
        if(isset($xml->baseparameters->imgpath)) $this->_img_path = (string)$xml->baseparameters->imgpath;

        if(isset($xml->baseparameters->font)) {
            if(!empty($xml->baseparameters->font['name'])) $this->_basepar['font']['name'] = (string)$xml->baseparameters->font['name'];
            if(!empty($xml->baseparameters->font['size'])) $this->_basepar['font']['size'] = (float)$xml->baseparameters->font['size'];
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
                if(!empty($item['src']))
                    $this->_templatefile[] =  $this->readTemplateDef($item);
            }
        }

        if(isset($xml->allpages)) { # All Pages Fields exist, load them!
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

        if(!empty($this->_config['templatefile'])) $this->_templatefile[] = array(
           'src'=> (string)$this->_config['templatefile']
        );

        $this->_pagedefs = array();
        $ipage = 0;
        $fldcnt = 0;
        foreach($xml->pages->children() as $key => $pageitem) {
            $pageno = isset($pageitem['number']) ? (int) $pageitem['number'] : $ipage;
            $hide_it = isset($pageitem['hide']) ? (int) $pageitem['hide'] : 0;
            $pg_orient = isset($pageitem['orientation']) ? $pageitem['orientation'] : $this->_basepar['page']['orientation'];
            $gridpage = $gridFields = false;
            if ($key == 'importdef') { # Append pages definition from another XML cfg
                $addXml = (isset($pageitem['src']) ? (string)$pageitem['src'] : '');
                $subid  = (isset($pageitem['datasubid']) ? (string)$pageitem['datasubid'] : '');
                if (strlen($addXml)) {
                    $filePath = self::getRelativeFilename($this->_homedir, $this->_evalAttribute($addXml));
                    if ($filePath) $this->AppendPageDefFromXml($filePath, $subid);
                }
                continue;
            }
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
                );
            }
            if($hide_it) $this->_hide_pages[$pageno] = true;

            $this->_pagedefs[$ipage] = array('pageno'=>$pageno, 'fields'=>array()
                ,'repeat'=>array()
                ,'hide'=>$hide_it
                ,'gridpage'=>$gridpage
                ,'datagrids' => array()
                ,'orientation' => $pg_orient
                ,'ruler' => ( isset($pageitem['ruler']) ? (int)$pageitem['ruler'] : FALSE )
                ,'copies' => ( isset($pageitem['copies']) ? (int)$pageitem['copies'] : FALSE )
            );
#            if ($gridpage) { echo '<pre>' . print_r($gridpage,1) .'</pre>'; exit; } # debug echo
            foreach($pageitem->children() as $key=>$item) {
                if($key=='template') { # there is specific template for this page
                    $this->_pagedefs[$ipage]['template'] = array(
                        'src'    => (isset($item['src']) ? (string) $item['src'] : false)
                       ,'altsrc' => (isset($item['altsrc']) ? (string) $item['altsrc'] : false)
                       ,'page'   => (!empty($item['page']) ? (int) $item['page'] : 0)
                       ,'orientation' => (!empty($item['orientation']) ? $item['orientation'] : $this->_basepar['page']['orientation'])
                    );
                    continue;
                }
                elseif($key=='field' OR $key=='image' OR $key=='plugin') {
                    $fldname = isset($item['name'])? trim("{$item['name']}") : '';
                    if(!$fldname) continue;
                    $newar = $this->prepareFieldDef($item);
                    if($key=='image') $newar['type'] = 'image';
                    if($key=='plugin' || $newar['type']=='plugin') { # drawing specific data plugin
                        $newar['plugintype'] = isset($item['plugintype']) ? strtolower((string)$item['plugintype']) : '';
                        if($newar['plugintype']=='' && $newar['type']!='plugin') $newar['plugintype'] = $newar['type'];
                        $newar['type'] = 'plugin';
                    }
                    $this->_pagedefs[$ipage]['fields'][] = $newar;
                    $fldcnt++;
                }
                elseif($key=='datagrid') {

                    $dtfields = isset($item['fields']) ? (string) $item['fields'] : '';
                    $dtfields = str_replace(' ','',$dtfields);
                    $datasource = isset($item['datasource']) ? (string) $item['datasource'] : '';
                    if(!$dtfields) continue; # empty datagrid tag (w/o field list) ignored
                    $gridId = isset($item['name']) ? (string)$item['name'] : 'datagrid'.(count($this->_datagrids)+1);
                    $this->_datagrids[$gridId] = array(
                         'page'  => $ipage
                        ,'datasource' => $datasource
                        ,'fields'=> explode(',', $dtfields)
                        ,'posx'  => (isset($item['posx']) ? (string)$item['posx'] : '0')
                        ,'posy'  => (isset($item['posy']) ? (string)$item['posy'] : '0')
                        ,'step_y'=> (isset($item['step_y']) ? (float)$item['step_y'] : 0)
                        ,'rows'  => (isset($item['rows']) ? (int)$item['rows'] : 2)
                        ,'step_x' => (isset($item['step_x']) ? (float)$item['step_x'] : 0)
                        ,'order' => (isset($item['order']) ? (string)$item['order'] : 'R')
                        ,'cols' => (isset($item['cols']) ? (int)$item['cols'] : 1)
                    );
                    $pageno = count($this->_pagedefs)-1;
                    if ($datasource) {
                        $this->_pagedefs[$pageno]['datagrids'][] = $gridId;
                        # WriteDebugInfo("register datagrid $gridId for pagedef $pageno: data source=", $datasource);
                    }
                }
                elseif($key=='repeat') { # repeat all data on the sheet, with x/y shifting
                    $off_x = isset($item['offset_x']) ? (float)$item['offset_x'] : 0;
                    $off_y = isset($item['offset_y']) ? (float)$item['offset_y'] : 0;
                    $enabled = isset($item['enabled']) ? (string)$item['enabled'] : 1;
                    if(!empty($enabled) && is_callable($enabled)) $enabled = call_user_func($enabled, count($this->_pagedefs[$ipage]['repeat']));
                    if($off_x != 0 || $off_y != 0) $this->_pagedefs[$ipage]['repeat'][] = array($off_x,$off_y, $enabled);
                }

            }
            if(count($this->_datagrids)) foreach($this->_datagrids as $dtgrid) {
                $posyarr = explode(',', $dtgrid['posy']);
                $farr = array();
                foreach($this->_pagedefs[$ipage]['fields'] as $fno=>$fdef) {
                    if(in_array($fdef['name'],$dtgrid['fields'])) $farr[] = $fdef;
                }
                if(!count($farr)) continue; # Skip grid: no one field has a name listed in datagrid
                if (!empty($dtgrid['datasource'])){
                    # save $gridId for current pdf page def
                    continue; # named datasource will be handled w/o creating "pseudo-fields"
                }
                $rows = $dtgrid['rows'] ? $dtgrid['rows'] : count($posyarr);
                $posx = $dtgrid['posx'] ? floatval($dtgrid['posx']) : 0;

                for($kk=1;$kk<=$rows;$kk++) {
                    $poy_off = $kk-1;
                    $posy = isset($posyarr[$poy_off]) ? $posyarr[$poy_off] : ($posyarr[0]+($poy_off * $dtgrid['step_y']));
                    foreach($farr as $fdef) {
                        $newdef = ($fdef);
                        $newdef['name'] = $fdef['name'].$kk;
                        $newdef['posy'] = $fdef['posy'] + $posy;
                        if($posx!=0) foreach($newdef['posx'] as $k=>$v) {
                            $newdef['posx'][$k] += $posx;
                        }
                        $newdef['_gridtmp_'] = 1; # not a real field, just a clone
                        $this->_pagedefs[$ipage]['fields'][] = $newdef;
                    }
                }
            }
            $ipage++;
        }
        if (isset($xml->import)) {
            foreach($xml->import->children() as $key => $item) {
                if ($key === 'importdef') {
                    $addXml = (isset($item['src']) ? (string)$item['src'] : '');
                    $subid  = (isset($item['datasubid']) ? (string)$item['datasubid'] : '');
                    if (strlen($addXml)) {
                        $filePath = self::getRelativeFilename($this->_homedir, $addXml);
                        if ($filePath) $this->AppendPageDefFromXml($filePath, $subid);
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
    # read "template" sub-element to assoc.array
    protected function readTemplateDef($item) {
        $ret = array(
            'src' => (string)($item['src'])
           ,'altsrc'=> (isset($item['altsrc']) ? (string)$item['altsrc'] : '')
           ,'pagination'=> (isset($item['pagination']) ? (string)$item['pagination'] : FALSE)
           ,'pages'=> (isset($item['pages']) ? (string)$item['pages'] : '')
        );
#        if (!empty($ret['pagination'])) WriteDebugInfo("template with pagination:", $ret);
        return $ret;
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
    public function addPageDef($opts=array()) {
        $ipage = count($this->_pagedefs);
        $pg = array();
        $pg['orientation'] = (isset($opts['orientation']) ? $opts['orientation'] : $this->_basepar['page']['orientation']);
        $pg['pageno'] = (isset($opts['pageno']) ? $opts['pageno'] : $ipage);
        $pg['fields'] = (isset($opts['fields']) ? $opts['fields'] : array());
        $pg['repeat'] = (isset($opts['repeat']) ? $opts['repeat'] : array());
        $pg['hide'] = (isset($opts['hide']) ? $opts['hide'] : false);
        $pg['template'] = array(
           'src' => (isset($opts['src']) ? (string)$opts['src'] : '')
          ,'altsrc' => (isset($opts['altsrc']) ? (string)$opts['altsrc'] : '')
          ,'page' => (isset($opts['page']) ? max(1,(int)$opts['page']) : 1)
        );
        $this->_pagedefs[$ipage] = $pg;
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
            $this->_pagedefs[$ipage] = array('offset'=>count($this->_pagedefs), 'fields'=>array());
        if(empty($parm['name'])) return false; # array MUST contain 'name' element
        $tp = (isset($parm['type']) ? strtolower((string) $parm['type']) : '');
        $posy = (isset($parm['posy']) ? (string) $parm['posy'] : '1');
        $this->_pagedefs[$ipage]['fields'][] = array(
           'name'   => strtolower(trim($parm['name']))
          ,'type'    => $tp
          ,'posx'    => (isset($parm['posx']) ? explode(',', (string)$parm['posx']) : array(0))
          ,'posy'    => ($tp=='poly'? floatval($pos) : explode(',',$posy))
          ,'charstep'=> (isset($parm['charstep']) ? (float)$parm['charstep'] : 0)
          ,'maxlength'=> (isset($parm['maxlength']) ? (int)$parm['maxlength'] : 0)
          ,'width'   => (isset($parm['width']) ? floatval($parm['width']) : 0)
          ,'font'    => (isset($parm['font']) ? (string)($parm['font']) : '')
          ,'size'    => (isset($parm['size']) ? floatval($parm['size']) : 0)
          ,'align'   => (isset($parm['align']) ? (string) $parm['align'] : '')
          ,'convert' => (isset($parm['convert']) ? (string) $parm['convert'] : '')
          ,'color'   => (isset($parm['color']) ? (string) $parm['color'] : '')
          ,'bgcolor' => (isset($parm['bgcolor']) ? (string) $parm['bgcolor'] : '')
          ,'rotate'  => (isset($parm['rotate']) ? (float) $parm['rotate'] : 0)
          ,'norepeat'  => (isset($parm['norepeat']) ? (int) $parm['norepeat'] : 0)
          ,'border'  => (isset($parm['border']) ? (string) $parm['border'] : 0)
          ,'options'  => (isset($parm['options']) ? (string) $parm['options'] : '')
          ,'src'  => (isset($parm['src']) ? (string) $parm['src'] : '')
        );
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
        if(!empty($datacharset) && $datacharset != $this->_config['stringcharset'])
          mb_convert_variables($this->_config['stringcharset'], $datacharset, $entitydata);
        $pageData = array();
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

#             $this->_pagedefs[$ipage]['fields'] = $fielddefs['fields'];
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
            if (!isset($this->_field_newattr[$fieldname])) $this->_field_newattr[$fieldname] = array();
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
#                   $this->_pdf->SetLineWidth(0.2);
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
            $this->_pdf->SetTextColorArray(0);
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
        $new_fld = $this->prepareFieldDef($fdef);
        $name = $new_fld['name'];
        $this->_apFields[$name] = $new_fld;
        if($value) $this->_apValues[$name] = $value;
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
    public function prepareFieldDef($item) {
        $fldname = isset($item['name'])? trim("{$item['name']}") : '_field_'.rand(1000000,9999999);
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
          ,'size'    => (isset($item['size']) ? (float) $item['size'] : 0)
          ,'convert' => (isset($item['convert']) ? (string) $item['convert'] : '')
          ,'color'   => (isset($item['color']) ? (string) $item['color'] : '')
          ,'bgcolor' => (isset($item['bgcolor']) ? (string) $item['bgcolor'] : '')
          ,'rotate'  => (isset($item['rotate']) ? (float) $item['rotate'] : 0)
          ,'norepeat'=> (isset($item['norepeat']) ? (int) $item['norepeat'] : 0)
          ,'align'   => (isset($item['align']) ? (string) $item['align'] : '')
          ,'options' => (isset($item['options']) ? (string) $item['options'] : '')
          ,'src'     => (isset($item['src'])? (string)$item['src'] : '')
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
        @ini_set('max_execution_time', 600);
        if (is_callable('WebApp::getAppState')) {
            if (100 <= WebApp::getAppState()) {
                while(ob_get_level()) {
                    ob_end_flush();
                }
                return;
            }
        }
        if(count($this->_pagedefs)<1 && $this->_specialPages==0) {
            $this->_errormessage = 'Configuration not loaded, Rendering impossible !';
            return false;
        }
        if(empty($this->_outname)) {
            $this->_outname = 'generated.pdf';
            $off = max(strrpos($this->_outname, '/'), strrpos($this->_outname, '\\'));
            if($off!==false) $this->_outname = substr($this->_outname, $off+1);
        }

        if(!($this->_createPdfObject())) return false;

        $this->_pdf->SetAutoPageBreak(false,0); # disable auto creating new pages if data doesn't fit on page

        $creator = empty($this->_config['creator']) ? 'Printform-pdf module by Alexander Selifonov, using TCPDF/FPDF classes' : $this->_convertCset($this->_config['creator']);
        $this->_pdf->SetCreator($creator);
        $author = empty($this->_config['author']) ? 'CPrintFormPdf, TCPDF wrapper PHP class' : $this->_convertCset($this->_config['author']);
        $this->_pdf->SetAuthor($author);

        if(!empty($this->_config['title'])) $this->_pdf->SetTitle($this->_convertCset($this->_config['title']));
        if(!empty($this->_config['subject'])) $this->_pdf->Setsubject($this->_convertCset($this->_config['subject']));

        if($this->_rulerStep > 0 ) { # we'll draw a measuring grid
            $grcolor = array('R'=>200,'G'=>200,'B'=>200);
            if($this->_rulerColor) {
                $grcolor = $this->_parseColor($this->_rulerColor);
#                WriteDebugInfo('grid color from ', $this->_rulerColor, ' is ', $grcolor);
            }
        }
        # Populating with data...
        foreach($this->_data as $entno=>$onedatablock) { #<3>
            $this->pgno = $this->prnPage = 0;
            $this->_curSrcFile = $this->_curSrcPage = $this->_curSrcPgcount = -1;
            $dataentity = $onedatablock;

            # Main loop for generating one multi-paged document entity
            foreach($this->_pagedefs as $no=>$pagedef) { #<4>
                # skip the page if no printed fields in TEST mode:
                if(is_array($this->_printedfields) && (!isset($this->_printedfields[$no+1]) OR count($this->_printedfields[$no+1])<=0)) {
                    continue;
                }
                if($pagedef['hide']) {
                    continue; # page is temporary hidden by attrib "hide"
                }

                if (!empty($pagedef['gridpage'])) {
                    $this->_drawGridPage($entno, $pagedef);
                    continue;
                    # $dataid = $pagedef['gridpage']['datasource'];
                }
                $orientation = isset($pagedef['orientation'])? $pagedef['orientation'] : $this->_basepar['page']['orientation'];
                $this->_pdf->addPage($orientation);
                $this->pgno++;

                $this->prnPage++;
                $pgPref = $this->_basepar['pgprefix'] . $this->pgno;
                if (isset($onedatablock[$pgPref]) && is_array($onedatablock[$pgPref])) {
                # there is specific [_pageNN] data sub-array for this page
                    $dataentity = array_merge($dataentity, $onedatablock[$pgPref]);
                }
                if (!empty($pagedef['datasubid'])) {
                    $pgPref = $pagedef['datasubid'];
                    if (isset($onedatablock[$pgPref]) && is_array($onedatablock[$pgPref])) {
                    # there is specific [subid] data sub-array for this page
                        $dataentity = array_merge($dataentity, $onedatablock[$pgPref]);
                    }
                }

                $pdfTpl = $pdfPage = false;
                # use explicit PDF file if set inside "<page">, otherwise - load page from "basic PDF template listed in XML "templatefiles" section

                if(!empty($pagedef['template']['page'])) {

                    if(!empty($pagedef['template']['src'])) {
                        $pdfTpl = (string)$pagedef['template']['src'];
                        $pdfPage = isset($pagedef['template']['page']) ? ($pagedef['template']['page']) : 1;
                    }
                    if ( !empty($pdfTpl) && !empty($pdfPage) ) try {
                        if(is_file($this->_pdf_path . $pdfTpl))
                            $pdfTpl = $this->_pdf_path . $pdfTpl;
                        elseif(is_file($this->_homedir . $pdfTpl))
                            $pdfTpl = $this->_homedir . $pdfTpl;
                        elseif(!empty($pagedef['sourcepath']) && is_file($pagedef['sourcepath'] . $pdfTpl))
                            $pdfTpl = $pagedef['sourcepath'] . $pdfTpl;
#                        WriteDebugInfo("opening explicit template PDF: $pdfTpl /page $pdfPage");
                        if (is_file($pdfTpl)) {
                            $pc = $this->_pdf->setSourceFile($pdfTpl);
                            $pg = $this->_pdf->importPage($pdfPage);
                            $this->_pdf->useTemplate($pg);
                        }
                        else {
                            die ("Wrong config: template file $pdfTpl not found ! ");
                        }
                    }
                    catch (Exception $e) {
                        $this->_errormessage = 'Loading template PDF error, cause : '.$e->getMessage();
                        if(function_exists('WriteDebugInfo')) WriteDebugInfo($this->_errormessage," pagedef $no:",$this->pagedef);
                    }
                }
                else $this->getNextTemplatePage();

                $pageWidth  = $this->_pdf->getPageWidth();
                $pageHeight = $this->_pdf->getPageHeight();
#                if($this->pgno==1) { #operations to do on the first page only

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
                if(count($this->_apFields)) {
                    $this->_renderFieldSet($this->_apFields, array_merge($this->_apValues, $dataentity), $debug);
                }
                # "ALL PAGES" fields from nested loaded XML, if current page is "nested":
                if (!empty($pagedef['pageid'])) {
                    $pgid = $pagedef['pageid'];
                    if (isset($this->_subApFields[$pgid]) && count($this->_subApFields[$pgid])>0)
                        $this->_renderFieldSet($this->_subApFields[$pgid], array_merge($this->_subApValues[$pgid], $dataentity), $debug);
                }
                $this->_renderFieldSet($pagedef['fields'], $dataentity, $debug, $pagedef);
                # Printing "new-style" datagrids...
                if (isset($pagedef['datagrids']) && is_array($pagedef['datagrids']) && count($pagedef['datagrids'])>0)

                foreach ($pagedef['datagrids'] as $gridid) {

                    $sourceid = $this->_datagrids[$gridid]['datasource'];

                    if (!isset($this->_data[$no][$sourceid]) || !is_array($this->_data[$no][$sourceid])) continue; // no named data sub-array for datagrid, skip it
                    $step_y =  $this->_datagrids[$gridid]['step_y'];
                    $step_x =  $this->_datagrids[$gridid]['step_x'];
                    $max_x = $this->_datagrids[$gridid]['cols'];
                    $max_y = $this->_datagrids[$gridid]['rows'];
                    $order = $this->_datagrids[$gridid]['order'];
#                    WriteDebugInfo("step_y=$step_y step_x=$step_x max_y=$max_y max_x=$max_x order=[$order]!");
                    $page_xoff = $page_yoff = 0;

                    for ($krow = 0; $krow < count($this->_data[$no][$sourceid]); $krow++) {

                        foreach ( $this->_data[$no][$sourceid][$krow] as $fldid =>$fldvalue) {
                            if ($fldid === '_rowno_') $fldvalue = $krow+1; # line no.
                            # seek field definition
                            foreach ($pagedef['fields'] as $kfld => $fdef) {
                                if ($fdef['name'] === $fldid) {
                                    # print shifted value and break;
                                    $fdef['posy'] += ($step_y * $page_yoff) + $this->_datagrids[$gridid]['posy'];
                                    foreach($fdef['posx'] as &$oposx) {
                                        $oposx += $this->_datagrids[$gridid]['posx'] + $page_xoff * $step_x;
                                    }
                                    $this->_valueToPdf($fldvalue, $fdef);
                                    break;
                                }
                            }
                        }
###
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
###
                    }

                }
                if ($this->prnPage > 1) $this->_drawPageNo();

            } #<4>
        } #<3>

        $this->_pdf->SetCompression($this->_compression); # will work if 'gzcompress' function enabled ! (see TCPDF docs)

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

    /**
    * Loads next page from current "global" pdf template
    * @since 1.5
    */
    protected function getNextTemplatePage() {

        if (count($this->_templatefile)<1) {
            return;
        }
        if ($this->_curSrcFile >= count($this->_templatefile)) {
#            WriteDebugInfo('page '.$this->_curSrcFile . ': getNextTemplatePage: no more pdf templates');
            return; # no more PDF templates
        }

        if ($this->_curSrcFile <0) {
#            WriteDebugInfo('Start using PDF templates...');
            while($this->_curSrcFile < count( $this->_templatefile)) {

                $this->_curSrcFile++;
                if (!isset($this->_templatefile[$this->_curSrcFile])) return false;

                if (is_string($this->_templatefile[$this->_curSrcFile]['src'])) {

                    $justName = $this->_evalAttribute($this->_templatefile[$this->_curSrcFile]['src']);
                    $thisPdf = $this->_pdf_path . $justName;
                    if (!empty($this->_templatefile[$this->_curSrcFile]['pagination'])) {
                        $this->setPaginationMode($this->_templatefile[$this->_curSrcFile]['pagination']);
#                        WriteDebugInfo("001.pagination set to ",$this->_paginatonMode);
                    }
                    else $this->setPaginationMode(FALSE);
                }
                else { # if(is_array($this->_templatefile[$this->_curSrcFile])) # templates from imported definitions
                    $thisPdf = $this->_templatefile[$this->_curSrcFile]['src'][0] . $this->_evalAttribute($this->_templatefile[$this->_curSrcFile]['src'][1]); # path+fname
                    $this->_paginatonMode = FALSE;
                }
                # $thisPdf =  $this->_evalAttribute($thisPdf);
                if (is_file($thisPdf)) break; # file exists
            }
            if (is_file($thisPdf)) {
                $this->_curSrcPdf = $thisPdf;
                $this->_curSrcPgcount = $this->_pdf->setSourceFile($thisPdf);
                $this->_curSrcPage = 0;
            }
#            else WriteDebugInfo('след.шаблон не найден:' . $this->_pdf_path . $this->_templatefile[$this->_curSrcFile]);
        }
        ++ $this->_curSrcPage;
        if ( $this->_curSrcPage <= $this->_curSrcPgcount) {
#            $pc = $this->_pdf->setSourceFile($pdfTpl);
            $pg = $this->_pdf->importPage($this->_curSrcPage);
            $this->_pdf->useTemplate($pg);
            return true;
        }
        # end of current PDF file, find next...
        $thisPdf = FALSE;
        while(++$this->_curSrcFile < count( $this->_templatefile)) {

            if (is_string($this->_templatefile[$this->_curSrcFile]['src'])) {
#                WriteDebugInfo("KT-003: get next pdf:", $this->_templatefile[$this->_curSrcFile]);
                $thisPdf = $this->_pdf_path . $this->_evalAttribute($this->_templatefile[$this->_curSrcFile]['src']);
                if (!empty($this->_templatefile[$this->_curSrcFile]['pagination'])) {
                    $this->setPaginationMode($this->_templatefile[$this->_curSrcFile]['pagination']);
                }
                else $this->setPaginationMode(FALSE);
            }
            else # if (is_array($this->_templatefile[$this->_curSrcFile])) # templates from imported definitions
                $thisPdf = $this->_templatefile[$this->_curSrcFile]['src'][0] . $this->_evalAttribute($this->_templatefile[$this->_curSrcFile]['src'][1]); # path+fname

            if (is_file($thisPdf)) break; # file exists
#            else WriteDebugInfo("pdf template {$this->_curSrcFile} not found and skipped: ", $this->_templatefile[$this->_curSrcFile]);
        }
        if (!$thisPdf) return false;

        $this->_curSrcPdf = $thisPdf;
        $this->_curSrcPgcount = $this->_pdf->setSourceFile($this->_curSrcPdf);
        $this->_curSrcPage = 1;

        if ($this->_curSrcPage <= $this->_curSrcPgcount ) {
            $pg = $this->_pdf->importPage($this->_curSrcPage);
            $this->_pdf->useTemplate($pg);
            return true;
        }

    }
    protected function _drawPageNo() {
#        WriteDebugInfo("page $this->prnPage, paginationMode: ",$this->_paginatonMode);
        if (!empty($this->_basepar['pagination']) && empty($this->_paginatonMode)) {
            $pageWidth  = $this->_pdf->getPageWidth();
            $pageHeight = $this->_pdf->getPageHeight();
            $margins = $this->_pdf->getMargins();

            $width = $pageWidth - 20;
            $posx = 10;
            $height = 10;
            $align = $this->_basepar['pagination']['align'];
            if ($align==='EDGE' || $align ==='E') # align page no to "outer" edge of page
                $align = ( $this->prnPage % 2) ? 'R' : 'L';

            $value = str_replace('%page%', $this->prnPage, $this->_basepar['pagination']['format']);
            $posy = ($this->_basepar['pagination']['posy'] === 'top') ?
                max(8,$margins['top']) : min( ($pageHeight-8), ($pageHeight-$margins['bottom']));
            $this->_pdf->MultiCell($width,$height,$value,0 , $align, 0, 1, $posx, $posy );
        }
    }

    protected function setPaginationMode($mode) {
        $this->_paginatonMode = $mode;
#        WriteDebugInfo("Page $this->prnPage, paginationMode set to [$mode]" );
        if ($mode === 'reset') $this->prnPage = 0; # TODO: 0 or 1?
    }
    /**
    * Printing  one or more (or none, if no data) data-grid filled pages
    *
    * @param mixed $entno adata array offset for current document
    * @param mixed $pagedef page definition
    */
    protected function _drawGridPage($entno, $pagedef) {

        $debug = false;
        $listid = $pagedef['gridpage']['datasource'];
        $fieldlist = $pagedef['gridpage']['fields'];
        $gridFldDefs = $stdFldDefs = array();
        foreach($pagedef['fields'] as $fldno => $fldef) {
            if (in_array($fldef['name'], $pagedef['gridpage']['fields']) || $fldef['name'] === '_rowno_')
                $gridFldDefs[$fldef['name']] = $fldef;
            else
                $stdFldDefs[$fldef['name']] = $fldef;
        }
#        if ($debug) WriteDebugInfo('defs for grid fields:', $gridFldDefs);
#        if ($debug) WriteDebugInfo('defs for std fields:', $stdFldDefs);
        if (empty($this->_data[$entno][$listid])) return false; // No data for grid pages!
#        if ($debug) WriteDebugInfo('to continue: $this->_data : ', $this->_data[$entno]);

        $orientation = isset($pagedef['orientation'])? $pagedef['orientation'] : '';

        $pdfTpl = $pdfPage = false;
        # use explicit PDF file if set inside "<page">, otherwise - load page from "basic PDF template listed in XML "templatefiles" section

        if(!empty($pagedef['template'])) {

            if(!empty($pagedef['template']['src'])) {
                $pdfTpl = (string)$pagedef['template']['src'];
                $pdfPage = isset($pagedef['template']['page']) ? ($pagedef['template']['page']) : 1;
            }
            if ( !empty($pdfTpl) && !empty($pdfPage) ) try {
                if(is_file($this->_pdf_path . $pdfTpl)) $pdfTpl = $this->_pdf_path . $pdfTpl;
                elseif(!empty($pagedef['sourcepath']) && is_file($pagedef['sourcepath'] . $pdfTpl))
                    $pdfTpl = $pagedef['sourcepath'] . $pdfTpl;
                if (!is_file($pdfTpl)) {
                    die ("Wrong config: template file $pdfTpl not found !");
                }
            }
            catch (Exception $e) {
                $this->_errormessage = 'Loading template PDF error, cause : '.$e->getMessage();
                if(function_exists('WriteDebugInfo')) WriteDebugInfo($this->_errormessage," pagedef $no:",$this->pagedef);
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

        while (count($gdata)>0 ) {

            $this->_pdf->addPage($orientation);
            if ($this->_paginatonMode!=='stop') $this->prnPage++;
            if ($pdfTpl) { # apply PDF template
                $pc = $this->_pdf->setSourceFile($pdfTpl);
                $pg = $this->_pdf->importPage($pdfPage);
                $this->_pdf->useTemplate($pg);
            }

            if ($this->_rulerStep > 0) $this->_renderMeasuringGrid();

            if(count($this->_apFields)) { # strings printed on all pages
                $this->_renderFieldSet($this->_apFields, array_merge($this->_apValues, $this->_data[$entno]), $debug); # first print "ALL PAGES" fields
            }
            if(count($stdFldDefs)) { # "standard" fields (no grid data)
                $this->_renderFieldSet($stdFldDefs, array_merge($this->_apValues, $this->_data[$entno]), $debug); # first print "ALL PAGES" fields
            }
            # populate with grid rows
            $page_xoff = $page_yoff = 0;
            for($krow = 0; $page_xoff<$max_x && $page_yoff < $max_y; $krow++) { # STOP HERE
                if ($step_x <= 0 && $step_y <= 0) break; # avoid endless loop
                $rowdata = array_shift($gdata);
                foreach ($gridFldDefs as $flid => $fldef) { # draw one row of rid data
                    foreach($fldef['posx'] as &$oposx) {
                        $oposx += $base_x + $page_xoff * $step_x;
                    }
                    $fldef['posy'] += $base_y + $page_yoff*$step_y;
                    $value = (isset($rowdata[$flid]) ? $rowdata[$flid] : '');
                    if ($flid === '_rowno_' && $value ==='') $value = $_rowno_;

                    if($value !=='') $this->_valueToPdf($value, $fldef);
                }

                $_rowno_++;
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
                if (count($gdata)<1) break;
            }
            $this->_drawPageNo();
        }
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
        if(!is_array($data)) return array();
        if(!isset($this->_datagrids[$gridId])) return array();

        if(!isset($this->_curGridRow[$gridId])) $this->_curGridRow[$gridId] = 0;
        $this->_curGridRow[$gridId] +=1;
        if($this->_curGridRow[$gridId] > $this->_datagrids[$gridId]['rows']) {
            if(empty($this->_datagrids[$gridId]['multipage'])) return false; # no more rows allowed!
            # TODO: add new page, re-print "header" fields and begin new grid
#            foreach(
        }
        $griddata = array();
        foreach($this->_datagrids[$gridId]['fields'] as $fldid) {
            $thisfield = $fldid . $this->_curGridRow[$gridId];
            if(isset($data[$fldid])) $griddata[$thisfield] = $data[$fldid];
        }
        if($returnval) return $griddata;
        $pageNo = count($this->_data)-1; # AddData() should be called first !
        if($pageNo<0) return false;
        $this->_data[$pageNo] = array_merge($this->_data[$pageNo],$griddata);
        return true;
    }

    protected function _renderFieldSet($fldset, $dataentity, $debug=false, $pagedef=null) {

        if(count($fldset)) foreach($fldset as $no=>$fcfg) {
            if(is_array($this->_printedfields) && !in_array($no, $this->_printedfields[$this->pgno])) continue; // print only selectd fields
            $fldname = $fcfg['name'];
            $ftype = $fcfg['type'];
            if(in_array($ftype,array('rect','poly','image'))) $dataentity[$fldname] = 1;
            if(!$debug && $ftype!=='plugin') {
                if(!isset($dataentity[$fldname])) continue;
                if(!is_scalar($dataentity[$fldname])) continue;
            }
            else {
               $debugValue = ($fcfg['type']=='checkbox') ? 'X' : "X $fldname";
            }
            $fldtype = $fcfg['type'];
            $strval = isset($dataentity[$fldname]) ? $dataentity[$fldname] : ($debug ? $debugValue : '');
            if(!empty($fcfg['convert'])) {
                if(is_callable($fcfg['convert'])) # user converter function
                $strval = call_user_func($fcfg['convert'], $strval, $dataentity); # second param - the whole data array
            }

            if($strval === '' && isset($fcfg['fillempty'])) $strval = $fcfg['fillempty']; # substitute empty value with this
            if($strval==='' && $ftype!=='plugin') continue;

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

    }
    /**
    * Outputs generated PDF according to _tofile parameter (sends to te browser or saves to disc)
    * @param $toMemory - pass true if Ypu want to return PDF in memory
    */
    public function Output($outfilename=false, $toMemory = false) {

        if($outfilename) $this->_outname = $outfilename;

        $result = '';
        if($this->_tofile && !$toMemory) {
            if(strtolower($this->_templatefile[0]['src'])==strtolower($this->_outname)) $this->_outname .= '.pdf'; # protect accidental template overwriting !
            $result = $this->_pdf->Output($this->_outname,'F');
            if (!empty($this->signFunc) && is_callable($this->signFunc)) {
                # perform user "signing" function over created PDF file
                call_user_func($this->signFunc, $this->_outname);
            }
        }
        else {
            if (ob_get_level()) ob_end_clean();
            if (ob_get_level()) ob_end_clean();
            $outDest = ($toMemory ? 'S' : 'D');
            $mdest = $outDest;
            if (!empty($this->signFunc) && is_callable($this->signFunc)) {
                $mdest = 'S'; # to sign file, we need it's body!
            }
            $result = $this->_pdf->Output($this->_outname, $mdest);
            if (!empty($this->signFunc) && is_callable($this->signFunc) && $result) {
                # WriteDebugInfo("calling sign func: ", $this->signFunc, ", body size: ", strlen($result));
                $result = call_user_func($this->signFunc, $result);
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
        $this->_data = array();
        if ($toMemory) return $result;
    }

    protected function _evalAttribute($attr) {
        if(!$attr) return $attr;
        if(substr($attr,0,1)=='@') {

          $atfunc = substr($attr,1);
          if(is_callable($atfunc)) return call_user_func($atfunc);
          return '';
        }
        else {
            return $attr;
        }
    }

    # TCPDF 6.x moves convertHTMLColorToDec() to static method of TCPDF_COLORS class
    protected function colorToDec($clr) {
        if(class_exists('TCPDF_COLORS')) return TCPDF_COLORS::convertHTMLColorToDec($clr,$this->spot_colors);
        else return $this->_pdf->convertHTMLColorToDec($clr);
    }

    # tries to adjust font size to make text fit in area ($width x $height)
    protected function _getAdaptiveFontSize($string, $width, $height, $curFntSize, $fname='') {
#        $fntFactor = 2.9;
        $fntFactor = 3.7;
#        if (mb_strlen($string)>70) WriteDebugInfo('curFntSize:', $curFntSize);
        if ($curFntSize == 0) $curFntSize = $this->_basepar['font']['size'];
        $curFntSize /= $fntFactor; # millimeters becomes something approx. 3 times less
        $Sarea = ($width * $height);
        $Schar = ($curFntSize*0.6) * ($curFntSize * 1.2);

        $sLen = mb_strlen($string);
        if ($sLen === FALSE) {
            return round($curFntSize * $fntFactor,1);
        }
        $SforText = $Schar * $sLen;
        if ($SforText <= $Sarea) {
            $newFntSize = round($curFntSize * $fntFactor,1);
#            WriteDebugInfo("$fname - no font change (fit in area)");
            return $newFntSize;
        }
        $newFntSize = round($fntFactor * $curFntSize * sqrt($Sarea / $SforText) , 1);
#        if (mb_strlen($string)>70) WriteDebugInfo('new fnt size:', $newFntSize);

        return $newFntSize;
    }

    protected function _valueToPdf($value, $fcfg) {

        if(!isset($fcfg['name'])) return;
        $fldname = $fcfg['name'];
        if ( in_array($fldname, $this->_hide_fields) ) return;

        $origwidth  = $width  = empty($fcfg['width']) ? 0 : (float)$fcfg['width'];
        $origheight = $height = empty($fcfg['height']) ? 0 : (float)$fcfg['height'];
        $fldtype = isset($fcfg['type']) ? $fcfg['type'] : '';
        $posx = empty($fcfg['posx']) ? array(0) : $fcfg['posx'];
        $posy = empty($fcfg['posy']) ? 0 : $fcfg['posy'];

        # auto-adjust zero width and height
        if($width<=0 && $fldtype!=='image') {
            $width = $this->_pdf->getPageWidth() - $posx[0] - self::DEFAULT_MARGIN;
        }
        if($height<=0 && $fldtype!=='image') {
            $height = $this->_pdf->getPageHeight() - $posy - self::DEFAULT_MARGIN;
        }

        $cstep = empty($fcfg['charstep']) ? 0 : $fcfg['charstep'];
        $maxlen = empty($fcfg['maxlength']) ? 0 : $fcfg['maxlength'];
        $fntsize = empty($fcfg['size']) ? 0 : (float)$fcfg['size'];
        $fntname = empty($fcfg['font']) ? '' : $fcfg['font'];
        $color = $this->_evalAttribute($fcfg['color']);
        $bgcolor = $this->_evalAttribute($fcfg['bgcolor']);

        $src = empty($fcfg['src']) ? '' : $fcfg['src'];
        if (isset($this->_field_newattr[$fldname]['src']))
            $src = $this->_field_newattr[$fldname]['src'];
        $rotate = empty($fcfg['rotate']) ? 0 : floatval($fcfg['rotate']);
        $border = empty($fcfg['border']) ? 0 : (intval($fcfg['border'])? intval($fcfg['border']) : $fcfg['border']);
        $align  = isset($fcfg['align']) ? $fcfg['align'] : '';
        $foptions = $this->_evalAttribute($fcfg['options']);

        if($color) {
            $rgb = $this->colorToDec($color);
        }
        else $rgb = array(0,0,0);
        $this->_pdf->SetTextColorArray($rgb);

        if($rotate) {
            $this->_pdf->StartTransform();
            $this->_pdf->Rotate($rotate,$posx[0],$posy);
        }

        if($bgcolor) { # draw "background" filled ractangle
            $brgb = $this->colorToDec($bgcolor);
            if($brgb && $width>0 && $height>0) {
                $this->_pdf->Rect($posx[0], $posy, $width, $height, 'F', array(), array($brgb['R'],$brgb['G'],$brgb['B']));
            }
        }

        if ($fldtype==='image') {
            if( $this->_images_disabled ) return;
            if(is_file($this->_img_path . $src)) $src = $this->_img_path . $src;
            elseif(is_file($this->_homedir . $src)) $src = $this->_homedir . $src;
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

            if($fldtype === 'money' && is_numeric($value)) {
                $vUtf = number_format(floatval($value),2,'.',' ');
            }
            else $vUtf = $this->_convertCset($value);
            if($maxlen>0 && mb_strlen($vUtf)>$maxlen) {
                $vUtf = mb_substr($vUtf,0,$maxlen,'UTF-8');
            }
            if($fntname !='' && $this->_basepar['font']['name'] != $fntname) {
                $this->_pdf->SetFont($fntname);
            }
            if ($this->adaptive_sizing && !empty($origheight) && !empty($origwidth)) {
                $curFntSize = ($fntsize) ? $fntsize : $this->_basepar['font']['size'];
                $fntsize = $this->_getAdaptiveFontSize($vUtf, $width, $height, $curFntSize, $fldname);
            }
            if ($fntsize !=0 && $this->_basepar['font']['size'] != $fntsize) {
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
                $this->_pdf->MultiCell($width,$height,$vUtf,  $border  , $align, 0, 1, ($posx[0]+$this->offsets[0]), ($posy+$this->offsets[1]) );
            }
            # Get back to "std" font & text color
            if($this->_basepar['font']['name'])
                $this->_pdf->SetFont($this->_basepar['font']['name'],'',(float)$this->_basepar['font']['size']);
            if($color) $this->_pdf->SetTextColorArray(0); # back to normal black
        }

        elseif ($fldtype === 'html') { # render string as HTML code
            $vUtf = $this->_convertCset($value);
            if($fntname !='' && $this->_basepar['font']['name'] != $fntname) {
                $this->_pdf->SetFont($fntname);
            }
            if ($fntsize !=0 && $this->_basepar['font']['size'] != $fntsize) {
                $this->_pdf->setFontSize($fntsize);
            }
            # writeHTMLCell($w, $h, $x, $y, $html='', $border=0, $ln=0, $fill=0, $reseth=true, $align='', $autopadding=true)
            $this->_pdf->writeHTMLCell($width, $height, $posx[0], $posy, $vUtf, 0, 0, 0, true, $align, true);
            // get back to defaults:
            if($this->_basepar['font']['name'])
                $this->_pdf->SetFont($this->_basepar['font']['name'],'',(float)$this->_basepar['font']['size']);
            $this->_pdf->setFontSize($this->_basepar['font']['size']);
        }


        elseif($fldtype==='checkbox' or $fldtype==='check') {
            if ($fntsize !=0 && $this->_basepar['font']['size'] != $fntsize) {
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
            if(!empty($style['fontsize']) && empty($style['font'])) $style['font'] = $this->_basepar['font']['name']; # without 'font', fontsize is ignored in TCPDF!
#            if($rgb) $style['fgcolor'] = $rgb;
            $this->_pdf->write1DBarcode($value, $bctype, ($posx[0]+$this->offsets[0]), ($posy+$this->offsets[1]), $width, $height, $xres, $style, $align);
        }

        elseif($fldtype=='qrcode') { # Printing QRCode
            $qrtype = 'QRCODE,H';
            $style=array();
            if($rgb) $style['fgcolor'] = $rgb;
            $this->_pdf->write2DBarcode($value, $qrtype, ($posx[0]+$this->offsets[0]), ($posy+$this->offsets[1]), $width, $height, $style, $align, false);
        }

        elseif($fldtype=='rect') {
            $p = array($posx[0],$posy,$posx[0]+$width,$posy,$posx[0]+$width,$posy+$height,$posx[0],$posy+$height);
            $style = '';
#            $line_style = array('all'=> array('width' => 1, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => $rgb));
            $this->_pdf->SetDrawColorArray($rgb);
            $this->_pdf->Polygon($p, $style, array(),array(),true);
            # Polygon($p, $style='', $line_style=array(), $fill_color=array(), $closed=true)
        }

        elseif($fldtype=='poly') {
            $p = array();
            for($kk=0;$kk<min(count($posx),count($posy));$kk++) {
                $p[] = $posx[$kk]; $p[] = $posy[$kk];
            }
            $style = '';
#            $lstyle = array('width' => 1, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => $rgb);
            $this->_pdf->SetDrawColorArray($rgb);

            $this->_pdf->Polygon($p, $style, array(),array(), false);
        }

        elseif($fldtype=='plugin') { # Render specific area by calling plugin.Render()

            $plgclass = isset($fcfg['plugintype']) ? $fcfg['plugintype'] : '';
            if($plgclass!='' && class_exists($plgclass)  ) {

                $arr = explode(',',$foptions);
                $opt_arr = array();
                foreach($arr as $elem) {
                    $optpair = explode('=',$elem);
                    if(count($optpair)==2) $opt_arr[$optpair[0]] = $optpair[1];
                }

                $pdf_plg = new $plgclass($this->_pdf, array_merge($this->_config, $opt_arr), $posx[0],$posy,$width,$height);
                if($pdf_plg instanceof PfPdfPlugin) {
                    # plugin-specific options can be passed in plugin attribute options="name=value1,..."
#                    $pdf_plg->setConfig(array_merge($this->_config, $opt_arr));
#                    if(count($opt_arr)) $pdf_plg->setConfig($opt_arr);

                    $renderData = (isset($this->_pluginData[$fldname])) ? $this->_pluginData[$fldname] : $value;
                    $result = $pdf_plg->Render($renderData);
                    if(!$result && is_callable('WriteDebugInfo')) {
                        $this->_errormessage = $pdf_plg->getErrorMessage();
#                        WriteDebugInfo("plugin $plgclass::Render() error : ".$this->_errormessage);
                    }
                }
                else {
                    $this->_errormessage = "Unknown plugin class [$plgclass] or not instance of PfPdfPlugin, rendering skipped";
                    if(is_callable('WriteDebugInfo')) WriteDebugInfo($this->_errormessage);
                }
                unset($pdf_plg);
            }
        }
        if($rotate) $this->_pdf->StopTransform();
        if($fntsize !=0 && $this->_basepar['font']['size'] != $fntsize) { # back to default font size
            $this->_pdf->setFontSize($this->_basepar['font']['size']);
        }
        $this->_pdf->SetDrawColorArray(0);
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
    */
    public function AppendPageDefFromXml($xmlCfg, $subid='', $relpath = NULL) {

        $tmpCfg = new CPrintFormPdf($xmlCfg);
        if (!$relpath) $relpath = $tmpCfg->_homedir;
        if (substr($relpath,-1) !== '/' && substr($relpath,-1) !== '\\')
           $relpath .= '/';
        $orientation = $tmpCfg->_basepar['page']['orientation'];
        if (count($tmpCfg->_templatefile)) {
            foreach($tmpCfg->_templatefile as $oneTpl) {
                $realXmlname = self::getRelativeFilename($relpath, $oneTpl['src']);
                $tplpath = dirname($realXmlname);
                if ($tplpath) $tplpath .= '/';
                $tplname = basename($oneTpl['src']);
#                $oneTpl['src'] = $tplpath . $tplname;
                $this->_templatefile[] = array('src'=>array($tplpath, $tplname, $tmpCfg->_img_path));
                ## STOP HERE!
            }
        }

        if (count($tmpCfg->_pagedefs)) {
            $tFont = $tmpCfg->_basepar['font'];
#            WriteDebugInfo('child basepar font:', $tFont);
#            WriteDebugInfo('this basepar font:', $this->_basepar['font']);
#            echo 'child basepar:<pre>' . print_r($tmpCfg->_basepar,1) .'</pre>'; exit;
            $newName = ($tFont['name'] === $this->_basepar['font']['name']) ? '': $tFont['name'];
            $newSize = ($tFont['size'] === $this->_basepar['font']['size']) ? '': $tFont['size'];
            # apply BASE font & size as default for imported fields (if they're different vs "parent" cfg)
            $pageid = 'subpage_'.rand(100000,999999);
            foreach($tmpCfg->_pagedefs as &$pdef) {
                if ($subid) $pdef['datasubid'] = (string)$subid;
                $pdef['sourcepath'] = $relpath;
                $pdef['pageid'] = $pageid;
                foreach($pdef['fields'] as &$onefld) {
                    if (($newName) && !($onefld['font']))
                        { $onefld['font'] = $newName; }
                    if (($newSize) && $onefld['size']==0 && !($onefld['size']))
                        { $onefld['size'] = $newSize; }
                }
            }
            $this->_pagedefs = array_merge($this->_pagedefs, $tmpCfg->_pagedefs);
            if (count($tmpCfg->_apFields)) {
                $this->_subApFields[$pageid] = $tmpCfg->_apFields;
                $this->_subApValues[$pageid] = $tmpCfg->_apValues;
            }
        }
        unset($tmpCfg);
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
    public function drawAccolade($x0,$y0,$xk,$yk, $color=array()) {
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
#            if (class_exists('FPDI'))
                $this->_pdf = new FPDI($this->_basepar['page']['orientation'],$this->_basepar['page']['units'],$this->_basepar['page']['size']);
#            elseif (class_exists('TCPDF_IMPORT')) {
#                $this->_pdf = new TCPDF_IMPORT($this->_basepar['page']['orientation'],$this->_basepar['page']['units'],$this->_basepar['page']['size']);
#            }
            $this->_pdf->setPrintHeader(false);
            $this->_pdf->setPrintFooter(false);
            $this->_pdf->SetFont($this->_basepar['font']['name'], '', $this->_basepar['font']['size']);
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

        $ret = ($this->_config['stringcharset']!='' && $this->_config['stringcharset']!='UTF-8') ?
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
    public static function parseParam($par) {
        $ret = (string)$par;
        if (substr($ret,0,1) === '@') {
          $ret = substr($ret,1);
          if (is_callable($ret)) $ret = call_user_func($ret);
        }
        return $ret;
    }
} # class CPrintFormPdf definition end