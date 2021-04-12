<?php
/**
  @version   v5.22.0-dev  Unreleased
  @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
  @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Set tabs to 4 for best viewing.

* Driver for use with IBM DB2 Native Client
*
* Originally DB2 drivers were dependent on an ODBC driver, and some installations
* may still use that. To use an ODBC driver connection, use the odbc_db2
* ADOdb driver. For Linux, you need the 'ibm_db2' PECL extension for PHP,
* For Windows, you need to locate an appropriate version of the php_ibm_db2.dll,
* as well as the IBM data server client software.
* This is basically a full rewrite of the original driver, for information 
* about all the changes, see the update information on the ADOdb website 
* for version 5.21.0 
*
* @link http://pecl.php.net/package/ibm_db2 Pecl Extension For DB2
* @author Mark Newnham
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

  define("_ADODB_DB2_LAYER", 2 );

/*--------------------------------------------------------------------
----------------------------------------------------------------------*/


class ADODB_db2 extends ADOConnection {
	public $databaseType = "db2";
	public $fmtDate = "'Y-m-d'";

	public $sysTime = 'CURRENT TIME';//Note: This variable is not used any where in the entirety of this library except in the db2legacy driver

	public $fmtTimeStamp = "'Y-m-d H:i:s'";
	public $replaceQuote = "''"; // string to use to replace quotes
	public $dataProvider = "db2";
	public $hasAffectedRows = true;

	public $binmode = DB2_BINARY;

	/*
	* setting this to true will make array elements in FETCH_ASSOC 
	* mode case-sensitive breaking backward-compat
	*/
	public $useFetchArray = false; 
	protected $_bindInputArray = true;
	protected $_autocommit = true;
	protected $_lastAffectedRows = 0;
	public $hasInsertID = true;
	public $hasGenID    = true;
	
	/*
	 * Executed after successful connection
	 */
	public $connectStmt = '';
	
	/*
	 * Holds the current database name
	 */
	private $databaseName = '';

	/*
	 * Holds information about the stored procedure request
	 * currently being built
	 */
	private $storedProcedureParameters = false;
	 
	

    protected function _insertid()
    {
        return ADOConnection::GetOne('VALUES IDENTITY_VAL_LOCAL()');
    }

	public function _connect($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		return $this->doDB2Connect($argDSN, $argUsername, $argPassword, $argDatabasename);
	}
	
	public function _pconnect($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		return $this->doDB2Connect($argDSN, $argUsername, $argPassword, $argDatabasename,true);
	}
	
	private function doDB2Connect($argDSN, $argUsername, $argPassword, $argDatabasename, $persistent=false)
	{
		global $php_errormsg;

		if (!function_exists('db2_connect')) {
			ADOConnection::outp("DB2 extension not installed.");
			return null;
		}
		
		$connectionParameters = $this->unpackParameters($argDSN, 
													    $argUsername, 
													    $argPassword, 
													    $argDatabasename);
													 
		if ($connectionParameters == null)
		{
			/*
		     * Error thrown
			 */
			return null;
		}
		
		$argDSN 		         = $connectionParameters['dsn'];
		$argUsername 	         = $connectionParameters['uid'];
		$argPassword 	         = $connectionParameters['pwd'];
		$argDatabasename         = $connectionParameters['database'];
		$useCataloguedConnection = $connectionParameters['catalogue'];
		
		if ($this->debug){
			if ($useCataloguedConnection){
				$connectMessage = "Catalogued connection using parameters: ";
				$connectMessage .= "DB=$argDatabasename / ";
				$connectMessage .= "UID=$argUsername / ";
				$connectMessage .= "PWD=$argPassword";
			}
			else
		    {
				$connectMessage = "Uncatalogued connection using DSN: $argDSN";
			}
			ADOConnection::outp($connectMessage);
		}	
		/*
         * This needs to be set before the connect().
		 */
		ini_set('ibm_db2.binmode', $this->binmode);

		if ($persistent)
			$db2Function = 'db2_pconnect';
		else
			$db2Function = 'db2_connect';
		
		/*
		* We need to flatten out the connectionParameters
		*/

		$db2Options = array();
		if ($this->connectionParameters)
		{
			foreach($this->connectionParameters as $p)
				foreach($p as $k=>$v)
					$db2Options[$k] = $v;
		}
		
		if ($useCataloguedConnection)
			$this->_connectionID = $db2Function($argDatabasename,
											    $argUsername,
											    $argPassword,
											    $db2Options);
		else
			$this->_connectionID = $db2Function($argDSN,
											    null,
											    null,
											    $db2Options);
		
		$php_errormsg = '';

		$this->_errorMsg = @db2_conn_errormsg();
		
		if ($this->_connectionID && $this->connectStmt)
			$this->execute($this->connectStmt);

		return $this->_connectionID != false;
		
	}	
	
