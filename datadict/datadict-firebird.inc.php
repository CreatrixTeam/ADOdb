<?php

/**
  @version   v5.21.0-dev  ??-???-2016
  @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
  @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Set tabs to 4 for best viewing.

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB2_firebird extends ADODB_DataDict {

	public  $databaseType = 'firebird';
	public  $seqField = false;
	public  $seqPrefix = 's_';
	public  $blobSize = 40000;
	public  $sql_concatenateOperator = '||';
	public  $sql_sysDate = "cast('TODAY' as timestamp)";
	public  $sql_sysTimeStamp = "CURRENT_TIMESTAMP"; //"cast('NOW' as timestamp)";
	public $renameColumn = 'ALTER TABLE %s ALTER %s TO %s';
	public $alterCol = ' ALTER';
	public $dropCol = ' DROP';
	public $nameQuote = '"';

	public function ActualType($meta)
	{
		switch($meta) {
		case 'C': return 'VARCHAR';
		case 'XL':
		case 'X': return 'BLOB SUB_TYPE TEXT';

		case 'C2': return 'VARCHAR(32765)'; // up to 32K
		case 'X2': return 'VARCHAR(4096)';

		case 'V': return 'CHAR';
		case 'C1': return 'CHAR(1)';

		case 'B': return 'BLOB';

		case 'D': return 'DATE';
		case 'TS':
		case 'T': return 'TIMESTAMP';

		case 'L': return 'SMALLINT';
		case 'I': return 'INTEGER';
		case 'I1': return 'SMALLINT';
		case 'I2': return 'SMALLINT';
		case 'I4': return 'INTEGER';
		case 'I8': return 'BIGINT';

		case 'F': return 'DOUBLE PRECISION';
		case 'N': return 'DECIMAL';
		default:
			return $meta;
		}
	}

	public function CreateDatabase($dbname, $options=false)
	{
		$options = $this->_Options($options);
		$sql = array();

		$sql[] = "DECLARE EXTERNAL FUNCTION LOWER CSTRING(80) RETURNS CSTRING(80) FREE_IT ENTRY_POINT 'IB_UDF_lower' MODULE_NAME 'ib_udf'";

		return $sql;
	}

	protected function _DropAutoIncrement($t)
	{
		if (strpos($t,'.') !== false) {
			$tarr = explode('.',$t);
			return 'DROP GENERATOR '.$tarr[0].'."s_'.$tarr[1].'"';
		}
		return 'DROP GENERATOR s_'.$t;
	}


	protected function _CreateSuffix($fname,&$ftype,$fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned)
	{
		$suffix = '';

		if (strlen($fdefault)) $suffix .= " DEFAULT $fdefault";
		if ($fnotnull) $suffix .= ' NOT NULL';
		if ($fautoinc) $this->seqField = $fname;
		$fconstraint = preg_replace("/``/", "\"", $fconstraint);
		if ($fconstraint) $suffix .= ' '.$fconstraint;

		return $suffix;
	}

	/**
	 Generate the SQL to create table. Returns an array of sql strings.
	*/
	public function CreateTableSQL($tabname, $flds, $tableoptions=array())
	{
		list($lines,$pkey,$idxs) = $this->_GenFields($flds, true);
		// genfields can return FALSE at times
		if ($lines == null) $lines = array();

		$taboptions = $this->_Options($tableoptions);
		$tabname = $this->TableName ($tabname);
		$sql = $this->_TableSQL($tabname,$lines,$pkey,$taboptions);

		if ($this->autoIncrement && !isset($taboptions['DROP']))
		{ $tsql = $this->_Triggers($tabname,$taboptions);
			foreach($tsql as $s) $sql[] = $s;
		}

		if (is_array($idxs)) {
			foreach($idxs as $idx => $idxdef) {
				$sql_idxs = $this->CreateIndexSql($idx, $tabname,  $idxdef['cols'], $idxdef['opts']);
				$sql = array_merge($sql, $sql_idxs);
			}
		}

		return $sql;
	}


