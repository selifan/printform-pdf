<?PHP
/**
* @package PdfSesBlock
* @name PdfSesBlock.php, class PdfSesBlock,
* for generating Simple Electronic Sign (SES) in PDF document.
* @uses TCPDF
* @Author Alexander Selifonov, <alex [at] selifan {dot} ru>
* @version 1.06.003 2025-04-17
*
**/
# namespace NsPdfSesBlock;
class PdfSesBlock {

    public const VERSION = '1.05';
    const DEFAULT_MARGIN = 5;
    const DEFAULT_FONTSIZE = 9;
    const FONTSIZE_SMALLER = 8;
    const DEFAULT_FONT = 'arial';
	const X_PADDING = 2; # отступ от левого борта, мм
    private $deb = 0;

    private $_inited = FALSE;
    private $_rendered = FALSE;
    private $_data = array(); # _data['title'] ...
    private $_tcpdf = null;
    private $_curstate = array();
    protected $fonts = [];
    protected $mainObj = NULL;
    protected $_pos = [];
    protected $_dim = [];
    protected $cfg = [];
    protected $line_default = [ 'width'=>0.1, 'cap'=>'butt', 'dash'=>0,'color'=>[0,0,0] ];
    protected $sesType = FALSE; # SES block subtypes (for cerating title text: "Подпись zzzzzzzz"
    // default values for some parameters
    static $defaults = [
       'font' => ['name' => self::DEFAULT_FONT, 'size' =>9, 'color' => '#000'],
       'lineWidth' => 0.1,
       'border' => [ 'color' => '#888', 'width' => 0.1 ],
    ];

    private $_config = array(
        'stringcharset'=>'UTF-8'
       ,'font' => FALSE
    );
    private $_error_messages = [];

    public function __construct($tcpdfobj, $cfg = null, $x=FALSE,$y=FALSE,$w=FALSE,$h=FALSE) {
        $this->_tcpdf = $tcpdfobj;
        $this->_tcpdf->setPageUnit('mm');
        $this->cfg = $cfg;
        $this->_error_messages = [];
        if(is_array($cfg)) $this->setConfig($cfg);
        $x = (isset($cfg['posX']) ? floatval($cfg['posX']) : $x);
        $y = (isset($cfg['posY']) ? floatval($cfg['posY']) : $y);
        $w = (isset($cfg['width']) ? floatval($cfg['width']) : $w);
        $h = (isset($cfg['height']) ? floatval($cfg['height']) : $h);

        $this->_pos = array($x,$y);
        $this->_dim = array($w,$h);
    }

    /**
    * Sets chart plot area position
    *
    * @param mixed $x start x pos
    * @param mixed $y start y pos
    * @param mixed $w width
    * @param mixed $h height
    * @return PdfSesBlock
    */
    public function setAreaPosition($x, $y, $w=100, $h=30) {
        $this->_pos = array(floatval($x),floatval($y));
        $this->_dim = array(floatval($w),floatval($h));
        return $this;
    }
    public function setData($data) {
        $this->_data = $data;
        return $this;
    }

    public function setConfig($cfg) {
        $this->cfg = $cfg;
        if (isset($this->cfg['sestype'])) $this->sesType = $this->cfg['sestype'];
    }

