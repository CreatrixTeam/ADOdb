<?php
/*

@version   v5.21.0-dev  ??-???-2016
@copyright (c) 2000-2013 John Lim (jlim#natsoft.com). All rights reserved.
@copyright (c) 2014      Damien Regad, Mark Newnham and the ADOdb community
  Latest version is available at http://adodb.org/

  Released under both BSD license and Lesser GPL library license.
  Whenever there is any discrepancy between the two licenses,
  the BSD license will take precedence.

  Active Record implementation. Superset of Zend Framework's.

  Version 0.92

  See http://www-128.ibm.com/developerworks/java/library/j-cb03076/?ca=dgr-lnxw01ActiveRecord
  	for info on Ruby on Rails Active Record implementation
*/

/**
* Maximum length allowed for object prefix. Matches the setting in adodb-xmlschema03.inc.php
*/
if( !defined( 'XMLS_PREFIX_MAXLEN' ) ) {
	define( 'XMLS_PREFIX_MAXLEN', 10 );
}

global $_ADODB_ACTIVE_DBS;
global $ADODB_ACTIVE_CACHESECS; // set to true to enable caching of metadata such as field info
global $ACTIVE_RECORD_SAFETY; // set to false to disable safety checks
global $ADODB_ACTIVE_DEFVALS; // use default values of table definition when creating new active record.

// array of ADODB_Active_DB's, indexed by ADODB_Active_Record->_dbat
$_ADODB_ACTIVE_DBS = array();
$ACTIVE_RECORD_SAFETY = true;
$ADODB_ACTIVE_DEFVALS = false;
$ADODB_ACTIVE_CACHESECS = 0;

class ADODB_Active_DB {
	public  $db; // ADOConnection
	public  $tables; // assoc array of ADODB_Active_Table objects, indexed by tablename
}

class ADODB_Active_Table {
	public  $name; // table name
	public  $flds; // assoc array of adofieldobjs, indexed by fieldname
	public  $keys; // assoc array of primary keys, indexed by fieldname
	public  $_created; // only used when stored as a cached file
	public  $_belongsTo = array();
	public  $_hasMany = array();
}

// $db = database connection
// $index = name of index - can be associative, for an example see
//    http://phplens.com/lens/lensforum/msgs.php?id=17790
// returns index into $_ADODB_ACTIVE_DBS
function ADODB_SetDatabaseAdapter(&$db, $index=false)
{
	global $_ADODB_ACTIVE_DBS;

		foreach($_ADODB_ACTIVE_DBS as $k => $d) {
			if ($d->db === $db) {
				return $k;
			}
		}

		$obj = new ADODB_Active_DB();
		$obj->db = $db;
		$obj->tables = array();

		if ($index == false) {
			$index = sizeof($_ADODB_ACTIVE_DBS);
		}

		$_ADODB_ACTIVE_DBS[$index] = $obj;

		return sizeof($_ADODB_ACTIVE_DBS)-1;
}


class ADODB_Active_Record {
	static $_changeNames = true; // dynamically pluralize table names
	static $_quoteNames = false;
	static $_tablePrefix = "";	//  table prefix. this prefix is captured when a record is created, and retained for the duration of that record

	static $_foreignSuffix = '_id'; //
	protected  $_dbat; // associative index pointing to ADODB_Active_DB eg. $ADODB_Active_DBS[_dbat]
	protected  $_table; // tablename, if set in class definition then use it as table name
	protected  $_tableat; // associative index pointing to ADODB_Active_Table, eg $ADODB_Active_DBS[_dbat]->tables[$this->_tableat]
	protected  $_where; // where clause set in Load()
	protected  $_saved = false; // indicates whether data is already inserted.
	protected  $_lasterr = false; // last error message
	protected  $_original = false; // the original values loaded or inserted, refreshed on update
	protected  $_data = array(); // the current values
	protected  $_currentRecordPrefix = null;	// (PRIVATE) the prefix for this instance of the active record.

	public  $foreignName; // CFR: class name when in a relationship

	public  $lockMode = ' for update '; // you might want to change to

	static function UseDefaultValues($bool=null)
	{
	global $ADODB_ACTIVE_DEFVALS;
		if (isset($bool)) {
			$ADODB_ACTIVE_DEFVALS = $bool;
		}
		return $ADODB_ACTIVE_DEFVALS;
	}

	// should be static
	static function SetDatabaseAdapter(&$db, $index=false)
	{
		return ADODB_SetDatabaseAdapter($db, $index);
	}


	public function __set($name, $value)
	{
		$name = str_replace(' ', '_', $name);
		$this->_data[$name] = $value;
	}

