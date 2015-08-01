<?php

/*
 V5.20dev  ??-???-2014  (c) 2000-2014 John Lim (jlim#natsoft.com). All rights reserved.
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence. See License.txt.
  Set tabs to 4 for best viewing.

  Latest version is available at http://adodb.sourceforge.net

  Thanks Diogo Toscano (diogo#scriptcase.net) for the code.
	And also Sid Dunayer [sdunayer#interserv.com] for extensive fixes.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

include_once(ADODB_DIR."/drivers/adodb-pdo.inc.php");

class ADODB_pdo_sqlite extends ADODB_pdo {
	var $databaseType    = "pdo_sqlite";
	var $dsnType 		 = 'sqlite'; 
	var $metaTablesSQL   = "SELECT name FROM sqlite_master WHERE type='table'";
	var $sysDate         = 'current_date';
	var $sysTimeStamp    = 'current_timestamp';
	var $nameQuote       = '`';
	var $replaceQuote    = "''";
	var $hasGenID        = true;
	var $_genIDSQL       = "UPDATE %s SET id=id+1 WHERE id=%s";
	var $_genSeqCountSQL = 'SELECT COUNT(*) FROM %s';
	var $random='abs(random())';
	var $_bindInputArray = true;
	var $hasTransactions = false; // // should be set to false because of PDO SQLite driver not supporting changing autocommit mode
	var $hasInsertID = true;

	function ServerInfo()
	{
		@($ver = array_pop($this->GetCol("SELECT sqlite_version()")));
		@($enc = array_pop($this->GetCol("PRAGMA encoding")));

		$arr['version']     = $ver;
		$arr['description'] = 'SQLite ';
		$arr['encoding']    = $enc;

		return $arr;
	}

	function SelectLimit($sql,$nrows=-1,$offset=-1,$inputarr=false,$secs2cache=0)
	{
		$offsetStr = ($offset >= 0) ? " OFFSET $offset" : '';
		$limitStr  = ($nrows >= 0)  ? " LIMIT $nrows" : ($offset >= 0 ? ' LIMIT 999999999' : '');
	  	if ($secs2cache)
	   		$rs = $this->CacheExecute($secs2cache,$sql."$limitStr$offsetStr",$inputarr);
	  	else
	   		$rs = $this->Execute($sql."$limitStr$offsetStr",$inputarr);

		return $rs;
	}

	function GenID($seq='adodbseq',$start=1)
	{
		// if you have to modify the parameter below, your database is overloaded,
		// or you need to implement generation of id's yourself!
		$MAXLOOPS = 100;
		while (--$MAXLOOPS>=0) {
			@($num = array_pop($this->GetCol("SELECT id FROM {$seq}")));
			if ($num === false || !is_numeric($num)) {
				$tSQL = $this->_dataDict->CreateSequenceSQL($seq,$start);
				@$this->Execute($tSQL[0]);
				$num = '0';
				$cnt = $this->GetOne(sprintf($this->_genSeqCountSQL,$seq));
				if (!$cnt) {
					$ok = $this->Execute($tSQL[1]);
				}
				if (!$ok) return false;
			}
			$this->Execute(sprintf($this->_genIDSQL,$seq,$num));

			if ($this->affected_rows() > 0) {
                	        $num += 1;
                		$this->genID = intval($num);
                		return intval($num);
			}
		}
		if ($fn = $this->raiseErrorFn) {
			$fn($this->databaseType,'GENID',-32000,"Unable to generate unique id after $MAXLOOPS attempts",$seq,$num);
		}
		return false;
	}

	function SetTransactionMode($transaction_mode)
	{
		$this->_transmode = strtoupper($transaction_mode);
	}

	function BeginTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt += 1;
		$this->_autocommit = false;
		return $this->Execute("BEGIN {$this->_transmode}");
	}

	function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if (!$ok) return $this->RollbackTrans();
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;

		$ret = $this->Execute('COMMIT');
		return $ret;
	}

	function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;

		$ret = $this->Execute('ROLLBACK');
		return $ret;
	}


    // mark newnham
	function MetaColumns($pTableName,$pIsToNormalize=null)
	{
	  global $ADODB_FETCH_MODE;

	  $false = false;
	  $save = $ADODB_FETCH_MODE;
	  $ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;
	  $vParsedTableName = $this->ParseTableName($pTableName, $pIsToNormalize);
	  $tab = (array_key_exists('schema', $vParsedTableName) ? 
				$vParsedTableName['schema']['name'].".".$vParsedTableName['table']['name'] :
				$vParsedTableName['table']['name']);
	  if ($this->fetchMode !== false) $savem = $this->SetFetchMode(false);
	  $rs = $this->Execute("PRAGMA table_info('$tab')");
	  if (isset($savem)) $this->SetFetchMode($savem);
	  if (!$rs) {
	    $ADODB_FETCH_MODE = $save;
	    return $false;
	  }
	  $arr = array();
	  while ($r = $rs->FetchRow()) {
	    $type = explode('(',$r['type']);
	    $size = '';
	    if (sizeof($type)==2)
	    $size = trim($type[1],')');
	    $fn = strtoupper($r['name']);
	    $fld = new ADOFieldObject;
	    $fld->name = $r['name'];
	    $fld->type = $type[0];
	    $fld->max_length = $size;
	    $fld->not_null = $r['notnull'];
	    $fld->primary_key = $r['pk'];
	    $fld->default_value = $r['dflt_value'];
	    $fld->scale = 0;
	    if ($save == ADODB_FETCH_NUM) $arr[] = $fld;
	    else $arr[strtoupper($fld->name)] = $fld;
	  }
	  $rs->Close();
	  $ADODB_FETCH_MODE = $save;
	  return $arr;
	}

	function MetaTables($ttype=false,$showSchema=false,$mask=false)
	{

		if ($mask) {
			$save = $this->metaTablesSQL;
			$mask = $this->qstr(strtoupper($mask));
			$this->metaTablesSQL .= " AND name LIKE $mask";
		}

		$ret = $this->GetCol($this->metaTablesSQL);

		if ($mask) {
			$this->metaTablesSQL = $save;
		}
		return $ret;
   }

    //Verbatim copy from "adodb-sqlite.inc.php"/"adodb-sqlite3.inc.php"
	function MetaIndexes($pTableName, $primary = FALSE, $owner=false, $owner = false)
	{
		$false = false;
		// save old fetch mode
		global $ADODB_FETCH_MODE;
		$save = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		$vParsedTableName = $this->ParseTableName($pTableName);
		$table = (array_key_exists('schema', $vParsedTableName) ? 
				$vParsedTableName['schema']['name'].".".$vParsedTableName['table']['name'] :
				$vParsedTableName['table']['name']);
		if ($this->fetchMode !== FALSE) {
			$savem = $this->SetFetchMode(FALSE);
		}
		$SQL=sprintf("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='%s'", strtolower($table));
		$rs = $this->Execute($SQL);
		if (!is_object($rs)) {
			if (isset($savem)) {
				$this->SetFetchMode($savem);
			}
			$ADODB_FETCH_MODE = $save;
			return $false;
		}

		$indexes = array ();
		while ($row = $rs->FetchRow()) {
			if ($primary && preg_match("/primary/i",$row[1]) == 0) {
				continue;
			}
			//IGNORE AUTOMATICALLY CREATED INDICES
			if (empty($row[1]))
				{continue;}
			if (!isset($indexes[$row[0]])) {
				$indexes[$row[0]] = array(
					'unique' => preg_match("/unique/i",$row[1]),
					'columns' => array()
				);
			}
			/**
			 * There must be a more elegant way of doing this,
			 * the index elements appear in the SQL statement
			 * in cols[1] between parentheses
			 * e.g CREATE UNIQUE INDEX ware_0 ON warehouse (org,warehouse)
			 */
			$cols = explode("(",$row[1]);
			$cols = explode(")",$cols[1]);
			array_pop($cols);
			$indexes[$row[0]]['columns'] = $cols;
		}
		if (isset($savem)) {
			$this->SetFetchMode($savem);
			$ADODB_FETCH_MODE = $save;
		}
		return $indexes;
	}
 }

class  ADORecordSet_pdo_sqlite extends ADORecordSet_pdo {

	var $databaseType = 'pdo_sqlite';

	function ADORecordSet_pdo_sqlite($id,$mode=false)
	{
		return $this->ADORecordSet_pdo($id,$mode);
	}
}
