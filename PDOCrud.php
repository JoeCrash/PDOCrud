<?

	/**
	 * @Project: PDOCrud
	 * @Author: Conceptz (@joeycrash135)
	 * @Version: 1.0
	 * @Date: 10/2015
	 */

	class PDOCrud extends PDO
	{
		/**
		 * @var array Array of saved connections for reusing
		 */
		protected static $connections = array();

		private $dbDsn = ''; //Change as required
		private $dbUser = ''; // Change as required
		private $dbPass = ''; //Change as required
		private $options = []; //Change as required

		/**
		 * Misc vars for operation
		 */
		private $con = false; // Check to see if the connection is active
		public $db = null; // This will be our pdo object
		public $error = '';
		private $result = []; // Any results from a query will be stored here
		private $myQuery = null;// used for debugging process with SQL return
		private $numResults = null;// used for returning the number of rows
		private $rowCount = null;// used for returning the number of rows in resultset
		private $totalRows = null;// used for returning the number of total rows for pagination

		public function __construct($dsn = NULL, $username = NULL, $password = NULL, $options = [])
		{
			$this->dbDsn = $dsn;
			$this->dbUser = $username;
			$this->dbPass = $password;

			if($dsn == NULL){ $this->dbDsn = DB_DSN;}
			if($username == NULL){ $this->dbUser = DB_USER;}
			if($password == NULL){ $this->dbPass = DB_PASS;}

			$default_options = [
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, //key val arrays by default
				PDO::ATTR_EMULATE_PREPARES => false, //no need to emulate prepares, they will be generated
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, //show errors
			];
			$this->options = array_replace($default_options, $options);
			$this->connect();
		}

		public function beginTransaction() {
			return $this->db->beginTransaction();
		}

		public function commit() {
			return $this->db->commit();
		}

		public function rollBack() {
			return $this->db->rollBack();
		}

		/**
		 * @return bool
		 */
		public function connect()
		{
			if (!$this->con) {
				try {
					$this->db = new PDO($this->dbDsn, $this->dbUser, $this->dbPass, $this->options);
				} catch (Exception $e) {
					$this->result = $e->getMessage();
					return false; // Problem selecting database return FALSE
				}
				$this->con = true;
				return $this->con; // Connection has been made return TRUE
			}
		}

		//todo fix query using binds, instead of plugging in through execute
		private function bind(&$query, $k, &$v){
			if(is_numeric($v)){
				$query->bindParam(':'.$k, $v, PDO::PARAM_INT);
			} else if(is_string($v)){
				//echo $v.'is str';
				$query->bindParam(':'.$k, $v, PDO::PARAM_STR);
			} else if(is_bool($v)){
				$query->bindParam(':'.$k, $v, PDO::PARAM_BOOL);
			}else {
				$query->bindParam(':'.$k, $v);
			}
		}

		// Public function to return the data to the user
		public function getResult()
		{
			$val = $this->result;
			$this->result = [];
			return $val;
		}

		/**
		 * @return bool
		 */
		public function disconnect()
		{
			// If there is a connection to the database
			if ($this->con) {
				$this->db = null;
				$this->con = false;
				return true;
			}
			//otherwise return false, no connection to disconnect
			//todo add the connections array to clear selected connections
			return false;
		}

		/**
		 * run raw sql queries
		 * @param  string $sql sql command
		 * @return query results
		 */
		public function raw($sql)
		{
			try {
				$query = $this->db->query($sql);
			} catch (Exception $e) {
				$this->result = $e->getMessage();
				return false; // Problem selecting database return FALSE
			}
			$this->myQuery = $sql;
			$this->result = $query->fetchAll(PDO::FETCH_ASSOC);
		}

		/**
		 * @param $table
		 * @param string $select
		 * @param null $params
		 * @param $pdoParams
		 * @return bool
		 */
		public function select($table, $select = '*', $params = null, $pdoParams = null){
			//sql params
			$where = isset($params['where']) ? $params['where'] : null;
			$join = isset($params['join']) ? $params['join'] : null;
			$group = isset($params['group']) ? $params['group'] : null;
			$order = isset($params['order']) ? $params['order'] : null;
			$limit = isset($params['limit']) ? $params['limit'] : null;
			//pdoParams
			$fetchMode = isset($pdoParams['fetchMode']) ? $pdoParams['fetchMode'] : PDO::FETCH_ASSOC;
			$class = isset($pdoParams['class']) ? $pdoParams['class'] : null;

			// Create query from the variables passed to the function
			$q = 'SELECT SQL_CALC_FOUND_ROWS '.$select.' FROM '.$table;
			if($join != null){
				if(is_array($join)){
					if(is_array($join[0])){
						foreach($join as $k=>$v){
							$q .= (strtolower($v[0]) == 'right' ? ' RIGHT' : ' LEFT'). ' JOIN '.$v[1];
						}
					}else{
						$q .= (strtolower($join[0]) == 'right' ? ' RIGHT' : ' LEFT'). ' JOIN '.$join[1];
					}


					//$q .= ' LEFT JOIN '.$join;
				}else{
					$q .= ' LEFT JOIN '.$join;
				}
			}
			if($where != null){
				$q .= ' WHERE '.$where;
			}
			if($group != null){
				$q .= ' GROUP BY '.$group;
			}
			if($order != null){
				$q .= ' ORDER BY '.$order;
			}
			if($limit != null){
				$q .= ' LIMIT '.$limit;
			}

			#return print_r($q);
			$this->myQuery = $q; //save the last query sql
			$query = $this->db->prepare($q);

			try {
				$query->execute();
			} catch (Exception $e) {
				$this->result = $e->getMessage();
				return false;
			}

			if ($fetchMode === PDO::FETCH_CLASS) {
				$this->result = $query->fetchAll($fetchMode, $class);
			} else {
				$this->result = $query->fetchAll($fetchMode);
			}
			$this->rowCount = $query->rowCount();
			$this->totalRows = $this->db->query("SELECT FOUND_ROWS();")->fetchColumn();
			return $this->result !== false;

		}

		// Function to insert into the database
		public function insert($table, $params = array())
		{
			// Check to see if the table exists
			if ($this->tableExists($table)) {
				//echo "TABLE EXISTS";
				if(isset($params)){
					$fields = [];
					$values = [];
					$binds = [];
					foreach ($params as $k => $v) {
						$cleanK = str_replace(".", "_", $k);
						$fields[] = $k;
						$values[] = ":{$cleanK}";
						$binds[$cleanK] = $v;
					};
					//unset($k, $v);
					$fields = implode(" , ", $fields);
					$values = implode(" , ", $values);

					$fieldsStr = isset($fields) ? ' ('.$fields.')' : '';
					$valuesStr = isset($values) ? ' VALUES ('.$values.') ' : '';

					$SQL = "INSERT INTO {$table}{$fieldsStr}{$valuesStr}";

					$this->myQuery = $SQL;

					$query = $this->db->prepare($SQL);

					try {
						$query->execute($binds);
					} catch (Exception $e) {
						$this->result = $e->getMessage();
						return false;
					}

					$this->rowCount = $query->rowCount();
					$this->result = $this->db->lastInsertId();
					return $this->result !== FALSE;

				}

			}else{
				//todo make error messages
				echo "{$table} table does not exist";
			}

		}

		//Function to delete table or row(s) from database
		public function delete($table, $where = null, $join = null)
		{
			$checkAlias = explode(' ',trim($table));
			$table = isset($checkAlias[0]) ? $checkAlias[0] : $table;
			$alias = isset($checkAlias[1]) ? $checkAlias[1] : null;
			// Check to see if table exists
			if ($this->tableExists($table)) {
				$binds = [];
				if(isset($where)){
					$whereBinds = [];
					foreach ($where as $k => $v) {
						$cleanK = str_replace(".", "_", $k);
						$whereBinds[] = "({$k} = :{$cleanK})";
						$binds[$cleanK] = $v;
					};
					unset($k ,$v);
					$whereBinds = implode('AND ', $whereBinds);
				}

				//todo figure out joins for non fk constraint tables
				/*if(isset($join)){
					$joinBinds = [];
					$joinDeletes = [];

					foreach ($join as $k => $v) {
						$checkJoinAlias = explode(' ',trim($k));
						if(isset($checkJoinAlias[1])){
							$joinDeletes[] = $checkJoinAlias[1];
						}else{
							$joinDeletes[] = $k;
						}
						$joinBinds[] = "JOIN {$k} ON ({$v})";
					};

					unset($k, $v);
					$joinDeleteStr = ', '.implode(' , ', $joinDeletes);
					$joinBinds = implode(' ', $joinBinds);
				}
				//$joinCount = isset($joinBinds) ? count($join) : 0;
				//$joinStr = isset($join) && ($joinCount > 0) ? ' '.$joinBinds.' ' : '';*/
				$tableStr = isset($alias) ? " FROM {$table} {$alias}" : " FROM {$table}";
				$whereStr = isset($where) ? ' WHERE '.$whereBinds.' ' : '';

				$SQL = "DELETE {$alias}{$tableStr}{$whereStr}"; // Create query to delete rows

				$this->myQuery = $SQL;
				$query = $this->db->prepare($SQL);

				try {
					$query->execute($binds);
				} catch (Exception $e) {
					$this->result = $e->getMessage();
					return false;
				}
				$this->rowCount = $query->rowCount();
				return $this->rowCount !== FALSE;

			}
			else {
				return false; // The table does not exist
			}
		}

		// Function to update row in database
		public function update($table, $set = [], $where = [], $join = null)
		{
			$binds = [];
			if(isset($set)){
				$setBinds = [];
				foreach ($set as $k => $v) {
					$cleanK = str_replace(".", "_", $k);
					$setBinds[] = "{$k} = :{$cleanK}";
					$binds[$cleanK] = $v;
				};
				unset($k, $v);
				$setBinds = implode(" , ", $setBinds);
			}

			if(isset($where)){
				$whereBinds = [];
				foreach ($where as $k => $v) {
					$cleanK = str_replace(".", "_", $k);
					//find comparison, clip the 1st 2 characters if found, no symbol is = by default
					if(substr($v, 0, 1) === "<"){
						$v = substr($v, 2);
						$comparison = "<";
					}else if(substr($v, 0, 1) === ">"){
						$v = substr($v, 2);
						$comparison = ">";
					}else{
						$comparison = '=';
					}

					$whereBind = "({$k} {$comparison} :{$cleanK})";
					$whereBinds[] = $whereBind;
					$binds[$cleanK] = $v;

				};
				unset($k ,$v);
				$whereBinds = implode('AND ', $whereBinds);
			}

			if(isset($join)){
				$joinBinds = [];
				foreach ($join as $k => $v) {
					$joinBinds[] = " LEFT JOIN {$k} ON ({$v})";
				};
				unset($k, $v);
				$joinBinds = implode(' ', $joinBinds);
			}

			$joinCount = isset($joinBinds) ? count($join) : 0;
			$joinStr = isset($join) && ($joinCount > 0) ? ''.$joinBinds.' ' : '';
			$setStr = isset($set) ? ' SET '.$setBinds.'' : '';
			$whereStr = isset($where) ? ' WHERE '.$whereBinds.' ' : '';

			$SQL = "UPDATE {$table}{$joinStr}{$setStr}{$whereStr}";
			$this->myQuery = $SQL;
			$query = $this->db->prepare($SQL);

			try {
				$result = $query->execute($binds);
			} catch (Exception $e) {
				$this->result = $e->getMessage();
				return false;
			}
			$this->rowCount = $query->rowCount();
			//return $this->rowCount !== FALSE;
			return $result;
		}

		/**
		 * Check if a table exists in the current database.
		 *
		 * @param PDO $pdo PDO instance connected to a database.
		 * @param string $table Table to search for.
		 * @return bool TRUE if table exists, FALSE if no table found.
		 */
		function tableExists($table) {
			try {
				$result = $this->db->query("SELECT 1 FROM $table LIMIT 1");
			} catch (Exception $e) {
				// We got an exception == table not found
				return FALSE;
			}

			return $result !== FALSE;
		}

		public function getRowCount()
		{
			return $this->rowCount;
		}

		public function getTotalRows()
		{
			return $this->totalRows;
		}

		public function clearResult()
		{
			$this->result = [];
			$this->rowCount = null;
		}

		//Pass the SQL back for debugging
		public function getSql()
		{
			$val = $this->myQuery;
			$this->myQuery = array();
			return $val;
		}

	}
?>