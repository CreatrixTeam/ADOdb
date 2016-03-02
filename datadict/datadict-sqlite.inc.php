<?php

/**
  @version   v5.21.0-dev  ??-???-2016
  @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
  @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Set tabs to 4 for best viewing.

	SQLite datadict Andrei Besleaga

*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

class ADODB2_sqlite extends ADODB_DataDict {
	public  $databaseType = 'sqlite';
	public  $seqField = false;
	public  $addCol=' ADD COLUMN';
	public  $dropTable = 'DROP TABLE IF EXISTS %s';
	public  $dropIndex = 'DROP INDEX IF EXISTS %s';
	public  $renameTable = 'ALTER TABLE %s RENAME TO %s';
	public  $sql_concatenateOperator = '||';
	public  $nameQuote = '`';



	public function ActualType($meta)
	{
		switch(strtoupper($meta)) {
		case 'C': return 'VARCHAR'; //  TEXT , TEXT affinity
		case 'XL':return 'LONGTEXT'; //  TEXT , TEXT affinity
		case 'X': return 'TEXT'; //  TEXT , TEXT affinity

		case 'C2': return 'VARCHAR'; //  TEXT , TEXT affinity
		case 'X2': return 'LONGTEXT'; //  TEXT , TEXT affinity

		case 'B': return 'LONGBLOB'; //  TEXT , NONE affinity , BLOB

		case 'D': return 'DATE'; // NUMERIC , NUMERIC affinity
		case 'T': return 'DATETIME'; // NUMERIC , NUMERIC affinity
		case 'L': return 'TINYINT'; // NUMERIC , INTEGER affinity

		case 'R':
		case 'I4':
		case 'I': return 'INTEGER'; // NUMERIC , INTEGER affinity
		case 'I1': return 'TINYINT'; // NUMERIC , INTEGER affinity
		case 'I2': return 'SMALLINT'; // NUMERIC , INTEGER affinity
		case 'I8': return 'BIGINT'; // NUMERIC , INTEGER affinity

		case 'F': return 'DOUBLE'; // NUMERIC , REAL affinity
		case 'N': return 'NUMERIC'; // NUMERIC , NUMERIC affinity
		default:
			return $meta;
		}
	}

	// return string must begin with space
	protected function _CreateSuffix($fname,&$ftype,$fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned)
	{
		$suffix = '';
		if ($funsigned && !$fautoinc) $suffix .= ' UNSIGNED';
		if ($fnotnull && !$fautoinc) $suffix .= ' NOT NULL';
		if (strlen($fdefault)) $suffix .= " DEFAULT $fdefault";
		//if ($fautoinc) $suffix .= ' AUTOINCREMENT';
		if ($fconstraint) $suffix .= ' '.$fconstraint;
		return $suffix;
	}

	public function AlterColumnSQL($tabname, $flds, $tableflds='', $tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("AlterColumnSQL not supported natively by SQLite");
		return array();
	}

	public function DropColumnSQL($tabname, $flds, $tableflds='', $tableoptions='')
	{
		if ($this->debug) ADOConnection::outp("DropColumnSQL not supported natively by SQLite");
		return array();
	}

	public function RenameColumnSQL($tabname,$oldcolumn,$newcolumn,$flds='')
	{
		if ($this->debug) ADOConnection::outp("RenameColumnSQL not supported natively by SQLite");
		return array();
	}

	public function ChangeTableSQL($pTableName, $pTableFields, $pTableOptions = false, 
			$pIsToDropOldFields = false)
	{
		global $ADODB_FETCH_MODE;
		$vADODB_FETCH_MODE_old = $ADODB_FETCH_MODE;
		$vPreviousFetchMode = -1;
		$vRaiseErrorFn = NULL;
		$vCurrentTableFields = NULL;

		$ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;

		if($this->connection->fetchMode !== false)
			{$vPreviousFetchMode = $this->connection->SetFetchMode(false);}

		// check table exists
		$vRaiseErrorFn = $this->connection->raiseErrorFn;
		$this->connection->raiseErrorFn = '';
		$vCurrentTableFields = $this->MetaColumns($pTableName);
		$this->connection->raiseErrorFn = $vRaiseErrorFn;

		if($vPreviousFetchMode !== -1)
			{$this->connection->SetFetchMode($vPreviousFetchMode);}
		$ADODB_FETCH_MODE = $vADODB_FETCH_MODE_old;

		if(empty($vCurrentTableFields))
			{return $this->CreateTableSQL($pTableName, $pTableFields, $pTableOptions);}
		else
		{
			$tSQLs = array();
			$t_GenFields_lines = NULL;
			$tFieldsToAlter = array();
			$tFieldsToAdd = array();
			$tFieldsToDrop = NULL;
			
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

			$tSQLs = array_merge($tSQLs, $this->RenameTableSQL($pTableName, '`tempdfdfkjueb3`'));
			$tSQLs = array_merge($tSQLs, 
					$this->CreateTableSQL($pTableName, $pTableFields, $pTableOptions));
			$tSQLs[] = "BEGIN TRANSACTION";
			$tSQL2 = "";
			$tIsFirstColumn = true;
			foreach($pTableFields as $tTableField)
			{
				if($tIsFirstColumn)
				{
					$tSQL2 .= $this->NameQuote($tTableField['NAME']);
					$tIsFirstColumn = false;
				}
				else
					{$tSQL2 .= " ,".$this->NameQuote($tTableField['NAME']);}
			}
			$tSQLs[] = "INSERT INTO ".$this->TableName($pTableName)."(".$tSQL2.") ".
					"SELECT $tSQL2 FROM ".$this->TableName("`tempdfdfkjueb3`");
			$tSQLs[] = "DROP TABLE ".$this->TableName("`tempdfdfkjueb3`");
			$tSQLs[] = "COMMIT";
			//print_r($tSQLs);die();
			return $tSQLs;
			// $tSQLs = array_merge($tSQLs, $this->AddColumnSQL($pTableName, $tFieldsToAdd));
			// if($pIsToDropOldFields)
				// {$tSQLs = array_merge($tSQLs, $this->DropColumnSQL($pTableName, $tFieldsToDrop));}
			// $tSQLs = array_merge($tSQLs, $this->AlterColumnSQL($pTableName, $tFieldsToAlter));

			// return $this->_recreate_copy_table($pTableName, array(), $pTableFields, $pTableOptions);
		}
	}
	//VERBATIM COPY FROM "datadict-postgres.inc.php"
	protected function _recreate_copy_table($tabname,$dropflds,$tableflds,$tableoptions='')
	{
		if ($dropflds && !is_array($dropflds)) $dropflds = explode(',',$dropflds);
		$copyflds = array();
		foreach($this->MetaColumns($tabname) as $fld) {
			/*if (!$dropflds || !in_array($fld->name,$dropflds)) {
				// we need to explicit convert varchar to a number to be able to do an AlterColumn of a char column to a nummeric one
				if (preg_match('/'.$fld->name.' (I|I2|I4|I8|N|F)/i',$tableflds,$matches) &&
					in_array($fld->type,array('varchar','char','text','bytea'))) {
					$copyflds[] = "to_number($fld->name,'S9999999999999D99')";
				} else {
					$copyflds[] = $fld->name;
				}
				// identify the sequence name and the fld its on
				if ($fld->primary_key && $fld->has_default &&
					preg_match("/nextval\('([^']+)'::text\)/",$fld->default_value,$matches)) {
					$seq_name = $matches[1];
					$seq_fld = $fld->name;
				}
			}*/
			$copyflds[] = $fld->name;
		}
		$copyflds = implode(', ',$copyflds);

		$tempname = $tabname.'_tmp';
		$aSql[] = 'BEGIN';		// we use a transaction, to make sure not to loose the content of the table
		$aSql[] = "SELECT * INTO TEMPORARY TABLE $tempname FROM $tabname";
		$aSql = array_merge($aSql,$this->DropTableSQL($tabname));
		$aSql = array_merge($aSql,$this->CreateTableSQL($tabname,$tableflds,$tableoptions));
		$aSql[] = "INSERT INTO $tabname SELECT $copyflds FROM $tempname";
		if ($seq_name && $seq_fld) {	// if we have a sequence we need to set it again
			$seq_name = $tabname.'_'.$seq_fld.'_seq';	// has to be the name of the new implicit sequence
			$aSql[] = "SELECT setval('$seq_name',MAX($seq_fld)) FROM $tabname";
		}
		$aSql[] = "DROP TABLE $tempname";
		// recreate the indexes, if they not contain one of the droped columns
		foreach($this->MetaIndexes($tabname) as $idx_name => $idx_data)
		{
			if (substr($idx_name,-5) != '_pkey' && (!$dropflds || !count(array_intersect($dropflds,$idx_data['columns'])))) {
				$aSql = array_merge($aSql,$this->CreateIndexSQL($idx_name,$tabname,$idx_data['columns'],
					$idx_data['unique'] ? array('UNIQUE') : False));
			}
		}
		$aSql[] = 'COMMIT';
		return $aSql;
	}

	protected function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
	{
		$vStartID = $pStartID - 1;

		return array
		(
			sprintf("CREATE TABLE %s (id integer)", $pParsedSequenceName['name']),
			"insert into $pParsedSequenceName[name] values($vStartID)"
		);
	}
	
	protected function _DropSequenceSQL($pParsedSequenceName)
		{return array(sprintf('drop table %s', $pParsedSequenceName['name']));}

	protected function _GenIDSQL($pParsedSequenceName)
		{return array("select id from $pParsedSequenceName[name]");}
		
	protected function _event_GenID_calculateAndSetGenID($pParsedSequenceName, $pADORecordSet)
	{
		$vNumber = (($pADORecordSet && !$pADORecordSet->EOF) ? reset($pADORecordSet->fields) :
				0);
		$vADORecordSet = $this->connection->Execute(
				"update $pParsedSequenceName[name] set id=id+1 where id=$vNumber");
		
		if($this->connection->affected_rows() > 0)
			{$this->connection->genID = $vNumber + 1;}
	}

}
