<?php
/**
	@version   v5.22.0-dev  Unreleased
	@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
	@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community

	Released under both BSD license and Lesser GPL library license.
	Whenever there is any discrepancy between the two licenses,
	the BSD license will take precedence.

	Set tabs to 4 for best viewing.

	Latest version is available at https://adodb.org/

	Requires ODBC. Works on Windows and Unix.

	Problems:
		Where is float/decimal type in pdo_param_type
		LOB handling for CLOB/BLOB differs significantly
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();


/*
enum pdo_param_type {
PDO::PARAM_NULL, 0

/* int as in long (the php native int type).
 * If you mark a column as an int, PDO expects get_col to return
 * a pointer to a long
PDO::PARAM_INT, 1

/* get_col ptr should point to start of the string buffer
PDO::PARAM_STR, 2

/* get_col: when len is 0 ptr should point to a php_stream *,
 * otherwise it should behave like a string. Indicate a NULL field
 * value by setting the ptr to NULL
PDO::PARAM_LOB, 3

/* get_col: will expect the ptr to point to a new PDOStatement object handle,
 * but this isn't wired up yet
PDO::PARAM_STMT, 4 /* hierarchical result set

/* get_col ptr should point to a zend_bool
PDO::PARAM_BOOL, 5


/* magic flag to denote a parameter as being input/output
PDO::PARAM_INPUT_OUTPUT = 0x80000000
};
*/

function adodb_pdo_type($t)
{
	switch($t) {
	case 2: return 'VARCHAR';
	case 3: return 'BLOB';
	default: return 'NUMERIC';
	}
}

/*----------------------------------------------------------------------------*/


class ADODB_pdo extends ADOConnection {
	public  $databaseType = "pdo";
	public  $dataProvider = "pdo";
	public  $fmtDate = "'Y-m-d'";
	public  $fmtTimeStamp = "'Y-m-d, h:i:sA'";
	public  $replaceQuote = "''"; // string to use to replace quotes
	public  $hasAffectedRows = true;
	protected  $_bindInputArray = true;
	protected  $_autocommit = true;
	protected  $_lastAffectedRows = 0;

	protected  $_errormsg = false;
	protected  $_errorno = false;

	protected  $dsnType = '';
	public  $_stmt = false; //PDO driver specific
	
	/*
	* Describe parameters passed directly to the PDO driver
	*
	* @example $db->pdoOptions = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
	*/
	public $pdoParameters = array();

	// returns true or false
	protected function _connect($argDSN, $argUsername, $argPassword, $argDatabasename, $persist=false)
	{
		$at = strpos($argDSN,':');
		$vDsnType = substr($argDSN,0,$at);
		
		if($vDsnType === "dblib")
			{$vDsnType = "dblib_mssql";}
		
		if($this->dsnType !== $vDsnType){
			$this->_errormsg = 'Connection attempt failed: DSN mismatch. Driver expects '.
					$this->dsnType.'. '.$vDsnType." provided.";
			return false;
		}

		if ($argDatabasename) {
			switch($this->dsnType){
				case 'sqlsrv':
					$argDSN .= ';database='.$argDatabasename;
					break;
				case 'sqlite':
					break;
				case 'dblib_mssql':
					$argDSN = str_replace('dblib_mssql', 'dblib', $argDSN).';dbname='.$argDatabasename;
					break;
				case 'mssql':
				case 'mysql':
				case 'oci':
				case 'pgsql':
				case 'firebird':
				case 'dblib':
				default:
					$argDSN .= ';dbname='.$argDatabasename;
			}
		}
		/*
		* Configure for persistent connection if required,
		* by adding the the pdo parameter into any provided
		* ones
		*/
		if ($persist) {
			$this->pdoParameters[PDO::ATTR_PERSISTENT] = true;
		} 
		
		try {
			$this->_connectionID = new PDO($argDSN, $argUsername, $argPassword, $this->pdoParameters);
		} catch (Exception $e) {
			$this->_connectionID = false;
			$this->_errorno = -1;
			//var_dump($e);
			$this->_errormsg = 'Connection attempt failed: '.$e->getMessage();
			return false;
		}

		if ($this->_connectionID) {

			//$this->_connectionID->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT );
			$this->_connectionID->setAttribute(PDO::ATTR_CASE,PDO::CASE_NATURAL);

			// Now merge in any provided attributes for PDO
			foreach ($this->connectionParameters as $options) {
				foreach($options as $k=>$v) {
					if ($this->debug) {
						ADOconnection::outp('Setting attribute: ' . $k . ' to ' . $v);
					}
					$this->_connectionID->setAttribute($k,$v);
				}
			}

			//$this->_connectionID->setAttribute(PDO::ATTR_AUTOCOMMIT,true);
			
			$this->event_pdoConnectionEstablished();
			return true;
		}
		return false;
	}

