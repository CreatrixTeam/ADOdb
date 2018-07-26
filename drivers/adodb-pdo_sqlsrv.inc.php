<?php

/**
 * Provided by Ned Andre to support sqlsrv library
 */
 
 // security - hide paths
if (!defined('ADODB_DIR')) die();

include_once(ADODB_DIR."/drivers/adodb-pdo.inc.php");

class ADODB_pdo_sqlsrv extends ADODB_pdo
{

	public  $databaseType = 'pdo_sqlsrv';
	public  $dsnType = 'sqlsrv';
	public  $hasTop = 'top';
	public  $hasTransactions = true;
	protected  $_bindInputArray = true;
	public  $hasInsertID = true;
	public  $fmtTimeStamp = "'Y-m-d H:i:s'";
	public  $fmtDate = "'Y-m-d'";
	public  $hasGenID = true;
	public  $metaTablesSQL="select name,case when type='U' then 'T' else 'V' end from sysobjects where (type='U' or type='V') and (name not in ('sysallocations','syscolumns','syscomments','sysdepends','sysfilegroups','sysfiles','sysfiles1','sysforeignkeys','sysfulltextcatalogs','sysindexes','sysindexkeys','sysmembers','sysobjects','syspermissions','sysprotects','sysreferences','systypes','sysusers','sysalternates','sysconstraints','syssegments','REFERENTIAL_CONSTRAINTS','CHECK_CONSTRAINTS','CONSTRAINT_TABLE_USAGE','CONSTRAINT_COLUMN_USAGE','VIEWS','VIEW_TABLE_USAGE','VIEW_COLUMN_USAGE','SCHEMATA','TABLES','TABLE_CONSTRAINTS','TABLE_PRIVILEGES','COLUMNS','COLUMN_DOMAIN_USAGE','COLUMN_PRIVILEGES','DOMAINS','DOMAIN_CONSTRAINTS','KEY_COLUMN_USAGE','dtproperties'))";
	public  $metaColumnsSQL =
		"select c.name,
		t.name as type,
		c.length,
		c.xprec as precision,
		c.xscale as scale,
		c.isnullable as nullable,
		c.cdefault as default_value,
		c.xtype,
		t.length as type_length,
		sc.is_identity
		from syscolumns c
		join systypes t on t.xusertype=c.xusertype
		join sysobjects o on o.id=c.id
		join sys.tables st on st.name=o.name
		join sys.columns sc on sc.object_id = st.object_id and sc.name=c.name
		where o.name='%s'";
	
	public function BeginTrans()
	{
		$returnval = parent::BeginTrans();
		return $returnval;
	}

