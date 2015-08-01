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

class ADODB2_db2 extends ADODB_DataDict {

	var $databaseType = 'db2';
	var $seqField = false;
	var $sql_concatenateOperator = '||';
	var $sql_sysDate = 'CURRENT DATE';
	var $sql_sysTimeStamp = 'CURRENT TIMESTAMP';

 	function ActualType($meta)
	{
		switch($meta) {
		case 'C': return 'VARCHAR';
		case 'XL': return 'CLOB';
		case 'X': return 'VARCHAR(3600)';

		case 'C2': return 'VARCHAR'; // up to 32K
		case 'X2': return 'VARCHAR(3600)'; // up to 32000, but default page size too small

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

		case 'F': return 'DOUBLE';
		case 'N': return 'DECIMAL';
		default:
			return $meta;
		}
	}

	// return string must begin with space
	function _CreateSuffix($fname,&$ftype,$fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned)
	{
		$suffix = '';
		if ($fautoinc) return ' GENERATED ALWAYS AS IDENTITY'; # as identity start with
		if (strlen($fdefault)) $suffix .= " DEFAULT $fdefault";
		if ($fnotnull) $suffix .= ' NOT NULL';
		if ($fconstraint) $suffix .= ' '.$fconstraint;
		return $suffix;
	}

	function AlterColumnSQL($tabname, $flds, $tableflds='',$tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("AlterColumnSQL not supported");
		return array();
	}


	function DropColumnSQL($tabname, $flds, $tableflds='',$tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("DropColumnSQL not supported");
		return array();
	}


	function ChangeTableSQL($tablename, $flds, $tableoptions = false)
	{

		/**
		  Allow basic table changes to DB2 databases
		  DB2 will fatally reject changes to non character columns

		*/

		$validTypes = array("CHAR","VARC");
		$invalidTypes = array("BIGI","BLOB","CLOB","DATE", "DECI","DOUB", "INTE", "REAL","SMAL", "TIME");
		// check table exists
		$cols = $this->MetaColumns($tablename);
		if ( empty($cols)) {
			return $this->CreateTableSQL($tablename, $flds, $tableoptions);
		}

		// already exists, alter table instead
		list($lines,$pkey) = $this->_GenFields($flds);
		$alter = 'ALTER TABLE ' . $this->TableName($tablename);
		$sql = array();

		foreach ( $lines as $id => $v ) {
			if ( isset($cols[$id]) && is_object($cols[$id]) ) {
				/**
				  If the first field of $v is the fieldname, and
				  the second is the field type/size, we assume its an
				  attempt to modify the column size, so check that it is allowed
				  $v can have an indeterminate number of blanks between the
				  fields, so account for that too
				 */
				$vargs = explode(' ' , $v);
				// assume that $vargs[0] is the field name.
				$i=0;
				// Find the next non-blank value;
				for ($i=1;$i<sizeof($vargs);$i++)
					if ($vargs[$i] != '')
						break;

				// if $vargs[$i] is one of the following, we are trying to change the
				// size of the field, if not allowed, simply ignore the request.
				if (in_array(substr($vargs[$i],0,4),$invalidTypes))
					continue;
				// insert the appropriate DB2 syntax
				if (in_array(substr($vargs[$i],0,4),$validTypes)) {
					array_splice($vargs,$i,0,array('SET','DATA','TYPE'));
				}

				// Now Look for the NOT NULL statement as this is not allowed in
				// the ALTER table statement. If it is in there, remove it
				if (in_array('NOT',$vargs) && in_array('NULL',$vargs)) {
					for ($i=1;$i<sizeof($vargs);$i++)
					if ($vargs[$i] == 'NOT')
						break;
					array_splice($vargs,$i,2,'');
				}
				$v = implode(' ',$vargs);
				$sql[] = $alter . $this->alterCol . ' ' . $v;
			} else {
				$sql[] = $alter . $this->addCol . ' ' . $v;
			}
		}

		return $sql;
	}

