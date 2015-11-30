<?php
/**
	@version   v5.21dev  ??-???-2015
	@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
	@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community

	Released under both BSD license and Lesser GPL library license.
	Whenever there is any discrepancy between the two licenses,
	the BSD license will take precedence.

	Set tabs to 4 for best viewing.

	Latest version is available at http://adodb.sourceforge.net

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
	protected  $_haserrorfunctions = true;
	protected  $_lastAffectedRows = 0;

	protected  $_errormsg = false;
	protected  $_errorno = false;

	public  $dsnType = '';
	public  $stmt = false;

	public function __construct()
	{
	}

	// returns true or false
	protected function _connect($argDSN, $argUsername, $argPassword, $argDatabasename, $persist=false)
	{
		$at = strpos($argDSN,':');
		$vDsnType = substr($argDSN,0,$at);
		
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
				case 'mssql':
				case 'mysql':
				case 'oci':
				case 'pgsql':
				case 'sqlite':
				default:
					$argDSN .= ';dbname='.$argDatabasename;
			}
		}
		try {
			$this->_connectionID = new PDO($argDSN, $argUsername, $argPassword);
		} catch (Exception $e) {
			$this->_connectionID = false;
			$this->_errorno = -1;
			//var_dump($e);
			$this->_errormsg = 'Connection attempt failed: '.$e->getMessage();
			return false;
		}

		if ($this->_connectionID) {
			switch(ADODB_ASSOC_CASE){
				case ADODB_ASSOC_CASE_LOWER:
					$m = PDO::CASE_LOWER;
					break;
				case ADODB_ASSOC_CASE_UPPER:
					$m = PDO::CASE_UPPER;
					break;
				default:
				case ADODB_ASSOC_CASE_NATIVE:
					$m = PDO::CASE_NATURAL;
					break;
			}

			//$this->_connectionID->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT );
			$this->_connectionID->setAttribute(PDO::ATTR_CASE,$m);

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
	public function event_pdoConnectionEstablished()
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
		$save = (($this->fetchMode !== false) ? $this->SetFetchMode(false) : false);
		$ret = $this->SelectLimit($sql,$nrows,$offset,$inputarr,$secs2cache);

		$this->SetFetchMode($save);

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
		$this->_connectionID->setAttribute(PDO::ATTR_AUTOCOMMIT,false);

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
		$this->_connectionID->setAttribute(PDO::ATTR_AUTOCOMMIT,true);
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
		$this->_connectionID->setAttribute(PDO::ATTR_AUTOCOMMIT,true);
		return $ret;
	}

	public function Prepare($sql)
	{
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
	public function _query($sql,$inputarr=false)
	{
		if (is_array($sql)) {
			$stmt = $sql[1];
		} else {
			$stmt = $this->_connectionID->prepare($sql);
		}
		#adodb_backtrace();
		#var_dump($this->_bindInputArray);
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
}

//THE FOLLOWING CLASS IS NO LONGER USED. HOWEVER, KEEP IT FOR THE TIME BEING.
class ADODB_pdo_base extends ADODB_pdo {

	public  $sysDate = "'?'";
	public  $sysTimeStamp = "'?'";
	protected  $_bindInputArray = true;


	public function event_pdoConnectionEstablished()
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

	public  $bind = false;
	public  $databaseType = "pdo";
	public  $dataProvider = "pdo";

	public function __construct($id,$mode=false)
	{
		if ($mode === false) {
			global $ADODB_FETCH_MODE;
			$mode = $ADODB_FETCH_MODE;
		}
		$this->adodbFetchMode = $mode;
		switch($mode) {
		case ADODB_FETCH_NUM: $mode = PDO::FETCH_NUM; break;
		case ADODB_FETCH_ASSOC:  $mode = PDO::FETCH_ASSOC; break;

		case ADODB_FETCH_BOTH:
		default: $mode = PDO::FETCH_BOTH; break;
		}
		$this->fetchMode = $mode;

		$this->_queryID = $id;
		parent::__construct($id);
	}


	public function Init()
	{
		if ($this->_inited) {
			return;
		}
		$this->_inited = true;
		if ($this->_queryID) {
			@$this->_initrs();
		}
		else {
			$this->_numOfRows = 0;
			$this->_numOfFields = 0;
		}
		if ($this->_numOfRows != 0 && $this->_currentRow == -1) {
			$this->_currentRow = 0;
			if ($this->EOF = ($this->_fetch() === false)) {
				$this->_numOfRows = 0; // _numOfRows could be -1
			}
		} else {
			$this->EOF = true;
		}
	}

	protected function _initrs()
	{
	global $ADODB_COUNTRECS;

		$this->_numOfRows = ($ADODB_COUNTRECS) ? @$this->_queryID->rowCount() : -1;
		if (!$this->_numOfRows) {
			$this->_numOfRows = -1;
		}
		$this->_numOfFields = $this->_queryID->columnCount();
	}

	// returns the field object
	public function FetchField($fieldOffset = -1)
	{
		$off=$fieldOffset+1; // offsets begin at 1

		$o= new ADOFieldObject();
		$arr = @$this->_queryID->getColumnMeta($fieldOffset);
		if (!$arr) {
			$o->name = 'bad getColumnMeta()';
			$o->max_length = -1;
			$o->type = 'VARCHAR';
			$o->precision = 0;
	#		$false = false;
			return $o;
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

		switch(ADODB_ASSOC_CASE) {
			case ADODB_ASSOC_CASE_LOWER:
				$o->name = strtolower($o->name);
				break;
			case ADODB_ASSOC_CASE_UPPER:
				$o->name = strtoupper($o->name);
				break;
		}
		return $o;
	}

	protected function _seek($row)
	{
		return false;
	}

	protected function _fetch()
	{
		if (!$this->_queryID) {
			return false;
		}

		$this->fields = $this->_queryID->fetch($this->fetchMode);
		return !empty($this->fields);
	}

	protected function _close()
	{
		$this->_queryID = false;
	}

	public function Fields($colname)
	{
		if ($this->adodbFetchMode != ADODB_FETCH_NUM) {
			return @$this->fields[$colname];
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

}
