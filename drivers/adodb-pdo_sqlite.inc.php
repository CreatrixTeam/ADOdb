<?php

/*
 @version   v5.21.0-dev  ??-???-2016
 @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
 @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
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
	public  $databaseType    = "pdo_sqlite";
	public  $dsnType 		 = 'sqlite'; 
	public  $metaTablesSQL   = "SELECT name FROM sqlite_master WHERE type='table'";
	public  $sysDate         = 'current_date';
	public  $sysTimeStamp    = 'current_timestamp';
	public  $replaceQuote    = "''";
	public  $hasGenID        = true;
	public  $random='abs(random())';
	protected  $_bindInputArray = true;
	public  $hasTransactions = false; // // should be set to false because of PDO SQLite driver not supporting changing autocommit mode
	public  $hasInsertID = true;

	public function ServerInfo()
	{
		@($ver = array_pop($this->GetCol("SELECT sqlite_version()")));
		@($enc = array_pop($this->GetCol("PRAGMA encoding")));

		$arr['version']     = $ver;
		$arr['description'] = 'SQLite ';
		$arr['encoding']    = $enc;

		return $arr;
	}

	public function SelectLimit($sql,$nrows=-1,$offset=-1,$inputarr=false,$secs2cache=0)
	{
		$offsetStr = ($offset >= 0) ? " OFFSET $offset" : '';
		$limitStr  = ($nrows >= 0)  ? " LIMIT $nrows" : ($offset >= 0 ? ' LIMIT 999999999' : '');
	  	if ($secs2cache)
	   		$rs = $this->CacheExecute($secs2cache,$sql."$limitStr$offsetStr",$inputarr);
	  	else
	   		$rs = $this->Execute($sql."$limitStr$offsetStr",$inputarr);

		return $rs;
	}

	//VERBATIM copy in adodb-pdo_sqlite.inc.php, adodb-sqlite.inc.php and adodb-sqlite3.inc.php
	public function GenID($seq='adodbseq',$start=1)
	{
		if (!$this->hasGenID) {
			return 0; // formerly returns false pre 1.60
		}

		// if you have to modify the parameter below, your database is overloaded,
		// or you need to implement generation of id's yourself!
		$MAXLOOPS = 100;
		while (--$MAXLOOPS>=0) {
			if(ADOConnection::GenID($seq, $start) > 0)
				{return $this->genID;}
		}
		if ($fn = $this->raiseErrorFn) {
			$fn($this->databaseType,'GENID',-32000,"Unable to generate unique id after $MAXLOOPS attempts",$seq,$num);
		}
		return false;
	}

	public function SetTransactionMode($transaction_mode)
	{
		$this->_transmode = strtoupper($transaction_mode);
	}

	public function BeginTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt += 1;
		$this->_autocommit = false;
		return $this->Execute("BEGIN {$this->_transmode}");
	}

	public function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if (!$ok) return $this->RollbackTrans();
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;

		$ret = $this->Execute('COMMIT');
		return $ret;
	}

	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;

		$ret = $this->Execute('ROLLBACK');
		return $ret;
	}


    // mark newnham
	protected function _MetaColumns($pParsedTableName)
	{
	  $false = false;
	  $tab = (array_key_exists('schema', $pParsedTableName) ? 
				$pParsedTableName['schema']['name'].".".$pParsedTableName['table']['name'] :
				$pParsedTableName['table']['name']);
	  $savem = $this->SetFetchMode2(ADODB_FETCH_ASSOC);
	  $rs = $this->Execute("PRAGMA table_info('$tab')");

	  if (!$rs) {
		$this->SetFetchMode2($savem);

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
	    if ($this->GetFetchMode() == ADODB_FETCH_NUM) $arr[] = $fld;
	    else $arr[strtoupper($fld->name)] = $fld;
	  }
	  $rs->Close();
	  $this->SetFetchMode2($savem);

	  return $arr;
	}
	
	//Verbatim copy from "adodb-sqlite3.inc.php". This might not work on older Sqlite (<3) however.
	function metaForeignKeys( $table, $owner = FALSE, $upper = FALSE, $associative = FALSE )
	{
		if ($this->GetFetchMode() == ADODB_FETCH_ASSOC) 
		$associative = true;
		
	    /*
		* Read sqlite master to find foreign keys
		*/
		$sql = "SELECT sql
				 FROM (
				SELECT sql sql, type type, tbl_name tbl_name, name name
				  FROM sqlite_master
			          )
				WHERE type != 'meta'
				  AND sql NOTNULL
		          AND LOWER(name) ='" . strtolower($table) . "'";

		$tableSql = $this->getOne($sql);

		$fkeyList = array();
		$ylist = preg_split("/,+/",$tableSql);
		foreach ($ylist as $y)
		{
			if (!preg_match('/FOREIGN/',$y))
				continue;
			
			$matches = false;
			preg_match_all('/\((.+?)\)/i',$y,$matches);
			$tmatches = false;
			preg_match_all('/REFERENCES (.+?)\(/i',$y,$tmatches);
			
			if ($associative)
			{
				if (!isset($fkeyList[$tmatches[1][0]]))
					$fkeyList[$tmatches[1][0]]	= array();
				$fkeyList[$tmatches[1][0]][$matches[1][0]] = $matches[1][1];
			}
			else
				$fkeyList[$tmatches[1][0]][] = $matches[1][0] . '=' . $matches[1][1];
		}
		
		if ($associative)
		{
			if ($upper)
				$fkeyList = array_change_key_case($fkeyList,CASE_UPPER);
			else
				$fkeyList = array_change_key_case($fkeyList,CASE_LOWER);
		}
		return $fkeyList;
	}

	public function MetaTables($ttype=false,$showSchema=false,$mask=false)
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
	protected function _MetaIndexes($pParsedTableName, $primary = FALSE, $owner=false)
	{
		$false = false;
		$table = (array_key_exists('schema', $pParsedTableName) ? 
				$pParsedTableName['schema']['name'].".".$pParsedTableName['table']['name'] :
				$pParsedTableName['table']['name']);
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$SQL=sprintf("SELECT name,sql FROM sqlite_master WHERE type='index' AND LOWER(tbl_name)='%s'", strtolower($table));
		$rs = $this->Execute($SQL);
		if (!is_object($rs)) {
			$this->SetFetchMode2($savem);

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
		$this->SetFetchMode2($savem);

		return $indexes;
	}
 
	/**
	* Returns the maximum size of a MetaType C field. Because of the 
	* database design, sqlite places no limits on the size of data inserted
	*
	* @return int
	*/
	function charMax()
	{
		return ADODB_STRINGMAX_NOLIMIT;
	}

	/**
	* Returns the maximum size of a MetaType X field. Because of the 
	* database design, sqlite places no limits on the size of data inserted
	*
	* @return int
	*/
	function textMax()
	{
		return ADODB_STRINGMAX_NOLIMIT;
	}
 }