	// php5 constructor
	public function __construct($table = false, $pkeyarr=false, $db=false)
	{
	global $_ADODB_ACTIVE_DBS;

		if ($db == false && is_object($pkeyarr)) {
			$db = $pkeyarr;
			$pkeyarr = false;
		}

		if (!$table) {
			if (!empty($this->_table)) {
				$table = $this->_table;
			}
			else $table = $this->_pluralize(get_class($this));
		}
		$this->foreignName = strtolower(get_class($this)); // CFR: default foreign name
		if ($db) {
			$this->_dbat = ADODB_Active_Record::SetDatabaseAdapter($db);
		} else if (!isset($this->_dbat)) {
			if (sizeof($_ADODB_ACTIVE_DBS) == 0) {
				$this->Error(
					"No database connection set; use ADOdb_Active_Record::SetDatabaseAdapter(\$db)",
					'ADODB_Active_Record::__constructor'
				);
			}
			end($_ADODB_ACTIVE_DBS);
			$this->_dbat = key($_ADODB_ACTIVE_DBS);
		}

		$this->_table = $table;
		$this->_tableat = $table; # reserved for setting the assoc value to a non-table name, eg. the sql string in future

		$this->UpdateActiveTable($pkeyarr);
	}

	public function __wakeup()
	{
  		$class = get_class($this);
  		new $class;
	}

	protected function _pluralize($table)
	{
		if (!ADODB_Active_Record::$_changeNames) {
			return $table;
		}

		$ut = strtoupper($table);
		$len = strlen($table);
		$lastc = $ut[$len-1];
		$lastc2 = substr($ut,$len-2);
		switch ($lastc) {
		case 'S':
			return $table.'es';
		case 'Y':
			return substr($table,0,$len-1).'ies';
		case 'X':
			return $table.'es';
		case 'H':
			if ($lastc2 == 'CH' || $lastc2 == 'SH') {
				return $table.'es';
			}
		default:
			return $table.'s';
		}
	}

	// CFR Lamest singular inflector ever - @todo Make it real!
	// Note: There is an assumption here...and it is that the argument's length >= 4
	protected function _singularize($tables)
	{

		if (!ADODB_Active_Record::$_changeNames) {
			return $table;
		}

		$ut = strtoupper($tables);
		$len = strlen($tables);
		if($ut[$len-1] != 'S') {
			return $tables; // I know...forget oxen
		}
		if($ut[$len-2] != 'E') {
			return substr($tables, 0, $len-1);
		}
		switch($ut[$len-3]) {
			case 'S':
			case 'X':
				return substr($tables, 0, $len-2);
			case 'I':
				return substr($tables, 0, $len-3) . 'y';
			case 'H';
				if($ut[$len-4] == 'C' || $ut[$len-4] == 'S') {
					return substr($tables, 0, $len-2);
				}
			default:
				return substr($tables, 0, $len-1); // ?
		}
	}

	public function hasMany($foreignRef, $foreignKey = false, $foreignClass = 'ADODB_Active_Record')
	{
		$ar = new $foreignClass($foreignRef);
		$ar->foreignName = $foreignRef;
		$ar->UpdateActiveTable();
		$ar->foreignKey = ($foreignKey) ? $foreignKey : $foreignRef.ADODB_Active_Record::$_foreignSuffix;
		$table =& $this->TableInfo();
		$table->_hasMany[$foreignRef] = $ar;
	#	$this->$foreignRef = $this->_hasMany[$foreignRef]; // WATCHME Removed assignment by ref. to please __get()
	}

	// use when you don't want ADOdb to auto-pluralize tablename
	static function TableHasMany($table, $foreignRef, $foreignKey = false, $foreignClass = 'ADODB_Active_Record')
	{
		$ar = new ADODB_Active_Record($table);
		$ar->hasMany($foreignRef, $foreignKey, $foreignClass);
	}

	// use when you don't want ADOdb to auto-pluralize tablename
	static function TableKeyHasMany($table, $tablePKey, $foreignRef, $foreignKey = false, $foreignClass = 'ADODB_Active_Record')
	{
		if (!is_array($tablePKey)) {
			$tablePKey = array($tablePKey);
		}
		$ar = new ADODB_Active_Record($table,$tablePKey);
		$ar->hasMany($foreignRef, $foreignKey, $foreignClass);
	}


	// use when you want ADOdb to auto-pluralize tablename for you. Note that the class must already be defined.
	// e.g. class Person will generate relationship for table Persons
	static function ClassHasMany($parentclass, $foreignRef, $foreignKey = false, $foreignClass = 'ADODB_Active_Record')
	{
		$ar = new $parentclass();
		$ar->hasMany($foreignRef, $foreignKey, $foreignClass);
	}


	public function belongsTo($foreignRef,$foreignKey=false, $parentKey='', $parentClass = 'ADODB_Active_Record')
	{
		global $inflector;

		$ar = new $parentClass($this->_pluralize($foreignRef));
		$ar->foreignName = $foreignRef;
		$ar->parentKey = $parentKey;
		$ar->UpdateActiveTable();
		$ar->foreignKey = ($foreignKey) ? $foreignKey : $foreignRef.ADODB_Active_Record::$_foreignSuffix;

		$table =& $this->TableInfo();
		$table->_belongsTo[$foreignRef] = $ar;
	#	$this->$foreignRef = $this->_belongsTo[$foreignRef];
	}

