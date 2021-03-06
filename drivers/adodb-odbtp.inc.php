<?php
/*
  @version   v5.22.0-dev  Unreleased
  @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
  @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence. See License.txt.
  Set tabs to 4 for best viewing.
  Latest version is available at https://adodb.org/
*/
// Code contributed by "stefan bogdan" <sbogdan#rsb.ro>

// security - hide paths
if (!defined('ADODB_DIR')) die();

define("_ADODB_ODBTP_LAYER", 2 );

class ADODB_odbtp extends ADOConnection{
	public  $databaseType = "odbtp";
	public  $dataProvider = "odbtp";
	public  $fmtDate = "'Y-m-d'";
	public  $fmtTimeStamp = "'Y-m-d, h:i:sA'";
	public  $replaceQuote = "''"; // string to use to replace quotes
	public  $odbc_driver = 0; //odbtp driver specific. Refer to ODB_ATTR_DRIVER online for possible values.
	public  $hasAffectedRows = true;
	public  $hasInsertID = false;
	public  $hasGenID = true;
	public  $hasMoveFirst = true;

	protected  $_genSeqSQL = "create table %s (seq_name char(30) not null unique , seq_value integer not null)";
	protected  $_dropSeqSQL = "delete from adodb_seq where seq_name = '%s'";
	protected  $_bindInputArray = false;
	protected  $gOdbtp__CanOverrideBindInputArray = false;
	public  $_useUnicodeSQL = false; //odbtp driver specific.
	protected  $_canPrepareSP = false;
	protected  $_dontPoolDBC = true;

	public function ServerInfo()
	{
		return array('description' => @odbtp_get_attr( ODB_ATTR_DBMSNAME, $this->_connectionID),
		             'version' => @odbtp_get_attr( ODB_ATTR_DBMSVER, $this->_connectionID));
	}

	public function ErrorMsg()
	{
		if ($this->_errorMsg !== false) return $this->_errorMsg;
		if (empty($this->_connectionID)) return @odbtp_last_error();
		return @odbtp_last_error($this->_connectionID);
	}

	public function ErrorNo()
	{
		if ($this->_errorCode !== false) return $this->_errorCode;
		if (empty($this->_connectionID)) return @odbtp_last_error_state();
			return @odbtp_last_error_state($this->_connectionID);
	}
/*
	public function DBDate($d,$isfld=false)
	{
		if (empty($d) && $d !== 0) return 'null';
		if ($isfld) return "convert(date, $d, 120)";

		if (is_string($d)) $d = ADORecordSet::UnixDate($d);
		$d = adodb_date($this->fmtDate,$d);
		return "convert(date, $d, 120)";
	}

	public function DBTimeStamp($d,$isfld=false)
	{
		if (empty($d) && $d !== 0) return 'null';
		if ($isfld) return "convert(datetime, $d, 120)";

		if (is_string($d)) $d = ADORecordSet::UnixDate($d);
		$d = adodb_date($this->fmtDate,$d);
		return "convert(datetime, $d, 120)";
	}
*/

	protected function _insertid()
	{
	// SCOPE_IDENTITY()
	// Returns the last IDENTITY value inserted into an IDENTITY column in
	// the same scope. A scope is a module -- a stored procedure, trigger,
	// function, or batch. Thus, two statements are in the same scope if
	// they are in the same stored procedure, function, or batch.
			return $this->GetOne($this->identitySQL);
	}

	protected function _affectedrows()
	{
		if ($this->_queryID) {
			return @odbtp_affected_rows ($this->_queryID);
	   } else
		return 0;
	}

	public function CreateSequence($seqname='adodbseq',$start=1)
	{
		//verify existence
		$num = $this->GetOne("select seq_value from adodb_seq");
		$seqtab='adodb_seq';
		if( $this->odbc_driver == ODB_DRIVER_FOXPRO ) {
			$path = @odbtp_get_attr( ODB_ATTR_DATABASENAME, $this->_connectionID );
			//if using vfp dbc file
			if( !strcasecmp(strrchr($path, '.'), '.dbc') )
                $path = substr($path,0,strrpos($path,'\/'));
           	$seqtab = $path . '/' . $seqtab;
        }
		if($num == false) {
			if (empty($this->_genSeqSQL)) return false;
			$ok = $this->Execute(sprintf($this->_genSeqSQL ,$seqtab));
		}
		$num = $this->GetOne("select seq_value from adodb_seq where seq_name='$seqname'");
		if ($num) {
			return false;
		}
		$start -= 1;
		return $this->Execute("insert into adodb_seq values('$seqname',$start)");
	}