	/**
	 * Validates and preprocesses the passed parameters for consistency
	 *
	 * @param	string	$argDSN				Either DSN or database
	 * @param	string	$argUsername		User name or null
	 * @param	string	$argPassword		Password or null
	 * @param	string	$argDatabasename	Either DSN or database
	 *
	 * @return mixed  array if correct, null if not
	 */
	private function unpackParameters($argDSN, $argUsername, $argPassword, $argDatabasename)
	{
		
		global $php_errormsg;
		
		$connectionParameters = array('dsn'=>'',
									  'uid'=>'',
									  'pwd'=>'',
									  'database'=>'',
									  'catalogue'=>true
									  );
		
		/*
		 * Uou can either connect to a catalogued connection
         * with a database name e.g. 'SAMPLE'
         * or an uncatalogued connection with a DSN like connection
		 * DATABASE=database;HOSTNAME=hostname;PORT=port;PROTOCOL=TCPIP;UID=username;PWD=password;
		 */
		
		if (!$argDSN && !$argDatabasename)
		{
			$errorMessage = 'Supply either catalogued or uncatalogued connection parameters';
			$this->_errorMsg = $errorMessage;
			if ($this->debug)
				ADOConnection::outp($errorMessage);
			return null;
		}
		
		$useCataloguedConnection = true;
		$schemaName 			 = '';
		
		if ($argDSN && $argDatabasename)
		{
			/*
			 * If a catalogued connection if provided, 
			 * as well as user and password
			 * that will take priority
			 */
			if ($argUsername && $argPassword && !$this->isDsn($argDatabasename))
			{
				if ($this->debug){
					$errorMessage = 'Warning: Because you provided user,';
					$errorMessage.= 'password and database, DSN connection ';
					$errorMessage.= 'parameters were discarded';
					ADOConnection::outp($errorMessage);
				
				}
				$argDSN = '';
			}
			else if ($this->isDsn($argDSN) && $this->isDsn($argDatabasename))
			{
				$errorMessage = 'Supply uncatalogued connection parameters ';
				$errorMessage.= 'in either the database or DSN arguments, ';
				$errorMessage.= 'but not both';
				$php_errormsg = $errorMessage;
				if ($this->debug)
					ADOConnection::outp($errorMessage);
				return null;
			}
		}
				
		if (!$this->isDsn($argDSN) && $this->isDsn($argDatabasename))
		{
			/*
			 * Switch them around for next test
			 */
			$temp           = $argDSN;
			$argDsn         = $argDatabasename;
			$argDatabasenME = $temp;
		}
				
		if ($this->isDsn($argDSN))
		{
			
			if (!preg_match('/uid=/i',$argDSN) 
			||  !preg_match('/pwd=/i',$argDSN))
			{
				$errorMessage = 'For uncatalogued connections, provide ';
				$errorMessage.= 'both UID and PWD in the connection string';
				$php_errormsg = $errorMessage;
				if ($this->debug)
					ADOConnection::outp($errorMessage);
				return null;
			}
			
			if (preg_match('/database=/i',$argDSN))
			{
				if ($argDatabasename)
			    {
					$argDatabasename = '';
					if ($this->debug)
					{
						$errorMessage = 'Warning: Because you provided ';
						$errorMessage.= 'database information in the DSN ';
						$errorMessage.= 'parameters, the supplied database ';
						$errorMessage.= 'name was discarded';
						ADOConnection::outp($errorMessage);
					}
		        }
				$useCataloguedConnection = false;
				
			} 
			elseif ($argDatabasename)
			{
				$this->databaseName = $argDatabasename;
				$argDSN .= ';database=' . $argDatabasename;
				$argDatabasename = '';
				$useCataloguedConnection = false;
					
			} 
			else 
			{
				$errorMessage = 'Uncatalogued connection parameters ';
				$errorMessage.= 'must contain a database= argument';
				$php_errormsg = $errorMessage;
				if ($this->debug)
					ADOConnection::outp($errorMessage);
				return null;
			}
		}
		
		if ($argDSN && !$argDatabasename && $useCataloguedConnection)
		{
			$argDatabasename = $argDSN;
			$argDSN          = '';
		}
		

		if ($useCataloguedConnection 
		&& (!$argDatabasename 
		|| !$argUsername
		|| !$argPassword))
		{
					
			$errorMessage = 'For catalogued connections, provide ';
			$errorMessage.= 'database, username and password';
			$this->_errorMsg = $errorMessage;
			if ($this->debug)
				ADOConnection::outp($errorMessage);
			return null;
			
		}
		
		if ($argDatabasename)
			$this->databaseName = $argDatabasename;
		elseif (!$this->databaseName)
			$this->databaseName = $this->getDatabasenameFromDsn($argDSN);
	
		
		$connectionParameters = array('dsn'=>$argDSN,
									  'uid'=>$argUsername,
									  'pwd'=>$argPassword,
									  'database'=>$argDatabasename,
									  'catalogue'=>$useCataloguedConnection
									  );
		
		return $connectionParameters;
		
	}

	/**
	  * Does the provided string look like a DSN
	  *
	  * @param	string	$dsnString
	  *
	  * @return bool
	  */
	private function isDsn($dsnString){
		$dsnArray = preg_split('/[;=]+/',$dsnString);
		if (count($dsnArray) > 2)
			return true;
		return false;
	}
		

	/**
	  * Gets the database name from the DSN
	  *
	  * @param	string	$dsnString
	  *
	  * @return string
	  */
	private function getDatabasenameFromDsn($dsnString){
		
		$dsnArray = preg_split('/[;=]+/',$dsnString);
		$dbIndex  = array_search('database',$dsnArray);
		
		return $dsnArray[$dbIndex + 1];
	}	
	
		
	/**
	* format and return date string in database timestamp format
	*
	* @param	mixed	$ts		either a string or a unixtime
	* @param	bool	$isField	discarded
	*
	* @return string
	*/
	public function DBTimeStamp($ts,$isField=false)
	{
		if (empty($ts) && $ts !== 0) return 'null';
		if (is_string($ts)) $ts = ADORecordSet::UnixTimeStamp($ts);
		return 'TO_DATE('.adodb_date($this->fmtTimeStamp,$ts).",'YYYY-MM-DD HH24:MI:SS')";
	}

