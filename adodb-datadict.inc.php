<?php

/**
  @version   v5.21.0-dev  ??-???-2016
  @copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
  @copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Set tabs to 4 for best viewing.

 	DOCUMENTATION:

		See adodb/tests/test-datadict.php for docs and examples.
*/

/*
	Test script for parser
*/

// security - hide paths
if (!defined('ADODB_DIR')) die();

function Lens_ParseTest()
{
$str = "`zcol ACOL` NUMBER(32,2) DEFAULT 'The \"cow\" (and Jim''s dog) jumps over the moon' PRIMARY, INTI INT AUTO DEFAULT 0, zcol2\"afs ds";
print "<p>$str</p>";
$a= Lens_ParseArgs($str);
print "<pre>";
print_r($a);
print "</pre>";
}


if (!function_exists('ctype_alnum')) {
	function ctype_alnum($text) {
		return preg_match('/^[a-z0-9]*$/i', $text);
	}
}

//Lens_ParseTest();

/**
	Parse arguments, treat "text" (text) and 'text' as quotation marks.
	To escape, use "" or '' or ))

	Will read in "abc def" sans quotes, as: abc def
	Same with 'abc def'.
	However if `abc def`, then will read in as `abc def`

	@param endstmtchar    Character that indicates end of statement
	@param tokenchars     Include the following characters in tokens apart from A-Z and 0-9
	@returns 2 dimensional array containing parsed tokens.
*/
function Lens_ParseArgs($args,$endstmtchar=',',$tokenchars='_.-')
{
	$pos = 0;
	$intoken = false;
	$stmtno = 0;
	$endquote = false;
	$tokens = array();
	$tokens[$stmtno] = array();
	$max = strlen($args);
	$quoted = false;
	$tokarr = array();

	while ($pos < $max) {
		$ch = substr($args,$pos,1);
		switch($ch) {
		case ' ':
		case "\t":
		case "\n":
		case "\r":
			if (!$quoted) {
				if ($intoken) {
					$intoken = false;
					$tokens[$stmtno][] = implode('',$tokarr);
				}
				break;
			}

			$tokarr[] = $ch;
			break;

		case '`':
			if ($intoken) $tokarr[] = $ch;
		case '(':
		case ')':
		case '"':
		case "'":

			if ($intoken) {
				if (empty($endquote)) {
					$tokens[$stmtno][] = implode('',$tokarr);
					if ($ch == '(') $endquote = ')';
					else $endquote = $ch;
					$quoted = true;
					$intoken = true;
					$tokarr = array();
				} else if ($endquote == $ch) {
					$ch2 = substr($args,$pos+1,1);
					if ($ch2 == $endquote) {
						$pos += 1;
						$tokarr[] = $ch2;
					} else {
						$quoted = false;
						$intoken = false;
						$tokens[$stmtno][] = implode('',$tokarr);
						$endquote = '';
					}
				} else
					$tokarr[] = $ch;

			}else {

				if ($ch == '(') $endquote = ')';
				else $endquote = $ch;
				$quoted = true;
				$intoken = true;
				$tokarr = array();
				if ($ch == '`') $tokarr[] = '`';
			}
			break;

		default:

			if (!$intoken) {
				if ($ch == $endstmtchar) {
					$stmtno += 1;
					$tokens[$stmtno] = array();
					break;
				}

				$intoken = true;
				$quoted = false;
				$endquote = false;
				$tokarr = array();

			}

			if ($quoted) $tokarr[] = $ch;
			else if (ctype_alnum($ch) || strpos($tokenchars,$ch) !== false) $tokarr[] = $ch;
			else {
				if ($ch == $endstmtchar) {
					$tokens[$stmtno][] = implode('',$tokarr);
					$stmtno += 1;
					$tokens[$stmtno] = array();
					$intoken = false;
					$tokarr = array();
					break;
				}
				$tokens[$stmtno][] = implode('',$tokarr);
				$tokens[$stmtno][] = $ch;
				$intoken = false;
			}
		}
		$pos += 1;
	}
	if ($intoken) $tokens[$stmtno][] = implode('',$tokarr);

	return $tokens;
}


class ADODB_DataDict {
	public  $connection = null;
	public  $debug = false;
	public  $dropTable = 'DROP TABLE %s';
	public  $renameTable = 'RENAME TABLE %s TO %s';
	public  $dropIndex = 'DROP INDEX %s';
	public  $addCol = ' ADD';
	public  $alterCol = ' ALTER COLUMN';
	public  $dropCol = ' DROP COLUMN';
	public  $renameColumn = 'ALTER TABLE %s RENAME COLUMN %s TO %s';	// table, old-column, new-column, column-definitions (not used by default)
	public  $nameRegex = '\w';
	public  $nameRegexBrackets = 'a-zA-Z0-9_\(\)';
	public  $schema = false;
	public  $serverInfo = array();//DEPRECATED; Use GetServerInfo() instead;
	public  $autoIncrement = false;
	public  $dataProvider = null;
	public  $databaseType = null;
	public  $invalidResizeTypes4 = array('CLOB','BLOB','TEXT','DATE','TIME'); // for changetablesql
	public  $blobSize = 100; 	/// any varchar/char field this size or greater is treated as a blob
							/// in other words, we use a text area for editting.
	public	$nameQuote = '"';	/// string to use to quote identifiers
	public  $sql_concatenateOperator = '+'; /// default concat operator -- change to || for Oracle/Interbase
	public  $sql_sysDate = false; /// name of function that returns the current date
	public  $sql_sysTimeStamp = false; /// name of function that returns the current timestamp
	//PRIVATES
	protected  $_serverInfoArray = null;

	public function GetCommentSQL($table,$col)
	{
		return false;
	}

	public function SetCommentSQL($table,$col,$cmt)
	{
		return false;
	}

