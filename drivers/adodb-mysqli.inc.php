<?php
/*
@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
  Set tabs to 8.

  This is the preferred driver for MySQL connections, and supports both transactional
  and non-transactional table types. You can use this as a drop-in replacement for both
  the mysql and mysqlt drivers. As of ADOdb Version 5.20.0, all other native MySQL drivers
  are deprecated

  Requires mysql client. Works on Windows and Unix.

21 October 2003: MySQLi extension implementation by Arjen de Rijke (a.de.rijke@xs4all.nl)
Based on adodb 3.40
*/

// security - hide paths
if (!defined('ADODB_DIR')) {
	die();
}

if (!defined("_ADODB_MYSQLI_LAYER")) {
	define("_ADODB_MYSQLI_LAYER", 1);

// PHP5 compat...
if (! defined("MYSQLI_BINARY_FLAG"))  define("MYSQLI_BINARY_FLAG", 128);
if (!defined('MYSQLI_READ_DEFAULT_GROUP')) define('MYSQLI_READ_DEFAULT_GROUP',1);

 // disable adodb extension - currently incompatible.
 global $ADODB_EXTENSION; $ADODB_EXTENSION = false;

/**
 * Class ADODB_mysqli
 */
class ADODB_mysqli extends ADOConnection {
	public  $databaseType = 'mysqli';
	public  $dataProvider = 'mysql';
	public  $hasInsertID = true;
	public  $hasAffectedRows = true;
	public  $metaTablesSQL = "SELECT
			TABLE_NAME,
			CASE WHEN TABLE_TYPE = 'VIEW' THEN 'V' ELSE 'T' END
		FROM INFORMATION_SCHEMA.TABLES
		WHERE TABLE_SCHEMA=";
	public  $metaColumnsSQL = "SHOW COLUMNS FROM `%s`";
	public  $fmtTimeStamp = "'Y-m-d H:i:s'";
	public  $hasLimit = true;
	public  $hasMoveFirst = true;
	public  $hasGenID = true;
	public  $isoDates = true; // accepts dates in ISO format
	public  $hasTransactions = true;
	public  $forceNewConnect = false;
	public  $poorAffectedRows = true;
	public  $clientFlags = 0;
	public  $substr = "substring";
	public  $port = 3306; //Default to 3306 to fix HHVM bug
	public  $socket = ''; //Default to empty string to fix HHVM bug
	protected  $_bindInputArray = false;
	public  $optionFlags = array(array(MYSQLI_READ_DEFAULT_GROUP,0));
	public  $arrayClass = 'ADORecordSet_array_mysqli';
	public  $multiQuery = false;
	public $ssl_key = null;		//ADODB_mysqli specific
	public $ssl_cert = null;	//ADODB_mysqli specific
	public $ssl_ca = null;		//ADODB_mysqli specific
	public $ssl_capath = null;	//ADODB_mysqli specific
	public $ssl_cipher = null;	//ADODB_mysqli specific

	/**
	 * Tells the insert_id method how to obtain the last value, depending on whether
	 * we are using a stored procedure or not
	 */
	public function __construct()
	{
		// if(!extension_loaded("mysqli"))
		//trigger_error("You must have the mysqli extension installed.", E_USER_ERROR);
	}

	/**
	 * Sets the isolation level of a transaction.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:settransactionmode
	 *
	 * @param string $transaction_mode The transaction mode to set.
	 *
	 * @return void
	 */
	public function SetTransactionMode( $transaction_mode )
	{
		$this->_transmode = $transaction_mode;
		if (empty($transaction_mode)) {
			$this->Execute('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			return;
		}
		if (!stristr($transaction_mode,'isolation')) $transaction_mode = 'ISOLATION LEVEL '.$transaction_mode;
		$this->Execute("SET SESSION TRANSACTION ".$transaction_mode);
	}

	/**
	 * Connect to a database.
	 *
	 * @todo add: parameter int $port, parameter string $socket
	 *
	 * @param string|null $argHostname (Optional) The host to connect to.
	 * @param string|null $argUsername (Optional) The username to connect as.
	 * @param string|null $argPassword (Optional) The password to connect with.
	 * @param string|null $argDatabasename (Optional) The name of the database to start in when connected.
	 * @param bool $persist (Optional) Whether or not to use a persistent connection.
	 *
	 * @return bool|null True if connected successfully, false if connection failed, or null if the mysqli extension
	 * isn't currently loaded.
	 */
	protected function _connect($argHostname = NULL,
					  $argUsername = null,
					  $argPassword = null,
					  $argDatabasename = null,
					  $persist = false)
	{
		if(!extension_loaded("mysqli")) {
			return null;
		}
		$this->_connectionID = @mysqli_init();

		if (is_null($this->_connectionID)) {
			// mysqli_init only fails if insufficient memory
			if ($this->debug) {
				ADOConnection::outp("mysqli_init() failed : "  . $this->ErrorMsg());
			}
			return false;
		}
		/*
		I suggest a simple fix which would enable adodb and mysqli driver to
		read connection options from the standard mysql configuration file
		/etc/my.cnf - "Bastien Duclaux" <bduclaux#yahoo.com>
		*/
		foreach($this->optionFlags as $arr) {
			mysqli_options($this->_connectionID,$arr[0],$arr[1]);
		}

		/*
		* Now merge in the standard connection parameters setting
		*/
		foreach ($this->connectionParameters as $options)
		{
			foreach($options as $k=>$v)
				$ok = mysqli_options($this->_connectionID,$k,$v);
		}

		//https://php.net/manual/en/mysqli.persistconns.php
		if ($persist && PHP_VERSION > 5.2 && strncmp($argHostname,'p:',2) != 0) {
			$argHostname = 'p:' . $argHostname;
		}

		// SSL Connections for MySQLI
		if ($this->ssl_key || $this->ssl_cert || $this->ssl_ca || $this->ssl_capath || $this->ssl_cipher) {
			mysqli_ssl_set($this->_connectionID, $this->ssl_key, $this->ssl_cert, $this->ssl_ca, $this->ssl_capath, $this->ssl_cipher);
		}

		//#if (!empty($this->port)) $argHostname .= ":".$this->port;
		$ok = @mysqli_real_connect($this->_connectionID,
					$argHostname,
					$argUsername,
					$argPassword,
					$argDatabasename,
					//# PHP7 compat: port must be int. Use default port if cast yields zero
					(int)$this->port != 0 ? (int)$this->port : 3306,
					$this->socket,
					$this->clientFlags);

		if ($ok) {
			if ($argDatabasename)  return $this->SelectDB($argDatabasename);
			return true;
		} else {
			if ($this->debug) {
				ADOConnection::outp("Could not connect : "  . $this->ErrorMsg());
			}
			$this->_connectionID = null;
			return false;
		}
	}

	/**
	 * Connect to a database with a persistent connection.
	 *
	 * @param string|null $argHostname The host to connect to.
	 * @param string|null $argUsername The username to connect as.
	 * @param string|null $argPassword The password to connect with.
	 * @param string|null $argDatabasename The name of the database to start in when connected.
	 *
	 * @return bool|null True if connected successfully, false if connection failed, or null if the mysqli extension
	 * isn't currently loaded.
	 */
	protected function _pconnect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		return $this->_connect($argHostname, $argUsername, $argPassword, $argDatabasename, true);
	}

	/**
	 * Connect to a database, whilst setting $this->forceNewConnect to true.
	 *
	 * When is this used? Close old connection first?
	 * In _connect(), check $this->forceNewConnect?
	 *
	 * @param string|null $argHostname The host to connect to.
	 * @param string|null $argUsername The username to connect as.
	 * @param string|null $argPassword The password to connect with.
	 * @param string|null $argDatabasename The name of the database to start in when connected.
	 *
	 * @return bool|null True if connected successfully, false if connection failed, or null if the mysqli extension
	 * isn't currently loaded.
	 */
	protected function _nconnect($argHostname, $argUsername, $argPassword, $argDatabasename)
	{
		$this->forceNewConnect = true;
		return $this->_connect($argHostname, $argUsername, $argPassword, $argDatabasename);
	}

	/**
	 * Replaces a null value with a specified replacement.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:ifnull
	 *
	 * @param mixed $field The field in the table to check.
	 * @param mixed $ifNull The value to replace the null value with if it is found.
	 *
	 * @return string
	 */
	public function IfNull( $field, $ifNull )
	{
		return " IFNULL($field, $ifNull) ";
	}

	/**
	 * Retrieves the first column of the first matching row of an executed SQL statement.
	 *
	 * Note: do not use $ADODB_COUNTRECS
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:getone
	 *
	 * @param string $sql The SQL to execute.
	 * @param bool|array $inputarr (Optional) An array containing any required SQL parameters, or false if none needed.
	 *
	 * @return bool|array|null
	 */
	public function GetOne($sql,$inputarr=false)
	{
		global $ADODB_GETONE_EOF;

		$ret = false;
		$rs = $this->Execute($sql,$inputarr);
		if ($rs) {
			if ($rs->EOF) $ret = $ADODB_GETONE_EOF;
			else $ret = reset($rs->fields);
			$rs->Close();
		}
		return $ret;
	}

	/**
	 * Get information about the current MySQL server.
	 *
	 * @return array
	 */
	public function ServerInfo()
	{
		$arr['description'] = $this->GetOne("select version()");
		$arr['version'] = ADOConnection::_findvers($arr['description']);
		return $arr;
	}

	/**
	 * Begins a granular transaction.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:begintrans
	 *
	 * @return bool Always returns true.
	 */
	public function BeginTrans()
	{
		if ($this->transOff) return true;
		$this->transCnt += 1;

		//$this->Execute('SET AUTOCOMMIT=0');
		mysqli_autocommit($this->_connectionID, false);
		$this->Execute('BEGIN');
		return true;
	}

	/**
	 * Commits a granular transaction.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:committrans
	 *
	 * @param bool $ok (Optional) If false, will rollback the transaction instead.
	 *
	 * @return bool Always returns true.
	 */
	public function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if (!$ok) return $this->RollbackTrans();

		if ($this->transCnt) $this->transCnt -= 1;
		$this->Execute('COMMIT');

		//$this->Execute('SET AUTOCOMMIT=1');
		mysqli_autocommit($this->_connectionID, true);
		return true;
	}

	/**
	 * Rollback a smart transaction.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:rollbacktrans
	 *
	 * @return bool Always returns true.
	 */
	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$this->Execute('ROLLBACK');
		//$this->Execute('SET AUTOCOMMIT=1');
		mysqli_autocommit($this->_connectionID, true);
		return true;
	}

	/**
	 * Appropriately quotes strings with ' characters for insertion into the database.
	 *
	 * Original comment:  	Quotes a string to be sent to the database 
	 * 						When there is no active connection
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:qstr
	 *
	 * @param string $s The string to quote
	 * @param boolean $magic_quotes If false, use mysqli_real_escape_string()
	 *     if you are quoting a string extracted from a POST/GET variable,
	 *     then pass get_magic_quotes_gpc() as the second parameter. This will
	 *     ensure that the variable is not quoted twice, once by qstr() and
	 *     once by the magic_quotes_gpc.
	 *     Eg. $s = $db->qstr(_GET['name'],get_magic_quotes_gpc());
	 *
	 * @return string Quoted string
	 */
	public function qstr($s, $magic_quotes = false)
	{
		if (is_null($s)) return 'NULL';
		if (!$magic_quotes) {
			// mysqli_real_escape_string() throws a warning when the given
			// connection is invalid
			if ($this->_connectionID) {
				return "'" . mysqli_real_escape_string($this->_connectionID, $s) . "'";
			}

			if ($this->replaceQuote[0] == '\\') {
				$s = str_replace(array('\\',"\0"), array('\\\\',"\\\0") ,$s);
			}
			return "'" . str_replace("'", $this->replaceQuote, $s) . "'";
		}
		// undo magic quotes for "
		$s = str_replace('\\"','"',$s);
		return "'$s'";
	}

	/**
	 * Return the AUTO_INCREMENT id of the last row that has been inserted or updated in a table.
	 *
	 * @return int|string
	 */
	protected function _insertid()
	{
		$result = @mysqli_insert_id($this->_connectionID);
		if ($result == -1) {
			if ($this->debug) ADOConnection::outp("mysqli_insert_id() failed : "  . $this->ErrorMsg());
		}
		elseif ($result == 0) {
			$result = ADOConnection::GetOne('SELECT LAST_INSERT_ID()');
		}
		return $result;
	}

	/**
	 * Returns how many rows were effected by the most recently executed SQL statement.
	 * Only works for INSERT, UPDATE and DELETE queries.
	 *
	 * @return int The number of rows affected.
	 */
	protected function _affectedrows()
	{
		$result =  @mysqli_affected_rows($this->_connectionID);
		if ($result == -1) {
			if ($this->debug) ADOConnection::outp("mysqli_affected_rows() failed : "  . $this->ErrorMsg());
		}
		return $result;
	}

	public function MetaDatabases()
	{
		$query = "SHOW DATABASES";
		$ret = $this->Execute($query);
		if ($ret && is_object($ret)){
			$arr = array();
			while (!$ret->EOF){
				$db = $ret->Fields('Database');
				if ($db != 'mysql') $arr[] = $db;
				$ret->MoveNext();
			}
			return $arr;
		}
		return $ret;
	}

	protected function _MetaIndexes ($pParsedTableName, $primary = FALSE, $owner=false)
	{
		$false = false;
		$vSchema = @$pParsedTableName['schema']['name'];
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$rs = NULL;

		// get index details
		if(empty($vSchema))
			{$rs = $this->Execute(sprintf('SHOW INDEX FROM `%s`',$table));}
		else
			{$rs = $this->Execute(sprintf('SHOW INDEX FROM `%s`.`%s`', $vSchema, $table));}

		// restore fetchmode
		$this->SetFetchMode2($savem);

		if (!is_object($rs)) {
			return $false;
		}

		$indexes = array ();

		// parse index data into array
		while ($row = $rs->FetchRow()) {
			if ($primary == FALSE AND $row[2] == 'PRIMARY') {
				continue;
			}

			if (!isset($indexes[$row[2]])) {
				$indexes[$row[2]] = array(
					'unique' => ($row[1] == 0),
					'columns' => array()
				);
			}

			$indexes[$row[2]]['columns'][$row[3] - 1] = $row[4];
		}

		// sort columns by order in the index
		foreach ( array_keys ($indexes) as $index )
		{
			ksort ($indexes[$index]['columns']);
		}

		return $indexes;
	}

	/**
	 * Returns a database-specific concatenation of strings.
	 *
	 * Much easier to run "mysqld --ansi" or "mysqld --sql-mode=PIPES_AS_CONCAT" and use || operator
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:concat
	 *
	 * @return string
	 */
	public function Concat()
	{
		$arr = func_get_args();

		// suggestion by andrew005@mnogo.ru
		$s = implode(',',$arr);
		if (strlen($s) > 0) return "CONCAT($s)";
		else return '';
	}

	/**
	 * Creates a portable date offset field, for use in SQL statements.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:offsetdate
	 *
	 * @param float $dayFraction A day in floating point
	 * @param string|bool $date (Optional) The date to offset. If false, uses CURDATE()
	 *
	 * @return string
	 */
	public function OffsetDate($dayFraction,$date=false)
	{
		if (!$date) $date = $this->sysDate;

		$fraction = $dayFraction * 24 * 3600;
		return $date . ' + INTERVAL ' .	 $fraction.' SECOND';

//		return "from_unixtime(unix_timestamp($date)+$fraction)";
	}

	/**
	 * Returns information about stored procedures and stored functions.
	 *
	 * @param string|bool $NamePattern (Optional) Only look for procedures/functions with a name matching this pattern.
	 * @param null $catalog (Optional) Unused.
	 * @param null $schemaPattern (Optional) Unused.
	 *
	 * @return array
	 */
	public function MetaProcedures($NamePattern = false, $catalog  = null, $schemaPattern  = null)
	{

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$procedures = array ();

		// get index details

		$likepattern = '';
		if ($NamePattern) {
			$likepattern = " LIKE '".$NamePattern."'";
		}
		$rs = $this->Execute('SHOW PROCEDURE STATUS'.$likepattern);
		if (is_object($rs)) {

			// parse index data into array
			while ($row = $rs->FetchRow()) {
				$procedures[$row[1]] = array(
					'type' => 'PROCEDURE',
					'catalog' => '',
					'schema' => '',
					'remarks' => $row[7],
				);
			}
		}

		$rs = $this->Execute('SHOW FUNCTION STATUS'.$likepattern);
		if (is_object($rs)) {
			// parse index data into array
			while ($row = $rs->FetchRow()) {
				$procedures[$row[1]] = array(
					'type' => 'FUNCTION',
					'catalog' => '',
					'schema' => '',
					'remarks' => $row[7]
				);
			}
		}

		// restore fetchmode
		$this->SetFetchMode2($savem);

		return $procedures;
	}

	/**
	 * Retrieves a list of tables based on given criteria
	 *
	 * @param string|bool $ttype (Optional) Table type = 'TABLE', 'VIEW' or false=both (default)
	 * @param string|bool $showSchema (Optional) schema name, false = current schema (default)
	 * @param string|bool $mask (Optional) filters the table by name
	 *
	 * @return array list of tables
	 */
	public function MetaTables($ttype = false, $showSchema = false, $mask = false)
	{
		$save = $this->metaTablesSQL;
		if ($showSchema && is_string($showSchema)) {
			$this->metaTablesSQL .= $this->qstr($showSchema);
		} else {
			$this->metaTablesSQL .= "schema()";
		}

		if ($mask) {
			$mask = $this->qstr($mask);
			$this->metaTablesSQL .= " AND table_name LIKE $mask";
		}
		$ret = ADOConnection::MetaTables($ttype,$showSchema);

		$this->metaTablesSQL = $save;
		return $ret;
	}

	/**
	 * Return information about a table's foreign keys.
	 *
	 * "Innox - Juan Carlos Gonzalez" <jgonzalez#innox.com.mx>
	 *
	 * @param string $table The name of the table to get the foreign keys for.
	 * @param string|bool $owner (Optional) The database the table belongs to, or false to assume the current db.
	 * @param string|bool $upper (Optional) Force uppercase table name on returned array keys.
	 * @param bool $associative (Optional) Whether to return an associate or numeric array.
	 *
	 * @return array|bool An array of foreign keys, or false no foreign keys could be found.
	 */
	public function MetaForeignKeys( $table, $owner = FALSE, $upper = FALSE, $associative = FALSE )
	{
		if ($this->GetFetchMode() == ADODB_FETCH_ASSOC)
			$associative = true;

		$savem = $this->SetFetchMode2(ADODB_FETCH_ASSOC);

		if ( !empty($owner) ) {
			$table = "$owner.$table";
		}

		$a_create_table = $this->getRow(sprintf('SHOW CREATE TABLE %s', $table));

		$this->SetFetchMode2($savem);

		$create_sql = isset($a_create_table["Create Table"]) ? $a_create_table["Create Table"] : $a_create_table["Create View"];

		$matches = array();

		if (!preg_match_all("/FOREIGN KEY \(`(.*?)`\) REFERENCES `(.*?)` \(`(.*?)`\)/", $create_sql, $matches)) return false;
		$foreign_keys = array();
		$num_keys = count($matches[0]);
		for ( $i = 0; $i < $num_keys; $i ++ ) {
			$my_field  = explode('`, `', $matches[1][$i]);
			$ref_table = $matches[2][$i];
			$ref_field = explode('`, `', $matches[3][$i]);

			if ( $upper ) {
				$ref_table = strtoupper($ref_table);
			}

			// see https://sourceforge.net/p/adodb/bugs/100/
			if (!isset($foreign_keys[$ref_table])) {
				$foreign_keys[$ref_table] = array();
			}
			$num_fields = count($my_field);
			for ( $j = 0; $j < $num_fields; $j ++ ) {
				if ( $associative ) {
					$foreign_keys[$ref_table][$ref_field[$j]] = $my_field[$j];
				} else {
					$foreign_keys[$ref_table][] = "{$my_field[$j]}={$ref_field[$j]}";
				}
			}
		}

		return $foreign_keys;
	}

	//verbatim from adodb-mysql.inc.php
	protected function _MetaColumns($pParsedTableName)
	{
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$schema = @$pParsedTableName['schema']['name'];
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
			$fld->name = $rs->fields[0];
			$type = $rs->fields[1];

			// split type into type(length):
			$fld->scale = null;
			if (preg_match("/^(.+)\((\d+),(\d+)/", $type, $query_array)) {
				$fld->type = $query_array[1];
				$fld->max_length = is_numeric($query_array[2]) ? $query_array[2] : -1;
				$fld->scale = is_numeric($query_array[3]) ? $query_array[3] : -1;
			} elseif (preg_match("/^(.+)\((\d+)/", $type, $query_array)) {
				$fld->type = $query_array[1];
				$fld->max_length = is_numeric($query_array[2]) ? $query_array[2] : -1;
			} elseif (preg_match("/^(enum)\((.*)\)$/i", $type, $query_array)) {
				$fld->type = $query_array[1];
				$arr = explode(",",$query_array[2]);
				$fld->enums = $arr;
				$zlen = max(array_map("strlen",$arr)) - 2; // PHP >= 4.0.6
				$fld->max_length = ($zlen > 0) ? $zlen : 1;
			} else {
				$fld->type = $type;
				$fld->max_length = -1;
			}
			$fld->not_null = ($rs->fields[2] != 'YES');
			$fld->primary_key = ($rs->fields[3] == 'PRI');
			$fld->auto_increment = (strpos($rs->fields[5], 'auto_increment') !== false);
			$fld->binary = (strpos($type,'blob') !== false || strpos($type,'binary') !== false);
			$fld->unsigned = (strpos($type,'unsigned') !== false);
			$fld->zerofill = (strpos($type,'zerofill') !== false);

			if (!$fld->binary) {
				$d = $rs->fields[4];
				if ($d != '' && $d != 'NULL') {
					$fld->has_default = true;
					$fld->default_value = $d;
				} else {
					$fld->has_default = false;
				}
			}

			if ($this->GetFetchMode() == ADODB_FETCH_NUM) {
				$retarr[] = $fld;
			} else {
				$retarr[strtoupper($fld->name)] = $fld;
			}
			$rs->MoveNext();
		}

		$rs->Close();
		return $retarr;
	}

	/**
	 * Select which database to connect to.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:selectdb
	 *
	 * @param string $dbName The name of the database to select.
	 *
	 * @return bool True if the database was selected successfully, otherwise false.
	 */
	public function SelectDB($dbName)
	{
//		$this->_connectionID = $this->mysqli_resolve_link($this->_connectionID);
		$this->database = $dbName;
		$this->databaseName = $dbName; # obsolete, retained for compat with older adodb versions

		if ($this->_connectionID) {
			$result = @mysqli_select_db($this->_connectionID, $dbName);
			if (!$result) {
				ADOConnection::outp("Select of database " . $dbName . " failed. " . $this->ErrorMsg());
			}
			return $result;
		}
		return false;
	}

	/**
	 * Executes a provided SQL statement and returns a handle to the result, with the ability to supply a starting
	 * offset and record count.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:selectlimit
	 *
	 * Original comment: parameters use PostgreSQL convention, not MySQL
	 *
	 * @param string $sql The SQL to execute.
	 * @param int $nrows (Optional) The limit for the number of records you want returned. By default, all results.
	 * @param int $offset (Optional) The offset to use when selecting the results. By default, no offset.
	 * @param array|bool $inputarr (Optional) Any parameter values required by the SQL statement, or false if none.
	 * @param int $secs (Optional) If greater than 0, perform a cached execute. By default, normal execution.
	 *
	 * @return ADORecordSet|false The query results, or false if the query failed to execute.
	 */
	public function SelectLimit($sql,
						 $nrows = -1,
						 $offset = -1,
						 $inputarr = false,
						 $secs = 0)
	{
		$nrows = (int) $nrows;
		$offset = (int) $offset;
		$offsetStr = ($offset >= 0) ? "$offset," : '';
		if ($nrows < 0) $nrows = '18446744073709551615';

		if ($secs)
			$rs = $this->CacheExecute($secs, $sql . " LIMIT $offsetStr$nrows" , $inputarr );
		else
			$rs = $this->Execute($sql . " LIMIT $offsetStr$nrows" , $inputarr );

		return $rs;
	}

	/**
	 * Prepares an SQL statement and returns a handle to use.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:prepare
	 * @todo update this function to handle prepared statements correctly
	 *
	 * @param string $sql The SQL to prepare.
	 *
	 * @return string The original SQL that was provided.
	 */
	public function Prepare($sql)
	{
		return $sql;
		$stmt = $this->_connectionID->prepare($sql);
		if (!$stmt) {
			echo $this->ErrorMsg();
			return $sql;
		}
		return array($sql,$stmt);
	}

	/**
	 * Return the query id.
	 *
	 * @param string|array $sql
	 * @param array $inputarr
	 *
	 * @return bool|mysqli_result
	 */
	public function _query($sql, $inputarr)
	{
		global $ADODB_COUNTRECS;
		// Move to the next recordset, or return false if there is none. In a stored proc
		// call, mysqli_next_result returns true for the last "recordset", but mysqli_store_result
		// returns false. I think this is because the last "recordset" is actually just the
		// return value of the stored proc (ie the number of rows affected).
		// Commented out for reasons of performance. You should retrieve every recordset yourself.
		//	if (!mysqli_next_result($this->connection->_connectionID))	return false;

		if (is_array($sql)) {

			// Prepare() not supported because mysqli_stmt_execute does not return a recordset, but
			// returns as bound variables.

			$stmt = $sql[1];
			$a = '';
			foreach($inputarr as $k => $v) {
				if (is_string($v)) $a .= 's';
				else if (is_integer($v)) $a .= 'i';
				else $a .= 'd';
			}

			$fnarr = array_merge( array($stmt,$a) , $inputarr);
			call_user_func_array('mysqli_stmt_bind_param',$fnarr);
			$ret = mysqli_stmt_execute($stmt);
			return $ret;
		}

		/*
		if (!$mysql_res =  mysqli_query($this->_connectionID, $sql, ($ADODB_COUNTRECS) ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT)) {
			if ($this->debug) ADOConnection::outp("Query: " . $sql . " failed. " . $this->ErrorMsg());
			return false;
		}

		return $mysql_res;
		*/

		if ($this->multiQuery) {
			$rs = mysqli_multi_query($this->_connectionID, $sql.';');
			if ($rs) {
				$rs = ($ADODB_COUNTRECS) ? @mysqli_store_result( $this->_connectionID ) : @mysqli_use_result( $this->_connectionID );
				return $rs ? $rs : true; // mysqli_more_results( $this->_connectionID )
			}
		} else {
			$rs = mysqli_query($this->_connectionID, $sql, $ADODB_COUNTRECS ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT);

			if ($rs) return $rs;
		}

		if($this->debug)
			ADOConnection::outp("Query: " . $sql . " failed. " . $this->ErrorMsg());

		return false;

	}

	/**
	 * Returns a database specific error message.
	 *
	 * Original comment: Returns: the last error message from previous database operation
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:errormsg
	 *
	 * @return string The last error message.
	 */
	public function ErrorMsg()
	{
		if (empty($this->_connectionID))
			$this->_errorMsg = @mysqli_connect_error();
		else
			$this->_errorMsg = @mysqli_error($this->_connectionID);
		return $this->_errorMsg;
	}

	/**
	 * Returns the last error number from previous database operation.
	 *
	 * @return int The last error number.
	 */
	public function ErrorNo()
	{
		if (empty($this->_connectionID))
			return @mysqli_connect_errno();
		else
			return @mysqli_errno($this->_connectionID);
	}

	/**
	 * Close the database connection.
	 *
	 * Original comment: returns true or false   [THIS IS CLEARLY INVALID FOR THE CODE BELOW, BUT HELP DEBUG FUTURE ERRORS]
	 *
	 * @return void
	 */
	protected function _close()
	{
		if($this->_connectionID) {
			mysqli_close($this->_connectionID);
		}
		$this->_connectionID = false;
	}

	/**
	 * Returns the largest length of data that can be inserted into a character field.
	 *
	 * Original comment: Maximum size of C field
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:charmax
	 *
	 * @return int
	 */
	public function CharMax()
	{
		return 255;
	}

	/**
	 * Returns the largest length of data that can be inserted into a text field.
	 *
	 * Original comment: Maximum size of X field
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:textmax
	 *
	 * @return int
	 */
	public function TextMax()
	{
		return 4294967295;
	}

	/**
	 * Get the name of the character set the client connection is using now.
	 *
	 * this is a set of functions for managing client encoding - very important if the encodings
	 * of your database and your output target (i.e. HTML) don't match
	 * for instance, you may have UTF8 database and server it on-site as latin1 etc.
	 * GetCharSet - get the name of the character set the client is using now
	 * Under Windows, the functions should work with MySQL 4.1.11 and above, the set of charsets supported
	 * depends on compile flags of mysql distribution
	 *
	 * @return string|bool The name of the character set, or false if it can't be determined.
	 */
	public function GetCharSet()
	{
		//we will use ADO's builtin property charSet
		if (!method_exists($this->_connectionID,'character_set_name'))
			return false;

		$this->charSet = @$this->_connectionID->character_set_name();
		if (!$this->charSet) {
			return false;
		} else {
			return $this->charSet;
		}
	}

	/**
	 * Sets the character set for database connections (limited databases).
	 *
	 * Original comment: SetCharSet - switch the client encoding
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:setcharset
	 *
	 * @param string $charset_name The character set to switch to.
	 *
	 * @return bool True if the character set was changed successfully, otherwise false.
	 */
	public function SetCharSet($charset_name)
	{
		if (!method_exists($this->_connectionID,'set_charset')) {
			return false;
		}

		if ($this->charSet !== $charset_name) {
			$if = @$this->_connectionID->set_charset($charset_name);
			return ($if === true & $this->GetCharSet() == $charset_name);
		} else {
			return true;
		}
	}

}

