<?PHP
/**
* @name printform-pdf.php, contains class CPrintFormPdf,
* for generating "on the fly" PDF document populated with user data, possibly from source pdf template file,
* loaded configuration from XML file or string.
* @uses TCPDF, FPDFI classes used for reading/writing pdf body, see http://www.tcpdf.org/
* @uses Sudoku class by Richard Munroe (munroe@csworks.com) used  for creating Sudoku puzzle pages
* @Author Alexander Selifonov, <alex [at] selifan {dot} ru>
* @version 1.4.0023 2014-04-16
* @Link: http://www.selifan.ru
* @license http://www.opensource.org/licenses/bsd-license.php    BSD
*
**/
if(!class_exists('TCPDF',false)) {
    # You should this it in your module BEFORE include printform-pdf.php,
    #  and include your TCPDF language module rather then my case, lang/rus.php
    require_once('tcpdf/config/lang/rus.php');
    require_once('tcpdf/tcpdf.php');
    require_once('fpdf/fpdi2tcpdf_bridge.php');
    require_once('fpdf/fpdi.php');
}

/**
* Abstract class for PrintFormPDF plugins - modules that will draw by specific algorhytms in desired rectangle regions
* @since 1.1.0016 (2013-01-20)
*/
abstract class PfPdfPlugin {

