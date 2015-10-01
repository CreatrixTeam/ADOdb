<?php

/**
  V5.20dev  ??-???-2014  (c) 2000-2014 John Lim (jlim#natsoft.com). All rights reserved.
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Set tabs to 4 for best viewing.

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB2_ibase extends ADODB_DataDict {

	public  $databaseType = 'ibase';
	public  $seqField = false;
	public  $sql_concatenateOperator = '||';
	public  $sql_sysDate = "cast('TODAY' as timestamp)";
	public  $sql_sysTimeStamp = "CURRENT_TIMESTAMP"; //"cast('NOW' as timestamp)";


 	public function ActualType($meta)
	{
		switch($meta) {
		case 'C': return 'VARCHAR';
		case 'XL':
		case 'X': return 'VARCHAR(4000)';

		case 'C2': return 'VARCHAR'; // up to 32K
		case 'X2': return 'VARCHAR(4000)';

		case 'B': return 'BLOB';

		case 'D': return 'DATE';
		case 'TS':
		case 'T': return 'TIMESTAMP';

		case 'L': return 'SMALLINT';
		case 'I': return 'INTEGER';
		case 'I1': return 'SMALLINT';
		case 'I2': return 'SMALLINT';
		case 'I4': return 'INTEGER';
		case 'I8': return 'INTEGER';

		case 'F': return 'DOUBLE PRECISION';
		case 'N': return 'DECIMAL';
		default:
			return $meta;
		}
	}

	public function AlterColumnSQL($tabname, $flds, $tableflds='', $tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("AlterColumnSQL not supported");
		return array();
	}


	public function DropColumnSQL($tabname, $flds, $tableflds='', $tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("DropColumnSQL not supported");
		return array();
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
				$s .= $this->connection->qstr($ch);
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
		return array
		(
			"INSERT INTO RDB\$GENERATORS (RDB\$GENERATOR_NAME) VALUES (UPPER('$pParsedSequenceName[name]'))",
			"SET GENERATOR $pParsedSequenceName[name] TO ".($pStartID-1).';'
		);
	}

	protected function _DropSequenceSQL($pParsedSequenceName)
	{
		return array("delete from RDB\$GENERATORS where RDB\$GENERATOR_NAME='".
				strtoupper($pParsedSequenceName['name'])."'");
	}
	
	protected function _GenIDSQL($pParsedSequenceName)
		{return array ("SELECT Gen_ID($pParsedSequenceName[name],1) FROM RDB\$DATABASE");}
}