	public function DropSequence($seqname = 'adodbseq')
	{
		if (empty($this->_dropSeqSQL)) return false;
		return $this->Execute(sprintf($this->_dropSeqSQL,$seqname));
	}

	public function GenID($seq='adodbseq',$start=1)
	{
		$seqtab='adodb_seq';
		if( $this->odbc_driver == ODB_DRIVER_FOXPRO) {
			$path = @odbtp_get_attr( ODB_ATTR_DATABASENAME, $this->_connectionID );
			//if using vfp dbc file
			if( !strcasecmp(strrchr($path, '.'), '.dbc') )
                $path = substr($path,0,strrpos($path,'\/'));
           	$seqtab = $path . '/' . $seqtab;
        }
		$MAXLOOPS = 100;
		while (--$MAXLOOPS>=0) {
			$num = $this->GetOne("select seq_value from adodb_seq where seq_name='$seq'");
			if ($num === false) {
				//verify if abodb_seq table exist
				$ok = $this->GetOne("select seq_value from adodb_seq ");
				if(!$ok) {
					//creating the sequence table adodb_seq
					$this->Execute(sprintf($this->_genSeqSQL ,$seqtab));
				}
				$start -= 1;
				$num = '0';
				$ok = $this->Execute("insert into adodb_seq values('$seq',$start)");
				if (!$ok) return false;
			}
			$ok = $this->Execute("update adodb_seq set seq_value=seq_value+1 where seq_name='$seq'");
			if($ok) {
				$num += 1;
				$this->genID = $num;
				return $num;
			}
		}
	if ($fn = $this->raiseErrorFn) {
		$fn($this->databaseType,'GENID',-32000,"Unable to generate unique id after $MAXLOOPS attempts",$seq,$num);
	}
		return false;
	}