	public function MetaTables()
	{
		if (!$this->connection->IsConnected()) return array();
		return $this->connection->MetaTables();
	}

	public function MetaColumns($tab, $upper=null, $schema=false)
	{
		if (!$this->connection->IsConnected()) return array();
		return $this->connection->MetaColumns($tab, $upper, $schema);
	}

	public function MetaPrimaryKeys($tab,$owner=false,$intkey=false)
	{
		if (!$this->connection->IsConnected()) return array();
		return $this->connection->MetaPrimaryKeys($tab, $owner, $intkey);
	}

	public function MetaIndexes($table, $primary = false, $owner = false)
	{
		if (!$this->connection->IsConnected()) return array();
		return $this->connection->MetaIndexes($table, $primary, $owner);
	}

	public function MetaType($t,$len=-1,$fieldobj=false)
	{
		static $typeMap = array(
		'VARCHAR' => 'C',
		'VARCHAR2' => 'C',
		'CHAR' => 'C',
		'C' => 'C',
		'STRING' => 'C',
		'NCHAR' => 'C',
		'NVARCHAR' => 'C',
		'VARYING' => 'C',
		'BPCHAR' => 'C',
		'CHARACTER' => 'C',
		'INTERVAL' => 'C',  # Postgres
		'MACADDR' => 'C', # postgres
		'VAR_STRING' => 'C', # mysql
		##
		'LONGCHAR' => 'X',
		'TEXT' => 'X',
		'NTEXT' => 'X',
		'M' => 'X',
		'X' => 'X',
		'CLOB' => 'X',
		'NCLOB' => 'X',
		'LVARCHAR' => 'X',
		##
		'BLOB' => 'B',
		'IMAGE' => 'B',
		'BINARY' => 'B',
		'VARBINARY' => 'B',
		'LONGBINARY' => 'B',
		'B' => 'B',
		##
		'YEAR' => 'D', // mysql
		'DATE' => 'D',
		'D' => 'D',
		##
		'UNIQUEIDENTIFIER' => 'C', # MS SQL Server
		##
		'TIME' => 'T',
		'TIMESTAMP' => 'T',
		'DATETIME' => 'T',
		'TIMESTAMPTZ' => 'T',
		'SMALLDATETIME' => 'T',
		'T' => 'T',
		'TIMESTAMP WITHOUT TIME ZONE' => 'T', // postgresql
		##
		'BOOL' => 'L',
		'BOOLEAN' => 'L',
		'BIT' => 'L',
		'L' => 'L',
		##
		'COUNTER' => 'R',
		'R' => 'R',
		'SERIAL' => 'R', // ifx
		'INT IDENTITY' => 'R',
		##
		'INT' => 'I',
		'INT2' => 'I',
		'INT4' => 'I',
		'INT8' => 'I',
		'INTEGER' => 'I',
		'INTEGER UNSIGNED' => 'I',
		'SHORT' => 'I',
		'TINYINT' => 'I',
		'SMALLINT' => 'I',
		'I' => 'I',
		##
		'LONG' => 'N', // interbase is numeric, oci8 is blob
		'BIGINT' => 'N', // this is bigger than PHP 32-bit integers
		'DECIMAL' => 'N',
		'DEC' => 'N',
		'REAL' => 'N',
		'DOUBLE' => 'N',
		'DOUBLE PRECISION' => 'N',
		'SMALLFLOAT' => 'N',
		'FLOAT' => 'N',
		'NUMBER' => 'N',
		'NUM' => 'N',
		'NUMERIC' => 'N',
		'MONEY' => 'N',

		## informix 9.2
		'SQLINT' => 'I',
		'SQLSERIAL' => 'I',
		'SQLSMINT' => 'I',
		'SQLSMFLOAT' => 'N',
		'SQLFLOAT' => 'N',
		'SQLMONEY' => 'N',
		'SQLDECIMAL' => 'N',
		'SQLDATE' => 'D',
		'SQLVCHAR' => 'C',
		'SQLCHAR' => 'C',
		'SQLDTIME' => 'T',
		'SQLINTERVAL' => 'N',
		'SQLBYTES' => 'B',
		'SQLTEXT' => 'X',
		 ## informix 10
		"SQLINT8" => 'I8',
		"SQLSERIAL8" => 'I8',
		"SQLNCHAR" => 'C',
		"SQLNVCHAR" => 'C',
		"SQLLVARCHAR" => 'X',
		"SQLBOOL" => 'L'
		);

		if (!$this->connection->IsConnected()) {
			$t = strtoupper($t);
			if (isset($typeMap[$t])) return $typeMap[$t];
			return 'N';
		}
		return $this->connection->MetaType($t,$len,$fieldobj);
	}

	public function NameQuote($name = NULL,$allowBrackets=false)
	{
		if (!is_string($name)) {
			return FALSE;
		}

		$name = trim($name);

		if ( !is_object($this->connection) ) {
			return $name;
		}

		$quote = $this->nameQuote;

		// if name is of the form `name`, quote it
		if ( preg_match('/^`(.+)`$/', $name, $matches) ) {
			return $quote . $matches[1] . $quote;
		}

		// if name contains special characters, quote it
		$regex = ($allowBrackets) ? $this->nameRegexBrackets : $this->nameRegex;

		if ( !preg_match('/^[' . $regex . ']+$/', $name) ) {
			return $quote . $name . $quote;
		}

		return $name;
	}

	public function ForceNameQuote($pName = NULL)
	{
		if(!is_string($pName)) 
			{return false;}

		$vName = trim($pName);
		$vMatches = null;

		// if name is of the form `name`, remove ` and quote it
		if(preg_match('/^`(.+)`$/', $vName, $vMatches))
			{return $this->nameQuote.$vMatches[1].$this->nameQuote;}

		return $this->nameQuote.$vName.$this->nameQuote;
	}