	//(almost)VERBATIM COPY FROM "adodb-mssqlnative.inc.php"/"adodb-odbc_mssql.inc.php"
	protected function _MetaColumns($pParsedTableName){

		/*
		* A simple caching mechanism, to be replaced in ADOdb V6
		*/
		//static $cached_columns = array();
		$table = $this->BuildTableName($this->NormaliseIdentifierNameIf(
				$pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']), @$pParsedTableName['schema']['name']);
		$schema = (!empty($pParsedTableName['schema']['name']) ? 
				$pParsedTableName['schema']['name'] : false);
		//if ($this->cachedSchemaFlush)
			//$cached_columns = array();

		//if (array_key_exists($table,$cached_columns)){
			//return $cached_columns[$table];
		//}
		

		//if (!$this->mssql_version)
			//$this->ServerVersion();

		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		if ($schema) {
			$dbName = $this->database;
			$this->SelectDB($schema);
		}

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$rs = $this->Execute(sprintf($this->metaColumnsSQL,$table));

		if ($schema) {
			$this->SelectDB($dbName);
		}

		$this->SetFetchMode2($savem);

		if (!is_object($rs)) {
			$false = false;
			return $false;
		}

		$retarr = array();
		while (!$rs->EOF){

			$fld = new ADOFieldObject();
			if (array_key_exists(0,$rs->fields)) {
				$fld->name          = $rs->fields[0];
				$fld->type          = $rs->fields[1];
				$fld->max_length    = $rs->fields[2];
				$fld->precision     = $rs->fields[3];
				$fld->scale     	= $rs->fields[4];
				$fld->not_null      =!$rs->fields[5];
				$fld->has_default   = $rs->fields[6];
				$fld->xtype         = $rs->fields[7];
				$fld->type_length   = $rs->fields[8];
				$fld->auto_increment= $rs->fields[9];
			} else {
				$fld->name          = $rs->fields['name'];
				$fld->type          = $rs->fields['type'];
				$fld->max_length    = $rs->fields['length'];
				$fld->precision     = $rs->fields['precision'];
				$fld->scale     	= $rs->fields['scale'];
				$fld->not_null      =!$rs->fields['nullable'];
				$fld->has_default   = $rs->fields['default_value'];
				$fld->xtype         = $rs->fields['xtype'];
				$fld->type_length   = $rs->fields['type_length'];
				$fld->auto_increment= $rs->fields['is_identity'];
			}

			if ($this->GetFetchMode() == ADODB_FETCH_NUM)
				$retarr[] = $fld;
			else
				$retarr[strtoupper($fld->name)] = $fld;

			$rs->MoveNext();

		}
		$rs->Close();
		/*$table = $this->BuildTableName($this->NormaliseIdentifierNameIf(
				$pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']), @$pParsedTableName['schema']['name']);
		$cached_columns[$table] = $retarr;*/
		
		return $retarr;
	}

	//VERBATIM COPY FROM "adodb-mssqlnative.inc.php"/"adodb-odbc_mssql.inc.php"/adodb-mssql.inc.php
	public function MetaTables($ttype=false,$showSchema=false,$mask=false)
	{
		if ($mask) {//$this->debug=1;
			$save = $this->metaTablesSQL;
			$mask = $this->qstr($mask);
			$this->metaTablesSQL .= " AND name like $mask";
		}
		$ret = ADOConnection::MetaTables($ttype,$showSchema);

		if ($mask) {
			$this->metaTablesSQL = $save;
		}
		return $ret;
	}

	public function SelectLimit($sql, $nrows = -1, $offset = -1, $inputarr = false, $secs2cache = 0)
	{
		$ret = ADOConnection::SelectLimit($sql, $nrows, $offset, $inputarr, $secs2cache);
		return $ret;
	}

	public function ServerInfo()
	{
		return ADOConnection::ServerInfo();
	}


	//VERBATIM COPY FROM "adodb-mssqlnative.inc.php"/"adodb-odbc_mssql.inc.php"
	protected function _MetaIndexes($pParsedTableName,$primary=false, $owner=false)
	{
		$table = $this->BuildTableName($this->NormaliseIdentifierNameIf(
				$pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']), @$pParsedTableName['schema']['name']);
		$table = $this->qstr($table);

		$sql = "SELECT i.name AS ind_name, C.name AS col_name, USER_NAME(O.uid) AS Owner, c.colid, k.Keyno,
			CASE WHEN I.indid BETWEEN 1 AND 254 AND (I.status & 2048 = 2048 OR I.Status = 16402 AND O.XType = 'V') THEN 1 ELSE 0 END AS IsPK,
			CASE WHEN I.status & 2 = 2 THEN 1 ELSE 0 END AS IsUnique
			FROM dbo.sysobjects o INNER JOIN dbo.sysindexes I ON o.id = i.id
			INNER JOIN dbo.sysindexkeys K ON I.id = K.id AND I.Indid = K.Indid
			INNER JOIN dbo.syscolumns c ON K.id = C.id AND K.colid = C.Colid
			WHERE LEFT(i.name, 8) <> '_WA_Sys_' AND o.status >= 0 AND O.Name LIKE $table
			ORDER BY O.name, I.Name, K.keyno";

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$rs = $this->Execute($sql);

		$this->SetFetchMode2($savem);

		if (!is_object($rs)) {
			return FALSE;
		}

		$indexes = array();
		while ($row = $rs->FetchRow()) {
			if (!$primary && $row[5]) continue;

			$indexes[$row[0]]['unique'] = $row[6];
			$indexes[$row[0]]['columns'][] = $row[1];
		}
		return $indexes;
	}
}

class  ADORecordSet_pdo_sqlsrv extends ADORecordSet_pdo {

	public  $databaseType = 'pdo_sqlsrv';

	protected function _FetchField($fieldOffset = -1)
	{
		// Default behavior allows passing in of -1 offset, which crashes the method
		if ($fieldOffset === -1) {
			$fieldOffset = 0;
		}

		$o = new ADOFieldObject();
		$arr = @$this->_queryID->getColumnMeta($fieldOffset);
		if (!$arr) {
			return false;
		}
		//adodb_pr($arr);
		$o->name = $arr['name'];
		if (isset($arr['sqlsrv:decl_type']) && $arr['sqlsrv:decl_type'] <> "null") 
		{
		    $o->type = $arr['sqlsrv:decl_type'];
		}
		elseif (isset($arr['native_type']) && $arr['native_type'] <> "null") 
		{
		    $o->type = $arr['native_type'];
		}
		else 
		{
		     $o->type = adodb_pdo_type($arr['pdo_type']);
		}
		
		$o->max_length = $arr['len'];
		$o->precision = $arr['precision'];

		return $o;
	}
	
	function SetTransactionMode( $transaction_mode )
	{
		$this->_transmode  = $transaction_mode;
		if (empty($transaction_mode)) {
			$this->_connectionID->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
			return;
		}
		if (!stristr($transaction_mode,'isolation')) $transaction_mode = 'ISOLATION LEVEL '.$transaction_mode;
		$this->_connectionID->query("SET TRANSACTION ".$transaction_mode);
	}
}