	public function ServerInfo()
	{
		$vFetchMode = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$sql = "SELECT service_level, fixpack_num 
				  FROM TABLE(sysproc.env_get_inst_info())
					AS INSTANCEINFO";
		$row = $this->GetRow($sql);

		$this->SetFetchMode2($vFetchMode);

		if ($row) {
			$info['version'] = $row[0].':'.$row[1];
			$info['fixpack'] = $row[1];
			$info['description'] = '';
		} else {
			return ADOConnection::serverInfo();
		}

		return $info;
	}



	public function SelectLimit($sql,$nrows=-1,$offset=-1,$inputArr=false,$secs2cache=0)
	{
		$nrows = (integer) $nrows;
		$offset = (integer) $offset;
		
		if ($offset <= 0)
		{
			if ($nrows >= 0) 
				$sql .=  " FETCH FIRST $nrows ROWS ONLY ";
			
			$rs = $this->Execute($sql,$inputArr);
			
		} 
		else
		{
			if ($offset > 0 && $nrows < 0);
			
			else 
			{
				$nrows += $offset;
				$sql .=  " FETCH FIRST $nrows ROWS ONLY ";
			}
			
			/*
			 * DB2 has no native support for mid table offset
			 */
			$rs = ADOConnection::SelectLimit($sql,$nrows,$offset,$inputArr);
		
		}
		
		return $rs;
	}

	
	public function ErrorMsg()
	{
		if ($this->_errorMsg !== false) 
			return $this->_errorMsg;
		
		if (empty($this->_connectionID)) 
			return @db2_conn_errormsg();
		
		return @db2_conn_errormsg($this->_connectionID);
	}

	public function ErrorNo()
	{

		if ($this->_errorCode !== false) 
			return $this->_errorCode;
		

		if (empty($this->_connectionID)) 
			$e = @db2_conn_error();
		
		else 
			$e = @db2_conn_error($this->_connectionID);
		
		return $e;
	}



	public function BeginTrans()
	{
		if (!$this->hasTransactions) 
			return false;
		if ($this->transOff) 
			return true;
		
		$this->transCnt += 1;
		
		$this->_autocommit = false;
		
		return db2_autocommit($this->_connectionID,false);
	}

