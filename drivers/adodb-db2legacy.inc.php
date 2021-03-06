<?php
/**
  @version   v5.21.0-dev  ??-???-2016
  @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
  @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community

  This is a version of the ADODB driver for DB2.  It uses the 'ibm_db2' PECL extension
  for PHP (http://pecl.php.net/package/ibm_db2), which in turn requires DB2 V8.2.2 or
  higher.

  Originally tested with PHP 5.1.1 and Apache 2.0.55 on Windows XP SP2.
  More recently tested with PHP 5.1.2 and Apache 2.0.55 on Windows XP SP2.

  This file was ported from "adodb-odbc.inc.php" by Larry Menard, "larry.menard#rogers.com".
  I ripped out what I believed to be a lot of redundant or obsolete code, but there are
  probably still some remnants of the ODBC support in this file; I'm relying on reviewers
  of this code to point out any other things that can be removed.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

  define("_ADODB_DB2_LAYER", 2 );

/*--------------------------------------------------------------------------------------
--------------------------------------------------------------------------------------*/





class ADODB_db2legacy extends ADOConnection {
	public  $databaseType = "db2legacy";
	public  $fmtDate = "'Y-m-d'";

	public  $sysTime = 'CURRENT TIME';//Note: This variable is not used any where in the entirety of this library except in the db2 driver

	public  $fmtTimeStamp = "'Y-m-d H:i:s'";
	public  $replaceQuote = "''"; // string to use to replace quotes
	public  $dataProvider = "db2";
	public  $hasAffectedRows = true;

	public  $binmode = DB2_BINARY;

	protected  $_bindInputArray = false;
	protected  $_autocommit = true;
	protected  $_haserrorfunctions = true;
	protected  $_lastAffectedRows = 0;
	public  $uCaseTables = true; // for meta* functions, uppercase table names
	public  $hasInsertID = true;
	public $hasGenID    = true;
	public $connectStmt = '';


    protected function _insertid()
    {
        return ADOConnection::GetOne('VALUES IDENTITY_VAL_LOCAL()');
    }

	public function __construct()
	{
		$this->_haserrorfunctions = true;
	}

		// returns true or false
	protected function _connect($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		global $php_errormsg;

		if (!function_exists('db2_connect')) {
			ADOConnection::outp("Warning: The old ODBC based DB2 driver has been renamed 'odbc_db2'. This ADOdb driver calls PHP's native db2 extension which is not installed.");
			return null;
		}
		// This needs to be set before the connect().
		// Replaces the odbc_binmode() call that was in Execute()
		ini_set('ibm_db2.binmode', $this->binmode);

		if ($argDatabasename && empty($argDSN)) {

			if (stripos($argDatabasename,'UID=') && stripos($argDatabasename,'PWD=')) $this->_connectionID = db2_connect($argDatabasename,null,null);
			else $this->_connectionID = db2_connect($argDatabasename,$argUsername,$argPassword);
		} else {
			if ($argDatabasename) $schema = $argDatabasename;
			if (stripos($argDSN,'UID=') && stripos($argDSN,'PWD=')) $this->_connectionID = db2_connect($argDSN,null,null);
			else $this->_connectionID = db2_connect($argDSN,$argUsername,$argPassword);
		}
		if(function_exists('error_clear_last'))
			{error_clear_last();}
		elseif(isset($php_errormsg))
			{$php_errormsg = '';}

		// For db2_connect(), there is an optional 4th arg.  If present, it must be
		// an array of valid options.  So far, we don't use them.

		$this->_errorMsg = @db2_conn_errormsg();
		if (isset($this->connectStmt)) $this->Execute($this->connectStmt);

		if ($this->_connectionID && isset($schema)) $this->Execute("SET SCHEMA=$schema");
		return $this->_connectionID != false;
	}