	static function ClassBelongsTo($class, $foreignRef, $foreignKey=false, $parentKey='', $parentClass = 'ADODB_Active_Record')
	{
		$ar = new $class();
		$ar->belongsTo($foreignRef, $foreignKey, $parentKey, $parentClass);
	}

	static function TableBelongsTo($table, $foreignRef, $foreignKey=false, $parentKey='', $parentClass = 'ADODB_Active_Record')
	{
		$ar = new ADOdb_Active_Record($table);
		$ar->belongsTo($foreignRef, $foreignKey, $parentKey, $parentClass);
	}

	static function TableKeyBelongsTo($table, $tablePKey, $foreignRef, $foreignKey=false, $parentKey='', $parentClass = 'ADODB_Active_Record')
	{
		if (!is_array($tablePKey)) {
			$tablePKey = array($tablePKey);
		}
		$ar = new ADOdb_Active_Record($table, $tablePKey);
		$ar->belongsTo($foreignRef, $foreignKey, $parentKey, $parentClass);
	}


	/**
	 * __get Access properties - used for lazy loading
	 *
	 * @param mixed $name
	 * @access protected
	 * @return mixed
	 */
	public function __get($name)
	{
		if(array_key_exists($name, $this->_data))
			{return $this->_data[$name];}
		return $this->LoadRelations($name, '', -1, -1);
	}

	/**
	 * @param string $name
	 * @param string $whereOrderBy : eg. ' AND field1 = value ORDER BY field2'
	 * @param offset
	 * @param limit
	 * @return mixed
	 */
	public function LoadRelations($name, $whereOrderBy='', $offset=-1,$limit=-1)
	{
		$extras = array();
		$table = $this->TableInfo();
		if ($limit >= 0) {
			$extras['limit'] = $limit;
		}
		if ($offset >= 0) {
			$extras['offset'] = $offset;
		}

		if (strlen($whereOrderBy)) {
			if (!preg_match('/^[ \n\r]*AND/i', $whereOrderBy)) {
				if (!preg_match('/^[ \n\r]*ORDER[ \n\r]/i', $whereOrderBy)) {
					$whereOrderBy = 'AND ' . $whereOrderBy;
				}
			}
		}

		if(!empty($table->_belongsTo[$name])) {
			$obj = $table->_belongsTo[$name];
			$obj->_currentRecordPrefix = $this->GetTableNamePrefix();
			$obj->_tableat = $this->_tableat;
			$columnName = $obj->foreignKey;
			if(empty($this->_data[$columnName])) {
				$this->_data[$name] = null;
			}
			else {
				if ($obj->parentKey) {
					$key = $obj->parentKey;
				}
				else {
					$key = reset($table->keys);
				}

				$arrayOfOne = $obj->Find($key.'='.$this->_data[$columnName].' '.$whereOrderBy,false,false,$extras);
				if ($arrayOfOne) {
					$this->_data[$name] = $arrayOfOne[0];
					return $arrayOfOne[0];
				}
			}
		}
		if(!empty($table->_hasMany[$name])) {
			$obj = $table->_hasMany[$name];
			$obj->_currentRecordPrefix = $this->GetTableNamePrefix();
			$obj->_tableat = $this->_tableat;
			$key = reset($table->keys);
			$id = @$this->_data[$key];
			if (!is_numeric($id)) {
				$db = $this->DB();
				$id = $db->qstr($id);
			}
			$objs = $obj->Find($obj->foreignKey.'='.$id. ' '.$whereOrderBy,false,false,$extras);
			if (!$objs) {
				$objs = array();
			}
			$this->_data[$name] = $objs;
			return $objs;
		}

		return array();
	}
	//////////////////////////////////

