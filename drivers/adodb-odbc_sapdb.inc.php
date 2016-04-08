<?php
/*
@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.
Set tabs to 4 for best viewing.

  Latest version is available at http://adodb.sourceforge.net

  SAPDB data driver. Requires ODBC.

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

if (!defined('_ADODB_ODBC_LAYER')) {
	include(ADODB_DIR."/drivers/adodb-odbc.inc.php");
}
if (!defined('ADODB_SAPDB')){
define('ADODB_SAPDB',1);

class ADODB_odbc_sapdb extends ADODB_odbc {
	public  $databaseType = "odbc_sapdb";
	public  $fmtDate = "'Y-m-d'";	/// used by DBDate() as the default date format used by the database
	public  $fmtTimeStamp = "'Y-m-d H:i:s'"; /// used by DBTimeStamp as the default timestamp fmt.
	public  $hasInsertId = true;
	protected  $_bindInputArray = true;
	public  $hasGenID = true;

	public function ServerInfo()
	{
		$info = ADODB_odbc::ServerInfo();
		if (!$info['version'] && preg_match('/([0-9.]+)/',$info['description'],$matches)) {
			$info['version'] = $matches[1];
		}
		return $info;
	}

	protected function _MetaPrimaryKeys($pParsedTableName, $owner = false)
	{
		$table = $pParsedTableName['table']['name'];
		$table = $this->Quote(strtoupper($table));

		return $this->GetCol("SELECT columnname FROM COLUMNS WHERE UPPER(tablename)=$table AND mode='KEY' ORDER BY pos");
	}

	protected function _MetaIndexes ($pParsedTableName, $primary = FALSE, $owner = false)
	{
		$table = $pParsedTableName['table']['name'];
		$table = $this->Quote(strtoupper($table));

		$sql = "SELECT INDEXNAME,TYPE,COLUMNNAME FROM INDEXCOLUMNS ".
			" WHERE UPPER(TABLENAME)=$table".
			" ORDER BY INDEXNAME,COLUMNNO";

		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$rs = $this->Execute($sql);
		$this->SetFetchMode2($savem);

		if (!is_object($rs)) {
			return FALSE;
		}

		$indexes = array();
		while ($row = $rs->FetchRow()) {
			$indexes[$row[0]]['unique'] = $row[1] == 'UNIQUE';
			$indexes[$row[0]]['columns'][] = $row[2];
		}
		if ($primary) {
			$indexes['SYSPRIMARYKEYINDEX'] = array(
					'unique' => True,	// by definition
					'columns' => $this->GetCol("SELECT columnname FROM COLUMNS WHERE UPPER(tablename)=$table AND mode='KEY' ORDER BY pos"),
				);
		}
		return $indexes;
	}

 	protected function _MetaColumns ($pParsedTableName)
	{
		$table = $pParsedTableName['table']['name'];
		$savem = $this->SetFetchMode2(ADODB_FETCH_NUM);

		$table = $this->Quote(strtoupper($table));

		$retarr = array();
		foreach($this->GetAll("SELECT COLUMNNAME,DATATYPE,LEN,DEC,NULLABLE,MODE,\"DEFAULT\",CASE WHEN \"DEFAULT\" IS NULL THEN 0 ELSE 1 END AS HAS_DEFAULT FROM COLUMNS WHERE UPPER(tablename)=$table ORDER BY pos") as $column)
		{
			$fld = new ADOFieldObject();
			$fld->name = $column[0];
			$fld->type = $column[1];
			$fld->max_length = $fld->type == 'LONG' ? 2147483647 : $column[2];
			$fld->scale = $column[3];
			$fld->not_null = $column[4] == 'NO';
			$fld->primary_key = $column[5] == 'KEY';
			if ($fld->has_default = $column[7]) {
				if ($fld->primary_key && $column[6] == 'DEFAULT SERIAL (1)') {
					$fld->auto_increment = true;
					$fld->has_default = false;
				} else {
					$fld->default_value = $column[6];
					switch($fld->type) {
						case 'VARCHAR':
						case 'CHARACTER':
						case 'LONG':
							$fld->default_value = $column[6];
							break;
						default:
							$fld->default_value = trim($column[6]);
							break;
					}
				}
			}
			
			if($this->GetFetchMode() == ADODB_FETCH_NUM)
				{$retarr[] = $fld;}
			else
				{$retarr[strtoupper($fld->name)] = $fld;}
		}
		$this->SetFetchMode2($savem);

		return $retarr;
	}

	protected function _MetaColumnNames($pParsedTableName, $numIndexes = false, $useattnum = false)
	{
		$table = $pParsedTableName['table']['name'];
		$table = $this->Quote(strtoupper($table));

		return $this->GetCol("SELECT columnname FROM COLUMNS WHERE UPPER(tablename)=$table ORDER BY pos");
	}

	// unlike it seems, this depends on the db-session and works in a multiuser environment
	protected function _insertid($table,$column)
	{
		return empty($table) ? False : $this->GetOne("SELECT $table.CURRVAL FROM DUAL");
	}

	/*
		SelectLimit implementation problems:

		The following will return random 10 rows as order by performed after "WHERE rowno<10"
		which is not ideal...

			select * from table where rowno < 10 order by 1

		This means that we have to use the adoconnection base class SelectLimit when
		there is an "order by".

		See http://listserv.sap.com/pipermail/sapdb.general/2002-January/010405.html
	 */

};


class ADORecordSet_odbc_sapdb extends ADORecordSet_odbc {

	public  $databaseType = "odbc_sapdb";

}

} //define