	// returns true or false
	protected function _pconnect($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		global $php_errormsg;

		if (!function_exists('db2_connect')) return null;

		// This needs to be set before the connect().
		// Replaces the odbc_binmode() call that was in Execute()
		ini_set('ibm_db2.binmode', $this->binmode);

		$this->_errorMsg = '';

		if ($argDatabasename && empty($argDSN)) {

			if (stripos($argDatabasename,'UID=') && stripos($argDatabasename,'PWD=')) $this->_connectionID = db2_pconnect($argDatabasename,null,null);
			else $this->_connectionID = db2_pconnect($argDatabasename,$argUsername,$argPassword);
		} else {
			if ($argDatabasename) $schema = $argDatabasename;
			if (stripos($argDSN,'UID=') && stripos($argDSN,'PWD=')) $this->_connectionID = db2_pconnect($argDSN,null,null);
			else $this->_connectionID = db2_pconnect($argDSN,$argUsername,$argPassword);
		}
		if(function_exists('error_clear_last'))
			{error_clear_last();}
		elseif(isset($php_errormsg))
			{$php_errormsg = '';}

		$this->_errorMsg = @db2_conn_errormsg();
		if ($this->_connectionID && $this->autoRollback) @db2_rollback($this->_connectionID);
		if (isset($this->connectStmt)) $this->Execute($this->connectStmt);

		if ($this->_connectionID && isset($schema)) $this->Execute("SET SCHEMA=$schema");
		return $this->_connectionID != false;
	}

	// format and return date string in database timestamp format
	public function DBTimeStamp($ts, $isfld = false)
	{
		if (empty($ts) && $ts !== 0) return 'null';
		if (is_string($ts)) $ts = ADORecordSet::UnixTimeStamp($ts);
		return 'TO_DATE('.adodb_date($this->fmtTimeStamp,$ts).",'YYYY-MM-DD HH24:MI:SS')";
	}