	//example for $UserOrDSN
	//for visual fox : DRIVER={Microsoft Visual FoxPro Driver};SOURCETYPE=DBF;SOURCEDB=c:\YourDbfFileDir;EXCLUSIVE=NO;
	//for visual fox dbc: DRIVER={Microsoft Visual FoxPro Driver};SOURCETYPE=DBC;SOURCEDB=c:\YourDbcFileDir\mydb.dbc;EXCLUSIVE=NO;
	//for access : DRIVER={Microsoft Access Driver (*.mdb)};DBQ=c:\path_to_access_db\base_test.mdb;UID=root;PWD=;
	//for mssql : DRIVER={SQL Server};SERVER=myserver;UID=myuid;PWD=mypwd;DATABASE=OdbtpTest;
	//if uid & pwd can be separate
    protected function _connect($HostOrInterface, $UserOrDSN='', $argPassword='', $argDatabase='')
	{
		$vOldDatabaseType = $this->databaseType;

		if ($argPassword && stripos($UserOrDSN,'DRIVER=') !== false) {
			$this->_connectionID = odbtp_connect($HostOrInterface,$UserOrDSN.';PWD='.$argPassword);
		} else
			$this->_connectionID = odbtp_connect($HostOrInterface,$UserOrDSN,$argPassword,$argDatabase);
		if ($this->_connectionID === false) {
			$this->_errorMsg = $this->ErrorMsg() ;
			return false;
		}

		odbtp_convert_datetime($this->_connectionID,true);

		if ($this->_dontPoolDBC) {
			if (function_exists('odbtp_dont_pool_dbc'))
				@odbtp_dont_pool_dbc($this->_connectionID);
		}
		else {
			$this->_dontPoolDBC = true;
		}
		$this->odbc_driver = @odbtp_get_attr(ODB_ATTR_DRIVER, $this->_connectionID);
		$dbms = strtolower(@odbtp_get_attr(ODB_ATTR_DBMSNAME, $this->_connectionID));
		$this->odbc_name = $dbms;

		// Account for inconsistent DBMS names
		if( $this->odbc_driver == ODB_DRIVER_ORACLE )
			$dbms = 'oracle';
		else if( $this->odbc_driver == ODB_DRIVER_SYBASE )
			$dbms = 'sybase';

		// Set DBMS specific attributes
		switch( $dbms ) {
			case 'microsoft sql server':
				$this->databaseType = 'odbtp_mssql';
				$this->fmtDate = "'Y-m-d'";
				$this->fmtTimeStamp = "'Y-m-d h:i:sA'";
				$this->ansiOuter = true;
				$this->leftOuter = '*=';
				$this->rightOuter = '=*';
                $this->hasTop = 'top';
				$this->hasInsertID = true;
				$this->hasTransactions = true;
				$this->_bindInputArray = true;
				$this->gOdbtp__CanOverrideBindInputArray = true;
				$this->_canSelectDb = true;
				$this->substr = "substring";
				$this->length = 'len';
				$this->identitySQL = 'select SCOPE_IDENTITY()';
				$this->metaDatabasesSQL = "select name from master..sysdatabases where name <> 'master'";
				$this->_canPrepareSP = true;
				break;
			case 'access':
				$this->databaseType = 'odbtp_access';
				$this->fmtDate = "#Y-m-d#";
				$this->fmtTimeStamp = "#Y-m-d h:i:sA#";
                $this->hasTop = 'top';
				$this->hasTransactions = false;
				$this->_canPrepareSP = true;  // For MS Access only.
				break;
			case 'visual foxpro':
				$this->databaseType = 'odbtp_vfp';
				$this->fmtDate = "{^Y-m-d}";
				$this->fmtTimeStamp = "{^Y-m-d, h:i:sA}";
				$this->ansiOuter = true;
                $this->hasTop = 'top';
				$this->hasTransactions = false;
				$this->replaceQuote = "'+chr(39)+'";
				$this->true = '.T.';
				$this->false = '.F.';

				break;
			case 'oracle':
				$this->databaseType = 'odbtp_oci8';
				$this->fmtDate = "'Y-m-d 00:00:00'";
				$this->fmtTimeStamp = "'Y-m-d h:i:sA'";
				$this->hasTransactions = true;
				$this->_bindInputArray = true;
				$this->gOdbtp__CanOverrideBindInputArray = true;
				break;
			case 'sybase':
				$this->databaseType = 'odbtp_sybase';
				$this->fmtDate = "'Y-m-d'";
				$this->fmtTimeStamp = "'Y-m-d H:i:s'";
				$this->leftOuter = '*=';
				$this->rightOuter = '=*';
				$this->hasInsertID = true;
				$this->hasTransactions = true;
				$this->identitySQL = 'select SCOPE_IDENTITY()';
				break;
			default:
				//ODB_DRIVER_UNKNOWN
				//ODB_DRIVER_DB2
				//ODB_DRIVER_MYSQL
				$this->databaseType = 'odbtp';
				$this->_dataDict = null;
				if( @odbtp_get_attr(ODB_ATTR_TXNCAPABLE, $this->_connectionID) )
					$this->hasTransactions = true;
				else
					$this->hasTransactions = false;
		}
        @odbtp_set_attr(ODB_ATTR_FULLCOLINFO, TRUE, $this->_connectionID );

		if ($this->_useUnicodeSQL )
			@odbtp_set_attr(ODB_ATTR_UNICODESQL, TRUE, $this->_connectionID);

		if($vOldDatabaseType !== $this->databaseType)
			{$this->_dataDict = NewDataDictionary($this);}

		if($this->databaseType === 'odbtp';)
		{
			$this->Close();
			
			return false;
		}
		else
			{return true;}
	}

	protected function _pconnect($HostOrInterface, $UserOrDSN='', $argPassword='', $argDatabase='')
	{
		$this->_dontPoolDBC = false;
  		return $this->_connect($HostOrInterface, $UserOrDSN, $argPassword, $argDatabase);
	}