    public function getErrorMessage() { return $this->_error_messages; }
    /**
    * Renders SES block
    */
    public function render($data = 0) {
        # insurername|insurer_fullname, sessign_date,sessign_time,sescode
        # $brgb = $this->colorToDec($color);
        # writeDebugInfo("data passed: ", $data);
        # writeDebugInfo("this->data : ", $this->_data);
        # writeDebugInfo("this->cfg : ", $this->cfg);

        $pepStrg = (!empty($data['sescode']) ? $data['sescode'] : '');

        $fio = $data['insurername'] ?? $data['insr_fullname'] ?? $data['insurer_fullname'] ?? $this->_tcpdf->dataentity['insurer_fullname'] ?? '';
        $instype=  $data['insurer_type'] ?? $this->_tcpdf->dataentity['insurer_type'] ?? '2'; # 2 - ЮЛ, печатать еще МП

        $fntName = (!empty($this->cfg['font'])) ? $this->cfg['font'] : self::DEFAULT_FONT;

        $signdate = '';
        if(!empty($data['sessign_date'])) {
            if(strlen($data['sessign_date'])>=15) $signdate = to_char($data['sessign_date'],1);
            else {
                $signdate = to_char($data['sessign_date']);
                if (!empty($data['sessign_time'])) $signdate .= ' '.$data['sessign_time'];
            }
        }

        switch($this->sesType) {
            case 'client':
                $strg = "Подпись Клиента:"; # sestype=client в строке options=""
                if(!empty($data['client_sescode'])) $pepStrg = $data['client_sescode'];
                break;
            case 'agent': # {upd/2023-01-24} новый тип - для подписаний ПЭП агентом
                $strg = 'Подпись агента:';
                $fio = !empty($data['agent_fullname']) ? $data['agent_fullname'] : 'Неизвестное Имя Агента';
                break;

            case 'fl': case '': case 'pholder': case '1': # страхователь, Физ-Лицо
                $strg = "Подпись Страхователя:";
                break;
            case 'insured':
                $strg = "Подпись Застрахованного/родителя \n(законного представителя) Застрахованного:";
                $fio = empty($data['parfio']) ? $data['insured_fullname'] : $data['parfio'];
                $signdate = $data['sesinsured_date'] ?? '';
                $pepStrg = empty($data['sescode_insured']) ? $data['sescode'] : $data['sescode_insured'];
                break;
            case 'insurer_pdn':
                $strg = "Подпись Страхователя:";
                $signdate = $data['sesinsurer_pdn_date'] ?? '';
                break;
            case 'cbenef': # {upd/2025-02-11} Представитель Застрахованного ребенка
                $strg = "Подпись Представителя:";
                $fio = $data['child_delegate_name'] ?? $data['cbenef_fullname'] ?? 'Неизвестное Имя представителя';
                break;
            default: # и все прочие - считаю что передали собсно строку кого - "Субъекта"
                $strg = "Подпись " . $this->sesType . ':';
                break;
        }

        $x = $this->_pos[0];
        $y = $this->_pos[1];
        $width = $this->_dim[0];
        $height = $this->_dim[1];

        # echo ("fio= $fio, short = [$shortFio] <pre>".print_r($data,1) . '</pre>');
        # exit(__FILE__ .':'.__LINE__.' _data:<pre>' . print_r($this->_data,1) . '</pre>');
        if ( empty($pepStrg) ) { # ПЭП кода нет - либо ЭДО еше не согласован, либо НЕ ПЭП процесс
            if(!empty($this->cfg['noedoprint'])) {
                # Печатаем ______/_________ вместо ПЭП блока для подписей страхователя
                $shortFio = \RusUtils::MakeFIO($fio);
                $x = $this->_pos[0];
                $y = $this->_pos[1];
                $width = $this->_dim[0];
                $height = $this->_dim[1];
                # в параметре noedoprint можно передать значение расстояния по вертикали от верха до вывода _____/________
                if(is_numeric($this->cfg['noedoprint']) && floatval($this->cfg['noedoprint']) > 1)
                    $height = floatval($this->cfg['noedoprint']);

                $slashPos = floor($width * 0.28);
                $yPos = $y + $height;
                $styles = [ 'width'=>0.3, 'color'=>[0,0,0], 'dash'=>0 ];
                $this->_tcpdf->Line($x, $yPos, ($x+$width), $yPos, $styles);
                $fontStyle = '';
                $fontSize = 10;
                $this->_tcpdf->SetFont($fntName, $fontStyle, $fontSize);
                $this->_tcpdf->SetTextColor(0, 0, 0);
                $this->_tcpdf->SetFont('arial', $fontStyle, 8);
                $this->_tcpdf->Text(($x + $slashPos), ($yPos-3.2), "/ $shortFio");
                # $this->_tcpdf->Text($x, ($yPos+0.6), "(подпись)");
                # writeDebugInfo("noedoprint, instype=$instype, data: ", $data);
                $leftText = ($instype == 2) ?  "(подпись)\nМ.П." : "(подпись)";

                $this->_tcpdf->MultiCell($width-1,$height-1,$leftText,  0, 'L', 0,
                    1, ($x-0.5), ($yPos+0.2), TRUE,0,false,TRUE,$height);

                $this->_tcpdf->Text(($x + $slashPos+1), ($yPos+0.2), "(расшифровка подписи)");
                # writeDebugInfo("TODO: noedoprint at $yPos, $x, slashPos: $slashPos");
            }
            return; # нет ПЭП кода, ничего не вывожу либо вывел строчки для подписи "страхователя"
        }
        $style = '';
        if (!empty($this->cfg['color'])) $color = $this->colorToDec($this->cfg['color']);
        else $color = [0,0,0];
        if (!empty($this->cfg['bgcolor'])) {
            $fill_color = $this->colorToDec($this->cfg['bgcolor']);
        }
        else $fill_color = [];

        $border_style = [ 'width'=>0.4, 'cap'=>'butt', 'dash'=>0,'color'=>$color ];
        $fill_color = [];
        /*
        if ($fcfg['thickness']>0) {
            $border_style['width'] = $fcfg['thickness'];
        }
        */
        # echo 'cfg <pre>' . print_r($this->cfg,1). '</pre>'; exit;
        $this->_tcpdf->setLineStyle($border_style);
        $this->_tcpdf->Rect($x, $y, $width, $height, $style, $border_style, $fill_color);
        $this->_tcpdf->setLineStyle($this->line_default);

        $fitCell = TRUE;
        $border = FALSE;
        $valign = 'M'; # Вертик.выравнивание - T=TOP, M=Middle
        $this->_tcpdf->SetTextColorArray($color);
        $defFontSize = ($height>=25 ? self::DEFAULT_FONTSIZE : self::FONTSIZE_SMALLER);
        $height2 = ceil($height / 4);
        $fntSize =(!empty($this->cfg['size'])) ? $this->cfg['size'] : $defFontSize;
        $this->_tcpdf->setFontSize($fntSize);

        $yOff = 1;
        $yStep = ($height - 2*$yOff) / 7;
        $this->_tcpdf->setFont($fntName);

        # TODO: $this->cfg['sestype'] = возможны вариации блока

        $strg .= "\nПодписано простой электронной подписью"
            . "\n" . $fio
            . "\nДата подписания: " . $signdate
            . "\nКод подтверждения ЭП: " . $pepStrg
        ;

        $this->_tcpdf->MultiCell($width-1,$height-1,$strg,  $border  , 'L', 0,
            1, ($x+0.5), ($y+0.5), TRUE,0,false,TRUE,$height-1,$valign, $fitCell);

        return TRUE;
    }

