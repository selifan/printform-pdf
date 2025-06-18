<?php
/**
* @package \SelifanLab\FlexReport
* Configurable by XML report maker (out to XLS / HTM / TXT / XML formats)
* @author Alexander Selifonov
* @version 0.77.003
* @modified 2025-06-11
*/
namespace SelifanLab;

class FlexReport {

    const VERSION = '0.75';
    // Export formats
    const FMT_XLSX = 'xlsx';
    const FMT_HTML = 'html';
    const FMT_TXT = 'txt';
    const FMT_XML = 'xml';
    # schema to add in output XML file
    static $XML_SCHEMA = 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"';
    // error codes
    const ERR_BAD_XMLFILE = 1001;
    const ERR_NO_FIELDS = 1002;
    static $debugLimit = 0;
    private $_errCode = 0;
    // loaded report config will be here:
    private $_cfg = [];
    private $_cfgFileName = '';
    static $cfgFolder = '';
    private $_format = 'xlsx';
    private $_retBody = '';

    private $objPHPExcel = NULL;
    private $xlsSheet = NULL;
    private $xlRow = 0;
    private $_configFolder = '';
    private $callbackMdl = FALSE;
    private $formats = ['xlsx','html','txt','xml']; # allowed output formats (['xls','htm','txt','xml'])
    private $params = [];
    private $_dateformat = 'dd/mm/yyyy'; # date format in XLSX export
    private $_headWordWrap = TRUE; # auto word wrapping in XLSX headings
    private $rowConvertor = FALSE;
    private $subRowConvertor = FALSE;
    private $sqlPrepare = FALSE;
    private $debug = 0;
    public function __construct($xmlName = '', $callbackModule = FALSE, $configDir = FALSE) {
        if($configDir) $this->_configFolder = $configDir;
        if ($xmlName) $this->loadConfig($xmlName);
        if (is_object($callbackModule)) $this->callbackMdl = $callbackModule;
    }
    public function setConfigFolder($folder) {
        $this->_configFolder = $folder;
    }

    # pass callback for preparing SQL request before run
    public function AddSqlPrepare($callback) {
        $this->sqlPrepare = $callback;
    }

    public static function getVersion() { return self::VERSION; }