	public function SelectDB($dbName)
	{
		if (!@odbtp_select_db($dbName, $this->_connectionID)) {
			return false;
		}
		$this->database = $dbName;
		$this->databaseName = $dbName; # obsolete, retained for compat with older adodb versions
		return true;
	}

	public function MetaTables($ttype='',$showSchema=false,$mask=false)
	{
		$savefm = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$arr = $this->GetArray("||SQLTables||||$ttype");

		$this->SetFetchMode2($savefm);

		$arr2 = array();
		for ($i=0; $i < sizeof($arr); $i++) {
			if ($arr[$i][3] == 'SYSTEM TABLE' )	continue;
			if ($arr[$i][2])
				$arr2[] = $showSchema && $arr[$i][1]? $arr[$i][1].'.'.$arr[$i][2] : $arr[$i][2];
		}
		return $arr2;
	}

	protected function _MetaColumns($pParsedTableName)
	{
		$table = $pParsedTableName['table']['name'];
		$upper = $pParsedTableName['table']['isToNormalize'];
		$schema = @$pParsedTableName['schema']['name'];
		if ($upper) $table = strtoupper($table);

		$savefm = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$rs = $this->Execute( "||SQLColumns||$schema|$table" );

		$this->SetFetchMode2($savefm);

		if (!$rs || $rs->EOF) {
			$false = false;
			return $false;
		}
		$retarr = array();
		$table = strtoupper($table);
		while (!$rs->EOF) {
			//print_r($rs->fields);
			if (strtoupper($rs->fields[2]) == $table) {
				$fld = new ADOFieldObject();
				$fld->name = $rs->fields[3];
				$fld->type = $rs->fields[5];
				$fld->max_length = $rs->fields[6];
    			$fld->not_null = !empty($rs->fields[9]);
 				$fld->scale = $rs->fields[7];
				if (isset($rs->fields[12])) // vfp does not have field 12
	 				if (!is_null($rs->fields[12])) {
	 					$fld->has_default = true;
	 					$fld->default_value = $rs->fields[12];
					}

				if($this->GetFetchMode() == ADODB_FETCH_NUM)
					{$retarr[] = $fld;}
				else
					{$retarr[strtoupper($fld->name)] = $fld;}
			} else if (!empty($retarr))
				break;
			$rs->MoveNext();
		}
		$rs->Close();

		return $retarr;
	}

	protected function _MetaPrimaryKeys($pParsedTableName, $owner='')
	{
		$table = $this->NormaliseIdentifierNameIf($pParsedTableName['table']['isToNormalize'],
				$pParsedTableName['table']['name']);
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$arr = $this->GetArray("||SQLPrimaryKeys||$owner|$table");
		$this->SetFetchMode2($savem);

		//print_r($arr);
		$arr2 = array();
		for ($i=0; $i < sizeof($arr); $i++) {
			if ($arr[$i][3]) $arr2[] = $arr[$i][3];
		}
		return $arr2;
	}

	public function MetaForeignKeys($table, $owner='', $upper=false)
	{
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);
		$constraints = $this->GetArray("||SQLForeignKeys|||||$owner|$table");
		$this->SetFetchMode2($savem);

		$arr = false;
		foreach($constraints as $constr) {
			//print_r($constr);
			$arr[$constr[11]][$constr[2]][] = $constr[7].'='.$constr[3];
		}
		if (!$arr) {
			$false = false;
			return $false;
		}

		$arr2 = array();