    public function evalUserValue($strg, $value, $catid = FALSE) {
        if (is_string($strg) && substr($strg,0,1) === '@') {
            $func = substr($strg,1);
            return (is_callable($func) ? call_user_func($func,$value, $catid) : 0);
        }
        return $strg;
    }
    public function colorToDec($clr) {
        if (is_array($clr)) return $clr;
        if (is_numeric($clr) && $clr<=255)
            return ['R'=>$clr, 'G'=>$clr, 'B'=>$clr];

        if(class_exists('TCPDF_COLORS')) $ret = TCPDF_COLORS::convertHTMLColorToDec($clr,$this->spot_colors);
        else $ret = $this->_tcpdf->convertHTMLColorToDec($clr);
        return $ret;
    }

    protected function prepareFont() {
        $this->curFntSize = $this->_tcpdf->getFontSize() * $this->_tcpdf->getScaleFactor();
        $result = self::$defaults['font'];
        if (isset($font['name'])) $result['name'] = $font['name'];
        if (isset($font['size'])) $result['size'] = floatval($font['size']);
        if (isset($font['color'])) $result['color'] = $font['color'];
        if (empty($result['color'])) $result['color'] = self::$defaults['font']['color'];

        $this->_tcpdf->setFont($result['name'],'',$result['size']);
        $this->_tcpdf->SetTextColorArray($this->colorToDec($this->colorToDec($result['color'])));
    }

    private function _saveCurrentPdfState() {
        $this->_curstate = array(
            'fontsize' => $this->_tcpdf->getFontSize(),
            'fontfamily' => $this->_tcpdf->getFontFamily(),
            # ,'textcolor' => $this->_tcpdf->????
        );
    }
    private function _restorePdfState() {

        $scaleFactor = $this->_tcpdf->getScaleFactor();
        $this->_tcpdf->setFont($this->_curstate['fontfamily'],'',$this->_curstate['fontsize']*$scaleFactor);
        $this->_tcpdf->SetTextColor(0,0,0);
    }
    private function _printClipped($text, $x, $y, $w, $h) {
        // Start clipping.
        $this->_tcpdf->StartTransform();
        $this->_tcpdf->Rect($x, $y, $w, $h, 'CNZ');
        $this->_tcpdf->writeHTMLCell($w, $h, $x, $y, $text);
        $this->_tcpdf->StopTransform();
    }
}

/**
* create plugin class derived from PfPdfPlugin, for using in PrintFormPdf
*/
if(class_exists('PfPdfPlugin')) {

    class Pfpdf_PdfSesBlock extends PfPdfPlugin {

        private $mainObj = null;

        public function __construct($pdfobj, $config=null, $x=FALSE,$y=FALSE,$w=FALSE,$h=FALSE) {
            if ($x!==FALSE) $config['posX'] = $x;
            if ($y!==FALSE) $config['posy'] = $y;
            if ($w!==FALSE) $config['width'] = $w;
            if ($h!==FALSE) $config['height'] = $h;
            $this->mainObj = new PdfSesBlock($pdfobj,$config,$x,$y,$w,$h);
        }

        public function Render($data) {
            # $this->mainObj->render($data);
            $result = $this->mainObj->Render($data);
            $this->_error_message = $this->mainObj->getErrorMessage();
            $this->mainObj = null;
            return $result;
        }
    }
}