    public function loadConfig($cfgParam) {

        if (is_array($cfgParam)) {
            # ready-to-use array passed. Just get ut and return.
            $this->_cfg = $cfgParam;
            return;
        }
        $xml = FALSE;
        if(is_string($cfgParam)) { // try parse XML
            if (substr($cfgParam,0,5) === '<?xml') {
                $xml = @simplexml_load_string($cfgParam);
                $this->_cfgFileName = '';
            }
            else {
                # writeDebugInfo("report input xml config: ", $cfgParam);
                if(basename($cfgParam) == $cfgParam && !empty($this->_configFolder))
                    $cfgParam = $this->_configFolder . $cfgParam;
                # writeDebugInfo("final xml config: ", $cfgParam);
                if(!is_file($cfgParam)) throw new Exception("File not exist: $cfgParam");

                $xml = @simplexml_load_file($cfgParam);
                $this->_cfgFileName = $cfgParam;
            }
        }

        if (!is_object($xml)) {
            $this->_errCode = self::ERR_BAD_XMLFILE;
            return FALSE;
        }
        if (isset($xml->description))
            $this->_cfg['description'] = (string) $xml->description;
        else $this->_cfg['description'] = 'Report';
        if (isset($xml->title))
            $this->_cfg['title'] = (string) $xml->title;
        if (isset($xml->formtitle))
            $this->_cfg['formtitle'] = (string) $xml->formtitle;
        else $this->_cfg['formtitle'] = $this->_cfg['description'];
        if (isset($xml->dateformat))
            $this->_dateformat = (string) $xml->dateformat;

        if (isset($xml->formats)) {
            $this->formats = preg_split('/[,\|;\s]/',(string) $xml->formats,-1,PREG_SPLIT_NO_EMPTY);
        }
        if (isset($xml->info)) {
            $this->_cfg['info'] = [
              'version' => (isset($xml->info['version']) ? (string)$xml->info['version'] : '0.1'),
              'date' => (isset($xml->info['date']) ? (string)$xml->info['date'] : '2020-07-01'),
            ];
        }

        $this->_cfg['headings'] = [
          'background' => (isset($xml->headings['background']) ? (string)$xml->headings['background'] : '111111'),
          'border' => (isset($xml->headings['border']) ? (string)$xml->headings['border'] : '000000'),
          'color' => (isset($xml->headings['color']) ? (string)$xml->headings['color'] : '000000'),
          'align' => (isset($xml->headings['align']) ? (string)$xml->headings['align'] : 'C'),
        ];

        # single function callabck to convert all fielfd in one row
        $this->_cfg['preparedatarow'] = (isset($xml->preparedatarow) ? (string)$xml->preparedatarow : FALSE);

        $this->_cfg['export'] = [
          'filename' => 'report-'.date('Y-m-d')
        ];

        if (isset($xml->export['filename'])) {
            $this->_cfg['export']['filename'] = (string) $xml->export['filename'];
        }

        # callback function to check user access for this report
        if (!empty($xml->checkaccess)) {
            $this->_cfg['checkaccess'] = (string) $xml->checkaccess;
        }
        if (isset($xml->query)) { # query OR @callback_function that returns valid SQL query
            $this->_cfg['query'] = (string) $xml->query;
        }
        if (isset($xml->childquery)) { # sub-query ot cal for every row in "main" recordset proccess all data
            $this->_cfg['childquery'] = (string) $xml->childquery;
        }
        if (isset($xml->preparechildrow)) { # callback for convert child record or clear to FALSE (to skip in report)
            $this->_cfg['preparechildrow'] = (string) $xml->preparechildrow;
        }
        if (isset($xml->data)) { # callback function to get all data rows (instead of query)
            $this->_cfg['data'] = (string) $xml->data;
        }
        if (isset($xml->notes)) { # commentaries at the report's bottom
            $this->_cfg['notes'] = (string) $xml->notes;
            $this->_cfg['notes'] = \RusUtils::mb_trim($this->_cfg['notes']);
        }
        if (isset($xml->validateparams)) { # JS code to validate parameters
            $this->_cfg['validateparams'] = (string) $xml->validateparams;
            # writeDebugInfo("validateparams: ", $this->_cfg['validateparams']);
        }

        $pno=1;
        if (isset($xml->parameters)) foreach ($xml->parameters->children() as $key=>$item) {
            if($key !=='param') continue;
            $this->_cfg['parameters'][] = [
                'name' => (isset($item['name']) ? (string)$item['name'] : ('p'.$pno++)),
                'type' => (isset($item['type']) ? (string)$item['type'] : 'string'),
                'if' => (isset($item['if']) ? (string)$item['if'] : FALSE),
                'label' => (isset($item['label']) ? (string)$item['label'] : "parameter $pno"),
                'class' => (isset($item['class']) ? (string)$item['class'] : ''),
                'options' => (isset($item['options']) ? (string)$item['options'] : FALSE),
                'ifempty' => (isset($item['ifempty']) ? (string)$item['ifempty'] : ''),
                'default' => (isset($item['default']) ? (string)$item['default'] : NULL),
                'value' => (isset($item['value']) ? (string)$item['value'] : ''),
            ];
        }
        $pno = 1;
        if (isset($xml->fields)) foreach ($xml->fields->children() as $item) {
            $fldName = (isset($item['name']) ? (string)$item['name'] : ('p'.$pno++));
            $this->addField($fldName, $item);
        }
        if(count($this->_cfg['fields'])==0) {
            $this->_errCode = self::ERR_NO_FIELDS;
            return FALSE;
        }
        return TRUE;
    }
    /**
    * Adding one field definition
    *
    * @param mixed $fldName name field
    * @param mixed $item assoc. array with field parameters
    */
    public function addField($fldName, $item) {
        $this->_cfg['fields'][$fldName] = [
            'title' => (isset($item['title']) ? (string)$item['title'] : $fldName),
            'width' => (isset($item['width']) ? (int)$item['width'] : 0),
            'format' => (isset($item['format']) ? (string)$item['format'] : ''),
            'convert' => (isset($item['convert']) ? (string)$item['convert'] : FALSE),
        ];

    }
    public function getDescription() {
        return $this->_cfg['description'];
    }
    public function getTitle() {
        return $this->_cfg['formtitle'];
    }
    public function printConfig() {
        echo '<h3>Loaded report configuration</h3><pre>'.print_r($this->_cfg,1) . '</pre>';
    }
    public function getErrorCode() {
        return $this->_errCode;
    }