	// update metadata
	public function UpdateActiveTable($pkeys=false,$forceUpdate=false)
	{
		global $ADODB_ASSOC_CASE;
	global $_ADODB_ACTIVE_DBS , $ADODB_CACHE_DIR, $ADODB_ACTIVE_CACHESECS;
	global $ADODB_ACTIVE_DEFVALS;

		$activedb = $_ADODB_ACTIVE_DBS[$this->_dbat];

		$table = $this->_table;
		$tables = $activedb->tables;
		$tableat = $this->_tableat;
		if (!$forceUpdate && !empty($tables[$tableat])) {

			$acttab = $tables[$tableat];
			foreach($acttab->flds as $name => $fld) {
				if ($ADODB_ACTIVE_DEFVALS && isset($fld->default_value)) {
					$this->_data[$name] = $fld->default_value;
				}
				else {
					$this->_data[$name] = null;
				}
			}
			return;
		}
		$db = $activedb->db;
		$fname = $ADODB_CACHE_DIR . '/adodb_' . $db->databaseType . '_active_'. $table . '.cache';
		if (!$forceUpdate && $ADODB_ACTIVE_CACHESECS && $ADODB_CACHE_DIR && file_exists($fname)) {
			$fp = fopen($fname,'r');
			@flock($fp, LOCK_SH);
			$acttab = unserialize(fread($fp,100000));
			fclose($fp);
			if ($acttab->_created + $ADODB_ACTIVE_CACHESECS - (abs(rand()) % 16) > time()) {
				// abs(rand()) randomizes deletion, reducing contention to delete/refresh file
				// ideally, you should cache at least 32 secs

				foreach($acttab->flds as $name => $fld) {
					if ($ADODB_ACTIVE_DEFVALS && isset($fld->default_value)) {
						$this->_data[$name] = $fld->default_value;
					}
					else {
						$this->_data[$name] = null;
					}
				}

				$activedb->tables[$table] = $acttab;

				//if ($db->debug) ADOConnection::outp("Reading cached active record file: $fname");
			  	return;
			} else if ($db->debug) {
				ADOConnection::outp("Refreshing cached active record file: $fname");
			}
		}
		$activetab = new ADODB_Active_Table();
		$activetab->name = $table;

		$savem = $db->SetFetchMode2(ADODB_FETCH_ASSOC);


		$cols = $db->MetaColumns($this->getTableName()); //$cols = $db->MetaColumns($table);

		$db->SetFetchMode2($savem);

		if (!$cols) {
			$this->Error("Invalid table name: ".$this->getTableName(),'UpdateActiveTable'); //$this->Error("Invalid table name: $table",'UpdateActiveTable');
			return false;
		}
		$fld = reset($cols);
		if (!$pkeys) {
			if (isset($fld->primary_key)) {
				$pkeys = array();
				foreach($cols as $name => $fld) {
					if (!empty($fld->primary_key)) {
						$pkeys[] = $name;
					}
				}
			} else
				$pkeys = $this->GetPrimaryKeys($db, $table);
		}
		if (empty($pkeys)) {
			$this->Error("No primary key found for table $table",'UpdateActiveTable');
			return false;
		}

		$attr = array();
		$keys = array();

		switch ($ADODB_ASSOC_CASE) {
		case ADODB_ASSOC_CASE_LOWER:
			foreach($cols as $name => $fldobj) {
				$name = strtolower($name);
				if ($ADODB_ACTIVE_DEFVALS && isset($fldobj->default_value)) {
					$this->_data[$name] = $fldobj->default_value;
				}
				else {
					$this->_data[$name] = null;
				}
				$attr[$name] = $fldobj;
			}
			foreach($pkeys as $k => $name) {
				$keys[strtolower($name)] = strtolower($name);
			}
			break;

		case ADODB_ASSOC_CASE_UPPER:
			foreach($cols as $name => $fldobj) {
				$name = strtoupper($name);

				if ($ADODB_ACTIVE_DEFVALS && isset($fldobj->default_value)) {
					$this->_data[$name] = $fldobj->default_value;
				}
				else {
					$this->_data[$name] = null;
				}
				$attr[$name] = $fldobj;
			}

			foreach($pkeys as $k => $name) {
				$keys[strtoupper($name)] = strtoupper($name);
			}
			break;
		default:
			foreach($cols as $name => $fldobj) {
				$name = ($fldobj->name);

				if ($ADODB_ACTIVE_DEFVALS && isset($fldobj->default_value)) {
					$this->_data[$name] = $fldobj->default_value;
				}
				else {
					$this->_data[$name] = null;
				}
				$attr[$name] = $fldobj;
			}
			foreach($pkeys as $k => $name) {
				$keys[$name] = $cols[$name]->name;
			}
			break;
		}

		$activetab->keys = $keys;
		$activetab->flds = $attr;

		if ($ADODB_ACTIVE_CACHESECS && $ADODB_CACHE_DIR) {
			$activetab->_created = time();
			$s = serialize($activetab);
			if (!function_exists('adodb_write_file')) {
				include_once(ADODB_DIR.'/adodb-csvlib.inc.php');
			}
			adodb_write_file($fname,$s);
		}
		if (isset($activedb->tables[$table])) {
			$oldtab = $activedb->tables[$table];

			if ($oldtab) {
				$activetab->_belongsTo = $oldtab->_belongsTo;
				$activetab->_hasMany = $oldtab->_hasMany;
			}
		}
		$activedb->tables[$table] = $activetab;
	}

	public function GetPrimaryKeys(&$db, $table)
	{
		return $db->MetaPrimaryKeys($table);
	}