	public function ServerInfo()
	{
		$vFetchMode = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$row = $this->GetRow("SELECT service_level, fixpack_num FROM TABLE(sysproc.env_get_inst_info())
			as INSTANCEINFO");


		$this->SetFetchMode2($vFetchMode);

		if ($row) {
			$info['version'] = $row[0].':'.$row[1];
			$info['fixpack'] = $row[1];
			$info['description'] = '';
		} else {
			return ADOConnection::ServerInfo();
		}

		return $info;
	}

	public function SelectLimit($sql, $nrows = -1, $offset = -1, $inputArr = false, $secs2cache = 0)
	{
		$nrows = (int) $nrows;
		$offset = (int) $offset;
		if ($offset <= 0) {
		// could also use " OPTIMIZE FOR $nrows ROWS "
			if ($nrows >= 0) $sql .=  " FETCH FIRST $nrows ROWS ONLY ";
			$rs = $this->Execute($sql,$inputArr);
		} else {
			if ($offset > 0 && $nrows < 0);
			else {
				$nrows += $offset;
				$sql .=  " FETCH FIRST $nrows ROWS ONLY ";
			}
			$rs = ADOConnection::SelectLimit($sql,-1,$offset,$inputArr);
		}

		return $rs;
	}

	public function ErrorMsg()
	{
		if ($this->_haserrorfunctions) {
			if ($this->_errorMsg !== false) return $this->_errorMsg;
			if (empty($this->_connectionID)) return @db2_conn_errormsg();
			return @db2_conn_errormsg($this->_connectionID);
		} else return ADOConnection::ErrorMsg();
	}

	public function ErrorNo()
	{

		if ($this->_haserrorfunctions) {
			if ($this->_errorCode !== false) {
				// bug in 4.0.6, error number can be corrupted string (should be 6 digits)
				return (strlen($this->_errorCode)<=2) ? 0 : $this->_errorCode;
			}

			if (empty($this->_connectionID)) $e = @db2_conn_error();
			else $e = @db2_conn_error($this->_connectionID);

			 // bug in 4.0.6, error number can be corrupted string (should be 6 digits)
			 // so we check and patch
			if (strlen($e)<=2) return 0;
			return $e;
		} else return ADOConnection::ErrorNo();
	}



	public function BeginTrans()
	{
		if (!$this->hasTransactions) return false;
		if ($this->transOff) return true;
		$this->transCnt += 1;
		$this->_autocommit = false;
		return db2_autocommit($this->_connectionID,false);
	}

	public function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if (!$ok) return $this->RollbackTrans();
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;
		$ret = db2_commit($this->_connectionID);
		db2_autocommit($this->_connectionID,true);
		return $ret;
	}

	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;
		$ret = db2_rollback($this->_connectionID);
		db2_autocommit($this->_connectionID,true);
		return $ret;
	}

	protected function _MetaPrimaryKeys($pParsedTableName, $owner = false)
	{
		$table = $this->NormaliseIdentifierNameIf((!$pParsedTableName['table']['isToQuote'] ||
				$pParsedTableName['table']['isToNormalize']),
				$pParsedTableName['table']['name']);
		$schema = @$pParsedTableName['schema']['name'];

		if ($this->uCaseTables) {
			$table = strtoupper($table);
			$schema = strtoupper($schema);
		}

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$qid = @db2_primarykeys($this->_connectionID,'',$schema,$table);

		if (!$qid) {
			$this->SetFetchMode2($savem);
			return false;
		}
		$rs = $this->newADORecordSet($qid, $this->GetFetchMode());
		$this->SetFetchMode2($savem);

		if (!$rs) return false;

		$arr = $rs->GetArray();
		$rs->Close();
		$arr2 = array();
		for ($i=0; $i < sizeof($arr); $i++) {
			if ($arr[$i][3]) $arr2[] = $arr[$i][3];
		}
		return $arr2;
	}

	public function MetaForeignKeys($table, $owner = FALSE, $upper = FALSE, $asociative = FALSE )
	{
		$vParsedTableName = $this->_dataDict->ParseTableName($table);
		$table = $this->NormaliseIdentifierNameIf((!$pParsedTableName['table']['isToQuote'] ||
				$pParsedTableName['table']['isToNormalize']),
				$pParsedTableName['table']['name']);
		$schema = @$vParsedTableName['schema']['name'];
		if ($this->uCaseTables) $table = strtoupper($table);

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$qid = @db2_foreign_keys($this->_connectionID,'',$schema,$table);
		if (!$qid) {
			$this->SetFetchMode2($savem);
			return false;
		}
		$rs = $this->newADORecordSet($qid, $this->GetFetchMode());

		$this->SetFetchMode2($savem);
		/*
		$rs->fields indices
		0 PKTABLE_CAT
		1 PKTABLE_SCHEM
		2 PKTABLE_NAME
		3 PKCOLUMN_NAME
		4 FKTABLE_CAT
		5 FKTABLE_SCHEM
		6 FKTABLE_NAME
		7 FKCOLUMN_NAME
		*/
		if (!$rs) return false;

		$foreign_keys = array();
		$table = strtoupper($table);
		$schema = strtoupper($schema);
		while (!$rs->EOF) {
			if (strtoupper(trim($rs->fields[2])) == $table && (!$schema || strtoupper($rs->fields[1]) == $schema)) {
				if (!is_array($foreign_keys[$rs->fields[5].'.'.$rs->fields[6]]))
					$foreign_keys[$rs->fields[5].'.'.$rs->fields[6]] = array();
				$foreign_keys[$rs->fields[5].'.'.$rs->fields[6]][$rs->fields[7]] = $rs->fields[3];
			}
			$rs->MoveNext();
		}

		$rs->Close();
		return $foreign_keys;
	}


	public function MetaTables($ttype = false, $schema = false, $mask = false)
	{
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$qid = db2_tables($this->_connectionID);

		$rs = $this->newADORecordSet($qid, $this->GetFetchMode());

		$this->SetFetchMode2($savem);
		if (!$rs) {
			$false = false;
			return $false;
		}

		$arr = $rs->GetArray();
		$rs->Close();
		$arr2 = array();

		if ($ttype) {
			$isview = strncmp($ttype,'V',1) === 0;
		}
		for ($i=0; $i < sizeof($arr); $i++) {
			if (!$arr[$i][2]) continue;
			$type = $arr[$i][3];
			$owner = $arr[$i][1];
			$schemaval = ($schema) ? $arr[$i][1].'.' : '';
			if ($ttype) {
				if ($isview) {
					if (strncmp($type,'V',1) === 0) $arr2[] = $schemaval.$arr[$i][2];
				} else if (strncmp($owner,'SYS',3) !== 0) $arr2[] = $schemaval.$arr[$i][2];
			} else if (strncmp($owner,'SYS',3) !== 0) $arr2[] = $schemaval.$arr[$i][2];
		}
		return $arr2;
	}