class  ADORecordSet_pdo_sqlite extends ADORecordSet_pdo {

	public  $databaseType = 'pdo_sqlite';
	protected $_datadict = NULL;
	
	public function MetaType($t,$len=-1,$fieldobj=false)
	{
		$vReturn = NULL;

		if(($this->_datadict === NULL) && ($this->connection))
			{$this->_datadict = NewDataDictionary($this->connection);}

		if(($this->_datadict !== NULL) &&
				(floatval($this->_dataDict->GetServerInfo("version")) > 2))
			{$vReturn = $this->pdo_sqlite_MetaType($t, $len, $fieldobj);}
		else
			{$vReturn = parent::MetaType($t, $len, $fieldobj);}

		return $vReturn;
	}

	//VERBATIM COPY OF THE FUNCTION MetaType IN adodb-sqlite3.inc.php
	protected function pdo_sqlite_MetaType($t,$len=-1,$fieldobj=false)
	{
		
		if (is_object($t))
		{
			$fieldobj = $t;
			$t = $fieldobj->type;
			$len = $fieldobj->max_length;
		}
		
		$t = strtoupper($t);
		
		/*
		* We are using the Sqlite affinity method here
		* @link https://www.sqlite.org/datatype3.html
		*/
		$affinity = array( 
		'INT'=>'INTEGER',
		'INTEGER'=>'INTEGER',
		'TINYINT'=>'INTEGER',
		'SMALLINT'=>'INTEGER',
		'MEDIUMINT'=>'INTEGER',
		'BIGINT'=>'INTEGER',
		'UNSIGNED BIG INT'=>'INTEGER',
		'INT2'=>'INTEGER',
		'INT8'=>'INTEGER',

		'CHARACTER'=>'TEXT',
		'VARCHAR'=>'TEXT',
		'VARYING CHARACTER'=>'TEXT',
		'NCHAR'=>'TEXT',
		'NATIVE CHARACTER'=>'TEXT',
		'NVARCHAR'=>'TEXT',
		'TEXT'=>'TEXT',
		'CLOB'=>'TEXT',

		'BLOB'=>'BLOB',

		'REAL'=>'REAL',
		'DOUBLE'=>'REAL',
		'DOUBLE PRECISION'=>'REAL',
		'FLOAT'=>'REAL',

		'NUMERIC'=>'NUMERIC',
		'DECIMAL'=>'NUMERIC',
		'BOOLEAN'=>'NUMERIC',
		'DATE'=>'NUMERIC',
		'DATETIME'=>'NUMERIC'
		);
		
		if (!isset($affinity[$t]))
			return ADODB_DEFAULT_METATYPE;
		
		$subt = $affinity[$t];
		/*
		* Now that we have subclassed the provided data down
		* the sqlite 'affinity', we convert to ADOdb metatype
		*/
		
		$subclass = array('INTEGER'=>'I',
						  'TEXT'=>'X',
						  'BLOB'=>'B',
						  'REAL'=>'N',
						  'NUMERIC'=>'N');
		
		return $subclass[$subt];
	}
}
