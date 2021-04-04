<?php


/*
@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
  Set tabs to 8.

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

include_once(ADODB_DIR."/drivers/adodb-pdo.inc.php");

class ADODB_pdo_oci extends ADODB_pdo_base {

	public  $databaseType = "pdo_oci";
	protected  $dsnType = 'oci';
	public  $NLS_DATE_FORMAT = 'YYYY-MM-DD';  // To include time, use 'RRRR-MM-DD HH24:MI:SS'
	public  $random = "abs(mod(DBMS_RANDOM.RANDOM,10000001)/10000000)";
	public  $metaTablesSQL = "select table_name,table_type from cat where table_type in ('TABLE','VIEW')";
	public  $metaColumnsSQL = "select cname,coltype,width, SCALE, PRECISION, NULLS, DEFAULTVAL from col where tname='%s' order by colno";
	public  $metaColumnsSQL2 = "select column_name,data_type,data_length, data_scale, data_precision,
    case when nullable = 'Y' then 'NULL'
    else 'NOT NULL' end as nulls,
    data_default from all_tab_cols
  where owner='%s' and table_name='%s' order by column_id"; // when there is a schema
	protected  $_bindInputArray = true;
	protected  $_nestedSQL = true;
	public  $hasGenID = true;

 	protected  $_initdate = true;

	protected function event_pdoConnectionEstablished()
	{
		if ($this->_initdate) {
			$this->Execute("ALTER SESSION SET NLS_DATE_FORMAT='".$this->NLS_DATE_FORMAT."'");
		}
	}

	public function Time()
	{
		$rs = $this->_Execute("select $this->sysTimeStamp from dual");
		if ($rs && !$rs->EOF) {
			return $this->UnixTimeStamp(reset($rs->fields));
		}

		return false;
	}

	public function MetaTables($ttype=false,$showSchema=false,$mask=false)
	{
		if ($mask) {
			$save = $this->metaTablesSQL;
			$mask = $this->qstr(strtoupper($mask));
			$this->metaTablesSQL .= " AND table_name like $mask";
		}
		$ret = ADOConnection::MetaTables($ttype,$showSchema);

		if ($mask) {
			$this->metaTablesSQL = $save;
		}
		return $ret;
	}

	//VERBATIM COPY FROM "adodb-oci8.inc.php"
	protected function _MetaColumns($pParsedTableName)
	{
		$table = $this->NormaliseIdentifierNameIf((!$pParsedTableName['table']['isToQuote'] ||
				$pParsedTableName['table']['isToNormalize']),
				$pParsedTableName['table']['name']);
		$schema = @$pParsedTableName['schema']['name'];
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		if ($schema){
			$schema = $this->NormaliseIdentifierNameIf((!$pParsedTableName['schema']['isToQuote'] ||
					$pParsedTableName['schema']['isToNormalize']),
					$pParsedTableName['schema']['name']);
			$rs = $this->Execute(sprintf($this->metaColumnsSQL2, $schema, $table));
		}
		else {
			$rs = $this->Execute(sprintf($this->metaColumnsSQL,$table));
		}

		$this->SetFetchMode2($savem);

		if (!$rs) {
			return false;
		}
		$retarr = array();
		while (!$rs->EOF) { //print_r($rs->fields);
			$fld = new ADOFieldObject();
	   		$fld->name = $rs->fields[0];
	   		$fld->type = $rs->fields[1];
	   		$fld->max_length = $rs->fields[2];
			$fld->scale = $rs->fields[3];
			if ($rs->fields[1] == 'NUMBER') {
				if ($rs->fields[3] == 0) {
					$fld->type = 'INT';
				}
				$fld->max_length = $rs->fields[4];
			}
		   	$fld->not_null = (strncmp($rs->fields[5], 'NOT',3) === 0);
			$fld->binary = (strpos($fld->type,'BLOB') !== false);
			$fld->default_value = $rs->fields[6];

			if ($this->GetFetchMode() == ADODB_FETCH_NUM) {
				$retarr[] = $fld;
			}
			else {
				$retarr[strtoupper($fld->name)] = $fld;
			}
			$rs->MoveNext();
		}
		$rs->Close();
		if (empty($retarr)) {
			return false;
		}
		return $retarr;
	}

	//VERBATIM COPY FROM "adodb-oci8.inc.php"
	protected function _MetaIndexes ($pParsedTableName, $primary = FALSE, $owner=false)
	{
		$table = $pParsedTableName['table']['name'];

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		// get index details
		$table = strtoupper($table);

		// get Primary index
		$primary_key = '';

		$rs = $this->Execute(sprintf("SELECT * FROM ALL_CONSTRAINTS WHERE UPPER(TABLE_NAME)='%s' AND CONSTRAINT_TYPE='P'",$table));
		if (!is_object($rs)) {
			$this->SetFetchMode2($savem);

			return false;
		}

		if ($row = $rs->FetchRow()) {
			$primary_key = $row[1]; //constraint_name
		}

		if ($primary==TRUE && $primary_key=='') {
			$this->SetFetchMode2($savem);

			return false; //There is no primary key
		}

		$rs = $this->Execute(sprintf("SELECT ALL_INDEXES.INDEX_NAME, ALL_INDEXES.UNIQUENESS, ALL_IND_COLUMNS.COLUMN_POSITION, ALL_IND_COLUMNS.COLUMN_NAME FROM ALL_INDEXES,ALL_IND_COLUMNS WHERE UPPER(ALL_INDEXES.TABLE_NAME)='%s' AND ALL_IND_COLUMNS.INDEX_NAME=ALL_INDEXES.INDEX_NAME",$table));


		if (!is_object($rs)) {
			$this->SetFetchMode2($savem);

			return false;
		}

		$indexes = array ();
		// parse index data into array

		while ($row = $rs->FetchRow()) {
			if ($primary && $row[0] != $primary_key) {
				continue;
			}
			if (!isset($indexes[$row[0]])) {
				$indexes[$row[0]] = array(
					'unique' => ($row[1] == 'UNIQUE'),
					'columns' => array()
				);
			}
			$indexes[$row[0]]['columns'][$row[2] - 1] = $row[3];
		}

		// sort columns by order in the index
		foreach ( array_keys ($indexes) as $index ) {
			ksort ($indexes[$index]['columns']);
		}

		$this->SetFetchMode2($savem);

		return $indexes;
	}
}

class  ADORecordSet_pdo_oci extends ADORecordSet_pdo {

	public  $databaseType = 'pdo_oci';
}