/**
 * Class ADORecordSet_mysqli
 */
class ADORecordSet_mysqli extends ADORecordSet{

	public  $databaseType = "mysqli";
	public  $canSeek = true;

	protected function _initrs()
	{
	global $ADODB_COUNTRECS;

		$this->_numOfRows = $ADODB_COUNTRECS ? @mysqli_num_rows($this->_queryID) : -1;
		$this->_numOfFields = @mysqli_num_fields($this->_queryID);
	}

/*
1      = MYSQLI_NOT_NULL_FLAG
2      = MYSQLI_PRI_KEY_FLAG
4      = MYSQLI_UNIQUE_KEY_FLAG
8      = MYSQLI_MULTIPLE_KEY_FLAG
16     = MYSQLI_BLOB_FLAG
32     = MYSQLI_UNSIGNED_FLAG
64     = MYSQLI_ZEROFILL_FLAG
128    = MYSQLI_BINARY_FLAG
256    = MYSQLI_ENUM_FLAG
512    = MYSQLI_AUTO_INCREMENT_FLAG
1024   = MYSQLI_TIMESTAMP_FLAG
2048   = MYSQLI_SET_FLAG
32768  = MYSQLI_NUM_FLAG
16384  = MYSQLI_PART_KEY_FLAG
32768  = MYSQLI_GROUP_FLAG
65536  = MYSQLI_UNIQUE_FLAG
131072 = MYSQLI_BINCMP_FLAG
*/