		foreach($arr as $k => $v) {
			foreach($v as $a => $b) {
				if ($upper) $a = strtoupper($a);
				$arr2[$a] = $b;
			}
		}
		return $arr2;
	}

	public function BeginTrans()
	{
		if (!$this->hasTransactions) return false;
		if ($this->transOff) return true;
		$this->transCnt += 1;
		$this->autoCommit = false;
		if (defined('ODB_TXN_DEFAULT'))
			$txn = ODB_TXN_DEFAULT;
		else
			$txn = ODB_TXN_READUNCOMMITTED;
		$rs = @odbtp_set_attr(ODB_ATTR_TRANSACTIONS,$txn,$this->_connectionID);
		if(!$rs) return false;
		return true;
	}

	public function CommitTrans($ok=true)
	{
		if ($this->transOff) return true;
		if (!$ok) return $this->RollbackTrans();
		if ($this->transCnt) $this->transCnt -= 1;
		$this->autoCommit = true;
		if( ($ret = @odbtp_commit($this->_connectionID)) )
			$ret = @odbtp_set_attr(ODB_ATTR_TRANSACTIONS, ODB_TXN_NONE, $this->_connectionID);//set transaction off
		return $ret;
	}

	public function RollbackTrans()
	{
		if ($this->transOff) return true;
		if ($this->transCnt) $this->transCnt -= 1;
		$this->autoCommit = true;
		if( ($ret = @odbtp_rollback($this->_connectionID)) )
			$ret = @odbtp_set_attr(ODB_ATTR_TRANSACTIONS, ODB_TXN_NONE, $this->_connectionID);//set transaction off
		return $ret;
	}

	public function SelectLimit($sql,$nrows=-1,$offset=-1, $inputarr=false,$secs2cache=0)
	{
		// TOP requires ORDER BY for Visual FoxPro
		if( $this->odbc_driver == ODB_DRIVER_FOXPRO ) {
			if (!preg_match('/ORDER[ \t\r\n]+BY/is',$sql)) $sql .= ' ORDER BY 1';
		}
		$ret = ADOConnection::SelectLimit($sql,$nrows,$offset,$inputarr,$secs2cache);
		return $ret;
	}

	public function Prepare($sql)
	{
		if (! $this->_bindInputArray) return $sql; // no binding

        $this->_errorMsg = false;
		$this->_errorCode = false;

		$stmt = @odbtp_prepare($sql,$this->_connectionID);
		if (!$stmt) {
		//	print "Prepare Error for ($sql) ".$this->ErrorMsg()."<br>";
			return $sql;
		}
		return array($sql,$stmt,false);
	}

	public function PrepareSP($sql, $param = true)
	{
		if (!$this->_canPrepareSP) return $sql; // Can't prepare procedures

        $this->_errorMsg = false;
		$this->_errorCode = false;

		$stmt = @odbtp_prepare_proc($sql,$this->_connectionID);
		if (!$stmt) return false;
		return array($sql,$stmt);
	}

	/*
	Usage:
		$stmt = $db->PrepareSP('SP_RUNSOMETHING'); -- takes 2 params, @myid and @group

		# note that the parameter does not have @ in front!
		$db->Parameter($stmt,$id,'myid');
		$db->Parameter($stmt,$group,'group',false,64);
		$db->Parameter($stmt,$group,'photo',false,100000,ODB_BINARY);
		$db->Execute($stmt);

		@param $stmt Statement returned by Prepare() or PrepareSP().
		@param $var PHP variable to bind to. Can set to null (for isNull support).
		@param $name Name of stored procedure variable name to bind to.
		@param [$isOutput] Indicates direction of parameter 0/false=IN  1=OUT  2= IN/OUT. This is ignored in odbtp.
		@param [$maxLen] Holds an maximum length of the variable.
		@param [$type] The data type of $var. Legal values depend on driver.

		See odbtp_attach_param documentation at http://odbtp.sourceforge.net.
	*/
	public function Parameter(&$stmt, &$var, $name, $isOutput=false, $maxLen=0, $type=0)
	{
		if ( $this->odbc_driver == ODB_DRIVER_JET ) {
			$name = '['.$name.']';
			if( !$type && $this->_useUnicodeSQL
				&& @odbtp_param_bindtype($stmt[1], $name) == ODB_CHAR )
			{
				$type = ODB_WCHAR;
			}
		}
		else {
			$name = '@'.$name;
		}
		return @odbtp_attach_param($stmt[1], $name, $var, $type, $maxLen);
	}

	/*
		Insert a null into the blob field of the table first.
		Then use UpdateBlob to store the blob.

		Usage:

		$conn->Execute('INSERT INTO blobtable (id, blobcol) VALUES (1, null)');
		$conn->UpdateBlob('blobtable','blobcol',$blob,'id=1');
	*/

	public function UpdateBlob($table,$column,$val,$where,$blobtype='image')
	{
		$sql = "UPDATE $table SET $column = ? WHERE $where";
		if( !($stmt = @odbtp_prepare($sql, $this->_connectionID)) )
			return false;
		if( !@odbtp_input( $stmt, 1, ODB_BINARY, 1000000, $blobtype ) )
			return false;
		if( !@odbtp_set( $stmt, 1, $val ) )
			return false;
		return @odbtp_execute( $stmt ) != false;
	}

	protected function _MetaIndexes($pParsedTableName,$primary=false, $owner=false)
	{
		switch ( $this->odbc_driver) {
			case ODB_DRIVER_MSSQL:
				return $this->_MetaIndexes_mssql($pParsedTableName, $primary);
			default:
				return array();
		}
	}
	
	public function MetaIndexes_mssql($pTableName,$primary=false, $owner = false)
	{
		return $this->_MetaIndexes_mssql($this->_dataDict->ParseIdentifierName($pTableName),
				$primary, $owner);
	}

	protected function _MetaIndexes_mssql($pParsedTableName,$primary=false, $owner = false)
	{
		$table = $pParsedTableName['table']['name'];
		$table = strtolower($this->qstr($table));

		$sql = "SELECT i.name AS ind_name, C.name AS col_name, USER_NAME(O.uid) AS Owner, c.colid, k.Keyno,
			CASE WHEN I.indid BETWEEN 1 AND 254 AND (I.status & 2048 = 2048 OR I.Status = 16402 AND O.XType = 'V') THEN 1 ELSE 0 END AS IsPK,
			CASE WHEN I.status & 2 = 2 THEN 1 ELSE 0 END AS IsUnique
			FROM dbo.sysobjects o INNER JOIN dbo.sysindexes I ON o.id = i.id
			INNER JOIN dbo.sysindexkeys K ON I.id = K.id AND I.Indid = K.Indid
			INNER JOIN dbo.syscolumns c ON K.id = C.id AND K.colid = C.Colid
			WHERE LEFT(i.name, 8) <> '_WA_Sys_' AND o.status >= 0 AND lower(O.Name) = $table
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

	public function IfNull( $field, $ifNull )
	{
		switch( $this->odbc_driver ) {
			case ODB_DRIVER_MSSQL:
				return " ISNULL($field, $ifNull) ";
			case ODB_DRIVER_JET:
				return " IIF(IsNull($field), $ifNull, $field) ";
		}
		return " CASE WHEN $field is null THEN $ifNull ELSE $field END ";
	}

	protected function _query($sql,$inputarr=false)
	{
		$last_php_error = $this->resetLastError();
		$this->_errorMsg = false;
		$this->_errorCode = false;

 		if ($inputarr) {
			if (is_array($sql)) {
				$stmtid = $sql[1];
			} else {
				$stmtid = @odbtp_prepare($sql,$this->_connectionID);
				if ($stmtid == false) {
					$this->_errorMsg = $this->getChangedErrorMsg($last_php_error);
					return false;
				}
			}
			$num_params = @odbtp_num_params( $stmtid );
			/*
			for( $param = 1; $param <= $num_params; $param++ ) {
				@odbtp_input( $stmtid, $param );
				@odbtp_set( $stmtid, $param, $inputarr[$param-1] );
			}*/

			$param = 1;
			foreach($inputarr as $v) {
				@odbtp_input( $stmtid, $param );
				@odbtp_set( $stmtid, $param, $v );
				$param += 1;
				if ($param > $num_params) break;
			}

			if (!@odbtp_execute($stmtid) ) {
				return false;
			}
		} else if (is_array($sql)) {
			$stmtid = $sql[1];
			if (!@odbtp_execute($stmtid)) {
				return false;
			}
		} else {
			$stmtid = odbtp_query($sql,$this->_connectionID);
   		}
		$this->_lastAffectedRows = 0;
		if ($stmtid) {
				$this->_lastAffectedRows = @odbtp_affected_rows($stmtid);
		}
        return $stmtid;
	}

	protected function _close()
	{
		$ret = @odbtp_close($this->_connectionID);
		$this->_connectionID = false;
		return $ret;
	}

	public function odbtp_setIsToEnableNativeSqlParameterBinding($pIsToEnableNativeSqlParameterBinding)
	{
		if(!$this->gOdbtp__CanOverrideBindInputArray)
			{return ($this->_bindInputArray === ($pIsToEnableNativeSqlParameterBinding ? true : false));}
		else
		{
			$this->_bindInputArray = ($pIsToEnableNativeSqlParameterBinding ? true : false);
		
			return true;
		}
	}
}