	// error handler for both PHP4+5.
	public function Error($err,$fn)
	{
	global $_ADODB_ACTIVE_DBS;

		$fn = get_class($this).'::'.$fn;
		$this->_lasterr = $fn.': '.$err;

		if ($this->_dbat < 0) {
			$db = false;
		}
		else {
			$activedb = $_ADODB_ACTIVE_DBS[$this->_dbat];
			$db = $activedb->db;
		}

		if (function_exists('adodb_throw')) {
			if (!$db) {
				adodb_throw('ADOdb_Active_Record', $fn, -1, $err, 0, 0, false);
			}
			else {
				adodb_throw($db->databaseType, $fn, -1, $err, 0, 0, $db);
			}
		} else {
			if (!$db || $db->debug) {
				ADOConnection::outp($this->_lasterr);
			}
		}

	}

	// return last error message
	public function ErrorMsg()
	{
		if (!function_exists('adodb_throw')) {
			if ($this->_dbat < 0) {
				$db = false;
			}
			else {
				$db = $this->DB();
			}

			// last error could be database error too
			if ($db && $db->ErrorMsg()) {
				return $db->ErrorMsg();
			}
		}
		return $this->_lasterr;
	}

	public function ErrorNo()
	{
		if ($this->_dbat < 0) {
			return -9999; // no database connection...
		}
		$db = $this->DB();

		return (int) $db->ErrorNo();
	}


	// retrieve ADOConnection from _ADODB_Active_DBs
	public function DB()
	{
	global $_ADODB_ACTIVE_DBS;

		if ($this->_dbat < 0) {
			$false = false;
			$this->Error("No database connection set: use ADOdb_Active_Record::SetDatabaseAdaptor(\$db)", "DB");
			return $false;
		}
		$activedb = $_ADODB_ACTIVE_DBS[$this->_dbat];
		$db = $activedb->db;
		return $db;
	}

	// retrieve ADODB_Active_Table
	public function &TableInfo()
	{
	global $_ADODB_ACTIVE_DBS;
		$activedb = $_ADODB_ACTIVE_DBS[$this->_dbat];
		$table = $activedb->tables[$this->_tableat];
		return $table;
	}


	// I have an ON INSERT trigger on a table that sets other columns in the table.
	// So, I find that for myTable, I want to reload an active record after saving it. -- Malcolm Cook
	public function Reload()
	{
		$db = $this->DB();
		if (!$db) {
			return false;
		}
		$table = $this->TableInfo();
		$where = $this->GenWhere($db, $table);
		return($this->Load($where));
	}


	// set a numeric array (using natural table field ordering) as object properties
	public function Set(&$row)
	{
	global $ACTIVE_RECORD_SAFETY;

		$db = $this->DB();

		if (!$row) {
			$this->_saved = false;
			return false;
		}

		$this->_saved = true;

		$table = $this->TableInfo();
		if ($ACTIVE_RECORD_SAFETY && sizeof($table->flds) != sizeof($row)) {
			# <AP>
			$bad_size = TRUE;
			if (sizeof($row) == 2 * sizeof($table->flds)) {
				// Only keep string keys
				$keys = array_filter(array_keys($row), 'is_string');
				if (sizeof($keys) == sizeof($table->flds)) {
					$bad_size = FALSE;
				}
			}
			if ($bad_size) {
				$this->Error("Table structure of ".$this->GetTableName()." has changed","Load");//$this->Error("Table structure of $this->_table has changed","Load");
				return false;
			}
			# </AP>
		}
		else
			$keys = array_keys($row);

		# <AP>
		reset($keys);
		$this->_original = array();
		foreach($table->flds as $name=>$fld) {
			$value = $row[current($keys)];
			$this->_data[$name] = $value;
			$this->_original[] = $value;
			next($keys);
		}

		# </AP>
		return true;
	}

	// get last inserted id for INSERT
	public function LastInsertID(&$db,$fieldname)
	{
		if ($db->hasInsertID) {
			$val = $db->Insert_ID($this->GetTableName(),$fieldname); //$val = $db->Insert_ID($this->_table,$fieldname);
		}
		else {
			$val = false;
		}

		if (is_null($val) || $val === false) {
			// this might not work reliably in multi-user environment
			return $db->GetOne("select max(".$this->_QName($fieldname, $db).") from ".$this->_QName($this->GetTableName(), $db)); //return $db->GetOne("select max(".$fieldname.") from ".$this->_table);
		}
		return $val;
	}

	// quote data in where clause
	public function doquote(&$db, $val,$t)
	{
		switch($t) {
		case 'L':
			if (strpos($db->databaseType,'postgres') !== false) {
				return $db->qstr($val);
			}
		case 'D':
		case 'T':
			if (empty($val)) {
				return 'null';
			}
		case 'B':
		case 'N':
		case 'C':
		case 'X':
			if (is_null($val)) {
				return 'null';
			}

			if (strlen($val)>0 &&
				(strncmp($val,"'",1) != 0 || substr($val,strlen($val)-1,1) != "'")
			) {
				return $db->qstr($val);
				break;
			}
		default:
			return $val;
			break;
		}
	}