	/**
	 * Returns raw, database specific information about a field.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:recordset:fetchfield
	 *
	 * @param int $fieldOffset (Optional) The field number to get information for.
	 *
	 * @return ADOFieldObject|bool
	 */
	protected function _FetchField($fieldOffset = -1)
	{
		$fieldnr = $fieldOffset;
		if ($fieldOffset != -1) {
			$fieldOffset = @mysqli_field_seek($this->_queryID, $fieldnr);
		}
		$o = @mysqli_fetch_field($this->_queryID);
		if (!$o) return false;

		//Fix for HHVM
		if ( !isset($o->flags) ) {
			$o->flags = 0;
		}
		/* Properties of an ADOFieldObject as set by MetaColumns */
		$o->primary_key = $o->flags & MYSQLI_PRI_KEY_FLAG;
		$o->not_null = $o->flags & MYSQLI_NOT_NULL_FLAG;
		$o->auto_increment = $o->flags & MYSQLI_AUTO_INCREMENT_FLAG;
		$o->binary = $o->flags & MYSQLI_BINARY_FLAG;
		// $o->blob = $o->flags & MYSQLI_BLOB_FLAG; /* not returned by MetaColumns */
		$o->unsigned = $o->flags & MYSQLI_UNSIGNED_FLAG;

		/*
		* Trivial method to cast class to ADOfieldObject
		*/
		$a = new ADOFieldObject;
		$a->FillFromObject($o);
		
		return $a;
	}