    # clean fields list brfore adding my own ones...
    public function clearFields() {
        $this->_cfg['fields'] = [];
    }
    public function parseOptionList($options) {
        if (substr($options,0,1) === '@') {
            $data = $this->evalParam($options);
            $arr = [];
            if(is_array($data)) foreach($data as $key=>$row) {
                if (is_array($row) && count($row)>1) $arr[] = [ 'value'=>$row[0], 'label'=>$row[1] ];
                else $arr[] = ['value'=>$key, 'label'=>(is_array($row)?$row[0]:$row) ];
            }
        }
        else { # options listed in jqGrid style string: "value1:label1;value2:label2 ..."
            $items = explode(';', $options);
            $arr = [];
            foreach($items as $element) {
                $pair = explode(':', $element);
                $arr[] = ['value' => $pair[0], 'label' => (isset($pair[1]) ?$pair[1]: $pair[0])];
            }
        }
        return $arr;
    }

    public function paramForm() {
        # if (empty($this->_cfg['parameters'])) return '';
        $ret = '';
        $title_format = \WebApp::getLocalized('output_format','Output format');

        if (!empty($this->_cfg['parameters']) && count($this->_cfg['parameters']))
          foreach ($this->_cfg['parameters'] as $par) {
            if (in_array($par['type'], ['const','hidden'])) { # constant value
                /*
                $value = $par['value'];
                if (!empty($value) && is_callable($value)) $value = call_user_func($value);
                $value = urlencode($value);
                $ret .= "\n<tr><td><input type=\"hidden\" name=\"$par[name]\" value=\"$value\"></td></tr>";
                */
                continue;
            }
            if(!empty($par['if']) && is_callable($par['if'])) {
                # skip this parameter if callback returns FALSE or 0
                $enabled = call_user_func($par['if']);
                if(!$enabled) continue;
            }
            $attrs = '';
            if(!empty($par['class'])) $attrs .= " class=\"$par[class]\"";
            $defValue = $par['default'];

            switch($par['type']) {
                case 'date':
                    $input = "<input type=\"text\" name=\"$par[name]\" class=\"form-control w100 datefield\">";
                    break;
                case 'checkbox':
                    $input = "<input type=\"checkbox\" name=\"$par[name]\" value='1' $attrs>";
                    break;
                case 'select':
                    $arrOptions = $this->parseOptionList($par['options']);
                    $input = "<select name=\"$par[name]\" $attrs>";

                    if (is_array($arrOptions))
                        $input .= DrawSelectOptions($arrOptions,'',TRUE);
                    /*
                    foreach($arrOptions as $optKey=>$optData) {
                        $optVal = $optData['value'];
                        $optLabel = $optData['label'];
                        $input .= "\n<option value='$optVal'>$optLabel</option>";
                    }
                    */
                    $input .= "\n</select>";
                    break;

                case 'multiselect':
                    $arrOptions = $this->parseOptionList($par['options']);

                    $input = "<div class=\"bordered\" style=\"max-height:200px; padding:6px; min-width:240px; overflow:auto\" $attrs><table>";
                    $fldname = $par['name']. '[]';
                    if (is_array($arrOptions)) foreach($arrOptions as $optKey=>$optData) {
                        $optVal = $optData['value'];
                        $optLabel = $optData['label'];
                        $input .= "<tr><td class='nowrap'><label><input type=\"checkbox\" name=\"$fldname\" value='$optVal'> $optLabel</label></td></tr>";
                    }
                    $input .= "\n</table></div>";
                    break;

                case 'radio':
                    $arrOptions = $this->parseOptionList($par['options']);
                    $input = '';
                    if (is_array($arrOptions)) foreach($arrOptions as $optKey=>$optData) {
                        $optVal = $optData['value'];
                        $optLabel = $optData['label'];
                        $input .= "<label $attrs><input type='radio' name='$par[name]' id='{$fname}_{$optVal}' value='$optVal'> $optLabel</label> &nbsp;";
                    }
                    break;

                case 'int': case 'integer':
                    $def = ($defValue === NULL) ? '' : " value='$defValue'";
                    $input = "<input type=\"number\" name=\"$par[name]\" $attrs step=\"1\" $def />";
                    break;

                default:
                    $def = ($defValue === NULL) ? '' : " value='$defValue'";
                    $input = "<input type=\"text\" name=\"$par[name]\" $attrs $def />";
                    break;
            }
            $ret .= "\n<div class='d-flex flex-md-row-reverse col-md-7 col-12 py-1 border-bottom'>$input</div><div class='col-md-4 col-12 py-1 border-bottom'>$par[label]</div>";
        }

        $fmtXls = in_array(self::FMT_XLSX, $this->formats) ?
          '<label><input type="radio" name="fr_format" id="fmt_xlsx" value="xlsx" checked="checked" onclick="flexRep.setAjax(1)"> Excel</label>&nbsp;' : '';
        $fmtHtm = in_array(self::FMT_HTML, $this->formats) ?
          '<label><input type="radio" name="fr_format" id="fmt_html" value="html" onclick="flexRep.setAjax(0)"> HTML</label>&nbsp;' : '';
        $fmtTxt = in_array(self::FMT_TXT, $this->formats) ?
          '<label><input type="radio" name="fr_format" id="fmt_txt" value="txt" onclick="flexRep.setAjax(1)"> TXT(tab-delimited)</label>&nbsp;' : '';
        $fmtXml = in_array(self::FMT_XML, $this->formats) ?
          '<label><input type="radio" name="fr_format" id="fmt_xml" value="xml" onclick="flexRep.setAjax(1)"> XML</label>' : '';

        $ret .= <<< EOHTM
<div class="d-flex align-items-center">$title_format:&nbsp;&nbsp;
$fmtXls &nbsp; $fmtHtm &nbsp; $fmtTxt &nbsp; $fmtXml
</div>
EOHTM;

        return "<div class='row'>$ret</div>";
    }
    /**
    * Draws full HTML form with parameter fields
    *
    * @param mixed $bckend
    */
    public function fullForm($backend = './') {
        $fileid = $this->_cfgFileName;
        $homedir = $_SERVER['DOCUMENT_ROOT'];
        $relFile = strtr($fileid, [$homedir => '']);
        if ($this->_configFolder != '') {
            $fileid = str_replace($this->_configFolder, '',$fileid);
        }
        if (!empty($this->_cfg['checkaccess']) && is_callable($this->_cfg['checkaccess'])) {

            $access = call_user_func($this->_cfg['checkaccess']);
            if (!$access) {
                if (class_exists('\AppEnv')) {
                    $ret = \appEnv::getLocalized('err-no-rights');
                }
                else $ret = 'Access to report denied';
                return $ret;
            }
        }
        $paramBody = $this->paramForm();
        $validateJs = 'null';
        if (!empty($this->_cfg['validateparams']))
            $validateJs = 'function() { ' . $this->_cfg['validateparams'] . ' }';

        $ret = <<< EOHTM
<form id="fm_flexreport">
<input type="hidden" name="fr_xmlconfig" value="$fileid" />
<input type="hidden" name="ajax" value="1" />
<input type="hidden" name="fr_action" value="run" />
<script type="text/javascript">
flexRep = {
 setAjax: function(ajxVal) {
   $("input[name=ajax]").val(ajxVal);
 }
};
</script>
<div class="card w-600">
 <div class="card-body">$paramBody</div>
  <div class="area-buttons card-footer">
  <input type="button" class="btn btn-primary" onclick="flexreport.run()" value="Выполнить" />
  </div>
</div>
</form>
<script type="text/javascript">
flexreport = {
  paramValidate: $validateJs
  ,run: function() {
    var bRun = true;
    if(typeof(flexreport.paramValidate) == 'function') bRun = flexreport.paramValidate();
    if (bRun)
      window.open("$backend" +"&" + $("#fm_flexreport").serialize(), "_blank");
  }
}
</script>
EOHTM;
        return $ret;
    }
    public function evalParam($parString, $debug = 0) {
        # if ($debug) echo " evalParam($parString)...<br>";
        if (substr($parString,0,1) === '@') {
            $func = substr($parString,1);
            # if ($debug) echo "func: <pre>".print_r($func,1).'</pre>';
            if (is_callable($func)) {
                $data = call_user_func($func, $this->params);
            }
            elseif(is_object($this->callbackMdl) && method_exists($this->callbackMdl, $func)) {
                $data = $this->callbackMdl->$func($this->params);
            }
            else $data = '';
            return $data;
        }
        # if ($debug) echo "$parString not parsed, use as is<br>";
        return $parString;
    }
    /**
    * Create report and draw/send to client
    * @param mixed $params optional parameters
    */
    public function execute($params = FALSE) {
        # writeDebugInfo(__METHOD__, ", callbackModule: ", $this->callbackMdl);
        if (empty($params) && isset($_GET)) $params = array_merge($_GET, $_POST);
        $this->params = $params;
        $link = $data = FALSE;
        $subst = [];
        $this->_retBody = '';
        if (!empty($params['fr_format']))
            $this->_format = $params['fr_format'];
        else $this->_format = self::FMT_HTML;
        $cfgParams = $this->_cfg['parameters'] ?? [];
        foreach($cfgParams as $parItem) {
            $pname = $parItem['name'];
            $ptype = $parItem['type'];
            if (in_array($ptype, ['const','hidden'])) {
                $pval = $parItem['value'];
                if (!empty($pval)) $pval = $this->evalParam($pval);
            }
            else {
                $pval = isset($params[$pname]) ? $params[$pname] : '';
                if ($pval === '' && !empty($parItem['ifempty'])) $pval = $parItem['ifempty'];
                if($ptype ==='date') $pval = to_date($pval);
            }
            $subst['{'.$pname.'}'] = is_scalar($pval) ? $pval : (is_array($pval) ? implode(',',$pval): '');
        }
        $this->rowConvertor = isset($this->_cfg['preparedatarow']) ? $this->_cfg['preparedatarow'] : '';
        $this->subRowConvertor = isset($this->_cfg['preparechildrow']) ? $this->_cfg['preparechildrow'] : '';

        if (!empty($this->rowConvertor) && substr($this->rowConvertor, 0,1) === '@') {
            $this->rowConvertor = substr($this->rowConvertor,1);
        }

        # exit(__FILE__ .':'.__LINE__.' _cfg:<pre>' . print_r($this->_cfg,1) . '</pre>');
        if (!empty($this->_cfg['query'])) {
            $query = $this->evalParam($this->_cfg['query']);
            $query = strtr($query, $subst);
            if(!empty($this->sqlPrepare) && is_callable($this->sqlPrepare)) {
                $query = call_user_func($this->sqlPrepare, $query);
            }

            $link = \appEnv::$db->sql_query($query);
            $err = \appEnv::$db->sql_error();
            if ($err) die ("FlexReport Error - bad Query <pre>$query</pre> SQL error: $err<br>");
            # exit("report SQL:" . \appEnv::$db->getLastQuery());
        }
        elseif(!empty($this->_cfg['data'])) {
            $callBk = $this->_cfg['data'];
            if (substr($callBk,0,1)=='@') $callBk = substr($callBk,1);
            if (!is_callable($callBk)) die("FlexReport Error: $callBk is not a callable function");
            $data = call_user_func($callBk, $params);
            # echo 'data <pre>' . print_r($data,1). '</pre>'; exit;
        }
        # $this->printConfig();
        $this->_reportHeaders();
        $recDone = 0;
        $childQry = '';

        if(!empty($this->_cfg['childquery'])) {
            $childQry = $this->evalParam($this->_cfg['childquery']);
            $childQry = strtr($childQry, $subst);
            if($this->debug) writeDebugInfo("child Query template : $childQry");
        }

        if (is_array($data)) foreach($data as $no => $r) {
            $this->_buildDataRow($r, $this->rowConvertor);
            # writeDebugInfo("row($no): ", $r);
            $recDone++;
            if (self::$debugLimit>0 && $recDone>=self::$debugLimit) break; #debug pit stop
        }
        elseif ($link) while($r = \appEnv::$db->fetch_assoc($link)) {
            $this->_buildDataRow($r, $this->rowConvertor);
            # writeDebugInfo("raw row: ", $r);
            $recDone++;
            if (self::$debugLimit>0 && $recDone>=self::$debugLimit) break; #debug pit stop
            if($childQry) {
                $this->handleChildRows($childQry, $r);
            }

        }
        $this->_reportEnding();
        return $this->_retBody;
    }

