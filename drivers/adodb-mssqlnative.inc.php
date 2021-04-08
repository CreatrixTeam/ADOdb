<?php
/*
@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
Set tabs to 4 for best viewing.

  Latest version is available at http://adodb.org/

  Native mssql driver. Requires mssql client. Works on Windows.
    http://www.microsoft.com/sql/technologies/php/default.mspx
  To configure for Unix, see
   	http://phpbuilder.com/columns/alberto20000919.php3

    $stream = sqlsrv_get_field($stmt, $index, SQLSRV_SQLTYPE_STREAM(SQLSRV_ENC_BINARY));
    stream_filter_append($stream, "convert.iconv.ucs-2/utf-8"); // Voila, UTF-8 can be read directly from $stream

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

if (!function_exists('sqlsrv_configure')) {
	die("mssqlnative extension not installed");
}

if (!function_exists('sqlsrv_set_error_handling')) {
	function sqlsrv_set_error_handling($constant) {
		sqlsrv_configure("WarningsReturnAsErrors", $constant);
	}
}
if (!function_exists('sqlsrv_log_set_severity')) {
	function sqlsrv_log_set_severity($constant) {
		sqlsrv_configure("LogSeverity", $constant);
	}
}
if (!function_exists('sqlsrv_log_set_subsystems')) {
	function sqlsrv_log_set_subsystems($constant) {
		sqlsrv_configure("LogSubsystems", $constant);
	}
}


//----------------------------------------------------------------
// MSSQL returns dates with the format Oct 13 2002 or 13 Oct 2002
// and this causes tons of problems because localized versions of
// MSSQL will return the dates in dmy or  mdy order; and also the
// month strings depends on what language has been configured. The
// following two variables allow you to control the localization
// settings - Ugh.
//
// MORE LOCALIZATION INFO
// ----------------------
// To configure datetime, look for and modify sqlcommn.loc,
//   typically found in c:\mssql\install
// Also read :
//   http://support.microsoft.com/default.aspx?scid=kb;EN-US;q220918
// Alternatively use:
//   CONVERT(char(12),datecol,120)
//
// Also if your month is showing as month-1,
//   e.g. Jan 13, 2002 is showing as 13/0/2002, then see
//     http://phplens.com/lens/lensforum/msgs.php?id=7048&x=1
//   it's a localisation problem.
//----------------------------------------------------------------


// has datetime conversion to YYYY-MM-DD format, and also mssql_fetch_assoc
ini_set('mssql.datetimeconvert',0);

class ADODB_mssqlnative extends ADOConnection {
	public  $databaseType = "mssqlnative";
	public  $dataProvider = "mssqlnative";
	public  $replaceQuote = "''"; // string to use to replace quotes
	public  $fmtDate = "'Y-m-d'";
	public  $fmtTimeStamp = "'Y-m-d\TH:i:s'";
	public  $hasInsertID = true;
	public  $substr = "substring";
	public  $length = 'len';
	public  $hasAffectedRows = true;
	public  $poorAffectedRows = false;
	public  $metaDatabasesSQL = "select name from sys.sysdatabases where name <> 'master'";
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
	public $hasTop = 'top';		// support mssql SELECT TOP 10 * FROM TABLE
	public $hasGenID = true;
	public $maxParameterLen = 4000;
	public $arrayClass = 'ADORecordSet_array_mssqlnative';
	public $uniqueSort = true;
	public $leftOuter = '*=';
	public $rightOuter = '=*';
	public $ansiOuter = true; // for mssql7 or later
	public $identitySQL = 'select SCOPE_IDENTITY()'; // 'select SCOPE_IDENTITY'; # for mssql 2000
	public $uniqueOrderBy = true;
	protected $_bindInputArray = true;

	public $connectionInfo    = array('ReturnDatesAsStrings'=>true);
	public $cachedSchemaFlush = false;

	public $mssql_version = '';

	public function __construct()
	{
		if ($this->debug) {
			ADOConnection::outp("<pre>");
			sqlsrv_set_error_handling( SQLSRV_ERRORS_LOG_ALL );
			sqlsrv_log_set_severity( SQLSRV_LOG_SEVERITY_ALL );
			sqlsrv_log_set_subsystems(SQLSRV_LOG_SYSTEM_ALL);
			sqlsrv_configure('WarningsReturnAsErrors', 0);
		} else {
			sqlsrv_set_error_handling(0);
			sqlsrv_log_set_severity(0);
			sqlsrv_log_set_subsystems(SQLSRV_LOG_SYSTEM_ALL);
			sqlsrv_configure('WarningsReturnAsErrors', 0);
		}
	}

	/**
	 * Initializes the SQL Server version.
	 * Dies if connected to a non-supported version (2000 and older)
	 */
	public function ServerVersion() {
		$data = $this->ServerInfo();
		preg_match('/^\d{2}/', $data['version'], $matches);
		$version = (int)reset($matches);

		// We only support SQL Server 2005 and up
		if($version < 9) {
			die("SQL SERVER VERSION {$data['version']} NOT SUPPORTED IN mssqlnative DRIVER");
		}

		$this->mssql_version = $version;
	}

	public function ServerInfo() {
		static $arr = false;
		if (is_array($arr))
			return $arr;
		
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$arrServerInfo = sqlsrv_server_info($this->_connectionID);
		$this->SetFetchMode2($savem);
		$arr['description'] = $arrServerInfo['SQLServerName'].' connected to '.$arrServerInfo['CurrentDatabase'];
		$arr['version'] = $arrServerInfo['SQLServerVersion'];//ADOConnection::_findvers($arr['description']);
		return $arr;
	}

	public function IfNull( $field, $ifNull )
	{
		return " ISNULL($field, $ifNull) "; // if MS SQL Server
	}

	protected function _insertid()
	{
		$rez = sqlsrv_query($this->_connectionID,$this->identitySQL);
		sqlsrv_fetch($rez);
		$this->lastInsertID = sqlsrv_get_field($rez, 0);
		return $this->lastInsertID;
	}

	protected function _affectedrows()
	{
		if ($this->_queryID)
		return sqlsrv_rows_affected($this->_queryID);
	}

	public function GenID($seq='adodbseq',$start=1) {
		switch($this->mssql_version){
		case 9:
		case 10:
			return $this->GenID2008($seq, $start);
			break;
		default:
			return ADOConnection::GenID($seq, $start);
			break;
		}
	}

	public function CreateSequence($seq='adodbseq',$start=1)
	{
		switch($this->mssql_version){
		case 9:
		case 10:
			return $this->CreateSequence2008($seq, $start);
			break;
		default:
			return  ADOConnection::CreateSequence($seq, $start);
			break;
		}
	}

	/**
	 * For Server 2005,2008, duplicate a sequence with an identity table
	 */
	public function CreateSequence2008($seq='adodbseq',$start=1)
	{
		if($this->debug) ADOConnection::outp("<hr>CreateSequence($seq,$start)");
		sqlsrv_begin_transaction($this->_connectionID);
		$ok = ADOConnection::CreateSequence($seq, $start);
		if (!$ok) {
			if($this->debug) ADOConnection::outp("<hr>Error: ROLLBACK");
			sqlsrv_rollback($this->_connectionID);
			return false;
		}
		sqlsrv_commit($this->_connectionID);
		return true;
	}

	/**
	 * For Server 2005,2008, duplicate a sequence with an identity table
	 */
	public function GenID2008($seq='adodbseq',$start=1)
	{
		if($this->debug) ADOConnection::outp("<hr>CreateSequence($seq,$start)");
		sqlsrv_begin_transaction($this->_connectionID);
		$num = ADOConnection::GenID($seq, $start);
		if ($num == 0) {
			if($this->debug) ADOConnection::outp("<hr>Error: ROLLBACK");
			sqlsrv_rollback($this->_connectionID);
			return 0;
		}

		sqlsrv_commit($this->_connectionID);
		return $num;
	}

	public function BeginTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt += 1;
		if ($this->debug) ADOConnection::outp('<hr>begin transaction');
		sqlsrv_begin_transaction($this->_connectionID);
		return true;
	}

	public function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if ($this->debug) ADOConnection::outp('<hr>commit transaction');
		if (!$ok) return $this->RollbackTrans();
		if ($this->transCnt) $this->transCnt -= 1;
		sqlsrv_commit($this->_connectionID);
		return true;
	}

	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->debug) ADOConnection::outp('<hr>rollback transaction');
		if ($this->transCnt) $this->transCnt -= 1;
		sqlsrv_rollback($this->_connectionID);
		return true;
	}

	public function SetTransactionMode( $transaction_mode )
	{
		$this->_transmode  = $transaction_mode;
		if (empty($transaction_mode)) {
			$this->Execute('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
			return;
		}
		if (!stristr($transaction_mode,'isolation')) $transaction_mode = 'ISOLATION LEVEL '.$transaction_mode;
		$this->Execute("SET TRANSACTION ".$transaction_mode);
	}

	/*
		Usage:

		$this->BeginTrans();
		$this->RowLock('table1,table2','table1.id=33 and table2.id=table1.id'); # lock row 33 for both tables

		# some operation on both tables table1 and table2

		$this->CommitTrans();

		See http://www.swynk.com/friends/achigrik/SQL70Locks.asp
	*/
	public function RowLock($tables,$where,$col='1 as adodbignore')
		{return ADOConnection::RowLock($tables,$where,$col);}

	public function SelectDB($dbName)
	{
		$this->database = $dbName;
		$this->databaseName = $dbName; # obsolete, retained for compat with older adodb versions
		if ($this->_connectionID) {
			$rs = $this->Execute('USE '.$dbName);
			if($rs) {
				return true;
			} else return false;
		}
		else return false;
	}

	public function ErrorMsg()
	{
		$retErrors = sqlsrv_errors(SQLSRV_ERR_ALL);
		if($retErrors != null) {
			foreach($retErrors as $arrError) {
				$this->_errorMsg .= "SQLState: ".$arrError[ 'SQLSTATE']."\n";
				$this->_errorMsg .= "Error Code: ".$arrError[ 'code']."\n";
				$this->_errorMsg .= "Message: ".$arrError[ 'message']."\n";
			}
		}
		return $this->_errorMsg;
	}

	public function ErrorNo()
	{
		$err = sqlsrv_errors(SQLSRV_ERR_ALL);
		if($err[0]) return $err[0]['code'];
		else return 0;
	}

	// returns true or false
	protected function _connect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		if (!function_exists('sqlsrv_connect'))
		{
			if ($this->debug)
				ADOConnection::outp('Microsoft SQL Server native driver (mssqlnative) not installed');
			return null;
		}
		
		$connectionInfo 			= $this->connectionInfo;
		$connectionInfo["Database"]	= $argDatabasename;
		if ((string)$argUsername != '' || (string)$argPassword != '')
		{
			/*
			* If they pass either a userid or password, we assume
			* SQL Server authentication
			*/
			$connectionInfo["UID"]		= $argUsername;
			$connectionInfo["PWD"]		= $argPassword;
			
			if ($this->debug)
				ADOConnection::outp('userid or password supplied, attempting connection with SQL Server Authentication');
			
		}
		else 
		{
			/*
			* If they don't pass either value, we won't add them to the
			* connection parameters. This will then force an attempt
			* to use windows authentication
			*/
			if ($this->debug)
				ADOConnection::outp('No userid or password supplied, attempting connection with Windows Authentication');
		}
				
		
		/*
		* Now merge in the passed connection parameters setting
		*/
		foreach ($this->connectionParameters as $options)
		{
			foreach($options as $parameter=>$value)
				$connectionInfo[$parameter] = $value;
		}

		if ($this->debug) ADOConnection::outp("connecting to host: $argHostname params: ".var_export($connectionInfo,true));
		if(!($this->_connectionID = @sqlsrv_connect($argHostname,$connectionInfo)))
		{
			if ($this->debug) 
				ADOConnection::outp( 'Connection Failed: '.print_r( sqlsrv_errors(), true));
			return false;
		}

		$this->ServerVersion();

		return true;
	}

	// returns true or false
	protected function _pconnect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		//return null;//not implemented. NOTE: Persistent connections have no effect if PHP is used as a CGI program. (FastCGI!)
		return $this->_connect($argHostname, $argUsername, $argPassword, $argDatabasename);
	}

	public function Prepare($sql)
	{
		return $sql; // prepare does not work properly with bind parameters as bind parameters are managed by sqlsrv_prepare!
	}

	// returns concatenated string
	// MSSQL requires integers to be cast as strings
	// automatically cast every datatype to VARCHAR(255)
	// @author David Rogers (introspectshun)
	public function Concat()
	{
		$s = "";
		$arr = func_get_args();

		// Split single record on commas, if possible
		if (sizeof($arr) == 1) {
			foreach ($arr as $arg) {
				$args = explode(',', $arg);
			}
			$arr = $args;
		}

		foreach($arr as $key => $value)
			{$arr[$key] = "CAST(" . $value . " AS VARCHAR(255))";}
		$s = implode('+',$arr);
		if (sizeof($arr) > 0) return "$s";

		return '';
	}

	/*
		Unfortunately, it appears that mssql cannot handle varbinary > 255 chars
		So all your blobs must be of type "image".

		Remember to set in php.ini the following...

		; Valid range 0 - 2147483647. Default = 4096.
		mssql.textlimit = 0 ; zero to pass through

		; Valid range 0 - 2147483647. Default = 4096.
		mssql.textsize = 0 ; zero to pass through
	*/
	public function UpdateBlob($table,$column,$val,$where,$blobtype='BLOB')
	{

		if (strtoupper($blobtype) == 'CLOB') {
			$sql = "UPDATE $table SET $column='" . $val . "' WHERE $where";
			return $this->Execute($sql) != false;
		}
		$sql = "UPDATE $table SET $column=0x".bin2hex($val)." WHERE $where";
		return $this->Execute($sql) != false;
	}

	// returns query ID if successful, otherwise false
	public function _query($sql,$inputarr=false)
	{
		$this->_errorMsg = false;

		if (is_array($sql))
			$sql = $sql[1];

		$insert = false;
		// handle native driver flaw for retrieving the last insert ID
		if(preg_match('/^\W*insert[\s\w()[\]",.]+values\s*\((?:[^;\']|\'\'|(?:(?:\'\')*\'[^\']+\'(?:\'\')*))*;?$/i', $sql)) {
			$insert = true;
			$sql .= '; '.$this->identitySQL; // select scope_identity()
		}
		if($inputarr)
		{
			/*
			* Ensure that the input array is numeric, as required by
			* sqlsrv_query. If param() was used to create portable binds
			* then the array might be associative
			*/
			$inputarr = array_values($inputarr);
			$rez = sqlsrv_query($this->_connectionID, $sql, $inputarr);
		} else {
			$rez = sqlsrv_query($this->_connectionID,$sql);
		}

		if ($this->debug) ADOConnection::outp("<hr>running query: ".var_export($sql,true)."<hr>input array: ".var_export($inputarr,true)."<hr>result: ".var_export($rez,true));

		if(!$rez)
			$rez = false;

		return $rez;
	}

	// returns true or false
	protected function _close()
	{
		if ($this->transCnt) {
			$this->RollbackTrans();
		}
		if($this->_connectionID) {
			$rez = sqlsrv_close($this->_connectionID);
		}
		$this->_connectionID = false;
		return $rez;
	}

	// mssql uses a default date like Dec 30 2000 12:00AM
	static function UnixDate($v)
	{
		return ADORecordSet_array_mssqlnative::UnixDate($v);
	}

	static function UnixTimeStamp($v)
	{
		return ADORecordSet_array_mssqlnative::UnixTimeStamp($v);
	}

	protected function _MetaIndexes($pParsedTableName,$primary=false, $owner=false)
	{
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
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

	public function MetaForeignKeys($table, $owner=false, $upper=false)
	{
		$savem = $this->SetFetchMode2($ADODB_FETCH_NUM);
		$table = $this->qstr(strtoupper($table));

		$sql =
			"select object_name(constid) as constraint_name,
				col_name(fkeyid, fkey) as column_name,
				object_name(rkeyid) as referenced_table_name,
				col_name(rkeyid, rkey) as referenced_column_name
			from sysforeignkeys
			where upper(object_name(fkeyid)) = $table
			order by constraint_name, referenced_table_name, keyno";

		$constraints = $this->GetArray($sql);

		$this->SetFetchMode2($savem);

		$arr = false;
		foreach($constraints as $constr) {
			//print_r($constr);
			$arr[$constr[0]][$constr[2]][] = $constr[1].'='.$constr[3];
		}
		if (!$arr) return false;

		$arr2 = false;

		foreach($arr as $k => $v) {
			foreach($v as $a => $b) {
				if ($upper) $a = strtoupper($a);
				if (is_array($arr2[$a])) {	// a previous foreign key was define for this reference table, we merge the new one
					$arr2[$a] = array_merge($arr2[$a], $b);
				} else {
					$arr2[$a] = $b;
				}
			}
		}
		return $arr2;
	}

	//From: Fernando Moreira <FMoreira@imediata.pt>
	public function MetaDatabases()
	{
		$this->SelectDB("master");
		$rs =& $this->Execute($this->metaDatabasesSQL);
		$rows = $rs->GetRows();
		$ret = array();
		for($i=0;$i<count($rows);$i++) {
			$ret[] = $rows[$i][0];
		}
		$this->SelectDB($this->database);
		if($ret)
			return $ret;
		else
			return false;
	}

	// "Stein-Aksel Basma" <basma@accelero.no>
	// tested with MSSQL 2000
	protected function _MetaPrimaryKeys($pParsedTableName, $owner=false)
	{
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$schema = @$pParsedTableName['schema']['name'];
		if (!$schema) $schema = $this->database;
		if ($schema) $schema = "and k.table_catalog like '$schema%'";

		$sql = "select distinct k.column_name,ordinal_position from information_schema.key_column_usage k,
		information_schema.table_constraints tc
		where tc.constraint_name = k.constraint_name and tc.constraint_type =
		'PRIMARY KEY' and k.table_name = '$table' $schema order by ordinal_position ";

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$a = $this->GetCol($sql);
		$this->SetFetchMode2($savem);

		if ($a && sizeof($a)>0) return $a;
		$false = false;
		return $false;
	}


	public function MetaTables($ttype=false,$showSchema=false,$mask=false)
	{
		if ($mask) {
			$save = $this->metaTablesSQL;
			$mask = $this->qstr(($mask));
			$this->metaTablesSQL .= " AND name like $mask";
		}
		$ret = ADOConnection::MetaTables($ttype,$showSchema);

		if ($mask) {
			$this->metaTablesSQL = $save;
		}
		return $ret;
	}
	protected function _MetaColumns($pParsedTableName){

		/*
		* A simple caching mechanism, to be replaced in ADOdb V6
		*/
		static $cached_columns = array();
		$table = $this->BuildTableName($this->NormaliseIdentifierNameIf(
				$pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']), @$pParsedTableName['schema']['name']);
		$schema = (!empty($pParsedTableName['schema']['name']) ? 
				$pParsedTableName['schema']['name'] : false);
		if ($this->cachedSchemaFlush)
			$cached_columns = array();

		if (array_key_exists($table,$cached_columns)){
			return $cached_columns[$table];
		}


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
				$fld->scale         = $rs->fields[4];
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
				$fld->scale         = $rs->fields['scale'];
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
		$table = $this->BuildTableName($this->NormaliseIdentifierNameIf(
				$pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']), @$pParsedTableName['schema']['name']);
		$cached_columns[$table] = $retarr;

		return $retarr;
	}

	/**
	* Returns a substring of a varchar type field
	*
	* The SQL server version varies because the length is mandatory, so
	* we append a reasonable string length
	*
	* @param	string	$fld	The field to sub-string
	* @param	int		$start	The start point
	* @param	int		$length	An optional length
	*
	* @return	The SQL text
	*/
	function substr($fld,$start,$length=0)
	{
		if ($length == 0)
			/*
		     * The length available to varchar is 2GB, but that makes no
			 * sense in a substring, so I'm going to arbitrarily limit
			 * the length to 1K, but you could change it if you want
			 */
			$length = 1024;

		$text = "SUBSTRING($fld,$start,$length)";
		return $text;
	}

	/**
	* Returns the maximum size of a MetaType C field. Because of the
	* database design, SQL Server places no limits on the size of data inserted
	* Although the actual limit is 2^31-1 bytes.
	*
	* @return int
	*/
	function charMax()
	{
		return ADODB_STRINGMAX_NOLIMIT;
	}

	/**
	* Returns the maximum size of a MetaType X field. Because of the
	* database design, SQL Server places no limits on the size of data inserted
	* Although the actual limit is 2^31-1 bytes.
	*
	* @return int
	*/
	function textMax()
	{
		return ADODB_STRINGMAX_NOLIMIT;
	}
	/**
	 * Lists procedures, functions and methods in an array.
	 *
	 * @param	string $procedureNamePattern (optional)
	 * @param	string $catalog				 (optional)
	 * @param	string $schemaPattern		 (optional)
	 
	 * @return array of stored objects in current database.
	 *
	 */
	public function metaProcedures($procedureNamePattern = null, $catalog  = null, $schemaPattern  = null)
	{
		
		$metaProcedures = array();
		$procedureSQL   = '';
		$catalogSQL     = '';
		$schemaSQL      = '';
				
		if ($procedureNamePattern)
			$procedureSQL = "AND ROUTINE_NAME LIKE " . strtoupper($this->qstr($procedureNamePattern));
		
		if ($catalog)
			$catalogSQL = "AND SPECIFIC_SCHEMA=" . strtoupper($this->qstr($catalog));
		
		if ($schemaPattern)
			$schemaSQL = "AND ROUTINE_SCHEMA LIKE {$this->qstr($schemaPattern)}";
		
				
		$fields = "	ROUTINE_NAME,ROUTINE_TYPE,ROUTINE_SCHEMA,ROUTINE_CATALOG";
		
		$SQL = "SELECT $fields
		          FROM {$this->database}.information_schema.routines
				 WHERE 1=1
				  $procedureSQL
				  $catalogSQL
				  $schemaSQL
				ORDER BY ROUTINE_NAME
				";
		
		$result = $this->execute($SQL);
		
		if (!$result)
			return false;
		while ($r = $result->fetchRow()){
			
			if (!isset($r[0]))
				/*
				* Convert to numeric
				*/
				$r = array_values($r);
			
			$procedureName = $r[0];
			$schemaName    = $r[2];
			$routineCatalog= $r[3];
			$metaProcedures[$procedureName] = array('type'=> $r[1],
												   'catalog' => $routineCatalog,
												   'schema'  => $schemaName,
												   'remarks' => '',
												    );
													
		}
		
		return $metaProcedures;
		
	}
	
}

/*--------------------------------------------------------------------------------------
	Class Name: Recordset
--------------------------------------------------------------------------------------*/

class ADORecordset_mssqlnative extends ADORecordSet {

	public  $databaseType = "mssqlnative";
	public  $canSeek = false;
	public  $fieldOffset = 0;
	// _mths works only in non-localised system

	/*
	* Cross-reference the objects by name for easy access
	*/
	private $fieldObjectsIndex = array();


	/*
	 * Cross references the dateTime objects for faster decoding
	 */
	private $dateTimeObjects = array();

	/*
	 * flags that we have dateTimeObjects to handle
	 */
	private $hasDateTimeObjects = false;

	/*
	 * This is cross reference between how the types are stored
	 * in SQL Server and their english-language description
	 * -154 is a time field, see #432
	 */
	private $_typeConversion = array(
			-155 => 'datetimeoffset',
			-154 => 'char',
			-152 => 'xml',
			-151 => 'udt',
			-11  => 'uniqueidentifier',
			-10  => 'ntext',
			-9   => 'nvarchar',
			-8   => 'nchar',
			-7   => 'bit',
			-6   => 'tinyint',
			-5   => 'bigint',
			-4   => 'image',
			-3   => 'varbinary',
			-2   => 'timestamp',
			-1   => 'text',
			 1   => 'char',
			 2   => 'numeric',
			 3   => 'decimal',
			 4   => 'int',
			 5   => 'smallint',
			 6   => 'float',
			 7   => 'real',
			 12  => 'varchar',
			 91  => 'date',
			 93  => 'datetime'
			);
	

	protected function _initrs()
	{
		$this->_numOfRows = -1;//not supported
		// Cache the metadata right now
		$this->_FetchField();

	}


	//Contributed by "Sven Axelsson" <sven.axelsson@bokochwebb.se>
	// get next resultset - requires PHP 4.0.5 or later
	protected function _NextRecordSet()
	{
		if (!sqlsrv_next_result($this->_queryID)) return false;
		return true;
	}

	/**
	* Returns: an object containing field information.
	*
	* Get column information in the Recordset object. FetchField()
	* can be used in order to obtain information about fields in a
	* certain query result. If the field offset isn't specified,
	* the next field that wasn't yet retrieved by FetchField()
	* is retrieved.
	*
	* $param int $fieldOffset (optional default=-1 for all
	* @return mixed an ADOFieldObject, or array of objects
	*/
	protected function _FetchField($fieldOffset = -1)
	{
		/*
		 * Retrieve all metadata in one go. This is always returned as a
		 * numeric array.
		 */
		$fieldMetaData = sqlsrv_field_metadata($this->_queryID);

		if (!$fieldMetaData)
			/*
		     * Not a statement that gives us metaData
			 */
			return false;

		$this->_numOfFields = count($fieldMetaData);
		foreach ($fieldMetaData as $key=>$value)
		{

			$fld = new ADOFieldObject;
			/*
			 * Caution - keys are case-sensitive, must respect
			 * casing of values
			 */

			$fld->name          = $value['Name'];
			$fld->max_length    = $value['Size'];
			$fld->column_source = $value['Name'];
			$fld->type          = $this->_typeConversion[$value['Type']];

			$this->_fieldobjects[$key] = $fld;

			$this->fieldObjectsIndex[$fld->name] = $key;

		}
		if ($fieldOffset == -1)
			return $this->_fieldobjects;

		return $this->_fieldobjects[$fieldOffset];
	}

	protected function _seek($row)
	{
		return false;//There is no support for cursors in the driver at this time.  All data is returned via forward-only streams.
	}

	// speedup
	protected function _MoveNext()
	{
		if ($this->EOF)
			return false;

		$this->_currentRow++;

		if ($this->_callFetch())
			return true;
		$this->EOF = true;

		return false;
	}

	protected function _fetch()
	{
		$this->bind = false;
		if ($this->fetchMode & ADODB_FETCH_ASSOC) {
			if ($this->fetchMode & ADODB_FETCH_NUM)
				$this->fields = @sqlsrv_fetch_array($this->_queryID,SQLSRV_FETCH_BOTH);
			else
				$this->fields = @sqlsrv_fetch_array($this->_queryID,SQLSRV_FETCH_ASSOC);
		}
		else
			$this->fields = @sqlsrv_fetch_array($this->_queryID,SQLSRV_FETCH_NUMERIC);

		if (!$this->fields)
			return false;

		return $this->fields;
	}

	/**
	 * close() only needs to be called if you are worried about using too much
	 * memory while your script is running. All associated result memory for
	 * the specified result identifier will automatically be freed.
	 */
	protected function _close()
	{
		if(is_resource($this->_queryID)) {
			$rez = sqlsrv_free_stmt($this->_queryID);
			$this->_queryID = false;
			return $rez;
		}
		return true;
	}

	// mssql uses a default date like Dec 30 2000 12:00AM
	static function UnixDate($v)
	{
		return ADORecordSet_array_mssqlnative::UnixDate($v);
	}

	static function UnixTimeStamp($v)
	{
		return ADORecordSet_array_mssqlnative::UnixTimeStamp($v);
	}
}


class ADORecordSet_array_mssqlnative extends ADORecordset_mssqlnative {
	public function __construct($id=false,$mode=false)
	{
		parent::__construct($id,$mode);
	}

	// mssql uses a default date like Dec 30 2000 12:00AM
	static function UnixDate($v)
	{

		if (is_numeric(substr($v,0,1))) return parent::UnixDate($v);

		global $ADODB_mssql_mths,$ADODB_mssql_date_order;

		//Dec 30 2000 12:00AM
		if ($ADODB_mssql_date_order == 'dmy') {
			if (!preg_match( "|^([0-9]{1,2})[-/\. ]+([A-Za-z]{3})[-/\. ]+([0-9]{4})|" ,$v, $rr)) {
				return parent::UnixDate($v);
			}
			if ($rr[3] <= TIMESTAMP_FIRST_YEAR) return 0;

			$theday = $rr[1];
			$themth =  substr(strtoupper($rr[2]),0,3);
		} else {
			if (!preg_match( "|^([A-Za-z]{3})[-/\. ]+([0-9]{1,2})[-/\. ]+([0-9]{4})|" ,$v, $rr)) {
				return parent::UnixDate($v);
			}
			if ($rr[3] <= TIMESTAMP_FIRST_YEAR) return 0;

			$theday = $rr[2];
			$themth = substr(strtoupper($rr[1]),0,3);
		}
		$themth = $ADODB_mssql_mths[$themth];
		if ($themth <= 0) return false;
		// h-m-s-MM-DD-YY
		return  adodb_mktime(0,0,0,$themth,$theday,$rr[3]);
	}

	static function UnixTimeStamp($v)
	{

		if (is_numeric(substr($v,0,1))) return parent::UnixTimeStamp($v);

		global $ADODB_mssql_mths,$ADODB_mssql_date_order;

		//Dec 30 2000 12:00AM
		if ($ADODB_mssql_date_order == 'dmy') {
			if (!preg_match( "|^([0-9]{1,2})[-/\. ]+([A-Za-z]{3})[-/\. ]+([0-9]{4}) +([0-9]{1,2}):([0-9]{1,2}) *([apAP]{0,1})|"
			,$v, $rr)) return parent::UnixTimeStamp($v);
			if ($rr[3] <= TIMESTAMP_FIRST_YEAR) return 0;

			$theday = $rr[1];
			$themth =  substr(strtoupper($rr[2]),0,3);
		} else {
			if (!preg_match( "|^([A-Za-z]{3})[-/\. ]+([0-9]{1,2})[-/\. ]+([0-9]{4}) +([0-9]{1,2}):([0-9]{1,2}) *([apAP]{0,1})|"
			,$v, $rr)) return parent::UnixTimeStamp($v);
			if ($rr[3] <= TIMESTAMP_FIRST_YEAR) return 0;

			$theday = $rr[2];
			$themth = substr(strtoupper($rr[1]),0,3);
		}

		$themth = $ADODB_mssql_mths[$themth];
		if ($themth <= 0) return false;

		switch (strtoupper($rr[6])) {
		case 'P':
			if ($rr[4]<12) $rr[4] += 12;
			break;
		case 'A':
			if ($rr[4]==12) $rr[4] = 0;
			break;
		default:
			break;
		}
		// h-m-s-MM-DD-YY
		return  adodb_mktime($rr[4],$rr[5],0,$themth,$theday,$rr[3]);
	}
}

/*
Code Example 1:

select	object_name(constid) as constraint_name,
		object_name(fkeyid) as table_name,
		col_name(fkeyid, fkey) as column_name,
	object_name(rkeyid) as referenced_table_name,
	col_name(rkeyid, rkey) as referenced_column_name
from sysforeignkeys
where object_name(fkeyid) = x
order by constraint_name, table_name, referenced_table_name,  keyno

Code Example 2:
select	constraint_name,
	column_name,
	ordinal_position
from information_schema.key_column_usage
where constraint_catalog = db_name()
and table_name = x
order by constraint_name, ordinal_position

http://www.databasejournal.com/scripts/article.php/1440551
*/