	public function TableName($name)
	{
		if ( $this->schema ) {
			return $this->NameQuote($this->schema) .'.'. $this->NameQuote($name);
		}
		return $this->NameQuote($name);
	}
	
	//PRIVATE	
	public function removeStandardAdodbDataDictNameQuotes($pName)
	{
		$vName = trim($pName);
		$vMatches = NULL;

		if(preg_match('/^`(.+)`$/', $vName, $vMatches))
			{return $vMatches[1];}
		return $vName;
	}
	
	// Executes the sql array returned by GetTableSQL and GetIndexSQL
	public function ExecuteSQLArray($sql, $continueOnError = true)
	{
		$rez = 2;
		$conn = $this->connection;
		$saved = $conn->debug;
		foreach($sql as $line) {

			if ($this->debug) $conn->debug = true;
			$ok = $conn->Execute($line);
			$conn->debug = $saved;
			if (!$ok) {
				if ($this->debug) ADOConnection::outp($conn->ErrorMsg());
				if (!$continueOnError) return 0;
				$rez = 1;
			}
		}
		return $rez;
	}

	/**
	 	Returns the actual type given a character code.

		C:  varchar
		X:  CLOB (character large object) or largest varchar size if CLOB is not supported
		C2: Multibyte varchar
		X2: Multibyte CLOB

		B:  BLOB (binary large object)

		D:  Date
		T:  Date-time
		L:  Integer field suitable for storing booleans (0 or 1)
		I:  Integer
		F:  Floating point number
		N:  Numeric or decimal number
	*/

	public function ActualType($meta)
	{
		return $meta;
	}

	public function CreateDatabase($dbname,$options=false)
	{
		$options = $this->_Options($options);
		$sql = array();

		$s = 'CREATE DATABASE ' . $this->NameQuote($dbname);
		if (isset($options[$this->upperName]))
			$s .= ' '.$options[$this->upperName];

		$sql[] = $s;
		return $sql;
	}

	/*
	 Generates the SQL to create index. Returns an array of sql strings.
	*/
	public function CreateIndexSQL($idxname, $tabname, $flds, $idxoptions = false)
	{
		if (!is_array($flds)) {
			$flds = explode(',',$flds);
		}

		foreach($flds as $key => $fld) {
			# some indexes can use partial fields, eg. index first 32 chars of "name" with NAME(32)
			$flds[$key] = $this->NameQuote($fld,$allowBrackets=true);
		}

		return $this->_IndexSQL($this->NameQuote($idxname), $this->TableName($tabname), $flds, $this->_Options($idxoptions));
	}

	public function DropIndexSQL ($idxname, $tabname = NULL)
	{
		return array(sprintf($this->dropIndex, $this->NameQuote($idxname), $this->TableName($tabname)));
	}

	public function SetSchema($schema)
	{
		$this->schema = $schema;
	}