		/**
	 * Adjusts the result pointer to an arbitrary row in the result.
	 *
	 * @param int $row The row to seek to.
	 *
	 * @return bool False if the recordset contains no rows, otherwise true.
	 */
	protected function _seek($row)
	{
		if ($this->_numOfRows == 0 || $row < 0) {
			return false;
		}

		mysqli_data_seek($this->_queryID, $row);
		$this->EOF = false;
		return true;
	}

	/**
	 * In databases that allow accessing of recordsets, retrieves the next set.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:recordset:nextrecordset
	 *
	 * @return bool
	 */
	protected function _NextRecordSet()
	{
		global $ADODB_COUNTRECS;

		mysqli_free_result($this->_queryID);
		$this->_queryID = false;
		// Move to the next recordset, or return false if there is none. In a stored proc
		// call, mysqli_next_result returns true for the last "recordset", but mysqli_store_result
		// returns false. I think this is because the last "recordset" is actually just the
		// return value of the stored proc (ie the number of rows affected).
		if (!mysqli_next_result($this->connection->_connectionID)) {
			return false;
		}

		// CD: There is no $this->_connectionID variable, at least in the ADO version I'm using
		$this->_queryID = ($ADODB_COUNTRECS) ? @mysqli_store_result($this->connection->_connectionID)
			: @mysqli_use_result($this->connection->_connectionID);

		if (!$this->_queryID) {
			return false;
		}

		return true;
	}

