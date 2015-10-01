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

class ADODB2_vfp extends ADODB_DataDict {
	public  $databaseType = 'vfp';
	public  $sql_sysDate = 'date()';
	public  $sql_sysTimeStamp = 'datetime()';
	
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