	public function AddColumnSQL($tabname, $flds)
	{
		$tabname = $this->TableName ($tabname);
		$sql = array();
		list($lines,$pkey,$idxs) = $this->_GenFields($flds);
		// genfields can return FALSE at times
		if ($lines  == null) $lines = array();
		$alter = 'ALTER TABLE ' . $tabname . $this->addCol . ' ';
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

	/**
	 * Change the definition of one column
	 *
	 * As some DBM's can't do that on there own, you need to supply the complete defintion of the new table,
	 * to allow, recreating the table and copying the content over to the new table
	 * @param string $tabname table-name
	 * @param string $flds column-name and type for the changed column
	 * @param string $tableflds='' complete defintion of the new table, eg. for postgres, default ''
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

	/**
	 * Rename one column
	 *
	 * Some DBM's can only do this together with changeing the type of the column (even if that stays the same, eg. mysql)
	 * @param string $tabname table-name
	 * @param string $oldcolumn column-name to be renamed
	 * @param string $newcolumn new column-name
	 * @param string $flds='' complete column-defintion-string like for AddColumnSQL, only used by mysql atm., default=''
	 * @return array with SQL strings
	 */
	public function RenameColumnSQL($tabname,$oldcolumn,$newcolumn,$flds='')
	{
		$tabname = $this->TableName ($tabname);
		if ($flds) {
			list($lines,$pkey,$idxs) = $this->_GenFields($flds);
			// genfields can return FALSE at times
			if ($lines == null) $lines = array();
			list(,$first) = each($lines);
			list(,$column_def) = preg_split("/[\t ]+/",$first,2);
		}
		return array(sprintf($this->renameColumn,$tabname,$this->NameQuote($oldcolumn),$this->NameQuote($newcolumn),$column_def));
	}

	/**
	 * Drop one column
	 *
	 * Some DBM's can't do that on there own, you need to supply the complete defintion of the new table,
	 * to allow, recreating the table and copying the content over to the new table
	 * @param string $tabname table-name
	 * @param string $flds column-name and type for the changed column
	 * @param string $tableflds='' complete defintion of the new table, eg. for postgres, default ''
	 * @param array/string $tableoptions='' options for the new table see CreateTableSQL, default ''
	 * @return array with SQL strings
	 */
	public function DropColumnSQL($tabname, $flds, $tableflds='',$tableoptions='')
	{
		$tabname = $this->TableName ($tabname);
		if (!is_array($flds)) $flds = explode(',',$flds);
		$sql = array();
		$alter = 'ALTER TABLE ' . $tabname . $this->dropCol . ' ';
		foreach($flds as $v) {
			$sql[] = $alter . $this->NameQuote($v);
		}
		return $sql;
	}

	public function DropTableSQL($tabname)
	{
		return array (sprintf($this->dropTable, $this->TableName($tabname)));
	}

	public function RenameTableSQL($tabname,$newname)
	{
		return array (sprintf($this->renameTable, $this->TableName($tabname),$this->TableName($newname)));
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

		// ggiunta - 2006/10/12 - KLUDGE:
        // if we are on autoincrement, and table options includes REPLACE, the
        // autoincrement sequence has already been dropped on table creation sql, so
        // we avoid passing REPLACE to trigger creation code. This prevents
        // creating sql that double-drops the sequence
        if ($this->autoIncrement && isset($taboptions['REPLACE']))
        	unset($taboptions['REPLACE']);
		$tsql = $this->_Triggers($tabname,$taboptions);
		foreach($tsql as $s) $sql[] = $s;

		if (is_array($idxs)) {
			foreach($idxs as $idx => $idxdef) {
				$sql_idxs = $this->CreateIndexSql($idx, $tabname,  $idxdef['cols'], $idxdef['opts']);
				$sql = array_merge($sql, $sql_idxs);
			}
		}

		return $sql;
	}



	protected function _GenFields($flds,$widespacing=false)
	{
		if (is_string($flds)) {
			$padding = '     ';
			$txt = $flds.$padding;
			$flds = array();
			$flds0 = Lens_ParseArgs($txt,',');
			$hasparam = false;
			foreach($flds0 as $f0) {
				$f1 = array();
				foreach($f0 as $token) {
					switch (strtoupper($token)) {
					case 'INDEX':
						$f1['INDEX'] = '';
						// fall through intentionally
					case 'CONSTRAINT':
					case 'DEFAULT':
						$hasparam = $token;
						break;
					default:
						if ($hasparam) $f1[$hasparam] = $token;
						else $f1[] = $token;
						$hasparam = false;
						break;
					}
				}
				// 'index' token without a name means single column index: name it after column
				if (array_key_exists('INDEX', $f1) && $f1['INDEX'] == '') {
					$f1['INDEX'] = isset($f0['NAME']) ? $f0['NAME'] : $f0[0];
					// check if column name used to create an index name was quoted
					if (($f1['INDEX'][0] == '"' || $f1['INDEX'][0] == "'" || $f1['INDEX'][0] == "`") &&
						($f1['INDEX'][0] == substr($f1['INDEX'], -1))) {
						$f1['INDEX'] = $f1['INDEX'][0].'idx_'.substr($f1['INDEX'], 1, -1).$f1['INDEX'][0];
					}
					else
						$f1['INDEX'] = 'idx_'.$f1['INDEX'];
				}
				// reset it, so we don't get next field 1st token as INDEX...
				$hasparam = false;

				$flds[] = $f1;

			}
		}
		$this->autoIncrement = false;
		$lines = array();
		$pkey = array();
		$idxs = array();
		foreach($flds as $fld) {
			$fld = _array_change_key_case($fld);

			$fname = false;
			$fdefault = false;
			$fautoinc = false;
			$ftype = false;
			$fsize = false;
			$fprec = false;
			$fprimary = false;
			$fnoquote = false;
			$fdefts = false;
			$fdefdate = false;
			$fconstraint = false;
			$fnotnull = false;
			$funsigned = false;
			$findex = '';
			$funiqueindex = false;

			//-----------------
			// Parse attributes
			foreach($fld as $attr => $v) {
				if ($attr == 2 && is_numeric($v)) $attr = 'SIZE';
				else if (is_numeric($attr) && $attr > 1 && !is_numeric($v)) $attr = strtoupper($v);

				switch($attr) {
				case '0':
				case 'NAME': 	$fname = $v; break;
				case '1':
				case 'TYPE': 	$ty = $v; $ftype = $this->ActualType(strtoupper($v)); break;

				case 'SIZE':
								$dotat = strpos($v,'.'); if ($dotat === false) $dotat = strpos($v,',');
								if ($dotat === false) $fsize = $v;
								else {
									$fsize = substr($v,0,$dotat);
									$fprec = substr($v,$dotat+1);
								}
								break;
				case 'UNSIGNED': $funsigned = true; break;
				case 'AUTOINCREMENT':
				case 'AUTO':	$fautoinc = true; $fnotnull = true; break;
				case 'KEY':
                // a primary key col can be non unique in itself (if key spans many cols...)
				case 'PRIMARY':	$fprimary = $v; $fnotnull = true; /*$funiqueindex = true;*/ break;
				case 'DEF':
				case 'DEFAULT': $fdefault = $v; break;
				case 'NOTNULL': $fnotnull = $v; break;
				case 'NOQUOTE': $fnoquote = $v; break;
				case 'DEFDATE': $fdefdate = $v; break;
				case 'DEFTIMESTAMP': $fdefts = $v; break;
				case 'CONSTRAINT': $fconstraint = $v; break;
				// let INDEX keyword create a 'very standard' index on column
				case 'INDEX': $findex = $v; break;
				case 'UNIQUE': $funiqueindex = true; break;
				} //switch
			} // foreach $fld

			//--------------------
			// VALIDATE FIELD INFO
			if (!strlen($fname)) {
				if ($this->debug) ADOConnection::outp("Undefined NAME");
				return false;
			}

			$fid = strtoupper(preg_replace('/^`(.+)`$/', '$1', $fname));
			$fname = $this->NameQuote($fname);

			if (!strlen($ftype)) {
				if ($this->debug) ADOConnection::outp("Undefined TYPE for field '$fname'");
				return false;
			} else {
				$ftype = strtoupper($ftype);
			}

			$ftype = $this->_GetSize($ftype, $ty, $fsize, $fprec);

			if ($ty == 'X' || $ty == 'X2' || $ty == 'B') $fnotnull = false; // some blob types do not accept nulls

			if ($fprimary) $pkey[] = $fname;

			// some databases do not allow blobs to have defaults
			if ($ty == 'X') $fdefault = false;

			// build list of indexes
			if ($findex != '') {
				if (array_key_exists($findex, $idxs)) {
					$idxs[$findex]['cols'][] = ($fname);
					if (in_array('UNIQUE', $idxs[$findex]['opts']) != $funiqueindex) {
						if ($this->debug) ADOConnection::outp("Index $findex defined once UNIQUE and once not");
					}
					if ($funiqueindex && !in_array('UNIQUE', $idxs[$findex]['opts']))
						$idxs[$findex]['opts'][] = 'UNIQUE';
				}
				else
				{
					$idxs[$findex] = array();
					$idxs[$findex]['cols'] = array($fname);
					if ($funiqueindex)
						$idxs[$findex]['opts'] = array('UNIQUE');
					else
						$idxs[$findex]['opts'] = array();
				}
			}

			//--------------------
			// CONSTRUCT FIELD SQL
			if ($fdefts) {
				if (substr($this->connection->databaseType,0,5) == 'mysql') {
					$ftype = 'TIMESTAMP';
				} else {
					$fdefault = $this->connection->sysTimeStamp;
				}
			} else if ($fdefdate) {
				if (substr($this->connection->databaseType,0,5) == 'mysql') {
					$ftype = 'TIMESTAMP';
				} else {
					$fdefault = $this->connection->sysDate;
				}
			} else if ($fdefault !== false && !$fnoquote) {
				if ($ty == 'C' or $ty == 'X' or
					( substr($fdefault,0,1) != "'" && !is_numeric($fdefault))) {

					if (($ty == 'D' || $ty == 'T') && strtolower($fdefault) != 'null') {
						// convert default date into database-aware code
						if ($ty == 'T')
						{
							$fdefault = $this->connection->DBTimeStamp($fdefault);
						}
						else
						{
							$fdefault = $this->connection->DBDate($fdefault);
						}
					}
					else
					if (strlen($fdefault) != 1 && substr($fdefault,0,1) == ' ' && substr($fdefault,strlen($fdefault)-1) == ' ')
						$fdefault = trim($fdefault);
					else if (strtolower($fdefault) != 'null')
						$fdefault = $this->connection->qstr($fdefault);
				}
			}
			$suffix = $this->_CreateSuffix($fname,$ftype,$fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned);

			// add index creation
			if ($widespacing) $fname = str_pad($fname,24);

			 // check for field names appearing twice
            if (array_key_exists($fid, $lines)) {
            	 ADOConnection::outp("Field '$fname' defined twice");
            }

			$lines[$fid] = $fname.' '.$ftype.$suffix;

			if ($fautoinc) $this->autoIncrement = true;
		} // foreach $flds

		return array($lines,$pkey,$idxs);
	}

	/**
		 GENERATE THE SIZE PART OF THE DATATYPE
			$ftype is the actual type
			$ty is the type defined originally in the DDL
	*/
	protected function _GetSize($ftype, $ty, $fsize, $fprec)
	{
		if (strlen($fsize) && $ty != 'X' && $ty != 'B' && strpos($ftype,'(') === false) {
			$ftype .= "(".$fsize;
			if (strlen($fprec)) $ftype .= ",".$fprec;
			$ftype .= ')';
		}
		return $ftype;
	}


	// return string must begin with space
	protected function _CreateSuffix($fname,&$ftype,$fnotnull,$fdefault,$fautoinc,$fconstraint,$funsigned)
	{
		$suffix = '';
		if (strlen($fdefault)) $suffix .= " DEFAULT $fdefault";
		if ($fnotnull) $suffix .= ' NOT NULL';
		if ($fconstraint) $suffix .= ' '.$fconstraint;
		return $suffix;
	}

	protected function _IndexSQL($idxname, $tabname, $flds, $idxoptions)
	{
		$sql = array();

		if ( isset($idxoptions['REPLACE']) || isset($idxoptions['DROP']) ) {
			$sql[] = sprintf ($this->dropIndex, $idxname);
			if ( isset($idxoptions['DROP']) )
				return $sql;
		}

		if ( empty ($flds) ) {
			return $sql;
		}

		$unique = isset($idxoptions['UNIQUE']) ? ' UNIQUE' : '';

		$s = 'CREATE' . $unique . ' INDEX ' . $idxname . ' ON ' . $tabname . ' ';

		if ( isset($idxoptions[$this->upperName]) )
			$s .= $idxoptions[$this->upperName];

		if ( is_array($flds) )
			$flds = implode(', ',$flds);
		$s .= '(' . $flds . ')';
		$sql[] = $s;

		return $sql;
	}

	protected function _DropAutoIncrement($tabname)
	{
		return false;
	}

	protected function _TableSQL($tabname,$lines,$pkey,$tableoptions)
	{
		$sql = array();

		if (isset($tableoptions['REPLACE']) || isset ($tableoptions['DROP'])) {
			$sql[] = sprintf($this->dropTable,$tabname);
			if ($this->autoIncrement) {
				$sInc = $this->_DropAutoIncrement($tabname);
				if ($sInc) $sql[] = $sInc;
			}
			if ( isset ($tableoptions['DROP']) ) {
				return $sql;
			}
		}
		$s = "CREATE TABLE $tabname (\n";
		$s .= implode(",\n", $lines);
		if (sizeof($pkey)>0) {
			$s .= ",\n                 PRIMARY KEY (";
			$s .= implode(", ",$pkey).")";
		}
		if (isset($tableoptions['CONSTRAINTS']))
			$s .= "\n".$tableoptions['CONSTRAINTS'];

		if (isset($tableoptions[$this->upperName.'_CONSTRAINTS']))
			$s .= "\n".$tableoptions[$this->upperName.'_CONSTRAINTS'];

		$s .= "\n)";
		if (isset($tableoptions[$this->upperName])) $s .= $tableoptions[$this->upperName];
		$sql[] = $s;

		return $sql;
	}

	/**
		GENERATE TRIGGERS IF NEEDED
		used when table has auto-incrementing field that is emulated using triggers
	*/
	protected function _Triggers($tabname,$taboptions)
	{
		return array();
	}

	/**
		Sanitize options, so that array elements with no keys are promoted to keys
	*/
	protected function _Options($opts)
	{
		if (!is_array($opts)) return array();
		$newopts = array();
		foreach($opts as $k => $v) {
			if (is_numeric($k)) $newopts[strtoupper($v)] = $v;
			else $newopts[strtoupper($k)] = $v;
		}
		return $newopts;
	}


	protected function _getSizePrec($size)
	{
		$fsize = false;
		$fprec = false;
		$dotat = strpos($size,'.');
		if ($dotat === false) $dotat = strpos($size,',');
		if ($dotat === false) $fsize = $size;
		else {
			$fsize = substr($size,0,$dotat);
			$fprec = substr($size,$dotat+1);
		}
		return array($fsize, $fprec);
	}

	/**
	"Florian Buzin [ easywe ]" <florian.buzin#easywe.de>

	This function changes/adds new fields to your table. You don't
	have to know if the col is new or not. It will check on its own.
	*/
	public function ChangeTableSQL($tablename, $flds, $tableoptions = false, $dropOldFlds=false)
	{
	global $ADODB_FETCH_MODE;

		$save = $ADODB_FETCH_MODE;
		$ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;
		if ($this->connection->fetchMode !== false) $savem = $this->connection->SetFetchMode(false);

		// check table exists
		$save_handler = $this->connection->raiseErrorFn;
		$this->connection->raiseErrorFn = '';
		$cols = $this->MetaColumns($tablename);
		$this->connection->raiseErrorFn = $save_handler;

		if (isset($savem)) $this->connection->SetFetchMode($savem);
		$ADODB_FETCH_MODE = $save;

		if ( empty($cols)) {
			return $this->CreateTableSQL($tablename, $flds, $tableoptions);
		}

		if (is_array($flds)) {
		// Cycle through the update fields, comparing
		// existing fields to fields to update.
		// if the Metatype and size is exactly the
		// same, ignore - by Mark Newham
			$holdflds = array();
			foreach($flds as $k=>$v) {
				if ( isset($cols[$k]) && is_object($cols[$k]) ) {
					// If already not allowing nulls, then don't change
					$obj = $cols[$k];
					if (isset($obj->not_null) && $obj->not_null)
						$v = str_replace('NOT NULL','',$v);
					if (isset($obj->auto_increment) && $obj->auto_increment && empty($v['AUTOINCREMENT']))
					    $v = str_replace('AUTOINCREMENT','',$v);

					$c = $cols[$k];
					$ml = $c->max_length;
					$mt = $this->MetaType($c->type,$ml);

					if (isset($c->scale)) $sc = $c->scale;
					else $sc = 99; // always force change if scale not known.

					if ($sc == -1) $sc = false;
					list($fsize, $fprec) = $this->_getSizePrec($v['SIZE']);

					if ($ml == -1) $ml = '';
					if ($mt == 'X') $ml = $v['SIZE'];
					if (($mt != $v['TYPE']) || ($ml != $fsize || $sc != $fprec) || (isset($v['AUTOINCREMENT']) && $v['AUTOINCREMENT'] != $obj->auto_increment)) {
						$holdflds[$k] = $v;
					}
				} else {
					$holdflds[$k] = $v;
				}
			}
			$flds = $holdflds;
		}


		// already exists, alter table instead
		list($lines,$pkey,$idxs) = $this->_GenFields($flds);
		// genfields can return FALSE at times
		if ($lines == null) $lines = array();
		$alter = 'ALTER TABLE ' . $this->TableName($tablename);
		$sql = array();

		foreach ( $lines as $id => $v ) {
			if ( isset($cols[$id]) && is_object($cols[$id]) ) {

				$flds = Lens_ParseArgs($v,',');

				//  We are trying to change the size of the field, if not allowed, simply ignore the request.
				// $flds[1] holds the type, $flds[2] holds the size -postnuke addition
				if ($flds && in_array(strtoupper(substr($flds[0][1],0,4)),$this->invalidResizeTypes4)
				 && (isset($flds[0][2]) && is_numeric($flds[0][2]))) {
					if ($this->debug) ADOConnection::outp(sprintf("<h3>%s cannot be changed to %s currently</h3>", $flds[0][0], $flds[0][1]));
					#echo "<h3>$this->alterCol cannot be changed to $flds currently</h3>";
					continue;
	 			}
				$sql[] = $alter . $this->alterCol . ' ' . $v;
			} else {
				$sql[] = $alter . $this->addCol . ' ' . $v;
			}
		}

		if ($dropOldFlds) {
			foreach ( $cols as $id => $v )
			    if ( !isset($lines[$id]) )
					$sql[] = $alter . $this->dropCol . ' ' . $v->name;
		}
		return $sql;
	}

	/**
	*	ACCESS: FINAL PROTECTED
	*	Towards full integration between ADODB and ADODB Data Dictionary: This function is 
	*		intended to build the server info data dynamicaly when needed. This 
	*		avoids having to do the same in each of ADODB's driver's connect functions. However
	*		functionality should be moved there eventually.
	*/	
	protected function _BuildServerInfo($pIsToForceReBuild = false)
	{
		if(($this->_serverInfoArray === null) || $pIsToForceReBuild)
		{
			if(($this->connection !== null) && !empty($this->connection->_connectionID))
				{$this->_serverInfoArray = $this->connection->ServerInfo();}
			$this->serverInfo = $this->_serverInfoArray; //for backward compatibility at the moment.
		}
	}

	/**
	*	ACCESS: FINAL PUBLIC
	*	This function is intended to retrieve relevant server info data. Please refer to
	*		ADOConnection::ServerInfo(). Current possible values for $pKey are 'description' and 
	*		'version'.
	*/	
	public function GetServerInfo($pKey)
	{
		$this->_BuildServerInfo();
		return @$this->_serverInfoArray[$pKey];
	}
	
	/**
	*	ACCESS: FINAL PUBLIC
	*	This function is intended to dynamicaly set the connection, and is intended for use
	*		by ADODB's functions only. Outside use is discouraged. If used ensure that the
	*		connection used is indeed relevant to the ADODB_DataDict instance.(Refer to 
	*		NewDataDictionary())
	*/
	public function SetConnection($pADOConnection)
	{
		$this->connection = $pADOConnection;
		$this->dataProvider = $pADOConnection->dataProvider;
		$this->databaseType = $pADOConnection->databaseType;
		$pADOConnection->nameQuote = $this->nameQuote;

		$pADOConnection->concat_operator = $this->sql_concatenateOperator;
		$pADOConnection->sysDate = $this->sql_sysDate;
		$pADOConnection->sysTimeStamp = $this->sql_sysTimeStamp;
		
		$this->_BuildServerInfo(true);

		$this->_event_connectionSet($pADOConnection);
	}
	
	/**
	*	ACCESS: PROTECTED
	*	Fired when SetConnection() is called and finished execution. Usefull if data dicionaries
	*		need to set up variables differently based on ADODB Driver and Database version.
	*/
	protected function _event_connectionSet($pADOConnection)
		{}

	/**
	*	ACCESS: FINAL PUBLIC
	*	Format date column in sql string given an input format that understands Y M D. Refer
	*			to ADOConnection::SQLDate() for specification. The parameter $pColumnName
	*			accepts a formated name per the ADODB_DataDict::ParseIdentifierName specification.
	*/
	public function FormatDateSQL($pFormat, $pColumnName = false)
	{
		if(!$pColumnName) 
			{return $this->_FormatDateSQL($pFormat, false);}

		return $this->_FormatDateSQL($pFormat, 
				$this->ParseIdentifierName($pColumnName));
	}

	/**
	*	ACCESS: PROTECTED
	*	Format date column in sql string given an input format that understands Y M D. Refer
	*			to ADOConnection::SQLDate() for specification
	*	@param $pFormat	Refer to ADOConnection::SQLDate()
	*	@param $pParsedColumnName The parsed info of identifier name. Refer to ADODB_DataDict::ParseIdentifierName for full specification of the return.
	*/
	protected function _FormatDateSQL($pFormat, $pParsedColumnName = false)
	{
		if (!$pParsedColumnName) {
			return array($this->sql_sysDate);
		}
		return array($pParsedColumnName["name"]); // child class implement
	}
	
	/**
	*	ACCESS: PUBLIC
	*
	* Will parse identifier name formatted using ADODB standardize identifier name format.
	* If identifier name is identifierName, the following can be passed:
	* 	FORM 1: identifierName = To keep default old behavior. (pre 25/jun/215)
	* 	FORM 2: `identifierName` = To quote identifierName with identifier or string quotation,
	*			and keep identifier name without case normalization. Some drivers will normalize 
	*			regardless at the moment.
	*	FORM 3: (identifierName) = To case normalize identifierName.
	*	FORM 4: `(identifierName)` = To quote identifierName with identifier or string quotation 
	*			and case normalize identifierName.
	* The parameter pPassedIsToNormalizeParameter is used for backward compatibility. Some 
	*		functions in ADODB already had support for normalization using a normalization 
	*		boolean parameter, which we shall call isToNormalize, with default value 'true'. All
	*		such functions default values have been, or will be changed from their legacy 
	*		true/false to null. isToNormalize parameter will be passed as 
	*		pPassedIsToNormalizeParameter. If pPassedIsToNormalizeParameter is:
	*		- NULL: This means legacy code did not set isToNormalize, and would also have only
	*			used FORM 1. Hence, if FORM 1, default normlization behavior is 
	*			used (true).
	*		- true/false: This means legacy code explicitly set isToNormalize. and would also 
	*			have only used FORM 1. Hence, if FORM 1, normlization is done based 
	*			on the value of pPassedIsToNormalizeParameter
	*		- -1: This means the ADODB function calling ParseIdentifierName did not have a
	*			isToNormalize parameter.
	*		pPassedIsToNormalizeParameter must only be used by legacy ADODB functions (pre 25/jun/215)
	*
	* @param pIdentifierName					Identifier name
	* @param pPassedIsToNormalizeParameter		This is used for backward compatibility Possible values are '-1', 'null', 'true', 'false'
	*/
	public function ParseIdentifierName($pIdentifierName,  $pPassedIsToNormalizeParameter = -1)
	{
		$vIdentifierName = trim($pIdentifierName);
		$vMatches = NULL;
		$vReturn = array
		(
			"isToQuote" => false,
			"isToNormalize" => false,
			"name" => $vIdentifierName
		);

		if(preg_match('/^`(.+)`$/', $vIdentifierName, $vMatches))
		{
			$vReturn["isToQuote"] = true;
			$vReturn["name"] = $vMatches[1];
			
			if(preg_match('/^\((.+)\)$/', $vMatches[1], $vMatches))
			{
				$vReturn["isToNormalize"] = true;
				$vReturn["name"] = $vMatches[1];
				
			}
		}
		elseif(preg_match('/^\((.+)\)$/', $vIdentifierName, $vMatches))
		{
			$vReturn["isToNormalize"] = true;
			$vReturn["name"] = $vMatches[1];
		}

		if(!$vReturn["isToQuote"] && !$vReturn["isToNormalize"])
		{
			if(($pPassedIsToNormalizeParameter === true) ||
				($pPassedIsToNormalizeParameter === NULL))
			{
				$vReturn["isToNormalize"] = true;
			}
		}

		return $vReturn;
	}
	
	/**
	*	ACCESS: PUBLIC
	*/
	public function ParseTableName($pTableName, $pPassedIsToNormalizeParameter = -1)
	{
		$vReturn = array();

		$vReturn['raw'] = $pTableName;
		if(preg_match('/^(.+)\.(.+)$/', $pTableName, $vMatches))
		{
			$vReturn['schema'] = $this->ParseIdentifierName($vMatches[1],
					$pPassedIsToNormalizeParameter);
			$vReturn['table'] = $this->ParseIdentifierName($vMatches[2],
					$pPassedIsToNormalizeParameter);
		}
		else
		{
			$vReturn['table'] = $this->ParseIdentifierName($pTableName,
					$pPassedIsToNormalizeParameter);
		}

		return $vReturn;
	}

	/**
	*	ACCESS: PUBLIC
	*	Returns the sql required for locking a row. Refer to ADOConnection::RowLock() for 
	*		specification
	*/	
	public function RowLockSQL($pTableNamesSqlSegment, $pWhereSqlSegment, $col='1 as adodbignore')
		{return array();}
		
	/**
	*	ACCESS: FINAL PUBLIC
	*	Creates the SQL required by ADOConnection::CreateSequence(). Refer to that function for
	*		specification. The parameter $pSequenceName accepts a formated name per the 
	*		ADODB_DataDict::ParseIdentifierName specification.
	*/
	public function CreateSequenceSQL($pSequenceName, $pStartID = 1)
	{
		$vParsedSequenceName = $this->ParseIdentifierName($pSequenceName);

		return $this->_CreateSequenceSQL($vParsedSequenceName, $pStartID);
	}
	
	/**
	*	ACCESS: PROTECTED
	*	Creates the SQL required by ADOConnection::CreateSequence(). Refer to that function for
	*		specification. The parameter $pParsedSequenceName is the parsed info of an identifier 
	*		name. Refer to ADODB_DataDict::ParseIdentifierName for full specification of the 
	*		return.
	*/
	protected function _CreateSequenceSQL($pParsedSequenceName, $pStartID = 1)
		{return array();}

	/**
	*	ACCESS: FINAL PUBLIC
	*	Creates the SQL required by ADOConnection::DropSequence(). Refer to that function for
	*		specification. The parameter $pSequenceName accepts a formated name per the 
	*		ADODB_DataDict::ParseIdentifierName specification.
	*/
	public function DropSequenceSQL($pSequenceName)
	{
		$vParsedSequenceName = $this->ParseIdentifierName($pSequenceName);

		return $this->_DropSequenceSQL($vParsedSequenceName);
	}
	
	/**
	*	ACCESS: PROTECTED
	*	Creates the SQL required by ADOConnection::DropSequence(). Refer to that function for
	*		specification. The parameter $pParsedSequenceName is the parsed info of an identifier 
	*		name. Refer to ADODB_DataDict::ParseIdentifierName for full specification of the 
	*		return.
	*/
	protected function _DropSequenceSQL($pParsedSequenceName)
		{return array();}
		
	/**
	*	ACCESS: FINAL PUBLIC
	*	Creates the SQL required by ADOConnection::GenID(). Refer to that function for
	*		specification. The parameter $pSequenceName accepts a formated name per the 
	*		ADODB_DataDict::ParseIdentifierName specification.
	*/
	public function GenIDSQL($pSequenceName, $pStartID = 1)
	{
		$vParsedSequenceName = $this->ParseIdentifierName($pSequenceName);

		return $this->_GenIDSQL($vParsedSequenceName, $pStartID);
	}
	
	/**
	*	ACCESS: PROCTECTED
	*	Creates the SQL required by ADOConnection::GenID(). Refer to that function for
	*		specification. The parameter $pParsedSequenceName is the parsed info of an identifier 
	*		name. Refer to ADODB_DataDict::ParseIdentifierName for full specification of the 
	*		return.
	*/
	protected function _GenIDSQL($pParsedSequenceName)
		{return array();}

	/**
	*	ACCESS: FINAL PUBLIC
	*	Fired by ADOConnection::GenID() before returning. The function must set the
	*		ADOConnection::$genID class variable. The parameter $pSequenceName accepts a 
	*		formated name per the ADODB_DataDict::ParseIdentifierName specification. The
	*		parameter $pADORecordSet is the returned record set of the last sql statement
	*		that ADOConnection::GenID() executed. The statement is provided by GenIDSQL()
	*	Note: If ADOConnection::$genID is set to 0, it indicates a false.
	*/	
	public function event_GenID_calculateAndSetGenID($pSequenceName, $pADORecordSet)
	{
		$vParsedSequenceName = $this->ParseIdentifierName($pSequenceName);
		
		$this->_event_GenID_calculateAndSetGenID($vParsedSequenceName, $pADORecordSet);
	}

	/**
	*	ACCESS: PROTECTED
	*	Fired by ADOConnection::GenID() before returning. The function must set the
	*		ADOConnection::$genID class variable. TThe parameter $pParsedSequenceName is the 
	*		parsed info of an identifier name. Refer to ADODB_DataDict::ParseIdentifierName 
	*		for full specification of the return. The parameter $pADORecordSet is the returned 
	*		record set of the last sql statement that ADOConnection::GenID() executed. The 
	*		statement is provided by GenIDSQL()
	*	Note: If ADOConnection::$genID is set to 0, it indicates a false. 
	*	Note: ADOConnection::$genID is already set to 0 before entering this function. Hence
	*		not explicitly setting it is the same as setting it to 0;
	*/	
	protected function _event_GenID_calculateAndSetGenID($pParsedSequenceName, $pADORecordSet)
	{
		if($pADORecordSet && !$pADORecordSet->EOF)
			{$this->connection->genID = (integer) reset($pADORecordSet->fields);}
	}
} // class