	public function CommitTrans($ok=true)
	{
		if ($this->transOff)
			return true;
		
		if (!$ok) 
			return $this->RollbackTrans();
		
		if ($this->transCnt)
			$this->transCnt -= 1;
		
		$this->_autocommit = true;
		$ret = @db2_commit($this->_connectionID);
		@db2_autocommit($this->_connectionID,true);
		return $ret;
	}

	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$this->_autocommit = true;
		$ret = @db2_rollback($this->_connectionID);
		@db2_autocommit($this->_connectionID,true);
		return $ret;
	}

	/**
      * Return a list of Primary Keys for a specified table
	  *
	  * We don't use db2_statistics as the function does not seem to play
	  * well with mixed case table names
      *
      * param  owner is not used in this driver
      *
      * returns string[]    Array of indexes
      */
	protected function _MetaPrimaryKeys($pParsedTableName, $owner=false)
	{
	    $table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$schema = @$pParsedTableName['schema']['name'];
		$primaryKeys = array();
		

		

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);


        $sql = "SELECT * 
				  FROM syscat.indexes
				 WHERE tabname='$table'";
		 
		$rows = $this->GetAll($sql);
		
		$this->SetFetchMode2($savem);

		if (empty($rows))
			return false;
        
		foreach ($rows as $r)
		{
			if ($r[7] != 'P')
				continue;
			
			$cols = explode('+',$r[6]);
			foreach ($cols as $colIndex=>$col)
			{
				if ($colIndex == 0)
					continue;
				$primaryKeys[] = $col;
			}
			break;
		}
		return $primaryKeys;
	}

	/**
	 * returns assoc array where keys are tables, and values are foreign keys
	 *
	 * @param	string	$table
	 * @param	string	$owner		[optional][discarded]
	 * @param	bool	$upper		[optional][discarded]
	 * @param	bool	$associative[optional][discarded]
	 *
	 * @return	mixed[]			Array of foreign key information
	 */
	public function MetaForeignKeys($table, $owner = FALSE, $upper = FALSE, $asociative = FALSE )
	{
		$vParsedTableName = $this->_dataDict->ParseTableName($table);
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$schema = @$vParsedTableName['schema']['name'];


		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		
		$sql = "SELECT SUBSTR(tabname,1,20) table_name,
					   SUBSTR(constname,1,20) fk_name,
					   SUBSTR(REFTABNAME,1,12) parent_table,
					   SUBSTR(refkeyname,1,20) pk_orig_table,
					   fk_colnames 
				 FROM syscat.references 
				WHERE tabname = '$table'";
		
		$results = $this->GetAll($sql);
		
		$this->SetFetchMode2($savem);

		if (empty($results))
			return false;
		
		$foreignKeys = array();
		
		foreach ($results as $r)
		{
			$parentTable = trim($r[2]);
			$keyName     = trim($r[1]);
			$foreignKeys[$parentTable] = $keyName;
		}
		
		return $foreignKeys;
	}

	/**
	  * Returns a list of tables
	  *
	  * @param string	$ttype (optional)
	  * @param	string	$schema	(optional)
	  * @param	string	$mask	(optional)
	  *
	  * @return array
	  */
	public function MetaTables($ttype = false, $schema = false, $mask = false)
	{
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		
		/*
		* Values for TABLE_TYPE
		* ---------------------------
		* ALIAS, HIERARCHY TABLE, INOPERATIVE VIEW, NICKNAME, 
		* MATERIALIZED QUERY TABLE, SYSTEM TABLE, TABLE, 
		* TYPED TABLE, TYPED VIEW, and VIEW
		*
		* If $ttype passed as '', match 'TABLE' and 'VIEW'
		* If $ttype passed as 'T' it is assumed to be 'TABLE'
		* if $ttype passed as 'V' it is assumed to be 'VIEW'
		*/
		$ttype = strtoupper($ttype);
		if ($ttype) {
			/*
			 * @todo We could do valid type checking or array type
			 */			
			 if ($ttype == 'V')
				$ttype = 'VIEW';
			if ($ttype == 'T')
				$ttype = 'TABLE';
		}
			
		if (!$schema)
			$schema = '%';
		
		if (!$mask)
			$mask = '%';
		
		$qid = @db2_tables($this->_connectionID,NULL,$schema,$mask,$ttype);

		$rs = new ADORecordSet_db2($qid, $this->GetFetchMode());
		
		$this->SetFetchMode2($savem);
		
		if (!$rs)
			return false;
		
		$arr = $rs->GetArray();
		
		$rs->Close();
		
		$tableList = array();

		/*
		* Array items
		* ---------------------------------
		* 0 TABLE_CAT	The catalog that contains the table. 
		*				The value is NULL if this table does not have catalogs.
		* 1 TABLE_SCHEM	Name of the schema that contains the table.
		* 2 TABLE_NAME	Name of the table.
		* 3 TABLE_TYPE	Table type identifier for the table.
		* 4 REMARKS		Description of the table.
		*/
		
		for ($i=0; $i < sizeof($arr); $i++) 
		{
			
			$tableRow = $arr[$i];
			$tableName = $tableRow[2];
			$tableType = $tableRow[3];
			
			if (!$tableName) 
				continue;
			
			if ($ttype == '' && (strcmp($tableType,'TABLE') <> 0 && strcmp($tableType,'VIEW') <> 0))
				continue;
			
			
			/*
			 * If we requested a schema, we prepend the schema 
			   name to the table name
			 */
			if (strcmp($schema,'%') <> 0)
				$tableName = $schema . '.' . $tableName;
			
			$tableList[] = $tableName;
			
		}
		return $tableList;
	}
    
    /**
      * Return a list of indexes for a specified table
	  *
	  * We don't use db2_statistics as the function does not seem to play
	  * well with mixed case table names
      *
      * @param string   $table
      * @param bool     $primary    (optional) only return primary keys
      * @param bool     $owner      (optional) not used in this driver
      *
      * @return string[]    Array of indexes
      */
    protected function _MetaIndexes ($pParsedTableName, $primary = false, $owner=false) {
        

		 /* Array(
		 *   [name_of_index] => Array(
		 *     [unique] => true or false
		 *     [columns] => Array(
		 *       [0] => firstcol
		 *       [1] => nextcol
         *       [2] => etc........
		 *     )
		 *   )
		 * )
		 */
		$indices 		= array();
		$primaryKeyName = '';
		$vSchema = @$pParsedTableName['schema']['name'];
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);


		
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

        $sql = "SELECT * 
				  FROM syscat.indexes
				 WHERE tabname='$table'";
		 
		$rows = $this->GetAll($sql);
		
		$this->SetFetchMode2($savem);
		
		if (empty($rows))
			return false;

        foreach ($rows as $r)
		{
			
			$primaryIndex = $r[7] == 'P'?1:0;
			if (!$primary)
				/*
			     * Primary key not requested, ignore that one
				 */
				if ($r[7] == 'P')
					continue;
				
			$indexName = $r[1];
			if (!isset($indices[$indexName]))
			{
				$unique = ($r[7] == 'U')?1:0;
				$indices[$indexName] = array('unique'=>$unique,
											 /*'primary'=>$primaryIndex, */ //NOT PER APPARANT SPECIFICATION
										     'columns'=>array()
										);
			}
			$cols = explode('+',$r[6]);
			foreach ($cols as $colIndex=>$col)
			{
				if ($colIndex == 0)
					continue;
				$columnName = $col;
				$indices[$indexName]['columns'][] = $columnName;
			}
			
		}
        
		return $indices;
       
    }
	
	/**
	 * List procedures or functions in an array.
	 *
     * We interrogate syscat.routines instead of calling the PHP 
	 * function procedures because ADOdb requires the type of procedure
	 * this is not available in the php function
	 *
	 * @param	string $procedureNamePattern (optional)
	 * @param	string $catalog				 (optional)
	 * @param	string $schemaPattern		 (optional)
	 
	 * @return array of procedures on current database.
	 *
	 */
	public function MetaProcedures($procedureNamePattern = null, $catalog  = null, $schemaPattern  = null) {
		
		
				
		$metaProcedures = array();
		$procedureSQL   = '';
		$catalogSQL     = '';
		$schemaSQL      = '';
		
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		
		if ($procedureNamePattern)
			$procedureSQL = "AND ROUTINENAME LIKE " . strtoupper($this->qstr($procedureNamePattern));
		
		if ($catalog)
			$catalogSQL = "AND OWNER=" . strtoupper($this->qstr($catalog));
		
		if ($schemaPattern)
			$schemaSQL = "AND ROUTINESCHEMA LIKE {$this->qstr($schemaPattern)}";
		
				
		$fields = "
		ROUTINENAME,
		CASE ROUTINETYPE
			 WHEN 'P' THEN 'PROCEDURE'
			 WHEN 'F' THEN 'FUNCTION'
			 ELSE 'METHOD'
			 END AS ROUTINETYPE_NAME,
		ROUTINESCHEMA,
		REMARKS";
		
		$SQL = "SELECT $fields
			      FROM syscat.routines 
				 WHERE OWNER IS NOT NULL
				  $procedureSQL
				  $catalogSQL
				  $schemaSQL
				ORDER BY ROUTINENAME
				";
		
		$result = $this->Execute($SQL);
		
		$this->SetFetchMode2($savem);
		
		if (!$result)
			return false;
		
		while ($r = $result->FetchRow()){
			$procedureName = $r[0];
			$schemaName    = $r[2];
			$metaProcedures[$procedureName] = array('type'=> $r[1],
												   'catalog' => '',
												   'schema'  => $schemaName,
												   'remarks' => $r[3]
												    );
		}
		
		return $metaProcedures;
		
	}
	
	/**
	  * Lists databases. Because instances are independent, we only know about
	  * the current database name
	  *
	  * @return string[]
	  */
	public function MetaDatabases(){
		
		$dbName = $this->databaseName;
		
		return (array)$dbName;
		
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
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$schema = @$pParsedTableName['schema']['name'];
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$schema = (empty($schema) ? '%' : $schema);
       	$colname = "%";
	    $qid = db2_columns($this->_connectionID, null, $schema, $table, $colname);
		if (empty($qid))
		{			
			if ($this->debug)
			{
				$errorMessage = @db2_conn_errormsg($this->_connectionID);
				ADOConnection::outp($errorMessage);
			}

			$this->SetFetchMode2($savem);

			return false;
		}

		$rs = new ADORecordSet_db2($qid, $this->GetFetchMode());

		if (!$rs) 
			{$this->SetFetchMode2($savem); return false;}
		
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
        12 Column Default
        13 SQL Data Type
        14 SQL DateTime SubType
        15 Max length in Octets
        16 Ordinal Position
        17 Is NULLABLE
		*/
		while (!$rs->EOF) 
		{
			if (strtoupper(trim($rs->fields[2])) == strtoupper($table)) 
			{
				
				$fld       = new ADOFieldObject();
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
				
				$fld->not_null         = !empty($rs->fields[10]);
				$fld->scale            = $rs->fields[8];
				$fld->primary_key      = false;
				
				//$columnName = $this->getMetaCasedValue($fld->name);
				$columnName = strtoupper($fld->name);
				$retarr[$columnName] = $fld;
			
			} 
			else if (sizeof($retarr)>0)
				break;
			
			$rs->MoveNext();
		
		}
		
		$rs->Close();
		if (empty($retarr)) 
			$retarr = false;

	    /*
		 * Now we find out if the column is part of a primary key
		 */
		
		$qid = @db2_primary_keys($this->_connectionID, "", $schema, $table);
		if (empty($qid)) 
			{$this->SetFetchMode2($savem); return false;}

		$rs = new ADORecordSet_db2($qid, ADODB_FETCH_NUM);

		if (!$rs)
		{	
			$this->SetFetchMode2($savem);

			return $retarr;
		}	
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
		while (!$rs->EOF) {
			if (strtoupper(trim($rs->fields[2])) == $table 
			&& (!$schema || strtoupper($rs->fields[1]) == $schema))
			{
				$retarr[strtoupper($rs->fields[3])]->primary_key = true;
			} 
			else if (sizeof($retarr)>0)
				break;
			
			$rs->MoveNext();
		}
		$rs->Close();

		$this->SetFetchMode2($savem);

		if (empty($retarr)) 
			return false;
		
		/*
		* If the fetch mode is numeric, return as numeric array
		*/
		if ($this->GetFetchMode() == ADODB_FETCH_NUM)
			$retarr = array_values($retarr);
		
		return $retarr;
	}

	/**
	  * In this version if prepareSp, we just check to make sure
	  * that the name of the stored procedure is correct
	  * If true, we returns an array
	  * else false
	  *
	  * @param	string	$procedureName
	  * @param	mixed   $parameters (not used in db2 connections)
	  * @return mixed[]
	  */
	public function PrepareSP($procedureName,$parameters=false) {
		
		
		$this->storedProcedureParameters = array('name'=>'',
												 'resource'=>false,
												 'in'=>array(),
												 'out'=>array(),
												 'index'=>array(),
												 'parameters'=>array(),
												 'keyvalue' => array());
		
		//$procedureName = strtoupper($procedureName);
		//$procedureName = $this->getTableCasedValue($procedureName);

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		
		$qid = db2_procedures($this->_connectionID, NULL , '%' , $procedureName );
				
		$this->SetFetchMode2($savem);
		
		if (!$qid)
		{
			if ($this->debug)
				ADOConnection::outp(sprintf('No Procedure of name %s available',$procedureName));
			return false;
		}
		
		
				
		$this->storedProcedureParameters['name'] = $procedureName;
		/*
		 * Now we know we have a valid procedure name, lets see if it requires 
		 * parameters
		 */
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		
		$qid = db2_procedure_columns($this->_connectionID, NULL , '%' , $procedureName , NULL );
		

		if (!$qid)
		{
			if ($this->debug)
				ADOConnection::outp(sprintf('No columns of name %s available',$procedureName));
			
			$this->SetFetchMode2($savem);

			return false;
		}
		$rs = new ADORecordSet_db2($qid, $this->GetFetchMode());
		if (!$rs) 
			{$this->SetFetchMode2($savem); return false;}
		
		$preparedStatement = 'CALL %s(%s)';
		$parameterMarkers = array();
		while (!$rs->EOF)
		{
			$parameterName = $rs->fields[3];
			if ($parameterName == '')
			{
				$rs->MoveNext();
				continue;
			}
			$parameterType = $rs->fields[4];
			$ordinalPosition = $rs->fields[17];
			switch($parameterType)
			{
			case DB2_PARAM_IN:
			case DB2_PARAM_INOUT:
			    $this->storedProcedureParameters['in'][$parameterName] = '';
				break;
			case DB2_PARAM_INOUT:
			case DB2_PARAM_OUT:
			    $this->storedProcedureParameters['out'][$parameterName] = '';
				break;	
			}
			$this->storedProcedureParameters['index'][$parameterName] = $ordinalPosition;
			$this->storedProcedureParameters['parameters'][$ordinalPosition] = $rs->fields;
			$rs->MoveNext();

		}
		$parameterCount = count($this->storedProcedureParameters['index']);
		$parameterMarkers = array_fill(0,$parameterCount,'?');
		
		/*
		 * We now know how many parameters to bind to the stored procedure
		 */
		$parameterList = implode(',',$parameterMarkers);
		
		$sql = sprintf($preparedStatement,$procedureName,$parameterList);
		
		$spResource = @db2_prepare($this->_connectionID,$sql);

		$this->SetFetchMode2($savem);
		
		if (!$spResource)
		{
			$errorMessage = @db2_conn_errormsg($this->_connectionID);
			$this->_errorMsg = $errorMessage;
			
			if ($this->debug)
				ADOConnection::outp($errorMessage);
			
			return false;
		}
		
		$this->storedProcedureParameters['resource'] = $spResource;
		
		if ($this->debug)
		{
			
			ADOConnection::outp('The following parameters will be used in the SP call');
			ADOConnection::outp(print_r($this->storedProcedureParameters));
		}
		/*
		 * We now have a stored parameter resource 
		 * to bind to. The spResource and sql that is returned are
		 * not usable, its for dummy compatibility. Everything
		 * will be handled by the storedProcedureParameters 
		 * array
		 */
		return array($sql,$spResource);
		
	}
	
	private function storedProcedureParameter(&$stmt, 
											  &$var, 
											  $name, 
											  $isOutput=false, 
											  $maxLen=4000, 
											  $type=false)
	{
		
				
		$name = strtoupper($name);
		
		/*
		 * Must exist in the list of parameter names for the type
		 */
		if ($isOutput 
		&& !isset( $this->storedProcedureParameters['out'][$name]))
		{
			$errorMessage = sprintf('%s is not a valid OUT parameter name',$name);
			
			$this->_errorMsg = $errorMessage;
			if ($this->debug)
				ADOConnection::outp($errorMessage);
			return false;
		}
		
		if (!$isOutput 
		&& !isset( $this->storedProcedureParameters['in'][$name]))
		{
			$errorMessage = sprintf('%s is not a valid IN parameter name',$name);
			
			$this->_errorMsg = $errorMessage;
			if ($this->debug)
				ADOConnection::outp($errorMessage);
			return false;
		}
		
		/*
		 * We will use these values to bind to when we execute
		 * the query
		 */
		$this->storedProcedureParameters['keyvalue'][$name] = &$var;
		
		return true;
		
	}
	
	/**
	* Executes a prepared stored procedure. 
	* 
	* The function uses the previously accumulated information and
	* resources in the $storedProcedureParameters array
	*
	* @return mixed	The statement id if successful, or false
	*/
	private function executeStoredProcedure()
	{
	
		/*
		 * Get the previously built resource
		 */
		$stmtid = $this->storedProcedureParameters['resource'];
					
		/*
		 * Bind our variables to the DB2 procedure
		 */
		foreach ($this->storedProcedureParameters['keyvalue'] as $spName=>$spValue){
			
			/*
			 * Get the ordinal position, required for binding
			 */
			$ordinalPosition = $this->storedProcedureParameters['index'][$spName];
		
			/*
			 * Get the db2 column dictionary for the parameter
			 */
			$columnDictionary = $this->storedProcedureParameters['parameters'][$ordinalPosition];
			$parameterType    = $columnDictionary[4];
			$dataType         = $columnDictionary[5];
			$precision        = $columnDictionary[10];
			$scale        	  = $columnDictionary[9];
			
			$ok = @db2_bind_param ($this->storedProcedureParameters['resource'], 
								  $ordinalPosition , 
								  $spName,
								  $parameterType,
								  $dataType,
								  $precision,
								  $scale
								  );
			
			if (!$ok)
			{
				$this->_errorMsg  = @db2_stmt_errormsg();
				$this->_errorCode = @db2_stmt_error();

				if ($this->debug) 
					ADOConnection::outp($this->_errorMsg);	
				return false;
			}
			
			if ($this->debug)
				ADOConnection::outp("Correctly Bound parameter $spName to procedure");	
			
			/*
			 * Build a variable in the current environment that matches 
			 * the parameter name
			 */
			${$spName} = $spValue;
			
		}
		
		/*
		 * All bound, execute
		 */
					
		if (!@db2_execute($stmtid)) 
		{
			$this->_errorMsg = @db2_stmt_errormsg();
			$this->_errorCode = @db2_stmt_error();
			
			if ($this->debug) 
				ADOConnection::outp($this->_errorMsg);	
			return false;
		}
		
		/*
		 * We now take the changed parameters back into the
		 * stored procedures array where we can query them later
		 * Remember that $spValue was passed in by reference, so we
		 * can access the value in the variable that was originally
		 * passed to inParameter or outParameter
		 */
		foreach ($this->storedProcedureParameters['keyvalue'] as $spName=>$spValue)
		{
			/*
			 * We make it available to the environment
			 */
			$spValue = ${$spName};
			$this->storedProcedureParameters['keyvalue'][$spName] = $spValue;
		}
		
		return $stmtid;
	}
	
	/**
	*
    * Accepts an input or output parameter to bind to either a stored
    * or prepared statements. For DB2, this should not be called as an
	* API. always wrap with inParameter and outParameter
    *
	* @param mixed[] $stmt 		Statement returned by Prepare() or PrepareSP().
	* @param mixed   $var 		PHP variable to bind to. Can set to null (for isNull support).
	* @param string  $name 		Name of stored procedure variable name to bind to.
	* @param int	 $isOutput 	optional) Indicates direction of parameter 
	* 							0/false=IN  1=OUT  2= IN/OUT
	*							This is ignored for Stored Procedures
	* @param int	$maxLen		(optional)Holds an maximum length of the variable.
	*							This is ignored for Stored Procedures
	* @param int	$type 		(optional) The data type of $var.
	*							This is ignored for Stored Procedures
    *
	* @return bool				Success of the operation
	*/
	public function Parameter(&$stmt, &$var, $name, $isOutput=false, $maxLen=4000, $type=false)
	{
		
		/*
		 * If the $stmt is the name of a stored procedure we are
		 * setting up, we will process it one way, otherwise
		 * we assume we are setting up a prepared statement
		*/
		if (is_array($stmt))
		{
			if ($this->debug)
				ADOConnection::outp("Adding parameter to stored procedure");
			if ($stmt[1] == $this->storedProcedureParameters['resource'])
				return $this->storedProcedureParameter($stmt[1], 
														$var, 
														$name, 
														$isOutput, 
														$maxLen,
														$type);
												
		}
		
		/*
		 * We are going to add a parameter to a prepared statement
		 */
		if ($this->debug)
			ADOConnection::outp("Adding parameter to prepared statement");
	}
	
	
	/**
	* Prepares a prepared SQL statement, not used for stored procedures
	*
	* @param string	$sql
	* 
	* @return mixed
	*/
	public function Prepare($sql)
	{
		
		if (! $this->_bindInputArray) return $sql; // no binding
		
		$stmt = @db2_prepare($this->_connectionID,$sql);
		if (!$stmt) {
			// we don't know whether db2 driver is parsing prepared stmts, so just return sql
			return $sql;
		}
		return array($sql,$stmt,false);
	}

	/**
 	* Executes a query 
	* 
	* @param	mixed $sql
	* @param	mixed $inputarr	An optional array of parameters
	* 
	* @return mixed				either the queryID or false
	*/
	public function _query(&$sql,$inputarr=false)
	{
        
        GLOBAL $php_errormsg;
		
		if (isset($php_errormsg))
			$php_errormsg = '';
		$this->_errorMsg = '';

		$db2Options = array();
            
		$db2Options = array('db2_attr_case'=>DB2_CASE_NATURAL);
        $setOption = @db2_set_option($this->_connectionID,$db2Options,1);	
		
        if ($inputarr)
		{
			if (is_array($sql))
			{
				$stmtid = $sql[1];
			} 
			else
			{
				$stmtid = @db2_prepare($this->_connectionID,$sql);

				if ($stmtid == false)
				{
					$this->_errorMsg = isset($php_errormsg) ? $php_errormsg : '';
					return false;
				}
			}

			if (! @db2_execute($stmtid,$inputarr))
			{
				$this->_errorMsg = @db2_stmt_errormsg();
				$this->_errorCode = @db2_stmt_error();
				if ($this->debug) 
					ADOConnection::outp($this->_errorMsg);	
				return false;
			}

		} 
		else if (is_array($sql))
		{
			
			/*
			 * Either a prepared statement or a stored procedure
			 */
			
			if (is_array($this->storedProcedureParameters)
				&& is_resource($this->storedProcedureParameters['resource']
			)) 
				/*
				 * This is all handled in the separate method for
				 * readability
				 */
				return $this->executeStoredProcedure();
				
			/*
			 * First, we prepare the statement
			 */
			$stmtid = @db2_prepare($this->_connectionID,$sql[0]);
			if (!$stmtid){
				$this->_errorMsg = @db2_stmt_errormsg();
				$this->_errorCode = @db2_stmt_error();
				if ($this->debug)
					ADOConnection::outp("Prepare failed: " . $this->_errorMsg);

				return false;
			}
			/*
			 * We next bind some input parameters
			 */
			$ordinal = 1;
			foreach ($sql[1] as $psVar=>$psVal){
				${$psVar} = $psVal;
				$ok = @db2_bind_param($stmtid, $ordinal, $psVar, DB2_PARAM_IN);
				if (!$ok)
				{
					$this->_errorMsg = @db2_stmt_errormsg();
					$this->_errorCode = @db2_stmt_error();
					if ($this->debug)
						ADOConnection::outp("Bind failed: " . $this->_errorMsg);
					return false;
				}
			}
			
			if (!@db2_execute($stmtid)) 
			{
				$this->_errorMsg = @db2_stmt_errormsg();
				$this->_errorCode = @db2_stmt_error();
				if ($this->debug) 
					ADOConnection::outp($this->_errorMsg);	
				return false;
			}
			
			return $stmtid;
		}
		else
		{

			$stmtid = @db2_exec($this->_connectionID,$sql);
        }
		$this->_lastAffectedRows = 0;
		if ($stmtid)
		{
			if (@db2_num_fields($stmtid) == 0)
			{
				$this->_lastAffectedRows = db2_num_rows($stmtid);
				$stmtid = true;
			} 
			else 
			{
				$this->_lastAffectedRows = 0;
			}

			$this->_errorMsg = '';
			$this->_errorCode = 0;
			
		}
		else
		{
			
			$this->_errorMsg = @db2_stmt_errormsg();
			$this->_errorCode = @db2_stmt_error();
		
		}
		return $stmtid;
	}

	/*
		Insert a null into the blob field of the table first.
		Then use UpdateBlob to store the blob.

		Usage:

		$conn->execute('INSERT INTO blobtable (id, blobcol) VALUES (1, null)');
		$conn->UpdateBlob('blobtable','blobcol',$blob,'id=1');
	*/
	public function UpdateBlob($table,$column,$val,$where,$blobtype='BLOB')
	{
		return $this->execute("UPDATE $table SET $column=? WHERE $where",array($val)) != false;
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

class ADORecordSet_db2 extends ADORecordSet {

	public $bind = false;
	public $databaseType = "db2";
	public $dataProvider = "db2";
	public $useFetchArray;



	// returns the field object
	protected function _FetchField($offset = -1)
	{
		$o			   = new ADOFieldObject();
		$o->name 	   = @db2_field_name($this->_queryID,$offset);
		$o->type 	   = @db2_field_type($this->_queryID,$offset);
		$o->max_length = @db2_field_width($this->_queryID,$offset);
		
		/*
		if (ADODB_ASSOC_CASE == 0) 
			$o->name = strtolower($o->name);
		else if (ADODB_ASSOC_CASE == 1) 
			$o->name = strtoupper($o->name);
		*/
		return $o;
	}



	protected function _initrs()
	{
		global $ADODB_COUNTRECS;
		$this->_numOfRows = ($ADODB_COUNTRECS) ? @db2_num_rows($this->_queryID) : -1;
		
		$this->_numOfFields = @db2_num_fields($this->_queryID);
		
		// some silly drivers such as db2 as/400 and intersystems cache return _numOfRows = 0
		
		if ($this->_numOfRows == 0) 
			$this->_numOfRows = -1;
	}

	protected function _seek($row)
	{
		return false;
	}

	protected function _MoveNext()
	{
		$this->bind = false;
		if ($this->_numOfRows != 0 && !$this->EOF) {
			$this->_currentRow++;

			$this->processCoreFetch();
			
			if ($this->fields) {
				return true;
			}
		}
		$this->fields = false;
		$this->EOF = true;
		return false;
	}
    
    final private function processCoreFetch()
    {
        switch ($this->fetchMode){
		case ADODB_FETCH_ASSOC:
			
			/*
			 * Associative array
			 */
			$this->fields = @db2_fetch_assoc($this->_queryID);
			break;
			
		case ADODB_FETCH_BOTH:
			/*
			 * Fetch both numeric and Associative array
			 */
			$this->fields = @db2_fetch_both($this->_queryID);
			break;
		default:
			/*
			 * Numeric array
			 */
			$this->fields = @db2_fetch_array($this->_queryID);
			break;
		}
    }

    protected function _fetch()
	{
		$this->bind = false;
        $this->processCoreFetch();
        if ($this->fields) 
			return true;
		
		$this->fields = false;
		return false;
	}
	
	public function db2__fetch()
		{return $this->_callFetch();}

	protected function _close()
	{
		$ok = @db2_free_result($this->_queryID);
		if (!$ok)
		{
			$this->_errorMsg  = @db2_stmt_errormsg($this->_queryId);
			$this->_errorCode = @db2_stmt_error();

			if ($this->debug) 
				ADOConnection::outp($this->_errorMsg);	
			return false;
		}
		
		return $ok;
	}

}