    var $_error_message = 'no error';
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
    private $_data = array();
    private $_pageno = 0;
    private $_pluginData = array();
    private $_basepar = array();
    protected $_templatefile = ''; # source file to be used as template
    protected $_alttemplatefile = ''; # alternative template
    private $_outname = '';
    private $_pdf = null;
    private $_tofile  = false;
    private $_configfile = '';
    protected $_config = array();
    protected $_pagedefs = array();
    private $_errormessage = '';
    private $_alttemplate = false;
    private $_gridStep = false;
    private $_gridColor = array(80,80,80);
    private $_compression = false; # compress result PDF
    private $offsets = array(0,0); # for fine tuning field positions, depending on user printer
    private $_printedfields = false;
    private $_datagrids = array();
    private $_curGridRow = array();
    private $_specialPages = 0;
    private $_mpgrid = FALSE; # becomes TRUE if multi-paging grid detected
    private $_hide_pages = array();
    private $_images_disabled = false;
    private $_homedir = ''; # directory wher loaded XML file resides
    private $_pdf_path = ''; # PDF templates folder
    private $_img_path = ''; # images folder
    private $_apFields = array(); # fields to draw on every page
    private $_apValues = array(); # values for All Pages fields
    protected $spot_colors = array();
    public function __construct($param='') {
        $loadData = NULL;
        $this->_basepar = array(
          'page'     => array('orientation'=>'P', 'size'=>'A4', 'units'=>'mm')
          ,'font'    => array('name'=>'arial', 'size'=>4)
          ,'margins' => array('left'=>0, 'top'=>0, 'right'=>0, 'bottom'=>0)
        );
        $this->_config = array(
             'subject' => ''
            ,'author'  => ''
            ,'creator' => ''
            ,'protectfile' => false
        );
        if(is_array($param)) {

            if(isset($param['template'])) $this->_templatefile = (string)$param['template'];
            if(isset($param['alttemplate'])) $this->_alttemplatefile = (string)$param['template'];
            if(isset($param['outname']))  $this->_outname= $param['outname'];
            if(!empty($param['compression']))  $this->_compression = true;
            if(isset($param['tofile']))    $this->_tofile  = $param['tofile'];
            if(isset($param['configfile'])) {
               $this->_configfile  = $param['configfile'];
            }
            if(isset($param['subject'])) $this->_config['subject'] = (string)$param['subject'];
            if(isset($param['description'])) $this->_config['description'] = (string)$param['description'];
            if(isset($param['author'])) $this->_config['author'] = (string)$param['author'];
            if(isset($param['creator'])) $this->_config['creator'] = (string)$param['creator'];
            if(isset($param['stringcharset'])) $this->_config['stringcharset'] = (string)$param['stringcharset'];
            if(isset($param['pdfpath'])) $this->_pdf_path = (string)$param['pdfpath'];
            if(isset($param['imgpath'])) $this->_img_path = (string)$param['imgpath'];
        }
        elseif(is_scalar($param)) { # configuration XML filename or whole XML string was passed
            $this->_configfile = $param;
        }
        if(!empty($this->_configfile)) {
            $this->_homedir = dirname($this->_configfile) .'/';
            $ok = $this->LoadConfig();
        }

        $this->_pageno = 0;
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
        elseif(substr($cfgname,0,5)=='<'.'?xml') { $xml = @simplexml_load_string($cfgname); }
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
        if(isset($xml->stringcharset)) $this->_config['stringcharset'] = strtoupper($xml->stringcharset);
        # if not UTF-8 and not '', use iconv() to make UTF-8 !

        if(isset($xml->baseparameters->page)) {
            if(isset($xml->baseparameters->page['orientation'])) $this->_basepar['page']['orientation'] = (string)$xml->baseparameters->page['orientation'];
            if(isset($xml->baseparameters->page['size'])) $this->_basepar['page']['size'] = (string)$xml->baseparameters->page['size'];
            if(isset($xml->baseparameters->page['units'])) $this->_basepar['page']['units'] = (string)$xml->baseparameters->page['units'];
        }

        if(isset($xml->baseparameters->pdfpath)) {
            $this->_pdf_path = (string)$xml->baseparameters->pdfpath;
        }
        if(isset($xml->baseparameters->imgpath)) $this->_img_path = (string)$xml->baseparameters->imgpath;

        if(isset($xml->baseparameters->font)) {
            if(isset($xml->baseparameters->font['name'])) $this->_basepar['font']['name'] = (string)$xml->baseparameters->font['name'];
            if(isset($xml->baseparameters->font['size'])) $this->_basepar['font']['size'] = (float)$xml->baseparameters->font['size'];
        }
        if(isset($xml->baseparameters->margins)) {
            if(isset($xml->baseparameters->margins['left'])) $this->_basepar['margins']['left'] = (float)$xml->baseparameters->font['left'];
            if(isset($xml->baseparameters->margins['right'])) $this->_basepar['margins']['right'] = (float)$xml->baseparameters->font['right'];
            if(isset($xml->baseparameters->margins['top'])) $this->_basepar['margins']['top'] = (float)$xml->baseparameters->font['top'];
            if(isset($xml->baseparameters->margins['bottom'])) $this->_basepar['margins']['bottom'] = (float)$xml->baseparameters->font['bottom'];
        }

        if(isset($xml->baseparameters->templatefile)) {
            if(!empty($xml->baseparameters->templatefile['src']))
                $this->_templatefile    = (string)$xml->baseparameters->templatefile['src'];
            if(!empty($xml->baseparameters->templatefile['altsrc']))
                $this->_alttemplatefile = (string)$xml->baseparameters->templatefile['altsrc'];
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

        if(!empty($this->_config['templatefile'])) $this->_templatefile = (string)$this->_config['templatefile'];

        $this->_pagedefs = array();
        $ipage = 0;
        $fldcnt = 0;
        foreach($xml->pages->children() as $key => $pageitem) {
            $pageno = isset($pageitem['number']) ? (int) $pageitem['number'] : $ipage;
            $hide_it = isset($pageitem['hide']) ? (int) $pageitem['hide'] : 0;
            if($hide_it) $this->_hide_pages[$pageno] = true;
            $this->_pagedefs[] = array('pageno'=>$pageno, 'fields'=>array(), 'repeat'=>array(), 'hide'=>$hide_it);
            foreach($pageitem->children() as $key=>$item) {
                if($key=='template') { # указан PDF-шаблон для данной страницы
                    $this->_pagedefs[$ipage]['template'] = array(
                        'src'    => (isset($item['src']) ? (string) $item['src'] : false)
                       ,'altsrc' => (isset($item['altsrc']) ? (string) $item['altsrc'] : false)
                       ,'page'   => (!empty($item['page']) ? (int) $item['page'] : 0)
                    );
                    continue;
                }
                elseif($key=='field' OR $key=='image' OR $key=='plugin') {
                    $fldname = isset($item['name'])? trim("{$item['name']}") : '';
                    if(!$fldname) continue;
                    $newar = $this->_prepareFieldDef($item);
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
                    if(!$dtfields) continue; # empty datagrid tag (w/o field list) ignored
                    $gridId = isset($item['name']) ? (string)$item['name'] : 'datagrid'.(count($this->_datagrids)+1);
                    $this->_datagrids[$gridId] = array(
                         'page'  => $ipage
                        ,'fields'=> explode(',', $dtfields)
                        ,'posx'  => (isset($item['posx']) ? (string)$item['posx'] : '0')
                        ,'posy'  => (isset($item['posy']) ? (string)$item['posy'] : '0')
                        ,'step_y'=> (isset($item['step_y']) ? (float)$item['step_y'] : 0)
                        ,'rows'  => (isset($item['rows']) ? (int)$item['rows'] : 2)
                        ,'multipage' =>(isset($item['multipage']) ? (int)$item['multipage'] : false)
                    );
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
        if(!$fldcnt) {
            $this->_errormessage = 'No valid workpage definitions found (no fields defined)!';
            $ret = false;
        }
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
        $pg['pageno'] = (isset($opts['pageno']) ? $opts['pageno'] : $ipage);
        $pg['fields'] = (isset($opts['fields']) ? $opts['fields'] : array());
        $pg['repeat'] = (isset($opts['repeat']) ? $opts['repeat'] : array());
        $pg['hide'] = (isset($opts['hide']) ? $opts['hide'] : false);
        $pg['template'] = array(
           'src' => (isset($opts['src']) ? (string)$opts['src'] : '')
          ,'altsrc' => (isset($opts['altsrc']) ? (string)$opts['altsrc'] : '')
          ,'page' => (isset($opts['page']) ? (int)$opts['page'] : 1)
        );
        $this->_pagedefs[$ipage] = $pg;
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
    * Turns ON creating of measuring grid (will be drawn on the first page only)
    *
    * @param mixed $step
    */
    public function DrawMeasuringGrid($step = 10, $color = false) {
        $this->_gridStep = ($step==1) ? 10 : $step;
        if($color) $this->_gridColor = $color;
        else $this->_gridColor = array(80,80,80);
    }

    /**
    * Adds field definition (and possible value) to ALL PAGES (AP) in document.
    * For drawing "DRAFT" sign, bar-code, logo image and so on, on every PDF page
    * @param mixed $fdef full field definition array: name,type, posx,posy,rotate,color,...
    * @param mixed $value optional initial value for the field
    * @since 1.4
    */
    public function addAllPagesField($fdef, $value=null) {
        $new_fld = $this->_prepareFieldDef($fdef);
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
    private function _prepareFieldDef($item) {
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

        # Populating with data...
        foreach($this->_data as $entno=>$dataentity) { #<3>
            $pgno = 0;
            foreach($this->_pagedefs as $no=>$pagedef) { #<4>
                # skip the page if no printed fields in TEST mode:
                if(is_array($this->_printedfields) && (!isset($this->_printedfields[$no+1]) OR count($this->_printedfields[$no+1])<=0)) {
                    continue;
                }

                if($pagedef['hide']) {
                    continue; # page is temporary hidden by attrib "hide"
                }
                $this->_pdf->addPage();
                $pgno++;

                if(!empty($pagedef['template']['page'])) {
                    if(!empty($pagedef['template']['src'])) $pdfTpl = $pagedef['template']['src'];
                    else $pdfTpl = $this->_templatefile; # use MAIN template if nop specific pdf for the page
                    if($this->_alttemplate) {
                        if(!empty($pagedef['template']['altsrc'])) $pdfTpl = $pagedef['template']['altsrc'];
                        else $pdfTpl = $this->_alttemplatefile;
                    }

                    if(!empty($pdfTpl) /*&& is_file($pdfTpl)*/) try {
                        if(is_file($this->_pdf_path . $pdfTpl)) $pdfTpl = $this->_pdf_path . $pdfTpl;
                        $pc = $this->_pdf->setSourceFile((string)$pdfTpl);
                        $pg = $this->_pdf->importPage($pagedef['template']['page']); # pages numbers are 1-based!
                        $this->_pdf->useTemplate($pg);
                    }
                    catch (Exception $e) {
                        $this->_errormessage = 'Loading template PDF error, cause : '.$e->getMessage();
                        if(function_exists('WriteDebugInfo')) WriteDebugInfo($this->_errormessage," pagedef $no:",$this->pagedef);
                    }
                }
                $pageWidth  = $this->_pdf->getPageWidth();
                $pageHeight = $this->_pdf->getPageHeight();
#                if($pgno==1) { #operations to do on the first page only
                if($this->_gridStep > 0 ) { # let's draw a measuring grid
                    if($this->_gridColor) {
                        $color = $this->_parseColor($this->_gridColor);
                        $this->_pdf->SetDrawColorArray($color);
                        $this->_pdf->SetTextColorArray($color);
                    }
                    $this->_pdf->SetFontSize(4.5);
#                   $this->_pdf->SetLineWidth(0.2);
                    for($posx=0; $posx<$pageWidth; $posx+=$this->_gridStep) {
                        $this->_pdf->Line($posx,0,$posx, $pageHeight);
                        if($posx>0) {
                            $this->_pdf->Text($posx+0.05, 1.0, "$posx");
                            $this->_pdf->Text($posx+0.05, $pageHeight-5, "$posx");
                        }
                    }
                    for($posy=0; $posy<$pageHeight; $posy+=$this->_gridStep) {
                        $this->_pdf->Line(0,$posy,$pageWidth, $posy);
                        if($posy>0) {
                            $this->_pdf->Text(1.0, $posy+0.1, "$posy");
                            $this->_pdf->Text($pageWidth-5, $posy+0.1, "$posy");
                        }
                    }
                    $this->_pdf->SetTextColorArray(0);
                    $this->_pdf->setFontSize($this->_basepar['font']['size']);
                }
#                }
                if(count($this->_apFields)) {
#                    $values = array_merge($this->_apValues, $dataentity);
                    $this->_renderFieldSet($this->_apFields, array_merge($this->_apValues, $dataentity)); # first print "ALL PAGES" fields
                }
                $this->_renderFieldSet($pagedef['fields'], $dataentity, $debug, $pagedef);

            } #<4>
        } #<3>

        $this->_pdf->SetCompression($this->_compression); # will work if 'gzcompress' function enabled ! (see TCPDF docs)

        if(!empty($this->_config['protectfile'])) { # file protecting
            $pwd = isset($this->_config['password']) ? (string)$this->_config['password'] : '00000';
            $this->_pdf->SetProtection(array(),$pwd); # ATTENTION: protecting (encrypting) can take ve-e-ery long, could cause PHP timeout
        }

        if($output) {
            $this->Output();
        }
        return true;
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

    private function _renderFieldSet($fldset, $dataentity, $debug=false, $pagedef=null) {

        if(count($fldset)) foreach($fldset as $no=>$fcfg) {
            if(is_array($this->_printedfields) && !in_array($no, $this->_printedfields[$pgno])) continue; // print only selectd fields
            $fldname = $fcfg['name'];
            $ftype = $fcfg['type'];
            if(in_array($ftype,array('rect','poly','image'))) $dataentity[$fldname] = 1;
            if(!$debug && $ftype!=='plugin') {
                if(!isset($dataentity[$fldname])) continue;
                if(!is_scalar($dataentity[$fldname])) continue;
            }
            else {
               $debugValue = ($fcfg['type']=='checkbox') ? 'X' : "XX $fldname";
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
    *
    */
    public function Output($outfilename=false) {
        if($outfilename) $this->_outname = $outfilename;
        if($this->_tofile) {
            if(strtolower($this->_templatefile)==strtolower($this->_outname)) $this->_outname .= '.pdf'; # protect accidental template overwriting !
            $this->_pdf->Output($this->_outname,'F');
        }
        else {
            $this->_pdf->Output($this->_outname,'D');
        }
        $this->_pdf = null;
        $this->_data = array();
    }
    private function _evalAttribute($attr) {
       if($attr=='') return $attr;
       if(substr($attr,0,1)=='@') {
          $atfunc = substr($attr,1);
          if(function_exists($atfunc)) return call_user_func($atfunc);
          return '';
       }
       else return $attr;
    }

    # TCPDF 6.x moves convertHTMLColorToDec() to static method of TCPDF_COLORS class
    private function colorToDec($clr) {
        if(class_exists('TCPDF_COLORS')) return TCPDF_COLORS::convertHTMLColorToDec($clr,$this->spot_colors);
        else return $this->_pdf->convertHTMLColorToDec($clr);
    }

    private function _valueToPdf($value, $fcfg) {
        if(!isset($fcfg['name'])) return;
        $fldname = $fcfg['name'];
        $width = empty($fcfg['width']) ? 0 : (float)$fcfg['width'];
        $height = empty($fcfg['height']) ? 0 : (float)$fcfg['height'];
        $fldtype = isset($fcfg['type']) ? $fcfg['type'] : '';
        $posx = empty($fcfg['posx']) ? array(0) : $fcfg['posx'];
        $posy = empty($fcfg['posy']) ? 0 : $fcfg['posy'];

        # auto-adjust zero width and height
        if($width<=0) {
            $width = $this->_pdf->getPageWidth() - $posx[0] - self::DEFAULT_MARGIN;
        }
        if($height<=0) {
            $height = $this->_pdf->getPageHeight() - $posy - self::DEFAULT_MARGIN;
        }

        $cstep = empty($fcfg['charstep']) ? 0 : $fcfg['charstep'];
        $maxlen = empty($fcfg['maxlength']) ? 0 : $fcfg['maxlength'];
        $fntsize = empty($fcfg['size']) ? 0 : (float)$fcfg['size'];
        $fntname = empty($fcfg['font']) ? '' : $fcfg['font'];
        $color = $this->_evalAttribute($fcfg['color']);
        $bgcolor = $this->_evalAttribute($fcfg['bgcolor']);

        $src = empty($fcfg['src']) ? '' : $fcfg['src'];
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

        if ($fntsize !=0 && $this->_basepar['font']['size'] != $fntsize) {
            $this->_pdf->setFontSize($fntsize);
        }
        if ($fldtype==='image') {
            if(is_file($this->_img_path . $src)) $src = $this->_img_path . $src;
            elseif(is_file($this->_homedir . $src)) $src = $this->_homedir . $src;
            if( !$this->_images_disabled )
                $this->_pdf->Image($src,$posx[0],$posy,$width,$height);
        }
        elseif($fldtype==='' or $fldtype==='text' or $fldtype === 'money') {

            if($fldtype === 'money') $vUtf = number_format(floatval($value),2,'.',' ');
            else $vUtf = $this->_convertCset($value);
            if($maxlen>0 && mb_strlen($vUtf)>$maxlen) $vUtf = mb_substr($vUtf,0,$maxlen,'UTF-8');
            if($fntname !='' && $this->_basepar['font']['name'] != $fntname) {
                $this->_pdf->SetFont($fntname);
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
              $this->_pdf->MultiCell($width,$height,$vUtf, $border, $align, 0, 1, ($posx[0]+$this->offsets[0]), ($posy+$this->offsets[1]) );
            }
            # Get back to "std" font & text color
            if($this->_basepar['font']['name'])
                $this->_pdf->SetFont($this->_basepar['font']['name'],'',(float)$this->_basepar['font']['size']);
            if($color) $this->_pdf->SetTextColorArray(0); # back to normal black
        }

        elseif($fldtype==='checkbox' or $fldtype==='check') {
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

                    $result = (isset($this->_pluginData[$fldname])) ? $pdf_plg->Render($this->_pluginData[$fldname]) : -1;
                    if(!$result && function_exists('WriteDebugInfo')) {
                        $this->_errormessage = $pdf_plg->getErrorMessage();
                        WriteDebugInfo("plugin $plgclass::Render() error : ".$this->_errormessage);
                    }
                }
                else {
                    $this->_errormessage = "Unknown plugin class [$plgclass] or not instance of PfPdfPlugin, rendering skipped";
                    if(function_exists('WriteDebugInfo')) WriteDebugInfo($this->_errormessage);
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
    * generate SUDOKU puzzle game page
    * @param string $title Page title
    * @param int $level Difficulty level for generated Puzzle. If empty, no puzzle, just empty sudoku squares printed.
    * @param array $options passes optional parameters: grid color, digits color
    */
    public function AddPageSudoku($title='', $level=false, $options=null) {
        $margin_l = 30;
        $margin_r = 18;
        $margin_t = 18;
        $margin_b = 14;
        $cellsize = 7; # one cell size, mm

        $this->_specialPages++;

        $this->AddPage('P','mm');
        $colorGrid = isset($options['color_grid']) ? $this->_parseColor($options['color_grid']) : array(0,0,0);
        $colorCell = isset($options['color_cell']) ? $this->_parseColor($options['color_cell']) : array(0,0,0);

        if($title) $this->_valueToPdf($title,
           array(
              'posx'=> $margin_l
             ,'posy'=> min(2,($margin_t-10))
             ,'width' => ($this->_pdf->getPageWidth() - $margin_l - $margin_r)
             ,'align'=>'C'
             ,'size'=> 10
           ));

        $this->_pdf->SetDrawColorArray($colorGrid);

        for($stepY=$margin_t; $stepY<=$this->_pdf->getPageHeight()-($cellsize*9); $stepY+=90) { # <3>
            for($stepX=$margin_l; $stepX<=120; $stepX+=90) { #<4>
                # TODO: draw sudoku square 9x9 cells at [$stepX,$stepY] pos.
                for($yy=0; $yy<=9; $yy++) {
                    if($yy % 3 == 0) $this->_pdf->SetLineWidth(0.6);
                    else $this->_pdf->SetLineWidth(0.2);
                    $this->_pdf->Line($stepX,($stepY+$yy*$cellsize),$stepX+$cellsize*9,($stepY+$yy*$cellsize));
                }
                for($xx=0; $xx<=9; $xx++) {
                    if($xx % 3 == 0) $this->_pdf->SetLineWidth(0.6);
                    else $this->_pdf->SetLineWidth(0.2);
                    $this->_pdf->Line($stepX+$xx*$cellsize,($stepY),$stepX+$xx*$cellsize,($stepY+$cellsize*9));
                }

                # grid drawn, fill it with generated puzzle
                if($level>0 AND class_exists('Sudoku')) { #<5>
                    # generate puzzle data and print it on the sheet
                    $sudoku = new Sudoku();
                    $puzzlePos = $sudoku->generatePuzzle($level,70,12);

                    $this->_pdf->SetFontSize(11);
                    $this->_pdf->SetTextColorArray($colorCell);

                    if($puzzlePos) { # <6> Sometimes puzzle not generated, so playfield remains clean
                        foreach($puzzlePos as $item) { #<7>
                            $i = $item[0];
                            $j = $item[1];
                            $cifer = $item[2];
                            if(is_scalar($cifer))
                               $this->_pdf->Text(1 + $stepX+($j-1)*$cellsize,1 + $stepY+($i-1)*$cellsize, (string)$cifer);
#                            elseif(is_object($cifer)) $this->_pdf->Text(1 + $stepX+($j-1)*$cellsize,1 + $stepY+($i-1)*$cellsize, (string)$cifer->state);
                        } #<7>
                    } #<6>
                } #<5>
            } #<4>
        } #<3>
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
    private function _createPdfObject() {
        if(is_object($this->_pdf)) return true;
        try {
            $this->_pdf = new FPDI($this->_basepar['page']['orientation'],$this->_basepar['page']['units'],$this->_basepar['page']['size']);
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
    private function _convertCset($strval) {
        $ret = ($this->_config['stringcharset']!='' && $this->_config['stringcharset']!='UTF-8') ?
          @iconv($this->_config['stringcharset'],'UTF-8',$strval) : $strval;
        return $ret;
    }
    private function _parseColor($param) {
        if(is_array($param)) $color = $param;
        elseif(is_int($param)) $color = array($param,$param, $param);
        else {
            if(!$this->_pdf) $this->_createPdfObject();
            $color = $this->colorToDec((string)$param);
        }
        return $color;
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
} # class CPrintFormPdf definition end