<?php

namespace AdminNeo;

add_driver("elastic", "Elasticsearch 7 (beta)");

if (isset($_GET["elastic"])) {
	define("AdminNeo\DRIVER", "elastic");

	if (ini_bool('allow_url_fopen')) {
		define("AdminNeo\ELASTIC_DB_NAME", "elastic");

		class Min_DB {
			var $extension = "JSON", $server_info, $errno, $error, $_url;

			/**
			 * @param string $path
			 * @param array|null $content
			 * @param string $method
			 * @return array|false
			 */
			function rootQuery($path, ?array $content = null, $method = 'GET') {
				$file = @file_get_contents("$this->_url/" . ltrim($path, '/'), false, stream_context_create(['http' => [
					'method' => $method,
					'content' => $content !== null ? json_encode($content) : null,
					'header' => $content !== null ? 'Content-Type: application/json' : [],
					'ignore_errors' => 1,
					'follow_location' => 0,
					'max_redirects' => 0,
				]]));

				if ($file === false) {
					$this->error = lang('Invalid server or credentials.');
					return false;
				}

				$return = json_decode($file, true);
				if ($return === null) {
					$this->error = lang('Invalid server or credentials.');
					return false;
				}

				if (!preg_match('~^HTTP/[0-9.]+ 2~i', $http_response_header[0])) {
					if (isset($return['error']['root_cause'][0]['type'])) {
						$this->error = $return['error']['root_cause'][0]['type'] . ": " . $return['error']['root_cause'][0]['reason'];
					} elseif (isset($return['status']) && isset($return['error']) && is_string($return['error'])) {
						$this->error = $return['error'];
					}

					return false;
				}

				return $return;
			}

			/** Performs query relative to actual selected DB
			 * @param string $path
			 * @param array|null $content
			 * @param string $method
			 * @return array|false
			 */
			function query($path, ?array $content = null, $method = 'GET') {
				// Support for global search through all tables
				if ($path != "" && $path[0] == "S" && preg_match('/SELECT 1 FROM ([^ ]+) WHERE (.+) LIMIT ([0-9]+)/', $path, $matches)) {
					global $driver;

					$where = explode(" AND ", $matches[2]);

					return $driver->select($matches[1], ["*"], $where, null, [], $matches[3]);
				}

				return $this->rootQuery($path, $content, $method);
			}

			/**
			 * @param string $server
			 * @param string $username
			 * @param string $password
			 * @return bool
			 */
			function connect($server, $username, $password) {
				$this->_url = build_http_url($server, $username, $password, "localhost", 9200);

				$return = $this->query('');
				if (!$return) {
					return false;
				}

				if (!isset($return['version']['number'])) {
					$this->error = lang('Invalid server or credentials.');
					return false;
				}

				$this->server_info = $return['version']['number'];
				return true;
			}

			function select_db($database) {
				return true;
			}

			function quote($string) {
				return $string;
			}
		}

		class Min_Result {
			var $num_rows, $_rows;

			function __construct($rows) {
				$this->num_rows = count($rows);
				$this->_rows = $rows;

				reset($this->_rows);
			}

			function fetch_assoc() {
				$return = current($this->_rows);
				next($this->_rows);

				return $return;
			}

			function fetch_row() {
				$row = $this->fetch_assoc();

				return $row ? array_values($row) : false;
			}
		}
	}

	class Min_Driver extends Min_SQL {

		function select($table, $select, $where, $group, $order = [], ?int $limit = 1, $page = 0, $print = false) {
			$data = [];
			if ($select != ["*"]) {
				$data["fields"] = array_values($select);
			}

			if ($order) {
				$sort = [];
				foreach ($order as $col) {
					$col = preg_replace('~ DESC$~', '', $col, 1, $count);
					$sort[] = ($count ? [$col => "desc"] : $col);
				}
				$data["sort"] = $sort;
			}

			if ($limit !== null) {
				$data["size"] = +$limit;
				if ($page) {
					$data["from"] = ($page * $limit);
				}
			}

			foreach ($where as $val) {
				if (preg_match('~^\((.+ OR .+)\)$~', $val, $matches)) {
					$parts = explode(" OR ", $matches[1]);

					foreach ($parts as $part) {
						$this->addQueryCondition($part, $data);
					}
				} else {
					$this->addQueryCondition($val, $data);
				}
			}

			$query = "$table/_search";
			$start = microtime(true);
			$search = $this->_conn->rootQuery($query, $data);

			if ($print) {
				echo admin()->formatSelectQuery("$query: " . json_encode($data), $start, !$search);
			}
			if (empty($search)) {
				return false;
			}

			$return = [];
			foreach ($search["hits"]["hits"] as $hit) {
				$row = [];
				if ($select == ["*"]) {
					$row["_id"] = $hit["_id"];
				}

				if ($select != ["*"]) {
					$fields = [];
					foreach ($select as $key) {
						$fields[$key] = $key == "_id" ? $hit["_id"] : $hit["_source"][$key];
					}
				} else {
					$fields = $hit["_source"];
				}
				foreach ($fields as $key => $val) {
					$row[$key] = (is_array($val) ? json_encode($val) : $val);
				}

				$return[] = $row;
			}

			return new Min_Result($return);
		}

		private  function addQueryCondition($val, &$data)
		{
			list($col, $op, $val) = explode(" ", $val, 3);

			if (!preg_match('~^([^(]+)\(([^)]+)\)$~', $op, $matches)) {
				return;
			}
			$queryType = $matches[1]; // must, should, must_not
			$matchType = $matches[2]; // term, match, regexp

			if ($matchType == "regexp") {
				$data["query"]["bool"][$queryType][] = [
					"regexp" => [
						$col => [
							"value" => $val,
							"flags" => "ALL",
							"case_insensitive" => true,
						]
					]
				];
			} else {
				$data["query"]["bool"][$queryType][] = [
					$matchType => [$col => $val]
				];
			}
		}

		function update($type, $record, $queryWhere, $limit = 0, $separator = "\n") {
			//! use $limit
			$parts = preg_split('~ *= *~', $queryWhere);
			if (count($parts) == 2) {
				$id = trim($parts[1]);
				$query = "$type/$id";

				return $this->_conn->query($query, $record, 'POST');
			}

			return false;
		}

		function insert($type, $record) {
			$id = ""; //! user should be able to inform _id
			$query = "$type/$id";
			$response = $this->_conn->query($query, $record, 'POST');
			$this->_conn->last_id = $response['_id'];

			return $response['created'];
		}

		function delete($table, $queryWhere, $limit = 0) {
			//! use $limit
			$ids = [];
			if ($_GET["where"]["_id"] ?? null) {
				$ids[] = $_GET["where"]["_id"];
			}
			if (isset($_POST['check'])) {
				foreach ($_POST['check'] as $check) {
					$parts = preg_split('~ *= *~', $check);
					if (count($parts) == 2) {
						$ids[] = trim($parts[1]);
					}
				}
			}

			$this->_conn->affected_rows = 0;

			foreach ($ids as $id) {
				$query = "$table/_doc/$id";
				$response = $this->_conn->query($query, null, 'DELETE');
				if (isset($response['result']) && $response['result'] == 'deleted') {
					$this->_conn->affected_rows++;
				}
			}

			return $this->_conn->affected_rows;
		}
	}

	/**
	 * @return Min_DB|string
	 */
	function connect()
	{
		global $admin;

		$connection = new Min_DB();

		list($server, $username, $password) = $admin->getCredentials();

		if ($password != "" && $connection->connect($server, $username, "")) {
			$result = $admin->verifyDefaultPassword($password);

			return $result === true ? $connection : $result;
		}

		if (!$connection->connect($server, $username, $password)) {
			return $connection->error;
		}

		return $connection;
	}

	function support($feature) {
		return preg_match("~table|columns~", $feature);
	}

	function logged_user() {
		$credentials = admin()->getCredentials();

		return $credentials[1];
	}

	function get_databases() {
		return [ELASTIC_DB_NAME];
	}

	function limit($query, $where, ?int $limit, $offset = 0, $separator = " ") {
		return " $query$where" . ($limit !== null ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
	}

	function collations() {
		return [];
	}

	function db_collation($db, $collations) {
		//
	}

	function engines() {
		return [];
	}

	function count_tables($databases) {
		$return = connection()->rootQuery('_aliases');
		if (empty($return)) {
			return [
				ELASTIC_DB_NAME => 0
			];
		}

		return [
			ELASTIC_DB_NAME => count($return)
		];
	}

	function tables_list() {
		$aliases = connection()->rootQuery('_aliases');
		if (empty($aliases)) {
			return [];
		}

		ksort($aliases);

		$tables = [];
		foreach ($aliases as $name => $index) {
			$tables[$name] = "table";

			ksort($index["aliases"]);
			$tables += array_fill_keys(array_keys($index["aliases"]), "view");
		}

		return $tables;
	}

	function table_status($name = "", $fast = false) {
		$stats = connection()->rootQuery('_stats');
		$aliases = connection()->rootQuery('_aliases');

		if (empty($stats) || empty($aliases)) {
			return [];
		}

		$result = [];

		if ($name != "") {
			if (isset($stats["indices"][$name])) {
				return format_index_status($name, $stats["indices"][$name]);
			} else foreach ($aliases as $index_name => $index) {
				foreach ($index["aliases"] as $alias_name => $alias) {
					if ($alias_name == $name) {
						return format_alias_status($alias_name, $stats["indices"][$index_name]);
					}
				}
			}
		}

		ksort($stats["indices"]);
		foreach ($stats["indices"] as $name => $index) {
			if ($name[0] == ".") {
				continue;
			}

			$result[$name] = format_index_status($name, $index);

			if (!empty($aliases[$name]["aliases"])) {
				ksort($aliases[$name]["aliases"]);
				foreach ($aliases[$name]["aliases"] as $alias_name => $alias) {
					$result[$alias_name] = format_alias_status($alias_name, $stats["indices"][$name]);
				}
			}
		}

		return $result;
	}

	function format_index_status($name, $index) {
		return [
			"Name" => $name,
			"Engine" => "Lucene",
			"Oid" => $index["uuid"],
			"Rows" => $index["total"]["docs"]["count"],
			"Auto_increment" => 0,
			"Data_length" => $index["total"]["store"]["size_in_bytes"],
			"Index_length" => 0,
			"Data_free" => $index["total"]["store"]["reserved_in_bytes"],
		];
	}

	function format_alias_status($name, $index) {
		return [
			"Name" => $name,
			"Engine" => "view",
			"Rows" => $index["total"]["docs"]["count"],
		];
	}

	function is_view($table_status) {
		return $table_status["Engine"] == "view";
	}

	function error() {
		return h(connection()->error);
	}

	function information_schema() {
		//
	}

	function indexes($table, $connection2 = null) {
		return [
			["type" => "PRIMARY", "columns" => ["_id"]],
		];
	}

	function fields($table) {
		$mappings = [];
		$mapping = connection()->rootQuery("_mapping");

		if (!isset($mapping[$table])) {
			$aliases = connection()->rootQuery('_aliases');

			foreach ($aliases as $index_name => $index) {
				foreach ($index["aliases"] as $alias_name => $alias) {
					if ($alias_name == $table) {
						$table = $index_name;
						break;
					}
				}
			}
		}

		if (!empty($mapping)) {
			$mappings = $mapping[$table]["mappings"]["properties"];
		}

		$result = [
			"_id" => [
				"field" => "_id",
				"full_type" => "_id",
				"type" => "_id",
				"privileges" => ["insert" => 1, "select" => 1, "where" => 1, "order" => 1],
			]
		];

		foreach ($mappings as $name => $field) {
			$result[$name] = [
				"field" => $name,
				"full_type" => $field["type"],
				"type" => $field["type"],
				"privileges" => [
					"insert" => 1,
					"select" => 1,
					"update" => 1,
					"where" => !isset($field["index"]) || $field["index"] ?: null,
					"order" => $field["type"] != "text" ?: null
				],
			];
		}

		return $result;
	}

	function foreign_keys($table) {
		return [];
	}

	function table($idf) {
		return $idf;
	}

	function idf_escape($idf) {
		return $idf;
	}

	function convert_field($field) {
		//
	}

	function unconvert_field(array $field, $return) {
		return $return;
	}

	function fk_support($table_status) {
		//
	}

	function found_rows($table_status, $where) {
		return null;
	}

	/** Alter type
	 * @param array
	 * @return mixed
	 */
	function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
		$properties = [];
		foreach ($fields as $f) {
			$field_name = trim($f[1][0]);
			$field_type = trim($f[1][1] ? $f[1][1] : "text");
			$properties[$field_name] = [
				'type' => $field_type
			];
		}

		if (!empty($properties)) {
			$properties = ['properties' => $properties];
		}

		return connection()->query("_mapping/{$name}", $properties, 'PUT');
	}

	/** Drop types
	 * @param array
	 * @return bool
	 */
	function drop_tables($tables) {
		$return = true;
		foreach ($tables as $table) { //! convert to bulk api
			$return = $return && connection()->query(urlencode($table), null, 'DELETE');
		}

		return $return;
	}

	function last_id() {
		return connection()->last_id;
	}

	function driver_config() {
		$types = [];
		$structured_types = [];

		foreach ([
			lang('Numbers') => ["long" => 3, "integer" => 5, "short" => 8, "byte" => 10, "double" => 20, "float" => 66, "half_float" => 12, "scaled_float" => 21, "boolean" => 1],
			lang('Date and time') => ["date" => 10],
			lang('Strings') => ["string" => 65535, "text" => 65535, "keyword" => 65535],
			lang('Binary') => ["binary" => 255],
		] as $key => $val) {
			$types += $val;
			$structured_types[$key] = array_keys($val);
		}

		return [
			'possible_drivers' => ["json + allow_url_fopen"],
			'jush' => "elastic",
			'operators' => [
				"must(term)", "must(match)", "must(regexp)",
				"should(term)", "should(match)", "should(regexp)",
				"must_not(term)", "must_not(match)", "must_not(regexp)",
			],
			'operator_like' => "should(match)",
			'operator_regexp' => "should(regexp)",
			'functions' => [],
			'grouping' => [],
			'edit_functions' => [["json"]],
			'types' => $types,
			'structured_types' => $structured_types,
		];
	}
}
