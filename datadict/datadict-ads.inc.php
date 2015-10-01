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

class ADODB2_ads extends ADODB_DataDict {
	public  $databaseType = "ads";
	public  $sql_concatenateOperator = '';

	protected function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
	{
		return array("CREATE TABLE $pParsedSequenceName[name] ( ID autoinc( 1 ) ) IN DATABASE");
	}
	
	protected function _DropSequenceSQL($pParsedSequenceName)
		{return array("DROP TABLE $pParsedSequenceName[name]");}

	protected function _GenIDSQL($pParsedSequenceName)
		{return array("select * from $pParsedSequenceName[name]");}
		
	protected function _event_GenID_calculateAndSetGenID($pParsedSequenceName, $pADORecordSet)
	{
		if($pADORecordSet)
		{
			$tADORecordSet = $this->connection->Execute(
					"INSERT INTO $pParsedSequenceName[name] VALUES( DEFAULT )");

			if($tADORecordSet)
			{
				$tADORecordSet = $this->connection->Execute(
						"SELECT LastAutoInc( STATEMENT ) FROM system.iota");
				$this->connection->genID = $tADORecordSet->fields[0];
			}
		}
	}
}