    private function handleChildRows($childQry, $data) {
        if($this->debug) writeDebugInfo("handle child rows for data:", $data);
        $finalQry = $childQry;
        foreach($data as $key => $val) {
            $finalQry = str_replace('{'.$key.'}', $val, $finalQry);
        }

        $subLink = \appEnv::$db->sql_query($finalQry);
        $err = \appEnv::$db->sql_error();
        $recDone = 0;
        if ($err) die ("FlexReport Error - bad Query <pre>$finalQry</pre> SQL error: $err<br>");
        if($subLink) while($subRow = \appEnv::$db->fetch_assoc($subLink)) {
            if(is_array($subRow)) {
                $recDone += $this->_buildDataRow($subRow, $this->subRowConvertor);
                if($this->debug) writeDebugInfo("converted sub-row: ", $subRow);
            }
        }
        if($this->debug) writeDebugInfo("handled child rows: [$recDone]");
        return $recDone;
    }
    # call converting callback function(s) over passed data row
    private function _buildDataRow($r, $convertor = FALSE) {
        static $callCnt = 0;
        $callCnt++;
        if($this->debug) writeDebugInfo("src row (convertor=$convertor) ", $r);
        # if($callCnt< 3) writeDebugInfo("convertor: ", $convertor);
        if ($convertor) {
            $convList = preg_split('/[,;]/', $convertor, -1, PREG_SPLIT_NO_EMPTY);
            foreach($convList as $oneFunc) {
                if (is_callable($oneFunc))
                    $r = call_user_func($oneFunc, $r);
                elseif(is_object($this->callbackMdl) && method_exists($this->callbackMdl, $oneFunc)) {
                    $r = $this->callbackMdl->$oneFunc($r);
                }
            }
        }
        if(is_array($r)) {
            foreach($r as $key=> &$val) {
                if (!empty($this->_cfg['fields'][$key]['convert'])) {
                    $func = $this->_cfg['fields'][$key]['convert'];
                    if (is_callable($func)) $val = call_user_func($func, $val);
                }
            }
            $this->_reportRow($r);
            return 1;
        }
        # ir row is not array - skip it !
        return 0;
    }