	function _FormatDateSQL($fmt, $pParsedColumnName=false)
	{
		$col = false;

		if($pParsedColumnName)
			{$col = $pParsedColumnName['name'];}

		if($this->databaseType !== "odbc_db2")
		{
			// use right() and replace() ?
			if (!$col) $col = $this->sql_sysDate;

			/* use TO_CHAR() if $fmt is TO_CHAR() allowed fmt */
			if ($fmt== 'Y-m-d H:i:s')
				return array('TO_CHAR('.$col.", 'YYYY-MM-DD HH24:MI:SS')");

			$s = '';

			$len = strlen($fmt);
			for ($i=0; $i < $len; $i++) {
				if ($s) $s .= $this->sql_concatenateOperator;
				$ch = $fmt[$i];
				switch($ch) {
				case 'Y':
				case 'y':
					if ($len==1) return array("year($col)");
					$s .= "char(year($col))";
					break;
				case 'M':
					if ($len==1) return array("monthname($col)");
					$s .= "substr(monthname($col),1,3)";
					break;
				case 'm':
					if ($len==1) return array("month($col)");
					$s .= "right(digits(month($col)),2)";
					break;
				case 'D':
				case 'd':
					if ($len==1) return array("day($col)");
					$s .= "right(digits(day($col)),2)";
					break;
				case 'H':
				case 'h':
					if ($len==1) return array("hour($col)");
					if ($col != $this->sql_sysDate) $s .= "right(digits(hour($col)),2)";
					else $s .= "''";
					break;
				case 'i':
				case 'I':
					if ($len==1) return array("minute($col)");
					if ($col != $this->sql_sysDate)
						$s .= "right(digits(minute($col)),2)";
						else $s .= "''";
					break;
				case 'S':
				case 's':
					if ($len==1) return array("second($col)");
					if ($col != $this->sql_sysDate)
						$s .= "right(digits(second($col)),2)";
					else $s .= "''";
					break;
				default:
					if ($ch == '\\') {
						$i++;
						$ch = substr($fmt,$i,1);
					}
					$s .= $this->connection->qstr($ch);
				}
			}
			return (empty($s) ? array() : array($s));
		}
		else
		{
		// use right() and replace() ?
			if (!$col) $col = $this->sql_sysDate;
			$s = '';

			$len = strlen($fmt);
			for ($i=0; $i < $len; $i++) {
				if ($s) $s .= '||';
				$ch = $fmt[$i];
				switch($ch) {
				case 'Y':
				case 'y':
					$s .= "char(year($col))";
					break;
				case 'M':
					$s .= "substr(monthname($col),1,3)";
					break;
				case 'm':
					$s .= "right(digits(month($col)),2)";
					break;
				case 'D':
				case 'd':
					$s .= "right(digits(day($col)),2)";
					break;
				case 'H':
				case 'h':
					if ($col != $this->sql_sysDate) $s .= "right(digits(hour($col)),2)";
					else $s .= "''";
					break;
				case 'i':
				case 'I':
					if ($col != $this->sql_sysDate)
						$s .= "right(digits(minute($col)),2)";
						else $s .= "''";
					break;
				case 'S':
				case 's':
					if ($col != $this->sql_sysDate)
						$s .= "right(digits(second($col)),2)";
					else $s .= "''";
					break;
				default:
					if ($ch == '\\') {
						$i++;
						$ch = substr($fmt,$i,1);
					}
					$s .= $this->connection->qstr($ch);
				}
			}
			return (empty($s) ? array() : array($s));
		}
	}	

	function RowLockSQL($tables,$where,$col='1 as adodbignore')
		{return array("select $col from $tables where $where for update");}

	function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
	{
		return array
		(
			sprintf("CREATE SEQUENCE %s START WITH %s NO MAXVALUE NO CYCLE", 
					$pParsedSequenceName['name'], $pStartID)
		);
	}
	
	function _DropSequenceSQL($pParsedSequenceName)
		{return array(sprintf("DROP SEQUENCE %s", $pParsedSequenceName['name']));}

}
