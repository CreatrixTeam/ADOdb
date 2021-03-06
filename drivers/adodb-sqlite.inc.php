<?php
/*
@version   v5.22.0-dev  Unreleased
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Latest version is available at https://adodb.org/

  SQLite info: http://www.hwaci.com/sw/sqlite/

  Install Instructions:
  ====================
  1. Place this in adodb/drivers
  2. Rename the file, remove the .txt prefix.
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB_sqlite extends ADOConnection {
	public  $databaseType = "sqlite";
	public  $dataProvider = "sqlite";
	public  $replaceQuote = "''"; // string to use to replace quotes
	protected  $_errorNo = 0;
	public  $hasLimit = true;
	public  $hasInsertID = true; 		/// supports autoincrement ID?
	public  $hasAffectedRows = true; 	/// supports affected rows for update/delete?
	public  $hasGenID = true;
	public  $metaTablesSQL = "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name";
	public  $fmtTimeStamp = "'Y-m-d H:i:s'";

	public function ServerInfo()
	{
		$arr['version'] = sqlite_libversion();
		$arr['description'] = 'SQLite ';
		$arr['encoding'] = sqlite_libencoding();
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
		$false = false;
		$table = $pParsedTableName['table']['name'];
		$savem = $this->SetFetchMode2(ADODB_FETCH_ASSOC);

		$rs = $this->Execute("PRAGMA table_info('$table')");

		if (!$rs) {
			$this->SetFetchMode2($savem);

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
			if ($this->GetFetchMode() == ADODB_FETCH_NUM) {
				$arr[] = $fld;
			} else {
				$arr[strtoupper($fld->name)] = $fld;
			}
		}
		$rs->Close();
		$this->SetFetchMode2($savem);

		return $arr;
	}

	protected function _insertid()
	{
		return sqlite_last_insert_rowid($this->_connectionID);
	}

	protected function _affectedrows()
	{
		return sqlite_changes($this->_connectionID);
	}

	public function ErrorMsg()
 	{
		if ($this->_logsql) {
			return $this->_errorMsg;
		}
		return ($this->_errorNo) ? sqlite_error_string($this->_errorNo) : '';
	}

	public function ErrorNo()
	{
		return $this->_errorNo;
	}


	protected function _createFunctions()
	{
		@sqlite_create_function($this->_connectionID, 'adodb_date', 'adodb_date', 1);
		@sqlite_create_function($this->_connectionID, 'adodb_date2', 'adodb_date2', 2);
	}


	// returns true or false
	protected function _connect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		if (!function_exists('sqlite_open')) {
			return null;
		}
		if (empty($argHostname) && $argDatabasename) {
			$argHostname = $argDatabasename;
		}

		$this->_connectionID = sqlite_open($argHostname);
		if ($this->_connectionID === false) {
			return false;
		}
		$this->_createFunctions();
		return true;
	}

	// returns true or false
	protected function _pconnect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		if (!function_exists('sqlite_open')) {
			return null;
		}
		if (empty($argHostname) && $argDatabasename) {
			$argHostname = $argDatabasename;
		}

		$this->_connectionID = sqlite_popen($argHostname);
		if ($this->_connectionID === false) {
			return false;
		}
		$this->_createFunctions();
		return true;
	}

	// returns query ID if successful, otherwise false
	protected function _query($sql,$inputarr=false)
	{
		$rez = sqlite_query($sql,$this->_connectionID);
		if (!$rez) {
			$this->_errorNo = sqlite_last_error($this->_connectionID);
		}
		// If no data was returned, we don't need to create a real recordset
		// Note: this code is untested, as I don't have a sqlite2 setup available
		elseif (sqlite_num_fields($rez) == 0) {
			$rez = true;
		}

		return $rez;
	}

	public function SelectLimit($sql,$nrows=-1,$offset=-1,$inputarr=false,$secs2cache=0)
	{
		$nrows = (int) $nrows;
		$offset = (int) $offset;
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
		return @sqlite_close($this->_connectionID);
	}

	//Verbatim confirmed from "adodb-sqlite.inc.php"/"adodb-sqlite3.inc.php"
	protected function _MetaIndexes($pParsedTableName, $primary = FALSE, $owner=false)
	{
		$false = false;
		$table = $pParsedTableName['table']['name'];
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		
		$pragmaData = array();
		
		/*
		* If we want the primary key, we must extract
		* it from the table statement, and the pragma
		*/
		if ($primary)
		{
			$sql = sprintf('PRAGMA table_info([%s]);',
						   strtolower($table)
						   );
			$pragmaData = $this->GetAll($sql);
		}
		
		/*
		* Exclude the empty entry for the primary index
		*	Note: This also removes the implicitly created indices.
		*	Note: A lack of index does not mean a lack of primary key.
		*/
		$sqlite = "SELECT name,sql
					 FROM sqlite_master 
					WHERE type='index' 
					  AND sql IS NOT NULL
					  AND LOWER(tbl_name)='%s'";
		
		$SQL = sprintf($sqlite,
				     strtolower($table)
					 );
		
		$rs = $this->Execute($SQL);
		
		if (!is_object($rs)) {
			$this->SetFetchMode2($savem);

			return $false;
		}

		$indexes = array ();
		
		while ($row = $rs->FetchRow()) 
		{
			
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
			 * The index elements appear in the SQL statement
			 * in cols[1] between parentheses
			 * e.g CREATE UNIQUE INDEX ware_0 ON warehouse (org,warehouse)
			 */
			preg_match_all('/\((.*)\)/',$row[1],$indexExpression);
			$indexes[$row[0]]['columns'] = array_map('trim',explode(',',$indexExpression[1][0]));
		}

		$this->SetFetchMode2($savem);

		
		/*
		* If we want primary, add it here
		*/
		if ($primary){
			
			/*
			* Check the previously retrieved pragma to search
			* with a closure
			*/

			$pkIndexData = array('unique'=>1,'columns'=>array());
			
			foreach($pkIndexData as $key => $value){
				
				/*
				* As we iterate the elements check for pk index and sort
				*/
				if ($value[5] > 0)
				{
					$pkIndexData['columns'][$value[5]] = strtolower($value[1]);
					ksort($pkIndexData['columns']);
				}
			}

			/*
			* If we found no columns, there is no
			* primary index
			*/
			if (count($pkIndexData['columns']) > 0)
				$indexes['PRIMARY'] = $pkIndexData;
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

	/*
	 * Converts a date to a month only field and pads it to 2 characters
	 *
	 * @param 	str		$fld	The name of the field to process
	 * @return	str				The SQL Statement
	 */
	function month($fld)
	{
		$x = "strftime('%m',$fld)";

		return $x;
	}

	/*
	 * Converts a date to a day only field and pads it to 2 characters
	 *
	 * @param 	str		$fld	The name of the field to process
	 * @return	str				The SQL Statement
	 */
	function day($fld) {
		$x = "strftime('%d',$fld)";
		return $x;
	}

	/*
	 * Converts a date to a year only field
	 *
	 * @param 	str		$fld	The name of the field to process
	 * @return	str				The SQL Statement
	 */
	function year($fld) {
		$x = "strftime('%Y',$fld)";

		return $x;
	}
}