    private function _reportHeaders() {
        # writeDebugInfo("params: ", $this->params);
        $title = $this->evalParam($this->_cfg['title']);
        if (isset($this->_cfg['parameters']) && is_array($this->_cfg['parameters']))
        foreach($this->_cfg['parameters'] as $parItem) {

            $from = '{'.$parItem['name'] . '}';
            $to = empty($this->params[$parItem['name']]) ? '' : $this->params[$parItem['name']];
            if(is_array($to)) $to = implode(',', $to);
            $title = str_replace($from,$to, $title);
        }
        $title = str_replace('{today}',date('d.m.Y'), $title); # На текущую дату

        switch($this->_format) {
            case self::FMT_HTML:
                $this->_retBody .= "<h4>$title</h4><table class='table table-hover table-striped'><tr>";
                foreach($this->_cfg['fields'] as $fid=>$fitem) {
                    $this->_retBody .= "<th>$fitem[title]</th>";
                }
                $this->_retBody .= '</tr>';
                break;

            case self::FMT_XLSX:
                # creating XLSX sheet, column headers
                @include_once('PHPExcel.php');
                if (!class_exists('PHPExcel')) {
                    die('Модуль PHPExcel.php не обнаружен');
                }
                $this->objPHPExcel = new \PHPExcel();
                $this->objPHPExcel->setActiveSheetIndex(0);
                $this->xlsSheet = $this->objPHPExcel->getActiveSheet();
                $this->xlsSheet->setTitle("Отчет");
                $this->xlsSheet->SetCellValue('A1',$title);
                $colno = 0;
                foreach($this->_cfg['fields'] as $fid=>$fitem) {
                    $colno++;
                    $colName = \PHPExcel_Cell::stringFromColumnIndex($colno - 1);

                    $this->xlsSheet->SetCellValue($colName.'2',$fitem['title']);
                    if ($fitem['width'] >0) {
                        $this->xlsSheet->getColumnDimension($colName)->setAutoSize(false);
                        $this->xlsSheet->getColumnDimension($colName)->setWidth($fitem['width']);
                    }

                    if ($this->_cfg['headings']['align'] === 'C') {
                        $this->xlsSheet->getStyle($colName.'2')->getAlignment()->setHorizontal(
                            \PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
                    }

                    $this->cellBackground($colName.'2', $this->_cfg['headings']['background']);
                }
                if ($this->_headWordWrap) {
                    $this->xlsSheet->getStyle("A2:{$colName}2")->getAlignment()->setWrapText(TRUE);
                    $this->xlsSheet->getStyle("A2:{$colName}2")->getAlignment()->setVertical(\PHPExcel_Style_Alignment::VERTICAL_CENTER);
                }
                # $this->xlsSheet->getRowDimension(2)->setRowHeight(20); change head row height!
                # make thin borders on headings row
                $this->xlsSheet->getStyle("A2:{$colName}2")->applyFromArray(
                    [
                        'borders' => [
                            'allborders' => [
                                'style' => \PHPExcel_Style_Border::BORDER_THIN,
                                'color' => [ 'rgb' => $this->_cfg['headings']['border'] ]
                            ]
                        ],
                        'font' => [
                          'bold'  => TRUE,
                          'color' => [ 'rgb' => $this->_cfg['headings']['color'] ],
                        ],
                    ]
                );

                $this->xlRow = 2;

                break;

            case self::FMT_TXT:
                $head = [];
                foreach($this->_cfg['fields'] as $fid=>$fitem) {
                    $head[] = "$fitem[title]\t";
                }
                $this->_retBody .= implode("\t", $head) . "\n";
                break;
            case self::FMT_XML:
                $schema = self::$XML_SCHEMA;
                $this->_retBody .= '<?xml version="1.0" encoding="UTF-8"?>' . "\n<reportdata $schema>\n";
                break;
        }

    }
    # 444,555,111.00 -> 444555111
    public static function unformatDecimal($val) {
        $ret = floatval(strtr($val, [','=>'',  ' '=>'']));
        return $ret;
    }

    private function XlsSetMoney($row,$col, $value) {
        $this->xlsSheet->setCellValueByColumnAndRow($col, $row, self::unformatDecimal($value));
        $colname = \PHPExcel_Cell::stringFromColumnIndex($col) . $row;
        $this->xlsSheet->getStyle($colname)->getNumberFormat()->setFormatCode(\PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        # '#,##0.00' - thousands with delimiter, 2 decimal digits
    }
    private function XlsSetText($row,$col, $value) {
        $colname = \PHPExcel_Cell::stringFromColumnIndex($col) . $row;
        # $this->xlsSheet->getStyle($colname)->getNumberFormat()->setFormatCode(\PHPExcel_Style_NumberFormat::FORMAT_TEXT);
        # $this->xlsSheet->setCellValueByColumnAndRow($col, $row, self::unformatDecimal($value));
        $this->xlsSheet->setCellValueExplicit($colname," $value",\PHPExcel_Cell_DataType::TYPE_STRING);
        # $this->xlsSheet->getStyle($colname)->getNumberFormat()->setFormatCode('0');
    }

    private function XlsSetDate($row,$col, $value) {
        if(is_string($value)) { # try to convert 'dddd-mm-yy' or 'dd-mm-yyyy' to 'EXCEL' integer
            $elm = preg_split("/[\s\\-.\/]+/", $value);
            if(!is_array($elm) || count($elm)<3) {
                return;
            }
            $yy = intval($elm[0]); $mm = intval($elm[1]); $dd = intval($elm[2]); # default fmt is yyyy-mm-dd
            if($elm[2]>90) { # ok, it's must be mm/dd/yyyy OR dd.mm.yyyy
                $yy = intval($elm[2]);
                $mm = intval($elm[0]); $dd = intval($elm[1]);
            }
            if($mm>12) { # swap if wrong month (too big, may be it's day no.)
                $tmp = $dd; $mm = $dd; $dd=$mm; $mm=$tmp;
            }
            $value = floor(mktime(0,0,1,$mm,$dd,$yy) / 86400) + 25570; # 25570 - correcting from mktime to Excel date number
        }
        $this->xlsSheet->setCellValueByColumnAndRow($col, $row, $value);
        # $k1 = floor($col/26);   $k2 = intval($col % 26); $colname = (($k1>0)? chr(64+$k1):'') .chr(65+$k2).$row; # col,row number to "AZ5" notation
        $colname = \PHPExcel_Cell::stringFromColumnIndex($col) . $row;
        $this->xlsSheet->getStyle($colname)->getNumberFormat()->setFormatCode($this->_dateformat);
    }

    # делаем значение XLS ячейки URL-ой
    private function setAsLink($row,$col, $value) {
        $this->xlsSheet->setCellValueByColumnAndRow($col, $row, $value);
        if (empty($value)) return;
        # авто-фиксация ссылки без протокола http://, https:/// ...
        if (strpos($value,'://') === FALSE) $value = 'http://'.$value;
        $this->xlsSheet->getCellByColumnAndRow($col, $row)->getHyperlink()->setUrl($value);
    }

    private function _reportRow($r) {

        switch($this->_format) {

            case self::FMT_HTML:
                $rowContents = '';
                foreach($this->_cfg['fields'] as $key=>$fmt) { # $this->_cfg['fields']['fieldname']
                    $val = (isset($r[$key]) ? $r[$key] : '');
                    if ($fmt['format'] === 'date') {
                        if(intval($val)>0) {
                            if($this->_dateformat === 'dd/mm/yyyy' && intval($val)>1000)
                                $val = date('d.m.Y', strtotime($val));
                        }
                    }
                    elseif ($fmt['format'] === 'link') {
                        $val = "<a href=\"$val\" target=\"_blank\">$val</a>";
                    }

                    $rowContents .= "<td>$val</td>";
                }
                $rowStyle = '';
                if(!empty($r['__rowcolor__'])) {
                    # set row background color
                    $rowStyle = " style=\"background:#{$r['__rowcolor__']};\"";
                }

                $this->_retBody .=  '<tr'.$rowStyle . '>' . $rowContents . '</tr>';
                break;

            case self::FMT_XLSX:
                $this->xlRow++;
                $colno = 0;
                foreach($this->_cfg['fields'] as $key=>$fmt) { # $this->_cfg['fields']['fieldname']
                    $colno++;
                    $val = (isset($r[$key]) ? $r[$key] : '');
                    $colName = \PHPExcel_Cell::stringFromColumnIndex($colno - 1);
                    $format = $fmt['format'];
                    if ($format === 'money')
                        $this->XlsSetMoney($this->xlRow,($colno-1), $val);
                    elseif($format === 'date') {
                        if(intval($val)>0) {
                            $this->XlsSetDate($this->xlRow, ($colno-1), $val);
                        }
                    }
                    elseif($format === 'link') {
                        $this->setAsLink($this->xlRow, ($colno-1), $val);
                    }
                    elseif(in_array($format, ['text','string'])) {
                        $this->XlsSetText($this->xlRow, ($colno-1), $val);
                    }

                    else
                        $this->xlsSheet->SetCellValue($colName.$this->xlRow,$val);
                }
                if(!empty($r['__rowcolor__'])) {
                    # set row background color
                    $range = 'A' . $this->xlRow . ':' . $colName . $this->xlRow;
                    $this->cellBackground($range, $r['__rowcolor__']);
                }
                break;

            case self::FMT_TXT:
                $rowContents = [];
                foreach($this->_cfg['fields'] as $key=>$fmt) { # $this->_cfg['fields']['fieldname']
                    $rowContents[$key] = (isset($r[$key]) ? $r[$key] : '');
                    if ($fmt['format'] === 'date') {
                        if($this->_dateformat === 'dd/mm/yyyy' && intval($rowContents[$key])>1000)
                            $rowContents[$key] = date('d.m.Y', strtotime($rowContents[$key]));
                    }
                }

                $this->_retBody .=  implode("\t", $rowContents) . "\n";
                break;

            case self::FMT_XML:

                $this->_retBody .= "  <record>\n";
                foreach($this->_cfg['fields'] as $key=>$fmt) { # $this->_cfg['fields']['fieldname']
                    $val = (isset($r[$key]) ? $r[$key] : '');
                    if(self::specCharsExist($val))
                        $this->_retBody .= "    <$key><![CDATA[$val]]></$key>\n";
                    else
                        $this->_retBody .= "    <$key>$val</$key>\n";
                }
                $this->_retBody .= "  </record>\n";
                break;
        }

    }

    public static function specCharsExist($strg) {
        if(strpos($strg, '&')!==FALSE) return TRUE;
        if(strpos($strg, '>')!==FALSE) return TRUE;
        if(strpos($strg, '<')!==FALSE) return TRUE;
        return FALSE;
    }

    private function _reportEnding() {
        if(isset($this->_cfg['export']['filename']))
            $outname = strtr($this->_cfg['export']['filename'], ['{date}' => date('Y-m-d') ]);
        else $outname = '';
        $notes = FALSE;
        if (!empty($this->_cfg['notes'])) {
            $notes = $this->_cfg['notes'];
            if(mb_substr($notes,0,1,'UTF-8') === '@') {
                $notes = substr($notes,1);
                if(is_callable($notes)) $notes = call_user_func($notes, $this->params);
            }
        }
        switch($this->_format) {
            case self::FMT_HTML:
                $this->_retBody .= "</table>";
                if($notes) {
                    $this->_retBody .= "<pre>$notes</pre>";
                }
                break;

            case self::FMT_XLSX:
                if($notes) {
                    $this->xlRow += 2;
                    $lines = explode("\n", $notes);
                    foreach($lines as $line) {
                        $this->xlsSheet->SetCellValue('A'.($this->xlRow++),$line);
                    }
                }
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header("Content-Disposition: attachment;filename=\"$outname.xlsx\"");
                header('Cache-Control: max-age=0');
                $objWriter = \PHPExcel_IOFactory::createWriter($this->objPHPExcel, 'Excel2007');
                $objWriter->save('php://output');
                exit;

            case self::FMT_TXT:
                header('Content-type: text/plain');
                header("Content-Disposition: attachment;filename=\"$outname.txt\"");
                header('Cache-Control: max-age=0');
                exit($this->_retBody);
                break;
            case self::FMT_XML:
                $this->_retBody .= "</reportdata>\n";
                header('Content-type: application/xml');
                header("Content-Disposition: attachment;filename=\"$outname.xml\"");
                header('Cache-Control: max-age=0');
                exit($this->_retBody);
        }
    }

    public function cellBackground($cells,$color){

        if (substr($color,0,1) === '#') $color = substr($color,1);
        $this->xlsSheet->getStyle($cells)->getFill()->applyFromArray([
            'type' => \PHPExcel_Style_Fill::FILL_SOLID,
            'startcolor' => ['rgb' => $color ]
        ]);
    }

}
error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