	// generate where clause for an UPDATE/SELECT
	public function GenWhere(&$db, &$table)
	{
		$keys = $table->keys;
		$parr = array();

		foreach($keys as $k) {
			$f = $table->flds[$k];
			if ($f) {
				$parr[] = $this->_QName($k, $db).' = '.$this->doquote($db,$this->_data[$k],$db->MetaType($f->type));
			}
		}
		return implode(' and ', $parr);
	}


	protected function _QName($n,$db=false)
	{
		if (!ADODB_Active_Record::$_quoteNames) {
			return $n;
		}
		if (!$db) {
			$db = $this->DB();
			if (!$db) {
				return false;
			}
		}
		return $db->ForceNameQuote($n);
	}

	//------------------------------------------------------------ Public functions below

	public function Load($where=null,$bindarr=false, $lock = false)
	{
		$db = $this->DB();
		if (!$db) {
			return false;
		}
		$this->_where = $where;

		$savem = $db->SetFetchMode2(ADODB_FETCH_NUM);

		$qry = "select * from ".$this->_QName($this->GetTableName(), $db); //$qry = "select * from ".$this->_table;

		if($where) {
			$qry .= ' WHERE '.$where;
		}
		if ($lock) {
			$qry .= $this->lockMode;
		}

		$row = $db->GetRow($qry,$bindarr);

		$db->SetFetchMode2($savem);

		return $this->Set($row);
	}

	public function LoadLocked($where=null, $bindarr=false)
	{
		$this->Load($where,$bindarr,true);
	}

	# useful for multiple record inserts
	# see http://phplens.com/lens/lensforum/msgs.php?id=17795
	public function Reset()
	{
		$this->_where=null;
		$this->_saved = false;
		$this->_lasterr = false;
		$this->_original = false;
		foreach($this->_data as $k=>$v){
			$this->_data[$k]=null;
		}
		$this->foreignName=strtolower(get_class($this));
		return true;
	}

	// false on error
	public function Save()
	{
		if ($this->_saved) {
			$ok = $this->Update();
		}
		else {
			$ok = $this->Insert();
		}

		return $ok;
	}


	// false on error
	public function Insert()
	{
		$db = $this->DB();
		if (!$db) {
			return false;
		}
		$cnt = 0;
		$table = $this->TableInfo();

		$valarr = array();
		$names = array();
		$valstr = array();

		foreach($table->flds as $name=>$fld) {
			$val = $this->_data[$name];
			if(!is_array($val) || !is_null($val) || !array_key_exists($name, $table->keys)) {
				$valarr[] = $val;
				$names[] = $this->_QName($name,$db);
				$valstr[] = $db->Param($cnt);
				$cnt += 1;
			}
		}

		if (empty($names)){
			foreach($table->flds as $name=>$fld) {
				$valarr[] = null;
				$names[] = $this->_QName($name, $db);
				$valstr[] = $db->Param($cnt);
				$cnt += 1;
			}
		}
		$sql = 'INSERT INTO '.$this->_QName($this->GetTableName(), $db)." (".implode(',',$names).') VALUES ('.implode(',',$valstr).')'; //$sql = 'INSERT INTO '.$this->_table."(".implode(',',$names).') VALUES ('.implode(',',$valstr).')';
		$ok = $db->Execute($sql,$valarr);

		if ($ok) {
			$this->_saved = true;
			$autoinc = false;
			foreach($table->keys as $k) {
				if (is_null($this->_data[$k])) {
					$autoinc = true;
					break;
				}
			}
			if ($autoinc && sizeof($table->keys) == 1) {
				$k = reset($table->keys);
				$this->_data[$k] = $this->LastInsertID($db,$k);
			}
		}

		$this->_original = $valarr;
		return !empty($ok);
	}

	public function Delete()
	{
		$db = $this->DB();
		if (!$db) {
			return false;
		}
		$table = $this->TableInfo();

		$where = $this->GenWhere($db,$table);
		$sql = 'DELETE FROM '.$this->_QName($this->GetTableName(), $db).' WHERE '.$where; //$sql = 'DELETE FROM '.$this->_table.' WHERE '.$where;
		$ok = $db->Execute($sql);

		return $ok ? true : false;
	}

	// returns an array of active record objects
	public function Find($whereOrderBy,$bindarr=false,$pkeysArr=false,$extra=array())
	{
		$db = $this->DB();		
		if (!$db || empty($this->_table)) {
			return false;
		}
		$oldTablePrefix = ADODB_Active_Record::$_tablePrefix;
		ADODB_Active_Record::$_tablePrefix = $this->_currentRecordPrefix;
		$arr = $db->GetActiveRecordsClass(get_class($this),$this->_table, $whereOrderBy,$bindarr,$pkeysArr,$extra);
		ADODB_Active_Record::$_tablePrefix = $oldTablePrefix;
		return $arr;
	}