	/**
	*	ACCESS: PROTECTED
	*	Called when a successful PDO connection is established.
	*/
	protected function event_pdoConnectionEstablished()
		{}

	public function Concat()
	{
		$args = func_get_args();

		if (PHP_VERSION >= 5.3) {
			return call_user_func_array('parent::Concat', $args);
		}
		return call_user_func_array(array($this,'parent::Concat'), $args);
	}

	// returns true or false
	protected function _pconnect($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		return $this->_connect($argDSN, $argUsername, $argPassword, $argDatabasename, true);
	}

	/*------------------------------------------------------------------------------*/


	public function SelectLimit($sql,$nrows=-1,$offset=-1,$inputarr=false,$secs2cache=0)
	{
		$save = $this->SetFetchMode2(false);
		$ret = $this->SelectLimit($sql,$nrows,$offset,$inputarr,$secs2cache);

		$this->SetFetchMode2($save);

		return $ret;
	}

	public function InParameter(&$stmt,&$var,$name,$maxLen=4000,$type=false)
	{
		$obj = $stmt[1];
		if ($type) {
			$obj->bindParam($name, $var, $type, $maxLen);
		}
		else {
			$obj->bindParam($name, $var);
		}
	}


	public function ErrorMsg()
	{
		if ($this->_errormsg !== false) {
			return $this->_errormsg;
		}
		if (!empty($this->_stmt)) {
			$arr = $this->_stmt->errorInfo();
		}
		else if (!empty($this->_connectionID)) {
			$arr = $this->_connectionID->errorInfo();
		}
		else {
			return 'No Connection Established';
		}

		if ($arr) {
			if (sizeof($arr)<2) {
				return '';
			}
			if ((integer)$arr[0]) {
				return $arr[2];
			}
			else {
				return '';
			}
		}
		else {
			return '-1';
		}
	}


	public function ErrorNo()
	{
		if ($this->_errorno !== false) {
			return $this->_errorno;
		}
		if (!empty($this->_stmt)) {
			$err = $this->_stmt->errorCode();
		}
		else if (!empty($this->_connectionID)) {
			$arr = $this->_connectionID->errorInfo();
			if (isset($arr[0])) {
				$err = $arr[0];
			}
			else {
				$err = -1;
			}
		} else {
			return 0;
		}

		if ($err == '00000') {
			return 0; // allows empty check
		}
		return $err;
	}

	/**
	 * @param bool $auto_commit
	 * @return void
	 */
	public function SetAutoCommit($auto_commit)
	{
		$this->_connectionID->setAttribute(PDO::ATTR_AUTOCOMMIT, $auto_commit);
	}

	public function BeginTrans()
	{
		if (!$this->hasTransactions) {
			return false;
		}
		if ($this->transOff) {
			return true;
		}
		$this->transCnt += 1;
		$this->_autocommit = false;
		$this->SetAutoCommit(false);

		return $this->_connectionID->beginTransaction();
	}

	public function CommitTrans($ok=true)
	{
		if (!$this->hasTransactions) {
			return false;
		}
		if ($this->transOff) {
			return true;
		}
		if (!$ok) {
			return $this->RollbackTrans();
		}
		if ($this->transCnt) {
			$this->transCnt -= 1;
		}
		$this->_autocommit = true;

		$ret = $this->_connectionID->commit();
		$this->SetAutoCommit(true);
		return $ret;
	}

