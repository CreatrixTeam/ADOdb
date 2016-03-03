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

/*
In ADOdb, named quotes for MS SQL Server use ". From the MSSQL Docs:

	Note Delimiters are for identifiers only. Delimiters cannot be used for keywords,
	whether or not they are marked as reserved in SQL Server.

	Quoted identifiers are delimited by double quotation marks ("):
	SELECT * FROM "Blanks in Table Name"

	Bracketed identifiers are delimited by brackets ([ ]):
	SELECT * FROM [Blanks In Table Name]

	Quoted identifiers are valid only when the QUOTED_IDENTIFIER option is set to ON. By default,
	the Microsoft OLE DB Provider for SQL Server and SQL Server ODBC driver set QUOTED_IDENTIFIER ON
	when they connect.

	In Transact-SQL, the option can be set at various levels using SET QUOTED_IDENTIFIER,
	the quoted identifier option of sp_dboption, or the user options option of sp_configure.

	When SET ANSI_DEFAULTS is ON, SET QUOTED_IDENTIFIER is enabled.

	Syntax

		SET QUOTED_IDENTIFIER { ON | OFF }


*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB2_mssql extends ADODB_DataDict {
	public  $databaseType = 'mssql';
	public  $dropIndex = 'DROP INDEX %2$s.%1$s';
	public  $renameTable = "EXEC sp_rename '%s','%s'";
	public  $renameColumn = "EXEC sp_rename '%s.%s','%s'";
	public  $sql_sysDate = 'convert(datetime,convert(char,GetDate(),102),102)';
	public  $sql_sysTimeStamp = 'GetDate()';

	public  $typeX = 'TEXT';  ## Alternatively, set it to VARCHAR(4000)
	public  $typeXL = 'TEXT';

	//public  $alterCol = ' ALTER COLUMN ';

	protected function _event_connectionSet($pADOConnection)
	{
		if($this->dataProvider === "mssqlnative")
			{$this->dropIndex = 'DROP INDEX %1$s ON %2$s';}
		if($this->databaseType === "odbc_mssql")
		{
			$this->sql_sysDate = 'GetDate()';			
			$pADOConnection->sysDate = $this->sql_sysDate;
		}
		if($this->databaseType === "mssqlpo")
		{
			$this->sql_concatenateOperator = '||';
			$pADOConnection->concat_operator = $this->sql_concatenateOperator;
		}
	}
	public function MetaType($t,$len=-1,$fieldobj=false)
	{
		if (is_object($t)) {
			$fieldobj = $t;
			$t = $fieldobj->type;
			$len = $fieldobj->max_length;
		}

		if($this->dataProvider !== "mssqlnative")
		{
			$len = -1; // mysql max_length is not accurate
			switch (strtoupper($t)) {
				case 'R':
				case 'INT':
				case 'INTEGER': return  'I';
				case 'BIT':
				case 'TINYINT': return  'I1';
				case 'SMALLINT': return 'I2';
				case 'BIGINT':  return  'I8';
				case 'SMALLDATETIME': return 'T';
				case 'REAL':
				case 'FLOAT': return 'F';
				default: return parent::MetaType($t,$len,$fieldobj);
			}
		}
		else
		{//WARNING: THIS IMPLEMENTATION DOES NOT APPEAR TO HOLD TO APPARANT SPECIFICATION.
			$_typeConversion = array(
				-155 => 'D',
				  93 => 'D',
				-154 => 'D',
				  -2 => 'D',
				  91 => 'D',

				  12 => 'C',
				   1 => 'C',
				  -9 => 'C',
				  -8 => 'C',

				  -7 => 'L',
				  -6 => 'I2',
				  -5 => 'I8',
				 -11 => 'I',
				   4 => 'I',
				   5 => 'I4',

				  -1 => 'X',
				 -10 => 'X',

				   2 => 'N',
				   3 => 'N',
				   6 => 'N',
				   7 => 'N',

				-152 => 'X',
				-151 => 'X',
				  -4 => 'X',
				  -3 => 'X'
				);

			return $_typeConversion($t);
		}
	}

	public function ActualType($meta)
	{
		if($this->dataProvider !== "mssqlnative")
		{
			switch(strtoupper($meta)) {

				case 'C': return 'VARCHAR';
				case 'XL': return (isset($this)) ? $this->typeXL : 'TEXT';
				case 'X': return (isset($this)) ? $this->typeX : 'TEXT'; ## could be varchar(8000), but we want compat with oracle
				case 'C2': return 'NVARCHAR';
				case 'X2': return 'NTEXT';

				case 'B': return 'IMAGE';

				case 'D': return 'DATETIME';

				case 'TS':
				case 'T': return 'DATETIME';
				case 'L': return 'BIT';

				case 'R':
				case 'I': return 'INT';
				case 'I1': return 'TINYINT';
				case 'I2': return 'SMALLINT';
				case 'I4': return 'INT';
				case 'I8': return 'BIGINT';

				case 'F': return 'REAL';
				case 'N': return 'NUMERIC';
				default:
					return $meta;
			}
		}
		else
		{
			$DATE_TYPE = 'DATETIME';

			switch(strtoupper($meta)) {

				case 'C': return 'VARCHAR';
				case 'XL': return (isset($this)) ? $this->typeXL : 'TEXT';
				case 'X': return (isset($this)) ? $this->typeX : 'TEXT'; ## could be varchar(8000), but we want compat with oracle
				case 'C2': return 'NVARCHAR';
				case 'X2': return 'NTEXT';

				case 'B': return 'IMAGE';

				case 'D': return $DATE_TYPE;
				case 'T': return 'TIME';
				case 'L': return 'BIT';

				case 'R':
				case 'I': return 'INT';
				case 'I1': return 'TINYINT';
				case 'I2': return 'SMALLINT';
				case 'I4': return 'INT';
				case 'I8': return 'BIGINT';

				case 'F': return 'REAL';
				case 'N': return 'NUMERIC';
				default:
					print "RETURN $meta";
					return $meta;
			}	
		}
	}


	public function AddColumnSQL($tabname, $flds)
	{
		$tabname = $this->TableName ($tabname);
		$f = array();
		list($lines,$pkey) = $this->_GenFields($flds);
		$s = "ALTER TABLE $tabname $this->addCol";
		foreach($lines as $v) {
			$f[] = "\n $v";
		}
		$s .= implode(', ',$f);
		$sql[] = $s;
		return $sql;
	}

	/*
	public function AlterColumnSQL($tabname, $flds, $tableflds='', $tableoptions='')
	{
		$tabname = $this->TableName ($tabname);
		$sql = array();
		list($lines,$pkey) = $this->_GenFields($flds);
		foreach($lines as $v) {
			$sql[] = "ALTER TABLE $tabname $this->alterCol $v";
		}

		return $sql;
	}
	*/

	/**
	 * Drop a column, syntax is ALTER TABLE table DROP COLUMN column,column
	 *
	 * @param string   $tabname      Table Name
	 * @param string[] $flds         One, or an array of Fields To Drop
	 * @param string   $tableflds    Throwaway value to make the function match the parent
	 * @param string   $tableoptions Throway value to make the function match the parent
	 *
	 * @return string  The SQL necessary to drop the column
	 */
	public function DropColumnSQL($tabname, $flds, $tableflds='',$tableoptions='')
	{
		if($this->dataProvider !== "mssqlnative")
		{
			$tabname = $this->TableName ($tabname);
			if (!is_array($flds))
				$flds = explode(',',$flds);
			$f = array();
			$s = 'ALTER TABLE ' . $tabname;
			foreach($flds as $v) {
				$f[] = "\n$this->dropCol ".$this->NameQuote($v);
			}
			$s .= implode(', ',$f);
			$sql[] = $s;
			return $sql;
		}
		else
		{
			$tabname = $this->TableName ($tabname);
			if (!is_array($flds))
				$flds = explode(',',$flds);
			$f = array();
			$s = 'ALTER TABLE ' . $tabname . ' DROP COLUMN ';
			foreach($flds as $v) {
				//$f[] = "\n$this->dropCol ".$this->NameQuote($v);
				$f[] = $this->NameQuote($v);
			}
			$s .= implode(', ',$f);
			$sql[] = $s;
			return $sql;
		}
	}

	// return string must begin with space
	protected function _CreateSuffix($fname,&$ftype,$fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned)
	{
		$suffix = '';
		if (strlen($fdefault)) $suffix .= " DEFAULT $fdefault";
		if ($fautoinc) $suffix .= ' IDENTITY(1,1)';
		if ($fnotnull) $suffix .= ' NOT NULL';
		else if ($suffix == '') $suffix .= ' NULL';
		if ($fconstraint) $suffix .= ' '.$fconstraint;
		return $suffix;
	}

	/*
CREATE TABLE
    [ database_name.[ owner ] . | owner. ] table_name
    ( { < column_definition >
        | column_name AS computed_column_expression
        | < table_constraint > ::= [ CONSTRAINT constraint_name ] }

            | [ { PRIMARY KEY | UNIQUE } [ ,...n ]
    )

[ ON { filegroup | DEFAULT } ]
[ TEXTIMAGE_ON { filegroup | DEFAULT } ]

< column_definition > ::= { column_name data_type }
    [ COLLATE < collation_name > ]
    [ [ DEFAULT constant_expression ]
        | [ IDENTITY [ ( seed , increment ) [ NOT FOR REPLICATION ] ] ]
    ]
    [ ROWGUIDCOL]
    [ < column_constraint > ] [ ...n ]

< column_constraint > ::= [ CONSTRAINT constraint_name ]
    { [ NULL | NOT NULL ]
        | [ { PRIMARY KEY | UNIQUE }
            [ CLUSTERED | NONCLUSTERED ]
            [ WITH FILLFACTOR = fillfactor ]
            [ON {filegroup | DEFAULT} ] ]
        ]
        | [ [ FOREIGN KEY ]
            REFERENCES ref_table [ ( ref_column ) ]
            [ ON DELETE { CASCADE | NO ACTION } ]
            [ ON UPDATE { CASCADE | NO ACTION } ]
            [ NOT FOR REPLICATION ]
        ]
        | CHECK [ NOT FOR REPLICATION ]
        ( logical_expression )
    }

< table_constraint > ::= [ CONSTRAINT constraint_name ]
    { [ { PRIMARY KEY | UNIQUE }
        [ CLUSTERED | NONCLUSTERED ]
        { ( column [ ASC | DESC ] [ ,...n ] ) }
        [ WITH FILLFACTOR = fillfactor ]
        [ ON { filegroup | DEFAULT } ]
    ]
    | FOREIGN KEY
        [ ( column [ ,...n ] ) ]
        REFERENCES ref_table [ ( ref_column [ ,...n ] ) ]
        [ ON DELETE { CASCADE | NO ACTION } ]
        [ ON UPDATE { CASCADE | NO ACTION } ]
        [ NOT FOR REPLICATION ]
    | CHECK [ NOT FOR REPLICATION ]
        ( search_conditions )
    }


	*/

	/*
	CREATE [ UNIQUE ] [ CLUSTERED | NONCLUSTERED ] INDEX index_name
    ON { table | view } ( column [ ASC | DESC ] [ ,...n ] )
		[ WITH < index_option > [ ,...n] ]
		[ ON filegroup ]
		< index_option > :: =
		    { PAD_INDEX |
		        FILLFACTOR = fillfactor |
		        IGNORE_DUP_KEY |
		        DROP_EXISTING |
		    STATISTICS_NORECOMPUTE |
		    SORT_IN_TEMPDB
		}
*/
	protected function _IndexSQL($idxname, $tabname, $flds, $idxoptions)
	{
		$sql = array();

		if ( isset($idxoptions['REPLACE']) || isset($idxoptions['DROP']) ) {
			$sql[] = sprintf ($this->dropIndex, $idxname, $tabname);
			if ( isset($idxoptions['DROP']) )
				return $sql;
		}

		if ( empty ($flds) ) {
			return $sql;
		}

		$unique = isset($idxoptions['UNIQUE']) ? ' UNIQUE' : '';
		$clustered = isset($idxoptions['CLUSTERED']) ? ' CLUSTERED' : '';

		if ( is_array($flds) )
			$flds = implode(', ',$flds);
		$s = 'CREATE' . $unique . $clustered . ' INDEX ' . $idxname . ' ON ' . $tabname . ' (' . $flds . ')';

		if ( isset($idxoptions[$this->upperName]) )
			$s .= $idxoptions[$this->upperName];


		$sql[] = $s;

		return $sql;
	}


	protected function _GetSize($ftype, $ty, $fsize, $fprec, $options=false)
	{
		switch ($ftype) {
		case 'INT':
		case 'SMALLINT':
		case 'TINYINT':
		case 'BIGINT':
			return $ftype;
		}
    	if ($ty == 'T') return $ftype;
    	return parent::_GetSize($ftype, $ty, $fsize, $fprec, $options);

	}

	protected function _FormatDateSQL($fmt, $pParsedColumnName=false)
	{
		$col = false;

		if($pParsedColumnName)
			{$col = $pParsedColumnName['name'];}

		if (!$col) $col = $this->sql_sysTimeStamp;
		$s = '';

		$len = strlen($fmt);
		for ($i=0; $i < $len; $i++) {
			if ($s) $s .= '+';
			$ch = $fmt[$i];
			switch($ch) {
			case 'Y':
			case 'y':
				$s .= "datename(yyyy,$col)";
				break;
			case 'M':
				$s .= "convert(char(3),$col,0)";
				break;
			case 'm':
				$s .= "replace(str(month($col),2),' ','0')";
				break;
			case 'Q':
			case 'q':
				$s .= "datename(quarter,$col)";
				break;
			case 'D':
			case 'd':
				$s .= "replace(str(day($col),2),' ','0')";
				break;
			case 'h':
				$s .= "substring(convert(char(14),$col,0),13,2)";
				break;

			case 'H':
				$s .= "replace(str(datepart(hh,$col),2),' ','0')";
				break;

			case 'i':
				$s .= "replace(str(datepart(mi,$col),2),' ','0')";
				break;
			case 's':
				$s .= "replace(str(datepart(ss,$col),2),' ','0')";
				break;
			case 'a':
			case 'A':
				$s .= "substring(convert(char(19),$col,0),18,2)";
				break;

			default:
				if ($ch == '\\') {
					$i++;
					$ch = substr($fmt,$i,1);
				}
				$s .= $this->connection->qstr($ch);
				break;
			}
		}
		return (empty($s) ? array() : array($s));
	}
	
	public function RowLockSQL($tables,$where,$col='1 as adodbignore')
	{
		if ($col == '1 as adodbignore') $col = 'top 1 null as ignore';

		return array("select $col from $tables with (ROWLOCK,HOLDLOCK) where $where");
	}

	protected function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
	{
		$vVersion = @intval($this->_serverInfoArray['version']);

		if($this->databaseType === "odbc_mssql")
		{
			$tStartID = $pStartID - 1;

			return array
			(
				sprintf("create table %s (id integer)", $pParsedSequenceName['name']),
				"insert into $pParsedSequenceName[name] values($tStartID)"
			);
		}
		elseif(($vVersion < 9) || ($this->databaseType === "ado_mssql") ||
				($this->databaseType === "mssql"))
		{
			$tStartID = $pStartID - 1;

			return array
			(
				"create table $pParsedSequenceName[name] (id float(53))",
				"insert into $pParsedSequenceName[name] with (tablock,holdlock) values($tStartID)"
			);
		}
		elseif($vVersion < 11)
		{
			$tStartID = $pStartID - 1;

			return array
			(
				"create table $pParsedSequenceName[name] (id int)", //was float(53)
				"insert into $pParsedSequenceName[name] with (tablock,holdlock) values($tStartID)"
			);
		}
		else
		{
			return array("CREATE SEQUENCE $pParsedSequenceName[name] START WITH $pStartID INCREMENT BY 1");
		}
	}

	protected function _DropSequenceSQL($pParsedSequenceName)
	{
		$vVersion = @intval($this->_serverInfoArray['version']);
		
		if(($vVersion < 11) || ($this->databaseType === "odbc_mssql") ||
				($this->databaseType === "ado_mssql") ||
				($this->databaseType === "mssql"))
			{return array(sprintf("drop table %s", $pParsedSequenceName['name']));}
		else
			{return array(sprintf("DROP SEQUENCE %s", $pParsedSequenceName['name']));}
	}

	protected function _GenIDSQL($pParsedSequenceName)
	{
		$vVersion = @intval($this->_serverInfoArray['version']);

		if(($vVersion < 11) || ($this->databaseType === "odbc_mssql") ||
				($this->databaseType === "ado_mssql") ||
				($this->databaseType === "mssql"))
			{return array("select id from $pParsedSequenceName[name]");}
		else
			{return array("SELECT NEXT VALUE FOR $pParsedSequenceName[name]");}
	}
		
	protected function _event_GenID_calculateAndSetGenID($pParsedSequenceName, $pADORecordSet)
	{
		if($this->databaseType === "odbc_mssql")
		{
			$vNumber = (($pADORecordSet && !$pADORecordSet->EOF) ? reset($pADORecordSet->fields) :
				0);
			$vADORecordSet = $this->connection->Execute(
					"update $pParsedSequenceName[name] set id=id+1 where id=$vNumber");

			if($this->connection->affected_rows() > 0)
				{$this->connection->genID = $vNumber + 1;}
		}
		elseif(($vVersion < 11) || ($this->databaseType === "ado_mssql") ||
				($this->databaseType === "mssql"))
		{
			$vNumber = (($pADORecordSet && !$pADORecordSet->EOF) ? reset($pADORecordSet->fields) :
					0);
			$vADORecordSet = $this->connection->Execute(
					"update $pParsedSequenceName[name] with (tablock,holdlock) set id = id + 1");

			if($this->connection->affected_rows() > 0)
				{$this->connection->genID = $vNumber + 1;}
		}
		else
		{
			$vNumber = (($pADORecordSet && !$pADORecordSet->EOF) ? 
					reset($pADORecordSet->fields) : 0);

			if($vNumber > 0)
				{$this->connection->genID = $vNumber;}
		}
	}

}