	// returns 0 on error, 1 on update, 2 on insert
	public function Replace()
	{
		global $ADODB_ASSOC_CASE;

		$db = $this->DB();
		if (!$db) {
			return false;
		}
		$table = $this->TableInfo();

		$pkey = $table->keys;

		foreach($table->flds as $name=>$fld) {
			$val = $this->_data[$name];
			/*
			if (is_null($val)) {
				if (isset($fld->not_null) && $fld->not_null) {
					if (isset($fld->default_value) && strlen($fld->default_value)) {
						continue;
					}
					else {
						$this->Error("Cannot update null into $name","Replace");
						return false;
					}
				}
			}*/
			if (is_null($val) && !empty($fld->auto_increment)) {
				continue;
			}

			if (is_array($val)) {
				continue;
			}

			$t = $db->MetaType($fld->type);
			$arr[$name] = $this->doquote($db,$val,$t);
			$valarr[] = $val;
		}

		if (!is_array($pkey)) {
			$pkey = array($pkey);
		}

		switch ($ADODB_ASSOC_CASE) {
			case ADODB_ASSOC_CASE_LOWER:
				foreach ($pkey as $k => $v) {
					$pkey[$k] = strtolower($v);
				}
				break;
			case ADODB_ASSOC_CASE_UPPER:
				foreach ($pkey as $k => $v) {
					$pkey[$k] = strtoupper($v);
				}
				break;
		}

		$ok = $this->Replace__Do($this->GetTableName(),$arr,$pkey); //$ok = $db->Replace($this->_table,$arr,$pkey);

		if ($ok) {
			$this->_saved = true; // 1= update 2=insert
			if ($ok == 2) {
				$autoinc = false;
				foreach($table->keys as $k) {
					if (is_null($this->_data[$k])) {
						$autoinc = true;
						break;
					}
				}
				if ($autoinc && sizeof($table->keys) == 1) {
					$k = reset($table->keys);
					$this->_data[$k] = $this->LastInsertID($db,$k);
				}
			}

			$this->_original = $valarr;
		}
		return $ok;
	}

	//ALMOST VERBATIM TO _adodb_replace
	private function Replace__Do($table, $fieldArray, $keyCol)
	{
		if (count($fieldArray) == 0) return 0;
		$first = true;
		$uSet = '';
		$vDb = $this->DB();


		foreach($fieldArray as $k => $v) {
			if ($v === null) {
				$v = 'NULL';
				$fieldArray[$k] = $v;
			} 

			if (in_array($k,$keyCol)) continue; // skip UPDATE if is key

			if ($first) {
				$first = false;
				$uSet = $this->_QName($vDb, $k) . "=$v";
			} else
				$uSet .= "," . $this->_QName($vDb, $k) . "=$v";
		}

		$where = false;
		foreach ($keyCol as $v) {
			if (isset($fieldArray[$v])) {
				if ($where) $where .= ' and '.$this->_QName($vDb, $v).'='.$fieldArray[$v];
				else $where = $this->_QName($vDb, $v).'='.$fieldArray[$v];
			}
		}

		if ($uSet && $where) {
			$update = "UPDATE " . $this->_QName($vDb,$table) . " SET $uSet WHERE $where";

			$rs = $vDb->Execute($update);


			if ($rs) {
				if ($vDb->poorAffectedRows) {
				/*
				 The Select count(*) wipes out any errors that the update would have returned.
				http://phplens.com/lens/lensforum/msgs.php?id=5696
				*/
					if ($vDb->ErrorNo()<>0) return 0;

				# affected_rows == 0 if update field values identical to old values
				# for mysql - which is silly.

					$cnt = $vDb->GetOne("select count(*) from " . $this->_QName($vDb,$table) . " where $where");
					if ($cnt > 0) return 1; // record already exists
				} else {
					if (($vDb->Affected_Rows()>0)) return 1;
				}
			} else
				return 0;
		}

	//	print "<p>Error=".$this->ErrorNo().'<p>';
		$first = true;
		foreach($fieldArray as $k => $v) {
			if ($first) {
				$first = false;
				$iCols = $this->_QName($vDb,$k);
				$iVals = "$v";
			} else {
				$iCols .= "," . $this->_QName($vDb,$k);
				$iVals .= ",$v";
			}
		}
		$insert = "INSERT INTO " . $this->_QName($vDb,$table) . " ($iCols) VALUES ($iVals)";
		$rs = $vDb->Execute($insert);
		return ($rs) ? 2 : 0;
	}

