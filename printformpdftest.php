<?php
/**
* Class for creating test PDF forms , automatically populated with generated data
* @Author Alexander Selifonov, <alex [at] selifan {dot} ru>
* @version 1.16.35
* modified : 2025-02-03
* tests for New tcpdi PDF parser
*/
require_once(__DIR__ . '/printformpdf.php');
class PrintFormPdfTest extends PrintFormPdf {

	private $_default_grid_rows = 3;
    private $_longValues = 0; # make koeff if "longer" values needed for "width"-contained fields
    protected $_tv = []; // predefined test values

	public function setGridSize($rows) {
		if ($rows >= 0) $this->_default_grid_rows = intval($rows);
	}

    public function setTestValues($vals) {
        $this->_tv = $vals;
    }
    /**
    * Genereates test data for printing in $this->_data
    * All non-existed fields will be filled
    */
	public function generateData($initdata = false) {
		$baseData = (is_array($initdata) ? $initdata : array('__dummy__'=>"x"));
        $data = $initdata; # []
        /*
		if (count($this->_apFields))
			WriteDebugInfo('_apFields list ', $this->_apFields);
		if (count($this->_datagrids))
			WriteDebugInfo('_datagrids list ', $this->_datagrids);
        */

		foreach($this->_pagedefs as $no=>$pagedef) {

			$skipFields = array();
			$datasource = '';
			$onerow = array();
			if (!empty($pagedef['gridpage']['datasource'])) {

				$grDef = $pagedef['gridpage'];
				$datasource = $grDef['datasource'];
                $data[$datasource] = [];

                $maxRows = isset($pagedef['gridpage']['rows']) ? $pagedef['gridpage']['rows'] : 1;
                # how many "grid rows" on one page

                $gridFields = [];
				foreach($grDef['fields'] as	$fno=>$fname) {
                    # writeDebugInfo("seek datasource field [$fno] -> $fname");
                    $skipFields[$fname] = true;
                    $gridFields[] = $fname;
                }

                for($itemNo=1; $itemNo<= min($maxRows-2,1); $itemNo++) {
                    # 2 last rows empty, to check "fillempty" feature
                    $rowData = [];

                    foreach($gridFields as $fname) {
					    $fdef = $this->_findFieldDef($fname, $pagedef['fields']);
                        if (!empty($fdef['src'])) {
                            $this->setFieldAttribs($fname, array('src' =>$fdef['src']));
                        }
                        else $rowData[$fname] = $this->_makeValue($fdef, $itemNo);
                    }
                    $data[$datasource][] = $rowData;
				}
                # file_put_contents("__{$datasource}.log", print_r($data[$datasource],1));
			}
			elseif (!empty($pagedef['datagrids']) && count($pagedef['datagrids'])) {

                foreach($pagedef['datagrids'] as $gridno=>$gridid) { # only one datagrid supported!
                    # writeDebugInfo("_datagrids[$gridid]: ", $this->_datagrids[$gridid]['fields']);
					if (!isset($this->_datagrids[$gridid])) continue;
					$onerow = [];
					foreach($this->_datagrids[$gridid]['fields'] as $fno => $fldDef) {
                        $fname = is_array($fldDef) ? $fldDef['name'] : $fldDef;
                        if(!is_string($fname))
                            die("wrong field def, cannot get name:<pre>".print_r($fldDef,1).'</pre>');
                        $skipFields[$fname] = true;
                        # writeDebugInfo("field to skip: $fname");
						$fdef = is_array($fldDef) ? $fldDef : $this->_findFieldDef($fname, $pagedef['fields']);
						$onerow[$fname] = $this->_makeValue($fdef);
					}
					$datasource = $this->_datagrids[$gridid]['datasource'];
                }
			}

			if (!empty($datasource) && count($onerow)) {
					$data[$datasource] = array();
					for($kk=0; $kk<$this->_default_grid_rows; $kk++) {
						$data[$datasource][$kk] = $onerow;
						foreach($data[$datasource][$kk] as $no=>&$column) {
							$column .= "-". ($kk+1);
						}
					}
			}
			# ordinary fields not included in datagrids...

			foreach($pagedef['fields'] as $fno => &$fldef) {
				$fname = $fldef['name'];
                if(!empty($fldef['plugintype'])) {
                    # writeDebugInfo("plugin filed - dont fill it!");
                    continue;
                }
                elseif($fname == 'stamp') { # темстовая картинка штампа
                    $this->setFieldAttribs($fname, array('src' =>'sandbox/stamp.png'));
                }
                elseif(isset($baseData[$fname]['src'])) {
                    # set image path-filename from testvalues
                    # $fldef['src'] = $baseData[$fname]['src'];
                    $this->setFieldAttribs($fname, array('src' =>$baseData[$fname]['src']));
                }
                $ingrid = !empty($fldef['ingrid']);
                $isDig = isdigit(substr($fname,-1));
                $inSkip = in_array($fname,$skipFields, TRUE);
                # writeDebugInfo(">> working normal field: $fname ingrid=[$ingrid], isDig=[$isDig], inSkip=[$inSkip]");
                # writeDebugInfo("field fill: $fname ; [$ingrid] [$isDig]");
				if ($inSkip) continue;
                if ($ingrid && !$isDig) continue; # field is a grid base
                if(isset($baseData[$fname]))
                    $data[$fname] = $baseData[$fname];
                else
				    $data[$fname] = $this->_makeValue($fldef);

			}
		}
        foreach($this->_apFields as $id => $fdef) {
            $fname = $fdef['name'];
            $data[$fname] =  $this->_makeValue($fdef);
        }
        if(isset($this->_data[0]))
            $this->_data[0] = array_merge($this->_data[0], $data);
        else
		    $this->addData($data);

        # echo 'final printing data <pre>' . print_r($this->_data,1). '</pre>'; exit;
		return $this;
	}
	private function _findFieldDef($fldname, $fdlist) {
        if (isset($this->_tv[$fldname])) return $this->_tv[$fldname];
        foreach($fdlist as $no => $fdef) {
			if ($fdef['name'] === $fldname) return $fdef;
		}
		return false;
	}

	private function _makeValue($fdef) {

		$fname = $ret = $fdef['name'];
        if (isset($this->_tv[$fname])) {
            if (is_array($this->_tv[$fname]) && isset($this->_tv[$fname]['src'])) {
                $this->setFieldAttribs('stamp', [ 'src'=>$this->_tv[$fname]['src'] ]);
                return 1;
            }
            return $this->_tv[$fname];
        }

        $fltype = strtolower($fdef['type']);
		if ($fltype === 'checkbox') $ret = 1;
		elseif ($fltype === 'date') $ret = date('d.m.Y');
		else {
			$ret = ucfirst($fname); // strtoupper($fname);
			if ($fdef['width']>0 && $this->_longValues) $ret = str_pad($ret, intval($fdef['width'] * $this->_longValues),' A');
		}
		return $ret;
	}
}