<?php
/**
 * ADOdb PDO dblib driver.
 *
 * Released under both BSD license and Lesser GPL library license.
 * Whenever there is any discrepancy between the two licenses, the BSD license
 * will take precedence.
 *
 * @version   v5.22.0-dev  Unreleased
 * @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
 * @copyright (c) 2019      Damien Regad, Mark Newnham and the ADOdb community
 */
//WARNING: This driver is for MSSql, not Sybase.
class ADODB_pdo_dblib extends ADODB_pdo
{
	public  $databaseType = "pdo_dblib";
	protected  $dsnType = 'dblib_mssql'; 	//ACTUAL DSN SHOULD BE dblib. SEE COMMENTS AT "https://www.php.net/manual/en/ref.pdo-dblib.php"
											//		THE FUNCTION ADODB_pdo::_connect HANDLES THE CONVERSION
											//		REMEMEBER THAT THIS DRIVER IS ASSUMED TO BE FOR MSSQL, NOT SYBASE.
	public $hasTop = 'top';
	public $hasTransactions = true;
	public $hasInsertID = true;
	public $fmtTimeStamp = "'Y-m-d H:i:s'";
	public $fmtDate = "'Y-m-d'";
	public $metaDatabasesSQL = "select name from sysdatabases where name <> 'master'";
	public $metaTablesSQL="select name,case when type='U' then 'T' else 'V' end from sysobjects where (type='U' or type='V') and (name not in ('sysallocations','syscolumns','syscomments','sysdepends','sysfilegroups','sysfiles','sysfiles1','sysforeignkeys','sysfulltextcatalogs','sysindexes','sysindexkeys','sysmembers','sysobjects','syspermissions','sysprotects','sysreferences','systypes','sysusers','sysalternates','sysconstraints','syssegments','REFERENTIAL_CONSTRAINTS','CHECK_CONSTRAINTS','CONSTRAINT_TABLE_USAGE','CONSTRAINT_COLUMN_USAGE','VIEWS','VIEW_TABLE_USAGE','VIEW_COLUMN_USAGE','SCHEMATA','TABLES','TABLE_CONSTRAINTS','TABLE_PRIVILEGES','COLUMNS','COLUMN_DOMAIN_USAGE','COLUMN_PRIVILEGES','DOMAINS','DOMAIN_CONSTRAINTS','KEY_COLUMN_USAGE','dtproperties'))";

	public $metaColumnsSQL = "SELECT c.NAME, OBJECT_NAME(c.id) as tbl_name, c.length, c.isnullable, c.status, ( CASE WHEN c.xusertype=61 THEN 0 ELSE c.xprec END), ( CASE WHEN c.xusertype=61 THEN 0 ELSE c.xscale END), ISNULL(i.is_primary_key, 0) as primary_key FROM   syscolumns c INNER JOIN systypes t ON t.xusertype=c.xusertype INNER JOIN sysobjects o ON o.id=c.id LEFT JOIN sys.index_columns ic ON ic.object_id = c.id AND c.colid = ic.column_id LEFT JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id WHERE c.id = OBJECT_ID('%s') ORDER by c.colid";


	//(ALMOST)VERBATIM COPY FROM adodb-mssql.inc.php
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
		while (!$rs->EOF) {
			$fld = new ADOFieldObject();
			$fld->name = $rs->fields[0];
			$fld->type = $rs->fields[1];
			$fld->primary_key = $rs->fields[7];

			$fld->not_null = (!$rs->fields[3]);
			$fld->auto_increment = ($rs->fields[4] == 128);		// sys.syscolumns status field. 0x80 = 128 ref: http://msdn.microsoft.com/en-us/library/ms186816.aspx

			if (isset($rs->fields[5]) && $rs->fields[5]) {
				if ($rs->fields[5]>0) $fld->max_length = $rs->fields[5];
				$fld->scale = $rs->fields[6];
				if ($fld->scale>0) $fld->max_length += 1;
			} else
				$fld->max_length = $rs->fields[2];

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

	//VERBATIM COPY FROM adodb-mssql.inc.php
	public function SelectLimit($sql,$nrows=-1,$offset=-1, $inputarr=false,$secs2cache=0)
	{
		$nrows = (int) $nrows;
		$offset = (int) $offset;
		if ($nrows > 0 && $offset <= 0) {
			$sql = preg_replace(
				'/(^\s*select\s+(distinctrow|distinct)?)/i','\\1 '.$this->hasTop." $nrows ",$sql);

			if ($secs2cache)
				$rs = $this->CacheExecute($secs2cache, $sql, $inputarr);
			else
				$rs = $this->Execute($sql,$inputarr);
		} else
			$rs = ADOConnection::SelectLimit($sql,$nrows,$offset,$inputarr,$secs2cache);

		return $rs;
	}

	protected function _query($sql,$inputarr=false)
	{
		if (is_array($sql)) {
			$stmt = $sql[1];
		} else {
			$stmt = $this->_connectionID->prepare($sql);
		}

		if ($stmt) {
			$this->_driver->debug = $this->debug;
			if ($inputarr) {
				foreach ($inputarr as $key => $value) {
					if(gettype($key) == 'integer') {
						$key += 1;
					}
					$stmt->bindValue($key, $value, $this->GetPDODataType($value));
				}
			}
		}

		$ok = $stmt->execute();

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

	private function GetPDODataType($var)
	{
		if(gettype($var) == 'integer') {
			return PDO::PARAM_INT ;
		}
		return PDO::PARAM_STR;
	}

	protected function event_pdoConnectionEstablished()
	{
		$this->_connectionID->setAttribute(PDO::ATTR_EMULATE_PREPARES , true);
	}
}