/*
CREATE or replace TRIGGER jaddress_insert
before insert on jaddress
for each row
begin
IF ( NEW."seqField" IS NULL OR NEW."seqField" = 0 ) THEN
  NEW."seqField" = GEN_ID("GEN_tabname", 1);
end;
*/
	protected function _Triggers($tabname,$tableoptions)
	{
		if (!$this->seqField) return array();

		$tab1 = preg_replace( '/"/', '', $tabname );
		if ($this->schema) {
			$t = strpos($tab1,'.');
			if ($t !== false) $tab = substr($tab1,$t+1);
			else $tab = $tab1;
			$seqField = $this->seqField;
			$seqname = $this->schema.'.'.$this->seqPrefix.$tab;
			$trigname = $this->schema.'.t_'.$this->seqPrefix.$tab;
		} else {
			$seqField = $this->seqField;
			$seqname = $this->seqPrefix.$tab1;
			$trigname = 't_'.$seqname;
		}

		if (isset($tableoptions['DROP']))
		{ $sql[] = "DROP GENERATOR $seqname";
		}
		elseif (isset($tableoptions['REPLACE']))
		{ $sql[] = "DROP GENERATOR \"$seqname\"";
		  $sql[] = "CREATE GENERATOR \"$seqname\"";
		  $sql[] = "ALTER TRIGGER \"$trigname\" BEFORE INSERT OR UPDATE AS BEGIN IF ( NEW.$seqField IS NULL OR NEW.$seqField = 0 ) THEN NEW.$seqField = GEN_ID(\"$seqname\", 1); END";
		}
		else
		{ $sql[] = "CREATE GENERATOR $seqname";
		  $sql[] = "CREATE TRIGGER $trigname FOR $tabname BEFORE INSERT OR UPDATE AS BEGIN IF ( NEW.$seqField IS NULL OR NEW.$seqField = 0 ) THEN NEW.$seqField = GEN_ID($seqname, 1); END";
		}

		$this->seqField = false;
		return $sql;
	}

	/**
	 * Change the definition of one column
	 *
	 * As some DBM's can't do that on there own, you need to supply the complete definition of the new table,
	 * to allow, recreating the table and copying the content over to the new table
	 * @param string $tabname table-name
	 * @param string $flds column-name and type for the changed column
	 * @param string $tableflds='' complete definition of the new table, eg. for postgres, default ''
	 * @param array/string $tableoptions='' options for the new table see CreateTableSQL, default ''
	 * @return array with SQL strings
	 */
	public function AlterColumnSQL($tabname, $flds, $tableflds='',$tableoptions='')
	{
		$tabname = $this->TableName ($tabname);
		$sql = array();
		list($lines,$pkey,$idxs) = $this->_GenFields($flds);
		// genfields can return FALSE at times
		if ($lines == null) $lines = array();
		$alter = 'ALTER TABLE ' . $tabname . $this->alterCol . ' ';
		foreach($lines as $v) {
			$sql[] = $alter . $v;
		}
		if (is_array($idxs)) {
			foreach($idxs as $idx => $idxdef) {
				$sql_idxs = $this->CreateIndexSql($idx, $tabname, $idxdef['cols'], $idxdef['opts']);
				$sql = array_merge($sql, $sql_idxs);
			}

		}
		return $sql;
	}

	public function ChangeTableSQL($pTableName, $pTableFields, $pTableOptions = false, 
			$pIsToDropOldFields = false)
	{
		$vPreviousFetchMode = $this->connection->SetFetchMode2(ADODB_FETCH_ASSOC);
		$vRaiseErrorFn = NULL;
		$vCurrentTableFields = NULL;

		// check table exists
		$vRaiseErrorFn = $this->connection->raiseErrorFn;
		$this->connection->raiseErrorFn = '';
		$vCurrentTableFields = $this->MetaColumns($pTableName);
		$this->connection->raiseErrorFn = $vRaiseErrorFn;

		$this->connection->SetFetchMode2($vPreviousFetchMode);

		if(empty($vCurrentTableFields))
			{return $this->CreateTableSQL($pTableName, $pTableFields, $pTableOptions);}
		else
		{
			$tSQLs = array();
			$t_GenFields_lines = NULL;
			$tFieldsToAlter = array();
			$tFieldsToAdd = array();
			$tFieldsToDrop = NULL;
			$tSQL2 = "";
			$tSQL3 = "";
			$tIsFirstColumn = true;
			
			list($t_GenFields_lines, $pkey, $idxs) = $this->_GenFields($pTableFields);
			if($t_GenFields_lines == null)
				{$t_GenFields_lines = array();}
			
			foreach($t_GenFields_lines as $tID => $tV) 
			{
				if(isset($vCurrentTableFields[$tID]) && is_object($vCurrentTableFields[$tID]))
					{$tFieldsToAlter[$tID] = $pTableFields[$tID];}
				else
					{$tFieldsToAdd[$tID] = $pTableFields[$tID];}
			}
			if($pIsToDropOldFields)
			{
				$tFieldsToDrop = array();
				foreach($vCurrentTableFields as $tID => $tV)
				{
					if(!isset($t_GenFields_lines[$tID]))
						{$tFieldsToDrop[$tID] = $tID;}
				}
			}

			$tSQLs = array_merge($tSQLs, 
					$this->CreateTableSQL('`tempdfdfkjueb3`', $pTableFields, $pTableOptions));
			foreach($pTableFields as $tTableField)
			{
				if($tIsFirstColumn)
					{$tIsFirstColumn = false;}
				else
				{
					$tSQL2 .= " ,";
					$tSQL3 .= " ,";
				}
				$tSQL2 .= $this->NameQuote($tTableField['NAME']);
				$tSQL3 .= "CAST(".$this->NameQuote($tTableField['NAME'])." AS ".
						$this->ActualType(strtoupper($tTableField['TYPE']));
				
				if($tTableField['SIZE'])
					{$tSQL3 .= "(".$tTableField['SIZE'].")";}

				$tSQL3 .= ")";
			}
			$tSQLs[] = "INSERT INTO ".$this->TableName('`tempdfdfkjueb3`')."(".$tSQL2.") ".
					"SELECT $tSQL3 FROM ".$this->TableName($pTableName);
			$tSQLs[] = "DROP TABLE ".$this->TableName($pTableName);
			$tSQLs = array_merge($tSQLs, 
					$this->CreateTableSQL($pTableName, $pTableFields, $pTableOptions));
			$tSQLs[] = "INSERT INTO ".$this->TableName($pTableName)." SELECT * FROM ".
					$this->TableName('`tempdfdfkjueb3`');
			$tSQLs[] = "DROP TABLE ".$this->TableName('`tempdfdfkjueb3`');
			//$tSQLs[] = "COMMIT";
			//echo("<pre>");print_r($tSQLs);echo("</pre>");
			return $tSQLs;
		}
	}

	// Format date column in sql string given an input format that understands Y M D
	// Only since Interbase 6.0 - uses EXTRACT
	// problem - does not zero-fill the day and month yet
	protected function _FormatDateSQL($fmt, $pParsedColumnName=false)
	{
		$col = false;

		if($pParsedColumnName)
			{$col = $pParsedColumnName['name'];}

		if (!$col) $col = $this->sql_sysDate;
		$s = '';

		$len = strlen($fmt);
		for ($i=0; $i < $len; $i++) {
			if ($s) $s .= '||';
			$ch = $fmt[$i];
			switch($ch) {
			case 'Y':
			case 'y':
				$s .= "extract(year from $col)";
				break;
			case 'M':
			case 'm':
				$s .= "extract(month from $col)";
				break;
			case 'W':
			case 'w':
				// The more accurate way of doing this is with a stored procedure
				// See http://wiki.firebirdsql.org/wiki/index.php?page=DATE+Handling+Functions for details
				$s .= "((extract(yearday from $col) - extract(weekday from $col - 1) + 7) / 7)";
				break;
			case 'Q':
			case 'q':
				$s .= "cast(((extract(month from $col)+2) / 3) as integer)";
				break;
			case 'D':
			case 'd':
				$s .= "(extract(day from $col))";
				break;
			case 'H':
			case 'h':
				$s .= "(extract(hour from $col))";
				break;
			case 'I':
			case 'i':
				$s .= "(extract(minute from $col))";
				break;
			case 'S':
			case 's':
				$s .= "CAST((extract(second from $col)) AS INTEGER)";
				break;

			default:
				if ($ch == '\\') {
					$i++;
					$ch = substr($fmt,$i,1);
				}
				$s .= $this->qstr($ch);
				break;
			}
		}
		return (empty($s) ? array() : array($s));
	}
	
	public function RowLockSQL($table,$where,$col=false)
	{
		return array("UPDATE $table SET $col=$col WHERE $where "); // is this correct - jlim?
	}
	
	protected function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
	{
		if($this->databaseType !== "pdo_firebird")
		{
			return array
			(
				"CREATE GENERATOR $pParsedSequenceName[name]",
				"SET GENERATOR $pParsedSequenceName[name] TO ".($pStartID-1).';'
			);
		}
		else
		{
			return array
			(
				"CREATE SEQUENCE $pParsedSequenceName[name]",
				"ALTER SEQUENCE $pParsedSequenceName[name] RESTART WITH " . ($pStartID - 1)
			);
		}
	}
	
	protected function _DropSequenceSQL($pParsedSequenceName)
	{
		if($this->databaseType !== "pdo_firebird")
		{
			return array("DROP GENERATOR ".
					strtoupper($pParsedSequenceName['name']));
		}
		else
		{
			return array("DROP SEQUENCE ".
					strtoupper($pParsedSequenceName['name']));
		}
	}
	
	protected function _GenIDSQL($pParsedSequenceName)
		{return array ("SELECT Gen_ID($pParsedSequenceName[name],1) FROM RDB\$DATABASE");}
}
