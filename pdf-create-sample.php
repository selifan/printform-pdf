<?PHP
/**
* @name pdf-create-sample.phpprintPdf.php - test PDF generation with class CPrintFormPdf
* **/
require_once('class.Sudoku.php');
require_once('printform-pdf.php');

$options = array(
   'output' => 'F'
  ,'outname' => 'testing.pdf'
);


$ptype = isset($_GET['t']) ? $_GET['t'] : '';

if($ptype) $pdf = new CPrintFormPdf( $options );

if($ptype=='piano') {
    # Printing piano roll page:
    $piano = array('measures'=>2, 'merged_staves'=>2, 'accolade'=>1); # , 'color'=>array(255,10,10));
    $pdf->AddPageMusicStaff('Music staff sheet', $piano);
}
elseif($ptype=='line') {  # Ћист в клеточку
    $pdf->AddPageLined();
}
elseif($ptype=='lineh') {
    $pdf->AddPageLined('',array('step_x'=>0, 'step_y'=>5));
}
elseif($ptype=='linev') {
    $pdf->AddPageLined('',array('step_x'=>5, 'step_y'=>0)); # Ћист вертик.разлиновка
}
elseif($ptype=='mm') {
    $options = array('color'=>array(180,180,250), 'mm'=>1);
    $pdf->AddPageLined('',$options); # Ћист миллиметровки
}
elseif($ptype=='sudoku') {
    # Printing SUDOKU puzzle page
    $sudokuOptions = array(
        'color_grid' => array(80,80,255)
       ,'color_cell' => array(20,100,100)
    );
    $difficulty = 4;
    $pdf->AddPageSudoku('Sudoku page, difficulty: '.$difficulty,$difficulty, $sudokuOptions);
}
elseif($ptype=='g') {
#   $pdf = new CPrintFormPdf(array('configfile'=>'printPdf.xml','outname'=>'grid-data.pdf'));
   $pdf->LoadConfig('printPdf.xml');

   $data = array(
         'policy_serno'=>date('4444 808080')
        ,'policydate'=>date('d.m.Y')
        ,'draft_mark' => 'TESTING'
   );
   $data['grid:drv_list'] = array(
        array('drv_no'=>'1','drv_name'=>'Driver First','drv_birth'=>'21.02.1961','drv_sex'=>'M','drv_license'=>'7711 506001')
       ,array('drv_no'=>'2','drv_name'=>'Driver Second','drv_birth'=>'22.02.1962','drv_sex'=>'F','drv_license'=>'7711 506002')
   );
   $pdf->AddData($data);
#   $pdf->AddDataGridRow('drv_list', array('drv_no'=>'1','drv_name'=>'Driver First','drv_birth'=>'21.02.1961','drv_sex'=>'M','drv_license'=>'7711 506001'));
#   $pdf->AddDataGridRow('drv_list', array('drv_no'=>'2','drv_name'=>'Driver Second','drv_birth'=>'22.02.1962','drv_sex'=>'F','drv_license'=>'7711 506002'));
}
elseif($ptype=='vc') { # visit card
   $pdf->LoadConfig('pdf-vcard.xml');
   $data = array(
         'lastname'=>'Shumakher'
        ,'firstname'=>'Mickhael'
        ,'patronimname'=>'Ivanovitch'
        ,'duty'=>'Super driver of all times'
        ,'phones'=>'+2(555)111-2200, 222-4455'
   );
   $pdf->AddData($data);

}
if(!empty($_GET['t'])) $pdf->Render();
else {
    $self = $_SERVER['PHP_SELF'];
    echo '<html><head><title>Examples of using Printform-pdf</title></head><body><h4>Examples of using Printform-pdf</h4>';
    echo "<a href=\"$self?t=piano\" target='_blank'>Sample: Print music staff sheet</a><br>";
    echo "<a href=\"$self?t=line\" target='_blank'>Sample: 5mm-cell sheet</a><br>";
    echo "<a href=\"$self?t=lineh\" target='_blank'>Sample: Horizontal lined sheet</a><br>";
    echo "<a href=\"$self?t=linev\" target='_blank'>Sample: Vertical lined sheet</a><br>";
    echo "<a href=\"$self?t=mm\" target='_blank'>Sample: 'Millimeter' grid page</a><br>";
    echo "<a href=\"$self?t=sudoku\" target='_blank'>Sample: Sudoku puzzle</a><br>";
    echo "<a href=\"$self?t=vc\" target='_blank'>Sample: Business card printing</a><br>";
    echo '</body></html>';

}

# провер€ю как работает блокировка repeat-блоков
function CheckMyRepeat($rno) {
    return ($rno<=6);
}