/*--------------------------------------------------------------------------------------
		Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordset_sqlite extends ADORecordSet {

	public  $databaseType = "sqlite";
	public  $bind = false;

	protected function _FetchField($fieldOffset = -1)
	{
		$fld = new ADOFieldObject;
		$fld->name = sqlite_field_name($this->_queryID, $fieldOffset);
		$fld->type = 'VARCHAR';
		$fld->max_length = -1;

		if($fld->name === false)
			{return false;}

		return $fld;
	}

	protected function _initrs()
	{
		$this->_numOfRows = @sqlite_num_rows($this->_queryID);
		$this->_numOfFields = @sqlite_num_fields($this->_queryID);
	}

	protected function _seek($row)
	{
		return sqlite_seek($this->_queryID, $row);
	}

	protected function _fetch()
	{
		$this->bind = false;
		$this->fields = @sqlite_fetch_array($this->_queryID,$this->sqlite_getDriverFetchMode());
		return !empty($this->fields);
	}

	protected function _close()
	{
	}

	protected function sqlite_getDriverFetchMode()
	{
		switch($this->fetchMode)
		{
			case ADODB_FETCH_NUM:
				return SQLITE_NUM;
			case ADODB_FETCH_ASSOC:
				return SQLITE_ASSOC;
			case ADODB_FETCH_BOTH:
			default:
				return SQLITE_BOTH;
		}
	}

}