class ADORecordSet_odbtp extends ADORecordSet {

	public  $databaseType = 'odbtp';
	public  $canSeek = true;

	protected function _initrs()
	{
		$this->_numOfFields = @odbtp_num_fields($this->_queryID);
		if (!($this->_numOfRows = @odbtp_num_rows($this->_queryID)))
			$this->_numOfRows = -1;

		if (!$this->connection->_useUnicodeSQL) return;

		if ($this->connection->odbc_driver == ODB_DRIVER_JET) {
			if (!@odbtp_get_attr(ODB_ATTR_MAPCHARTOWCHAR,
			                     $this->connection->_connectionID))
			{
				for ($f = 0; $f < $this->_numOfFields; $f++) {
					if (@odbtp_field_bindtype($this->_queryID, $f) == ODB_CHAR)
						@odbtp_bind_field($this->_queryID, $f, ODB_WCHAR);
				}
			}
		}
	}

	protected function _FetchField($fieldOffset = -1)
	{
		if($fieldOffset === -1)
			{$fieldOffset = 0;}

		$off=$fieldOffset; // offsets begin at 0
		$o= new ADOFieldObject();
		$o->name = @odbtp_field_name($this->_queryID,$off);
		$o->type = @odbtp_field_type($this->_queryID,$off);
        $o->max_length = @odbtp_field_length($this->_queryID,$off);

		if(($o->name === false) && ($o->type === false) &&
				($o->max_length === false))
			{return false;}

		return $o;
	}

