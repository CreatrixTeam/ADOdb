<?php
/*
@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Latest version is available at http://adodb.sourceforge.net

  SQLite info: http://www.hwaci.com/sw/sqlite/

  Install Instructions:
  ====================
  1. Place this in adodb/drivers
  2. Rename the file, remove the .txt prefix.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB_sqlite3 extends ADOConnection {
	public  $databaseType = "sqlite3";
	public  $replaceQuote = "''"; // string to use to replace quotes
	protected  $_errorNo = 0;
	public  $hasLimit = true;
	public  $hasInsertID = true; 		/// supports autoincrement ID?
	public  $hasAffectedRows = true; 	/// supports affected rows for update/delete?
	public  $hasGenID = true;
	public  $metaTablesSQL = "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name";
	public  $sysDate = "adodb_date('Y-m-d')";
	public  $sysTimeStamp = "adodb_date('Y-m-d H:i:s')";
	public  $fmtTimeStamp = "'Y-m-d H:i:s'";

	public function ServerInfo()
	{
		$version = SQLite3::version();
		$arr['version'] = $version['versionString'];
		$arr['description'] = 'SQLite 3';
		return $arr;
	}

	public function BeginTrans()
	{
		if ($this->transOff) {
			return true;
		}
		$ret = $this->Execute("BEGIN TRANSACTION");
		$this->transCnt += 1;
		return true;
	}

	public function CommitTrans($ok=true)
	{
		if ($this->transOff) {
			return true;
		}
		if (!$ok) {
			return $this->RollbackTrans();
		}
		$ret = $this->Execute("COMMIT");
		if ($this->transCnt > 0) {
			$this->transCnt -= 1;
		}
		return !empty($ret);
	}

	public function RollbackTrans()
	{
		if ($this->transOff) {
			return true;
		}
		$ret = $this->Execute("ROLLBACK");
		if ($this->transCnt > 0) {
			$this->transCnt -= 1;
		}
		return !empty($ret);
	}
    
	// mark newnham
	protected function _MetaColumns($pParsedTableName)
	{
		global $ADODB_FETCH_MODE;
		$false = false;
		$save = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;
		$table = (array_key_exists('schema', $pParsedTableName) ? 
				$pParsedTableName['schema']['name'].".".$pParsedTableName['table']['name'] :
				$pParsedTableName['table']['name']);
		if ($this->fetchMode !== false) {
			$savem = $this->SetFetchMode(false);
		}
		$rs = $this->Execute("PRAGMA table_info('$table')");
		if (isset($savem)) {
			$this->SetFetchMode($savem);
		}
		if (!$rs) {
			$ADODB_FETCH_MODE = $save;
			return $false;
		}
		$arr = array();
		while ($r = $rs->FetchRow()) {
			$type = explode('(',$r['type']);
			$size = '';
			if (sizeof($type)==2) {
				$size = trim($type[1],')');
			}
			$fn = strtoupper($r['name']);
			$fld = new ADOFieldObject;
			$fld->name = $r['name'];
			$fld->type = $type[0];
			$fld->max_length = $size;
			$fld->not_null = $r['notnull'];
			$fld->default_value = $r['dflt_value'];
			$fld->scale = 0;
			if (isset($r['pk']) && $r['pk']) {
				$fld->primary_key=1;
			}
			if ($save == ADODB_FETCH_NUM) {
				$arr[] = $fld;
			} else {
				$arr[strtoupper($fld->name)] = $fld;
			}
		}
		$rs->Close();
		$ADODB_FETCH_MODE = $save;
		return $arr;
	}
	
	function metaForeignKeys( $table, $owner = FALSE, $upper = FALSE, $associative = FALSE )
	{
	    global $ADODB_FETCH_MODE;
		if ($ADODB_FETCH_MODE == ADODB_FETCH_ASSOC 
		|| $this->fetchMode == ADODB_FETCH_ASSOC) 
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


	protected function _insertid()
	{
		return $this->_connectionID->lastInsertRowID();
	}

	protected function _affectedrows()
	{
		return $this->_connectionID->changes();
	}

	public function ErrorMsg()
 	{
		if ($this->_logsql) {
			return $this->_errorMsg;
		}
		return ($this->_errorNo) ? $this->ErrorNo() : ''; //**tochange?
	}

	public function ErrorNo()
	{
		return $this->_connectionID->lastErrorCode(); //**tochange??
	}

	public function SQLDate($fmt, $col=false)
	{
		$fmt = $this->qstr($fmt);
		return ($col) ? "adodb_date2($fmt,$col)" : "adodb_date($fmt)";
	}


	protected function _createFunctions()
	{
		$this->_connectionID->createFunction('adodb_date', 'adodb_date', 1);
		$this->_connectionID->createFunction('adodb_date2', 'adodb_date2', 2);
	}


	// returns true or false
	protected function _connect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		if (empty($argHostname) && $argDatabasename) {
			$argHostname = $argDatabasename;
		}
		$this->_connectionID = new SQLite3($argHostname);
		$this->_createFunctions();

		return true;
	}

	// returns true or false
	protected function _pconnect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		// There's no permanent connect in SQLite3
		return $this->_connect($argHostname, $argUsername, $argPassword, $argDatabasename);
	}

	// returns query ID if successful, otherwise false
	public function _query($sql,$inputarr=false)
	{
		$rez = $this->_connectionID->query($sql);
		if ($rez === false) {
			$this->_errorNo = $this->_connectionID->lastErrorCode();
		}
		// If no data was returned, we don't need to create a real recordset
		elseif ($rez->numColumns() == 0) {
			$rez->finalize();
			$rez = true;
		}

		return $rez;
	}

	public function SelectLimit($sql,$nrows=-1,$offset=-1,$inputarr=false,$secs2cache=0)
	{
		$offsetStr = ($offset >= 0) ? " OFFSET $offset" : '';
		$limitStr  = ($nrows >= 0)  ? " LIMIT $nrows" : ($offset >= 0 ? ' LIMIT 999999999' : '');
		if ($secs2cache) {
			$rs = $this->CacheExecute($secs2cache,$sql."$limitStr$offsetStr",$inputarr);
		} else {
			$rs = $this->Execute($sql."$limitStr$offsetStr",$inputarr);
		}

		return $rs;
	}

	/*
		This algorithm is not very efficient, but works even if table locking
		is not available.

		Will return false if unable to generate an ID after $MAXLOOPS attempts.
	*/

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

	// returns true or false
	protected function _close()
	{
		return $this->_connectionID->close();
	}

	protected function _MetaIndexes($pParsedTableName, $primary = FALSE, $owner=false)
	{
		$false = false;
		// save old fetch mode
		global $ADODB_FETCH_MODE;
		$save = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_NUM;
		$table = (array_key_exists('schema', $pParsedTableName) ? 
				$pParsedTableName['schema']['name'].".".$pParsedTableName['table']['name'] :
				$pParsedTableName['table']['name']);
		if ($this->fetchMode !== FALSE) {
			$savem = $this->SetFetchMode(FALSE);
		}
		$SQL=sprintf("SELECT name,sql FROM sqlite_master WHERE type='index' AND LOWER(tbl_name)='%s'", strtolower($table));
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

/*--------------------------------------------------------------------------------------
		Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordset_sqlite3 extends ADORecordSet {

	public  $databaseType = "sqlite3";
	public  $bind = false;

	public function __construct($queryID,$mode=false)
	{

		if ($mode === false) {
			global $ADODB_FETCH_MODE;
			$mode = $ADODB_FETCH_MODE;
		}
		switch($mode) {
			case ADODB_FETCH_NUM:
				$this->fetchMode = SQLITE3_NUM;
				break;
			case ADODB_FETCH_ASSOC:
				$this->fetchMode = SQLITE3_ASSOC;
				break;
			default:
				$this->fetchMode = SQLITE3_BOTH;
				break;
		}
		$this->adodbFetchMode = $mode;

		$this->_queryID = $queryID;

		$this->_inited = true;
		$this->fields = array();
		if ($queryID) {
			$this->_currentRow = 0;
			$this->EOF = !$this->_fetch();
			@$this->_initrs();
		} else {
			$this->_numOfRows = 0;
			$this->_numOfFields = 0;
			$this->EOF = true;
		}

		return $this->_queryID;
	}


	public function FetchField($fieldOffset = -1)
	{
		$fld = new ADOFieldObject;
		$fld->name = $this->_queryID->columnName($fieldOffset);
		$fld->type = 'VARCHAR';
		$fld->max_length = -1;
		return $fld;
	}

	function MetaType($t,$len=-1,$fieldobj=false)
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

	protected function _initrs()
	{
		$this->_numOfFields = $this->_queryID->numColumns();

	}

	public function Fields($colname)
	{
		if ($this->fetchMode != SQLITE3_NUM) {
			return $this->fields[$colname];
		}
		if (!$this->bind) {
			$this->bind = array();
			for ($i=0; $i < $this->_numOfFields; $i++) {
				$o = $this->FetchField($i);
				$this->bind[strtoupper($o->name)] = $i;
			}
		}

		return $this->fields[$this->bind[strtoupper($colname)]];
	}

	protected function _seek($row)
	{
		// sqlite3 does not implement seek
		if ($this->debug) {
			ADOConnection::outp("SQLite3 does not implement seek");
		}
		return false;
	}

	protected function _fetch($ignore_fields=false)
	{
		$this->fields = $this->_queryID->fetchArray($this->fetchMode);
		return !empty($this->fields);
	}

	protected function _close()
	{
	}

}
