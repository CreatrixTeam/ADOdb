<?php
/*
@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim. All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Latest version is available at http://adodb.sourceforge.net

  Portable version of oci8 driver, to make it more similar to other database drivers.
  The main differences are

   1. that the OCI_ASSOC names are in lowercase instead of uppercase.
   2. bind variables are mapped using ? instead of :<bindvar>

   Should some emulation of RecordCount() be implemented?

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

include_once(ADODB_DIR.'/drivers/adodb-oci8.inc.php');

class ADODB_oci8po extends ADODB_oci8 {
	public  $databaseType = 'oci8po';
	public  $dataProvider = 'oci8';
	public  $metaColumnsSQL = "select lower(cname),coltype,width, SCALE, PRECISION, NULLS, DEFAULTVAL from col where tname='%s' order by colno"; //changed by smondino@users.sourceforge. net
	public  $metaTablesSQL = "select lower(table_name),table_type from cat where table_type in ('TABLE','VIEW')";

	public function __construct()
	{
		$this->_hasOCIFetchStatement = true;
		# oci8po does not support adodb extension: adodb_movenext()
	}

	public function Param($name,$type='C')
	{
		return '?';
	}

	public function Prepare($sql,$cursor=false)
	{
		$sqlarr = explode('?',$sql);
		$sql = $sqlarr[0];
		for ($i = 1, $max = sizeof($sqlarr); $i < $max; $i++) {
			$sql .=  ':'.($i-1) . $sqlarr[$i];
		}
		return ADODB_oci8::Prepare($sql,$cursor);
	}

	public function Execute($sql,$inputarr=false)
	{
		return ADOConnection::Execute($sql,$inputarr);
	}

	/**
	 * The optimizations performed by ADODB_oci8::SelectLimit() are not
	 * compatible with the oci8po driver, so we rely on the slower method
	 * from the base class.
	 * We can't properly handle prepared statements either due to preprocessing
	 * of query parameters, so we treat them as regular SQL statements.
	 */
	function SelectLimit($sql, $nrows=-1, $offset=-1, $inputarr=false, $secs2cache=0)
	{
		if(is_array($sql)) {
//			$sql = $sql[0];
		}
		return ADOConnection::SelectLimit($sql, $nrows, $offset, $inputarr, $secs2cache);
	}

	// emulate handling of parameters ? ?, replacing with :bind0 :bind1
	public function _query($sql,$inputarr=false)
	{
		if (is_array($inputarr)) {
			$i = 0;
			if (is_array($sql)) {
				foreach($inputarr as $v) {
					$arr['bind'.$i++] = $v;
				}
			} else {
				// Need to identify if the ? is inside a quoted string, and if
				// so not use it as a bind variable
				preg_match_all('/".*\??"|\'.*\?.*?\'/', $sql, $matches);
				foreach($matches[0] as $qmMatch){
					$qmReplace = str_replace('?', '-QUESTIONMARK-', $qmMatch);
					$sql = str_replace($qmMatch, $qmReplace, $sql);
				}

				// Replace parameters if any were found
				$sqlarr = explode('?',$sql);
				if(count($sqlarr) > 1) {
					$sql = $sqlarr[0];

					foreach ($inputarr as $k => $v) {
						$sql .= ":$k" . $sqlarr[++$i];
					}
				}

				$sql = str_replace('-QUESTIONMARK-', '?', $sql);
			}
		}
		return ADODB_oci8::_query($sql,$inputarr);
	}
}

/*--------------------------------------------------------------------------------------
		 Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordset_oci8po extends ADORecordset_oci8 {

	public  $databaseType = 'oci8po';

	protected function _FetchField($fieldOffset = -1)
	{
		$fld = new ADOFieldObject;
		$fieldOffset += 1;
		$fld->name = OCIcolumnname($this->_queryID, $fieldOffset);
		$fld->type = OCIcolumntype($this->_queryID, $fieldOffset);
		$fld->max_length = OCIcolumnsize($this->_queryID, $fieldOffset);

		if(($fld->name === false) && ($fld->type === false) &&
				($fld->max_length === false))
			{return false;}

		if ($fld->type == 'NUMBER') {
			$sc = OCIColumnScale($this->_queryID, $fieldOffset);
			if ($sc == 0) {
				$fld->type = 'INT';
			}
		}
		return $fld;
	}

	// 10% speedup to move MoveNext to child class
	protected function _MoveNext()
	{
		$this->bind = false;
		$ret = @oci_fetch_array($this->_queryID,$this->oci8_getDriverFetchAndOthersMode());
		if($ret !== false) {
		global $ADODB_ANSI_PADDING_OFF;
			$this->fields = $ret;
			$this->_currentRow++;

			if (!empty($ADODB_ANSI_PADDING_OFF)) {
				foreach($this->fields as $k => $v) {
					if (is_string($v)) $this->fields[$k] = rtrim($v);
				}
			}
			return true;
		}
		if (!$this->EOF) {
			$this->EOF = true;
			$this->_currentRow++;
		}
		return false;
	}

	/* Optimize SelectLimit() by using OCIFetch() instead of OCIFetchInto() */
	protected function _GetArrayLimit($nrows,$offset=-1)
	{
		if ($offset <= 0) {
			$arr = $this->GetArray($nrows);
			return $arr;
		}
		for ($i=1; $i < $offset; $i++)
			if (!@OCIFetch($this->_queryID)) {
				$arr = array();
				return $arr;
			}
		$this->bind = false;
		$ret = @oci_fetch_array($this->_queryID,$this->oci8_getDriverFetchAndOthersMode());
		if ($ret === false) {
			$arr = array();
			return $arr;
		}
		$this->fields = $ret;
		$results = array();
		$cnt = 0;
		while (!$this->EOF && $nrows != $cnt) {
			$results[$cnt++] = $this->fields;
			$this->MoveNext();
		}

		return $results;
	}

	protected function _fetch()
	{
		global $ADODB_ANSI_PADDING_OFF;

		$this->bind = false;
		$ret = @oci_fetch_array($this->_queryID,$this->oci8_getDriverFetchAndOthersMode());
		if ($ret) {
			$this->fields = $ret;

			if (!empty($ADODB_ANSI_PADDING_OFF)) {
				foreach($this->fields as $k => $v) {
					if (is_string($v)) $this->fields[$k] = rtrim($v);
				}
			}
		}
		return $ret !== false;
	}

}
