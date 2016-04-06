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

class ADODB2_access extends ADODB_DataDict {

	public  $databaseType = 'access';
	public  $seqField = false;
	public  $sql_sysDate = "FORMAT(NOW,'yyyy-mm-dd')";
	public  $sql_sysTimeStamp = 'NOW';
	public	$nameQuote = '[';


 	public function ActualType($meta)
	{
		switch($meta) {
		case 'C': return 'TEXT';
		case 'XL':
		case 'X': return 'MEMO';

		case 'C2': return 'TEXT'; // up to 32K
		case 'X2': return 'MEMO';

		case 'B': return 'BINARY';

		case 'TS':
		case 'D': return 'DATETIME';
		case 'T': return 'DATETIME';

		case 'L': return 'BYTE';
		case 'I': return 'INTEGER';
		case 'I1': return 'BYTE';
		case 'I2': return 'SMALLINT';
		case 'I4': return 'INTEGER';
		case 'I8': return 'INTEGER';

		case 'F': return 'DOUBLE';
		case 'N': return 'NUMERIC';
		default:
			return $meta;
		}
	}

	// return string must begin with space
	protected function _CreateSuffix($fname, &$ftype, $fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned)
	{
		if ($fautoinc) {
			$ftype = 'COUNTER';
			return '';
		}
		if (substr($ftype,0,7) == 'DECIMAL') $ftype = 'DECIMAL';
		$suffix = '';
		if (strlen($fdefault)) {
			//$suffix .= " DEFAULT $fdefault";
			if ($this->debug) ADOConnection::outp("Warning: Access does not supported DEFAULT values (field $fname)");
		}
		if ($fnotnull) $suffix .= ' NOT NULL';
		if ($fconstraint) $suffix .= ' '.$fconstraint;
		return $suffix;
	}

	public function CreateDatabase($dbname,$options=false)
	{
		return array();
	}


	public function SetSchema($schema)
	{
	}

	public function AlterColumnSQL($tabname, $flds, $tableflds='',$tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("AlterColumnSQL not supported");
		return array();
	}


	public function DropColumnSQL($tabname, $flds, $tableflds='',$tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("DropColumnSQL not supported");
		return array();
	}

	protected function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
	{
		$vStartID = $pStartID - 1;

		return array
		(
			sprintf("create table %s (id integer)", $pParsedSequenceName['name']),
			"insert into $pParsedSequenceName[name] values($vStartID)"
		);
	}

	protected function _DropSequenceSQL($pParsedSequenceName)
		{return array(sprintf('drop table %s', $pParsedSequenceName['name']));}

	protected function _GenIDSQL($pParsedSequenceName)
		{return array("select id from $pParsedSequenceName[name]");}
		
	protected function _event_GenID_calculateAndSetGenID($pParsedSequenceName, $pADORecordSet)
	{
		$vNumber = (integer)(($pADORecordSet && !$pADORecordSet->EOF) ? 
				reset($pADORecordSet->fields) : 0);
		$vGenID = 0;

		$this->connection->Execute(
				"update $pParsedSequenceName[name] set id=id+1 where id=$vNumber");
		$vGenID = $this->connection->GetOne("select id from $pParsedSequenceName[name]");

		if($vGenID == ($vNumber + 1))
			{$this->connection->genID = $vNumber + 1;}
	}

}
