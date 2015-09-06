<?php

/**
 * Provided by Ned Andre to support sqlsrv library
 */
 
 // security - hide paths
if (!defined('ADODB_DIR')) die();

include_once(ADODB_DIR."/drivers/adodb-pdo.inc.php");

class ADODB_pdo_sqlsrv extends ADODB_pdo
{

	var $databaseType = 'pdo_sqlsrv';
	var $dsnType = 'sqlsrv';
	var $hasTop = 'top';
	var $hasTransactions = true;
	var $_bindInputArray = true;
	var $hasInsertID = true;
	var $fmtTimeStamp = "'Y-m-d H:i:s'";
	var $fmtDate = "'Y-m-d'";
	var $hasGenID = true;

	function BeginTrans()
	{
		$returnval = parent::BeginTrans();
		return $returnval;
	}

	function _MetaColumns($pParsedTableName)
	{
		return false;
	}

	function MetaTables($ttype = false, $showSchema = false, $mask = false)
	{
		return false;
	}

	function SelectLimit($sql, $nrows = -1, $offset = -1, $inputarr = false, $secs2cache = 0)
	{
		$ret = ADOConnection::SelectLimit($sql, $nrows, $offset, $inputarr, $secs2cache);
		return $ret;
	}

	function ServerInfo()
	{
		return ADOConnection::ServerInfo();
	}

	//VERBATIM COPY FROM "adodb-mssqlnative.inc.php"/"adodb-odbc_mssql.inc.php"
	function _MetaIndexes($pParsedTableName,$primary=false, $owner=false)
	{
		$table = (array_key_exists('schema', $pParsedTableName) ? 
				$pParsedTableName['schema']['name'].".".$pParsedTableName['table']['name'] :
				$pParsedTableName['table']['name']);
		$table = $this->qstr($table);

		$sql = "SELECT i.name AS ind_name, C.name AS col_name, USER_NAME(O.uid) AS Owner, c.colid, k.Keyno,
			CASE WHEN I.indid BETWEEN 1 AND 254 AND (I.status & 2048 = 2048 OR I.Status = 16402 AND O.XType = 'V') THEN 1 ELSE 0 END AS IsPK,
			CASE WHEN I.status & 2 = 2 THEN 1 ELSE 0 END AS IsUnique
			FROM dbo.sysobjects o INNER JOIN dbo.sysindexes I ON o.id = i.id
			INNER JOIN dbo.sysindexkeys K ON I.id = K.id AND I.Indid = K.Indid
			INNER JOIN dbo.syscolumns c ON K.id = C.id AND K.colid = C.Colid
			WHERE LEFT(i.name, 8) <> '_WA_Sys_' AND o.status >= 0 AND O.Name LIKE $table
			ORDER BY O.name, I.Name, K.keyno";

		global $ADODB_FETCH_MODE;
		$save = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		if ($this->fetchMode !== FALSE) {
			$savem = $this->SetFetchMode(FALSE);
		}

		$rs = $this->Execute($sql);
		if (isset($savem)) {
			$this->SetFetchMode($savem);
		}
		$ADODB_FETCH_MODE = $save;

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

	var $databaseType = 'pdo_sqlsrv';

	function ADORecordSet_pdo_sqlsrv($id,$mode=false)
	{
		return $this->ADORecordSet_pdo($id,$mode);
	}
}