	public function RollbackTrans()
	{
		if (!$this->hasTransactions) {
			return false;
		}
		if ($this->transOff) {
			return true;
		}
		if ($this->transCnt) {
			$this->transCnt -= 1;
		}
		$this->_autocommit = true;

		$ret = $this->_connectionID->rollback();
		$this->SetAutoCommit(true);
		return $ret;
	}

	public function Prepare($sql)
	{
		if(!$this->_bindInputArray) 
			{return $sql;} // no binding

		$this->_stmt = $this->_connectionID->prepare($sql);
		if ($this->_stmt) {
			return array($sql,$this->_stmt);
		}

		return false;
	}

	public function PrepareStmt($sql)
	{
		$stmt = $this->_connectionID->prepare($sql);
		if (!$stmt) {
			return false;
		}
		$obj = new ADOPDOStatement($stmt,$this);
		return $obj;
	}

	/* returns queryID or false */
	protected function _query($sql,$inputarr=false)
	{
		$ok = false;
		if (is_array($sql)) {
			$stmt = $sql[1];
		} else {
			$stmt = $this->_connectionID->prepare($sql);
		}
		
		if ($stmt) {
			if ($inputarr) {
				$ok = $stmt->execute($inputarr);
			}
			else {
				$ok = $stmt->execute();
			}
		}


		$this->_errormsg = false;
		$this->_errorno = false;

		if ($ok) {
			$this->_stmt = $stmt;
			return $stmt;
		}

		if ($stmt) {

			$arr = $stmt->errorinfo();
			if ((integer)$arr[1]) {
				$this->_errormsg = $arr[2];
				$this->_errorno = $arr[1];
			}

		} else {
			$this->_errormsg = false;
			$this->_errorno = false;
		}
		return false;
	}

	// returns true or false
	protected function _close()
	{
		$this->_stmt = false;
		return true;
	}

	protected function _affectedrows()
	{
		return ($this->_stmt) ? $this->_stmt->rowCount() : 0;
	}

	protected function _insertid()
	{
		return ($this->_connectionID) ? $this->_connectionID->lastInsertId() : 0;
	}

	/**
	 * Quotes a string to be sent to the database.
	 *
	 * If we have an active connection, delegates quoting to the underlying
	 * PDO object PDO::quote(). Otherwise, replace "'" by the value of
	 * $replaceQuote (same behavior as mysqli driver).
	 *
	 * @param string  $s           The string to quote
	 * @param bool   $magic_quotes This param is not used since 5.21.0.
	 *                             It remains for backwards compatibility.
	 *
	 * @return string Quoted string
	 */
	public function qstr($s, $magic_quotes = false)
	{
		if (!$magic_quotes) {
			if ($this->_connectionID) {
				return $this->_connectionID->quote($s);
			}
			return "'" . str_replace("'", $this->replaceQuote, $s) . "'";
		}

		// undo magic quotes for "
		$s = str_replace('\\"', '"', $s);
		return "'$s'";
	}

}

//THE FOLLOWING CLASS IS NO LONGER USED. HOWEVER, KEEP IT FOR THE TIME BEING.
class ADODB_pdo_base extends ADODB_pdo {

	public  $sysDate = "'?'";
	public  $sysTimeStamp = "'?'";
	protected  $_bindInputArray = true;


	protected function event_pdoConnectionEstablished()
	{		
		#$parentDriver->_connectionID->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
	}

	public function ServerInfo()
	{
		return ADOConnection::ServerInfo();
	}

	public function SelectLimit($sql,$nrows=-1,$offset=-1,$inputarr=false,$secs2cache=0)
	{
		$ret = ADOConnection::SelectLimit($sql,$nrows,$offset,$inputarr,$secs2cache);
		return $ret;
	}