/*
See http://msdn.microsoft.com/library/default.asp?url=/library/en-us/db2/htm/db2datetime_data_type_changes.asp
/ SQL data type codes /
#define	SQL_UNKNOWN_TYPE	0
#define SQL_CHAR			1
#define SQL_NUMERIC		 2
#define SQL_DECIMAL		 3
#define SQL_INTEGER		 4
#define SQL_SMALLINT		5
#define SQL_FLOAT		   6
#define SQL_REAL			7
#define SQL_DOUBLE		  8
#if (DB2VER >= 0x0300)
#define SQL_DATETIME		9
#endif
#define SQL_VARCHAR		12


/ One-parameter shortcuts for date/time data types /
#if (DB2VER >= 0x0300)
#define SQL_TYPE_DATE	  91
#define SQL_TYPE_TIME	  92
#define SQL_TYPE_TIMESTAMP 93

#define SQL_UNICODE                             (-95)
#define SQL_UNICODE_VARCHAR                     (-96)
#define SQL_UNICODE_LONGVARCHAR                 (-97)
*/
	public function DB2Types($t)
	{
		switch ((integer)$t) {
		case 1:
		case 12:
		case 0:
		case -95:
		case -96:
			return 'C';
		case -97:
		case -1: //text
			return 'X';
		case -4: //image
			return 'B';

		case 9:
		case 91:
			return 'D';

		case 10:
		case 11:
		case 92:
		case 93:
			return 'T';

		case 4:
		case 5:
		case -6:
			return 'I';

		case -11: // uniqidentifier
			return 'R';
		case -7: //bit
			return 'L';

		default:
			return 'N'; //TODO: Correct usage of ADODB_DEFAULT_METATYPE. See commit https://github.com/ADOdb/ADOdb/commit/6005cb728243288093ea4c32112d350c138adf30
		}
	}

	protected function _MetaColumns($pParsedTableName)
	{
		$false = false;		
		$table = $this->NormaliseIdentifierNameIf((!$pParsedTableName['table']['isToQuote'] ||
				$pParsedTableName['table']['isToNormalize']),
				$pParsedTableName['table']['name']);
		$schema = @$pParsedTableName['schema']['name'];
		if ($this->uCaseTables) $table = strtoupper($table);

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

        	$colname = "%";
	        $qid = db2_columns($this->_connectionID, "", $schema, $table, $colname);
		if (empty($qid)) {$this->SetFetchMode2($savem); return $false;}

		$rs = $this->newADORecordSet($qid, $this->GetFetchMode());
		

		if (!$rs) {$this->SetFetchMode2($savem); return $false;}
		$rs->db2__fetch();

		$retarr = array();

		/*
		$rs->fields indices
		0 TABLE_QUALIFIER
		1 TABLE_SCHEM
		2 TABLE_NAME
		3 COLUMN_NAME
		4 DATA_TYPE
		5 TYPE_NAME
		6 PRECISION
		7 LENGTH
		8 SCALE
		9 RADIX
		10 NULLABLE
		11 REMARKS
		*/
		while (!$rs->EOF) {
			if (strtoupper(trim($rs->fields[2])) == strtoupper($table) && (!$schema || strtoupper($rs->fields[1]) == strtoupper($schema))) {
				$fld = new ADOFieldObject();
				$fld->name = $rs->fields[3];
				$fld->type = $this->DB2Types($rs->fields[4]);

				// ref: http://msdn.microsoft.com/library/default.asp?url=/archive/en-us/dnaraccgen/html/msdn_odk.asp
				// access uses precision to store length for char/varchar
				if ($fld->type == 'C' or $fld->type == 'X') {
					if ($rs->fields[4] <= -95) // UNICODE
						$fld->max_length = $rs->fields[7]/2;
					else
						$fld->max_length = $rs->fields[7];
				} else
					$fld->max_length = $rs->fields[7];
				$fld->not_null = !empty($rs->fields[10]);
				$fld->scale = $rs->fields[8];
				$fld->primary_key = false;
				$retarr[strtoupper($fld->name)] = $fld;
			} else if (sizeof($retarr)>0)
				break;
			$rs->MoveNext();
		}
		$rs->Close();
		if (empty($retarr)) $retarr = false;

	      $qid = db2_primary_keys($this->_connectionID, "", $schema, $table);
		if (empty($qid)) {$this->SetFetchMode2($savem); return $false;}

		$rs = $this->newADORecordSet($qid, $this->GetFetchMode());

		if(!(!$rs))
		{
			$rs->db2__fetch();

			/*
			$rs->fields indices
			0 TABLE_CAT
			1 TABLE_SCHEM
			2 TABLE_NAME
			3 COLUMN_NAME
			4 KEY_SEQ
			5 PK_NAME
			*/
			$table = strtoupper($table);
			$schema = strtoupper($schema);
			while (!$rs->EOF) {
				if (strtoupper(trim($rs->fields[2])) == $table && (!$schema || strtoupper($rs->fields[1]) == $schema)) {
					$retarr[strtoupper($rs->fields[3])]->primary_key = true;
				} else if (sizeof($retarr)>0)
					break;
				$rs->MoveNext();
			}
			$rs->Close();
		}

		$this->SetFetchMode2($savem)

		if(empty($retarr))
			{return false;}
		elseif($this->GetFetchMode() == ADODB_FETCH_NUM)
		{
			$tRetarr = array();
			
			foreach($retarr as $tKey => $tValue)
				{$tRetarr[] = $tValue;}

			return $tRetarr;
		}
		else
			{return $retarr;}
	}


	public function Prepare($sql)
	{
		if (! $this->_bindInputArray) return $sql; // no binding
		$stmt = db2_prepare($this->_connectionID,$sql);
		if (!$stmt) {
			// we don't know whether db2 driver is parsing prepared stmts, so just return sql
			return $sql;
		}
		return array($sql,$stmt,false);
	}

	/* returns queryID or false */
	protected function _query($sql,$inputarr=false)
	{
		$last_php_error = $this->resetLastError();
		$this->_errorMsg = '';

		if ($inputarr) {
			if (is_array($sql)) {
				$stmtid = $sql[1];
			} else {
				$stmtid = db2_prepare($this->_connectionID,$sql);

				if ($stmtid == false) {
					$this->_errorMsg = $this->getChangedErrorMsg($last_php_error);
					return false;
				}
			}

			if (! db2_execute($stmtid,$inputarr)) {
				if ($this->_haserrorfunctions) {
					$this->_errorMsg = db2_stmt_errormsg();
					$this->_errorCode = db2_stmt_error();
				}
				return false;
			}

		} else if (is_array($sql)) {
			$stmtid = $sql[1];
			if (!db2_execute($stmtid)) {
				if ($this->_haserrorfunctions) {
					$this->_errorMsg = db2_stmt_errormsg();
					$this->_errorCode = db2_stmt_error();
				}
				return false;
			}
		} else
			$stmtid = @db2_exec($this->_connectionID,$sql);

		$this->_lastAffectedRows = 0;
		if ($stmtid) {
			if (@db2_num_fields($stmtid) == 0) {
				$this->_lastAffectedRows = db2_num_rows($stmtid);
				$stmtid = true;
			} else {
				$this->_lastAffectedRows = 0;
			}

			if ($this->_haserrorfunctions) {
				$this->_errorMsg = '';
				$this->_errorCode = 0;
			} else {
				$this->_errorMsg = $this->getChangedErrorMsg($last_php_error);
			}
		} else {
			if ($this->_haserrorfunctions) {
				$this->_errorMsg = db2_stmt_errormsg();
				$this->_errorCode = db2_stmt_error();
			} else {
				$this->_errorMsg = $this->getChangedErrorMsg($last_php_error);
			}
		}
		return $stmtid;
	}

	/*
		Insert a null into the blob field of the table first.
		Then use UpdateBlob to store the blob.

		Usage:

		$conn->Execute('INSERT INTO blobtable (id, blobcol) VALUES (1, null)');
		$conn->UpdateBlob('blobtable','blobcol',$blob,'id=1');
	*/
	public function UpdateBlob($table,$column,$val,$where,$blobtype='BLOB')
	{
		return $this->Execute("UPDATE $table SET $column=? WHERE $where",array($val)) != false;
	}

	// returns true or false
	protected function _close()
	{
		$ret = @db2_close($this->_connectionID);
		$this->_connectionID = false;
		return $ret;
	}

	protected function _affectedrows()
	{
		return $this->_lastAffectedRows;
	}

}