	protected function _seek($row)
	{
		return @odbtp_data_seek($this->_queryID, $row);
	}

	protected function _fetch_odbtp($type=0)
	{
		$this->bind = false;
		switch ($this->fetchMode) {
			case ADODB_FETCH_NUM:
				$this->fields = @odbtp_fetch_row($this->_queryID, $type);
				break;
			case ADODB_FETCH_ASSOC:
				$this->fields = @odbtp_fetch_assoc($this->_queryID, $type);
				break;
            default:
				$this->fields = @odbtp_fetch_array($this->_queryID, $type);
		}
		if ($this->databaseType = 'odbtp_vfp') {
			if ($this->fields)
			foreach($this->fields as $k => $v) {
				if (strncmp($v,'1899-12-30',10) == 0) $this->fields[$k] = '';
			}
		}
		return is_array($this->fields);
	}

	protected function _fetch()
	{
		$this->bind = false;
		return $this->_fetch_odbtp();
	}

	protected function _MoveFirst()
	{
		if (!$this->_fetch_odbtp(ODB_FETCH_FIRST)) return false;
		$this->EOF = false;
		$this->_currentRow = 0;
		return true;
    }

	protected function _MoveLast()
	{
		if (!$this->_fetch_odbtp(ODB_FETCH_LAST)) return false;
		$this->EOF = false;
		$this->_currentRow = $this->_numOfRows - 1;
		return true;
	}

	protected function _NextRecordSet()
	{
		if (!@odbtp_next_result($this->_queryID)) return false;
		return true;
	}

	protected function _close()
	{
		return @odbtp_free_query($this->_queryID);
	}
}

class ADORecordSet_odbtp_mssql extends ADORecordSet_odbtp {

	public  $databaseType = 'odbtp_mssql';

}

class ADORecordSet_odbtp_access extends ADORecordSet_odbtp {

	public  $databaseType = 'odbtp_access';

}

class ADORecordSet_odbtp_vfp extends ADORecordSet_odbtp {

	public  $databaseType = 'odbtp_vfp';

}

class ADORecordSet_odbtp_oci8 extends ADORecordSet_odbtp {

	public  $databaseType = 'odbtp_oci8';

}

class ADORecordSet_odbtp_sybase extends ADORecordSet_odbtp {

	public  $databaseType = 'odbtp_sybase';

}