	public function MetaTables($ttype=false,$showSchema=false,$mask=false)
	{
		return false;
	}

	protected function _MetaColumns($pParsedTableName)
	{
		return false;
	}
}

class ADOPDOStatement {

	public  $databaseType = "pdo";
	public  $dataProvider = "pdo";
	protected  $_stmt;
	protected  $_connectionID;

	public function __construct($stmt,$connection)
	{
		$this->_stmt = $stmt;
		$this->_connectionID = $connection;
	}

	public function Execute($inputArr=false)
	{
		$savestmt = $this->_connectionID->_stmt;
		$rs = $this->_connectionID->Execute(array(false,$this->_stmt),$inputArr);
		$this->_connectionID->_stmt = $savestmt;
		return $rs;
	}

	public function InParameter(&$var,$name,$maxLen=4000,$type=false)
	{

		if ($type) {
			$this->_stmt->bindParam($name,$var,$type,$maxLen);
		}
		else {
			$this->_stmt->bindParam($name, $var);
		}
	}

	public function Affected_Rows()
	{
		return ($this->_stmt) ? $this->_stmt->rowCount() : 0;
	}

	public function ErrorMsg()
	{
		if ($this->_stmt) {
			$arr = $this->_stmt->errorInfo();
		}
		else {
			$arr = $this->_connectionID->errorInfo();
		}

		if (is_array($arr)) {
			if ((integer) $arr[0] && isset($arr[2])) {
				return $arr[2];
			}
			else {
				return '';
			}
		} else {
			return '-1';
		}
	}

	public function NumCols()
	{
		return ($this->_stmt) ? $this->_stmt->columnCount() : 0;
	}

	public function ErrorNo()
	{
		if ($this->_stmt) {
			return $this->_stmt->errorCode();
		}
		else {
			return $this->_connectionID->errorInfo();
		}
	}
}

/*--------------------------------------------------------------------------------------
	Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordSet_pdo extends ADORecordSet {

	public  $databaseType = "pdo";
	public  $dataProvider = "pdo";

	protected function _initrs()
	{
	global $ADODB_COUNTRECS;

		$this->_numOfRows = ($ADODB_COUNTRECS) ? @$this->_queryID->rowCount() : -1;
		if (!$this->_numOfRows) {
			$this->_numOfRows = -1;
		}
		$this->_numOfFields = $this->_queryID->columnCount();
	}

	/** 
	* returns the field object 
	* 
	* @param    int	$fieldOffset in PDO drivers $fieldOffset defaults to first field if not passed.
	* 
	* @return	obj	The ADOfieldobject describing the field 
	*/
	protected function _FetchField($fieldOffset = -1)
	{
		if($fieldOffset === -1)
			{$fieldOffset = 0;}

		$o= new ADOFieldObject();
		$arr = @$this->_queryID->getColumnMeta($fieldOffset);
		if (!$arr) {
			return false;
		}
		//adodb_pr($arr);
		$o->name = $arr['name'];
		if (isset($arr['native_type']) && $arr['native_type'] <> "null") {
			$o->type = $arr['native_type'];
		}
		else {
			$o->type = adodb_pdo_type($arr['pdo_type']);
		}
		$o->max_length = $arr['len'];
		$o->precision = $arr['precision'];

		return $o;
	}

	protected function _seek($row)
	{
		return false;
	}

	protected function _fetch()
	{
		$this->bind = false;
		if (!$this->_queryID) {
			$this->fields = false;
			return false;
		}

		$this->fields = $this->_queryID->fetch($this->pdo_getDriverFetchMode());
		return !empty($this->fields);
	}

	protected function _close()
	{
		$this->_queryID = false;
	}

	protected function pdo_getDriverFetchMode()
	{
		switch($this->fetchMode)
		{
			case ADODB_FETCH_NUM:
				return PDO::FETCH_NUM;
			case ADODB_FETCH_ASSOC:
				return PDO::FETCH_ASSOC;
			case ADODB_FETCH_BOTH:
			default:
				return PDO::FETCH_BOTH;
		}
	}

}