	/**
	 * Moves the cursor to the next record of the recordset from the current position.
	 *
	 * Note: 10% speedup to move MoveNext to child class
	 * 		This is the only implementation that works now (23-10-2003).
	 * 		Other functions return no or the wrong results.
	 *
	 * @link https://adodb.org/dokuwiki/doku.php?id=v5:reference:connection:movenext
	 *
	 * @return bool False if there are no more records to move on to, otherwise true.
	 */
	protected function _MoveNext()
	{
		if ($this->EOF) return false;
		$this->_currentRow++;
		$this->bind = false;
		$this->fields = @mysqli_fetch_array($this->_queryID,$this->mysqli_getDriverFetchMode());

		if (is_array($this->fields)) {
			return true;
		}
		$this->EOF = true;
		return false;
	}

	/**
	 * Attempt to fetch a result row using the current fetch mode and return whether or not this was successful.
	 *
	 * @return bool True if row was fetched successfully, otherwise false.
	 */
	protected function _fetch()
	{
		$this->bind = false;
		$this->fields = mysqli_fetch_array($this->_queryID,$this->mysqli_getDriverFetchMode());
		return is_array($this->fields);
	}

	/**
	 * Frees the memory associated with a result.
	 *
	 * @return void
	 */
	protected function _close()
	{
		//if results are attached to this pointer from Stored Procedure calls, the next standard query will die 2014
		//only a problem with persistent connections

		if (isset($this->connection->_connectionID) && $this->connection->_connectionID) {
			while (mysqli_more_results($this->connection->_connectionID)) {
				mysqli_next_result($this->connection->_connectionID);
			}
		}

		if ($this->_queryID instanceof mysqli_result) {
			mysqli_free_result($this->_queryID);
		}
		$this->_queryID = false;
	}

/*

0 = MYSQLI_TYPE_DECIMAL
1 = MYSQLI_TYPE_CHAR
1 = MYSQLI_TYPE_TINY
2 = MYSQLI_TYPE_SHORT
3 = MYSQLI_TYPE_LONG
4 = MYSQLI_TYPE_FLOAT
5 = MYSQLI_TYPE_DOUBLE
6 = MYSQLI_TYPE_NULL
7 = MYSQLI_TYPE_TIMESTAMP
8 = MYSQLI_TYPE_LONGLONG
9 = MYSQLI_TYPE_INT24
10 = MYSQLI_TYPE_DATE
11 = MYSQLI_TYPE_TIME
12 = MYSQLI_TYPE_DATETIME
13 = MYSQLI_TYPE_YEAR
14 = MYSQLI_TYPE_NEWDATE
247 = MYSQLI_TYPE_ENUM
248 = MYSQLI_TYPE_SET
249 = MYSQLI_TYPE_TINY_BLOB
250 = MYSQLI_TYPE_MEDIUM_BLOB
251 = MYSQLI_TYPE_LONG_BLOB
252 = MYSQLI_TYPE_BLOB
253 = MYSQLI_TYPE_VAR_STRING
254 = MYSQLI_TYPE_STRING
255 = MYSQLI_TYPE_GEOMETRY
*/