	// returns 0 on error, 1 on update, -1 if no change in data (no update)
	public function Update()
	{
		$db = $this->DB();
		if (!$db) {
			return false;
		}
		$table = $this->TableInfo();

		$where = $this->GenWhere($db, $table);

		if (!$where) {
			$this->error("Where missing for table $table", "Update");
			return false;
		}
		$valarr = array();
		$neworig = array();
		$pairs = array();
		$i = -1;
		$cnt = 0;
		foreach($table->flds as $name=>$fld) {
			$i += 1;
			$val = $this->_data[$name];
			$neworig[] = $val;

			if (isset($table->keys[$name]) || is_array($val)) {
				continue;
			}

			if (is_null($val)) {
				if (isset($fld->not_null) && $fld->not_null) {
					if (isset($fld->default_value) && strlen($fld->default_value)) {
						continue;
					}
					else {
						$this->Error("Cannot set field $name to NULL","Update");
						return false;
					}
				}
			}

			if (isset($this->_original[$i]) && strcmp($val,$this->_original[$i]) == 0) {
				continue;
			}

			if (is_null($this->_original[$i]) && is_null($val)) {
				continue;
			}

			$valarr[] = $val;
			$pairs[] = $this->_QName($name,$db).'='.$db->Param($cnt);
			$cnt += 1;
		}


		if (!$cnt) {
			return -1;
		}

		$sql = 'UPDATE '.$this->_QName($this->GetTableName(), $db)." SET ".implode(",",$pairs)." WHERE ".$where; //$sql = 'UPDATE '.$this->_table." SET ".implode(",",$pairs)." WHERE ".$where;
		$ok = $db->Execute($sql,$valarr);
		if ($ok) {
			$this->_original = $neworig;
			return 1;
		}
		return 0;
	}

	public function GetAttributeNames()
	{
		$table = $this->TableInfo();
		if (!$table) {
			return false;
		}
		return array_keys($table->flds);
	}

	public function GetTableName()
	{		
		return $this->GetTableNamePrefix().$this->_table;
	}
	public function GetTableNamePrefix()
	{
		if( $this->_currentRecordPrefix === null ) {
			$this->_currentRecordPrefix = adodb_active_GetTableNamePrefix();
		}
		return $this->_currentRecordPrefix;
	}
};

function adodb_active_GetTableNamePrefix()
{
	$maxPrefixLength = XMLS_PREFIX_MAXLEN;

	if(substr( ADODB_Active_Record::$_tablePrefix, -1 ) === '_' ) {
		$maxPrefixLength++;
	}

	//the following matches what is in adodb-xmlschema03.inc.php with consideration to the underscore option provided in that code
	switch( TRUE ) {
		// clear prefix
		case empty( ADODB_Active_Record::$_tablePrefix ):
			//logMsg( 'Cleared prefix' );
			return '';			
		// prefix too long
		case strlen( ADODB_Active_Record::$_tablePrefix ) > $maxPrefixLength:
		// prefix contains invalid characters
		case !preg_match( '/^[a-z][a-z0-9_]+$/i', ADODB_Active_Record::$_tablePrefix ):
			//logMsg( 'Invalid prefix: ' . $prefix );
			return FALSE;
	}

	return ADODB_Active_Record::$_tablePrefix;
}

function adodb_GetActiveRecordsClass(&$db, $class, $table,$whereOrderBy,$bindarr, $primkeyArr,
			$extra)
{
global $_ADODB_ACTIVE_DBS;


	$save = $db->SetFetchMode2(ADODB_FETCH_NUM);

	$qry = "select * from ".adodb_active_GetTableNamePrefix().$table; //$qry = "select * from ".$table;

	if (!empty($whereOrderBy)) {
		$qry .= ' WHERE '.$whereOrderBy;
	}
	if(isset($extra['limit'])) {
		$rows = false;
		if(isset($extra['offset'])) {
			$rs = $db->SelectLimit($qry, $extra['limit'], $extra['offset'],$bindarr);
		} else {
			$rs = $db->SelectLimit($qry, $extra['limit'],-1,$bindarr);
		}
		if ($rs) {
			while (!$rs->EOF) {
				$rows[] = $rs->fields;
				$rs->MoveNext();
			}
		}
	} else
		$rows = $db->GetAll($qry,$bindarr);

	$db->SetFetchMode2($save);

	$false = false;

	if ($rows === false) {
		return $false;
	}


	if (!class_exists($class)) {
		$db->outp_throw("Unknown class $class in GetActiveRecordsClass()",'GetActiveRecordsClass');
		return $false;
	}
	$arr = array();
	// arrRef will be the structure that knows about our objects.
	// It is an associative array.
	// We will, however, return arr, preserving regular 0.. order so that
	// obj[0] can be used by app developers.
	$arrRef = array();
	$bTos = array(); // Will store belongTo's indices if any
	foreach($rows as $row) {

		$obj = new $class($table,$primkeyArr,$db);
		if ($obj->ErrorNo()){
			$db->_errorMsg = $obj->ErrorMsg();
			return $false;
		}
		$obj->Set($row);
		$obj->GetTableName(); //imprints active record with prefix
		$arr[] = $obj;
	} // foreach($rows as $row)

	return $arr;
}