/*--------------------------------------------------------------------------------------
	 Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordSet_db2legacy extends ADORecordSet {

	public  $bind = false;
	public  $databaseType = "db2legacy";
	public  $dataProvider = "db2";

	// returns the field object
	protected function _FetchField($offset = -1)
	{
		$o= new ADOFieldObject();
		$o->name = @db2_field_name($this->_queryID,$offset);
		$o->type = @db2_field_type($this->_queryID,$offset);
		$o->max_length = db2_field_width($this->_queryID,$offset);

		if(($o->name === false) && ($o->type === false) &&
				($o->max_length === false))
			{return false;}

		return $o;
	}


	protected function _initrs()
	{
	global $ADODB_COUNTRECS;
		$this->_numOfRows = ($ADODB_COUNTRECS) ? @db2_num_rows($this->_queryID) : -1;
		$this->_numOfFields = @db2_num_fields($this->_queryID);
		// some silly drivers such as db2 as/400 and intersystems cache return _numOfRows = 0
		if ($this->_numOfRows == 0) $this->_numOfRows = -1;
	}

	protected function _seek($row)
	{
		return false;
	}

	// speed up SelectLimit() by switching to ADODB_FETCH_NUM as ADODB_FETCH_ASSOC is emulated
	protected function _GetArrayLimit($nrows,$offset=-1)
	{
		if ($offset <= 0) {
			$rs = $this->GetArray($nrows);
			return $rs;
		}
		$savem = $this->fetchMode;
		$this->fetchMode = ADODB_FETCH_NUM;
		$this->Move($offset);
		$this->fetchMode = $savem;

		if ($this->fetchMode == ADODB_FETCH_ASSOC) {
			$this->fields = $this->GetEmulatedRowAssoc();
		} else if ($this->fetchMode == ADODB_FETCH_BOTH) {
			$this->fields = array_merge($this->fields,$this->GetEmulatedRowAssoc());
		}

		$results = array();
		$cnt = 0;
		while (!$this->EOF && $nrows != $cnt) {
			$results[$cnt++] = $this->fields;
			$this->MoveNext();
		}

		return $results;
	}


	protected function _MoveNext()
	{
		$this->bind = false;
		if ($this->_numOfRows != 0 && !$this->EOF) {
			$this->_currentRow++;

			$this->fields = @db2_fetch_array($this->_queryID);
			if ($this->fields) {
				if ($this->fetchMode == ADODB_FETCH_ASSOC) {
					$this->fields = $this->GetEmulatedRowAssoc();
				} else if ($this->fetchMode == ADODB_FETCH_BOTH) {
					$this->fields = array_merge($this->fields,$this->GetEmulatedRowAssoc());
				}
				return true;
			}
		}
		$this->fields = false;
		$this->EOF = true;
		return false;
	}

	protected function _fetch()
	{
		$this->bind = false;
		$this->fields = db2_fetch_array($this->_queryID);
		if ($this->fields) {
			if ($this->fetchMode == ADODB_FETCH_ASSOC) {
				$this->fields = $this->GetEmulatedRowAssoc();
			} else if ($this->fetchMode == ADODB_FETCH_BOTH) {
				$this->fields = array_merge($this->fields,$this->GetEmulatedRowAssoc());
			}
			return true;
		}
		$this->fields = false;
		return false;
	}
	
	public function db2__fetch()
		{return $this->_callFetch();}

	protected function _close()
	{
		return @db2_free_result($this->_queryID);
	}

}