	/**
	 * Get the MetaType character for a given field type.
	 *
	 * @param string|object $t The type to get the MetaType character for.
	 * @param int $len (Optional) Redundant. Will always be set to -1.
	 * @param bool|object $fieldobj (Optional)
	 *
	 * @return string The MetaType
	 */
	public function MetaType($t, $len = -1, $fieldobj = false)
	{
		if (is_object($t)) {
			$fieldobj = $t;
			$t = $fieldobj->type;
			$len = $fieldobj->max_length;
		}

		$len = -1; // mysql max_length is not accurate
		switch (strtoupper($t)) {
			case 'STRING':
			case 'CHAR':
			case 'VARCHAR':
			case 'TINYBLOB':
			case 'TINYTEXT':
			case 'ENUM':
			case 'SET':

			case MYSQLI_TYPE_TINY_BLOB :
//			case MYSQLI_TYPE_CHAR :
			case MYSQLI_TYPE_STRING :
			case MYSQLI_TYPE_ENUM :
			case MYSQLI_TYPE_SET :
			case 253 :
				if ($len <= $this->blobSize) {
					return 'C';
				}

			case 'TEXT':
			case 'LONGTEXT':
			case 'MEDIUMTEXT':
				return 'X';

			// php_mysql extension always returns 'blob' even if 'text'
			// so we have to check whether binary...
			case 'IMAGE':
			case 'LONGBLOB':
			case 'BLOB':
			case 'MEDIUMBLOB':

			case MYSQLI_TYPE_BLOB :
			case MYSQLI_TYPE_LONG_BLOB :
			case MYSQLI_TYPE_MEDIUM_BLOB :
				return !empty($fieldobj->binary) ? 'B' : 'X';

			case 'YEAR':
			case 'DATE':
			case MYSQLI_TYPE_DATE :
			case MYSQLI_TYPE_YEAR :
				return 'D';

			case 'TIME':
			case 'DATETIME':
			case 'TIMESTAMP':

			case MYSQLI_TYPE_DATETIME :
			case MYSQLI_TYPE_NEWDATE :
			case MYSQLI_TYPE_TIME :
			case MYSQLI_TYPE_TIMESTAMP :
				return 'T';

			case 'INT':
			case 'INTEGER':
			case 'BIGINT':
			case 'TINYINT':
			case 'MEDIUMINT':
			case 'SMALLINT':

			case MYSQLI_TYPE_INT24 :
			case MYSQLI_TYPE_LONG :
			case MYSQLI_TYPE_LONGLONG :
			case MYSQLI_TYPE_SHORT :
			case MYSQLI_TYPE_TINY :
				if (!empty($fieldobj->primary_key)) {
					return 'R';
				}
				return 'I';

			// Added floating-point types
			// Maybe not necessary.
			case 'FLOAT':
			case 'DOUBLE':
	//		case 'DOUBLE PRECISION':
			case 'DECIMAL':
			case 'DEC':
			case 'FIXED':
			default:
				//if (!is_numeric($t)) echo "<p>--- Error in type matching $t -----</p>";
				return ADODB_DEFAULT_METATYPE;
		}
	}
	
	protected function mysqli_getDriverFetchMode()
	{
		switch($this->fetchMode)
		{
			case ADODB_FETCH_NUM:
				return MYSQLI_NUM;
			case ADODB_FETCH_ASSOC:
				return MYSQLI_ASSOC;
			case ADODB_FETCH_BOTH:
			default:
				return MYSQLI_BOTH;
		}
	}

} // rs class



class ADORecordSet_array_mysqli extends ADORecordSet_mysqli {

	public function __construct($id=false,$mode=false)
	{
		parent::__construct($id,$mode);
	}
}

} // if defined _ADODB_MYSQLI_LAYER
