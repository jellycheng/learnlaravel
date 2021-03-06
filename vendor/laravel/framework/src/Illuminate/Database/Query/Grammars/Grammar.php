<?php namespace Illuminate\Database\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Grammar as BaseGrammar;

class Grammar extends BaseGrammar {

	/**
	 * The components that make up a select clause.
	 *
	 * @var array
	 */
	protected $selectComponents = array(
		'aggregate',
		'columns',
		'from',
		'joins',
		'wheres',
		'groups',
		'havings',
		'orders',
		'limit',
		'offset',
		'unions',
		'lock',
	);

	/**
	 * Compile a select query into SQL.
	 *  返回拼接好的sql语句
	 * @param  \Illuminate\Database\Query\Builder
	 * @return string
	 */
	public function compileSelect(Builder $query)
	{
		if (is_null($query->columns)) $query->columns = array('*');

		return trim($this->concatenate($this->compileComponents($query)));
	}

	/**
	 * Compile the components necessary for a select clause.
	 * 构建select sql语句，返回select每部分单元值
	 * @param  \Illuminate\Database\Query\Builder
	 * @return array
	 */
	protected function compileComponents(Builder $query)
	{
		$sql = array();
		foreach ($this->selectComponents as $component)
		{
			if ( ! is_null($query->$component))
			{// 如果查询构建对象属性有设置值则通过本类方法获取拼接sql部分值
				$method = 'compile'.ucfirst($component);
				$sql[$component] = $this->$method($query, $query->$component);
			}
		}

		return $sql;
	}

	/**
	 * Compile an aggregated select clause.
	 * 返回如select count(*) as aggregate , select min(*) as aggregate 等
     * @param  \Illuminate\Database\Query\Builder  $query 类对象
	 * @param  array  $aggregate
	 * @return string
	 */
	protected function compileAggregate(Builder $query, $aggregate)
	{
		$column = $this->columnize($aggregate['columns']);

		if ($query->distinct && $column !== '*')
		{//去重
			$column = 'distinct '.$column;
		}

		return 'select '.$aggregate['function'].'('.$column.') as aggregate';
	}

	/**
	 * Compile the "select *" portion of the query.
	 * 如果有拼接过aggregate属性则不需要拼接字段属性,否则返回select 字段名，字段名N  或者 select *
	 * @param  \Illuminate\Database\Query\Builder  $query 类对象
	 * @param  array  $columns  =[字段名.每个单元一个字段名，所有用*表示]
	 * @return string
	 */
	protected function compileColumns(Builder $query, $columns)
	{  //如果有拼接过aggregate属性则不需要拼接字段属性
		if ( ! is_null($query->aggregate)) return;

		$select = $query->distinct ? 'select distinct ' : 'select ';

		return $select.$this->columnize($columns);
	}

	/**
	 * Compile the "from" portion of the query.
	 * 返回 from 表名
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  string  $table
	 * @return string
	 */
	protected function compileFrom(Builder $query, $table)
	{
		return 'from '.$this->wrapTable($table);
	}

	/**
	 * Compile the "join" portions of the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $joins = [每个单元均是join对象]
	 * @return string
	 */
	protected function compileJoins(Builder $query, $joins)
	{
		$sql = array();
        //执行查询构建对象->setBindings()方法,来设置其bindings属性，bindings['join']=[];
		$query->setBindings(array(), 'join');

		foreach ($joins as $join)
		{//循环查询构建对象->joins属性值
			$table = $this->wrapTable($join->table);//`表名`
			//on条件
			$clauses = array();
			foreach ($join->clauses as $clause)
			{
				$clauses[] = $this->compileJoinConstraint($clause);
			}

			foreach ($join->bindings as $binding)
			{
				$query->addBinding($binding, 'join');
			}

			//
			$clauses[0] = $this->removeLeadingBoolean($clauses[0]);

			$clauses = implode(' ', $clauses);

			$type = $join->type;

			//
			$sql[] = "$type join $table on $clauses";
		}

		return implode(' ', $sql);
	}

	/**
	 * Create a join clause constraint segment.
	 *
	 * @param  array   $clause
	 * @return string
	 */
	protected function compileJoinConstraint(array $clause)
	{
		$first = $this->wrap($clause['first']);

		$second = $clause['where'] ? '?' : $this->wrap($clause['second']);

		return "{$clause['boolean']} $first {$clause['operator']} $second";
	}

	/**
	 * Compile the "where" portions of the query.
	 * 不存在wheres属性值则返回空，有where条件则返回 where 字段=值 and 字段名=值 or 字段名=值
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @return string
	 */
	protected function compileWheres(Builder $query)
	{
		$sql = array();
		if (is_null($query->wheres)) return '';
		//
		foreach ($query->wheres as $where)
		{
			$method = "where{$where['type']}";
			$sql[] = $where['boolean'].' '.$this->$method($query, $where);
		}

		if (count($sql) > 0)
		{
			$sql = implode(' ', $sql);
			return 'where '.preg_replace('/and |or /', '', $sql, 1);
		}
		return '';
	}

	/**
	 * Compile a nested where clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNested(Builder $query, $where)
	{
		$nested = $where['query'];

		return '('.substr($this->compileWheres($nested), 6).')';
	}

	/**
	 * Compile a where condition with a sub-select.
	 *
	 * @param  \Illuminate\Database\Query\Builder $query
	 * @param  array   $where
	 * @return string
	 */
	protected function whereSub(Builder $query, $where)
	{
		$select = $this->compileSelect($where['query']);

		return $this->wrap($where['column']).' '.$where['operator']." ($select)";
	}

	/**
	 * Compile a basic where clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereBasic(Builder $query, $where)
	{
		$value = $this->parameter($where['value']);

		return $this->wrap($where['column']).' '.$where['operator'].' '.$value;
	}

	/**
	 * Compile a "between" where clause.
	 * 返回 字段名 between ? and ?  或 字段名 not between ? and ?
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereBetween(Builder $query, $where)
	{
		$between = $where['not'] ? 'not between' : 'between';

		return $this->wrap($where['column']).' '.$between.' ? and ?';
	}

	/**
	 * Compile a where exists clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereExists(Builder $query, $where)
	{
		return 'exists ('.$this->compileSelect($where['query']).')';
	}

	/**
	 * Compile a where exists clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotExists(Builder $query, $where)
	{
		return 'not exists ('.$this->compileSelect($where['query']).')';
	}

	/**
	 * Compile a "where in" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereIn(Builder $query, $where)
	{
		if (empty($where['values'])) return '0 = 1';

		$values = $this->parameterize($where['values']);

		return $this->wrap($where['column']).' in ('.$values.')';
	}

	/**
	 * Compile a "where not in" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotIn(Builder $query, $where)
	{
		if (empty($where['values'])) return '1 = 1';

		$values = $this->parameterize($where['values']);

		return $this->wrap($where['column']).' not in ('.$values.')';
	}

	/**
	 * Compile a where in sub-select clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereInSub(Builder $query, $where)
	{
		$select = $this->compileSelect($where['query']);

		return $this->wrap($where['column']).' in ('.$select.')';
	}

	/**
	 * Compile a where not in sub-select clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotInSub(Builder $query, $where)
	{
		$select = $this->compileSelect($where['query']);

		return $this->wrap($where['column']).' not in ('.$select.')';
	}

	/**
	 * Compile a "where null" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNull(Builder $query, $where)
	{
		return $this->wrap($where['column']).' is null';
	}

	/**
	 * Compile a "where not null" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotNull(Builder $query, $where)
	{
		return $this->wrap($where['column']).' is not null';
	}

	/**
	 * Compile a "where date" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereDate(Builder $query, $where)
	{
		return $this->dateBasedWhere('date', $query, $where);
	}

	/**
	 * Compile a "where day" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereDay(Builder $query, $where)
	{
		return $this->dateBasedWhere('day', $query, $where);
	}

	/**
	 * Compile a "where month" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereMonth(Builder $query, $where)
	{
		return $this->dateBasedWhere('month', $query, $where);
	}

	/**
	 * Compile a "where year" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereYear(Builder $query, $where)
	{
		return $this->dateBasedWhere('year', $query, $where);
	}

	/**
	 * Compile a date based where clause.
	 *
	 * @param  string  $type
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function dateBasedWhere($type, Builder $query, $where)
	{
		$value = $this->parameter($where['value']);

		return $type.'('.$this->wrap($where['column']).') '.$where['operator'].' '.$value;
	}

	/**
	 * Compile a raw where clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereRaw(Builder $query, $where)
	{
		return $where['sql'];
	}

	/**
	 * Compile the "group by" portions of the query.
	 * 返回group by 字段名1，字段名N
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $groups
	 * @return string
	 */
	protected function compileGroups(Builder $query, $groups)
	{
		return 'group by '.$this->columnize($groups);
	}

	/**
	 * Compile the "having" portions of the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $havings
	 * @return string
	 */
	protected function compileHavings(Builder $query, $havings)
	{
		$sql = implode(' ', array_map(array($this, 'compileHaving'), $havings));

		return 'having '.preg_replace('/and |or /', '', $sql, 1);
	}

	/**
	 * Compile a single having clause.
	 *
	 * @param  array   $having = ['type'=>'类型basic,raw', 'column'=>'字段', 'operator'=>'操作符>,=,<=', 'value'=>'值', 'boolean'=>'and']
	 * @return string
	 */
	protected function compileHaving(array $having)
	{
		if ($having['type'] === 'raw')
		{// 原始表达式
			return $having['boolean'].' '.$having['sql'];
		}
		return $this->compileBasicHaving($having);
	}

	/**
	 * Compile a basic having clause.
	 *
	 * @param  array   $having
	 * @return string
	 */
	protected function compileBasicHaving($having)
	{
		$column = $this->wrap($having['column']);//字段包裹起来``

		$parameter = $this->parameter($having['value']);//字段值,如果是表达式则返回表达式值，否则返回?

		return $having['boolean'].' '.$column.' '.$having['operator'].' '.$parameter;
	}

	/**
	 * Compile the "order by" portions of the query.
	 * 返回 order by 字段 asc
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $orders = [['column'=>'字段名', 'direction'=>'asc,desc'], ['type'=>'raw', 'sql'=>'字段名 asc,字段名2 desc']]
	 * @return string
	 */
	protected function compileOrders(Builder $query, $orders)
	{
		return 'order by '.implode(', ', array_map(function($order)
		{
			if (isset($order['sql'])) return $order['sql'];

			return $this->wrap($order['column']).' '.$order['direction'];
		}
		, $orders));
	}

	/**
	 * Compile the "limit" portions of the query.
	 * 返回limit 22
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  int  $limit
	 * @return string
	 */
	protected function compileLimit(Builder $query, $limit)
	{
		return 'limit '.(int) $limit;
	}

	/**
	 * Compile the "offset" portions of the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  int  $offset
	 * @return string
	 */
	protected function compileOffset(Builder $query, $offset)
	{
		return 'offset '.(int) $offset;
	}

	/**
	 * Compile the "union" queries attached to the main query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @return string
	 */
	protected function compileUnions(Builder $query)
	{
		$sql = '';

		foreach ($query->unions as $union)
		{
			$sql .= $this->compileUnion($union);
		}

		if (isset($query->unionOrders))
		{
			$sql .= ' '.$this->compileOrders($query, $query->unionOrders);
		}

		if (isset($query->unionLimit))
		{
			$sql .= ' '.$this->compileLimit($query, $query->unionLimit);
		}

		if (isset($query->unionOffset))
		{
			$sql .= ' '.$this->compileOffset($query, $query->unionOffset);
		}

		return ltrim($sql);
	}

	/**
	 * Compile a single union statement.
	 *
	 * @param  array  $union
	 * @return string
	 */
	protected function compileUnion(array $union)
	{
		$joiner = $union['all'] ? ' union all ' : ' union ';

		return $joiner.$union['query']->toSql();
	}

	/**
	 * Compile an insert statement into SQL.
	 * 拼接insert语句
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $values
	 * @return string
	 */
	public function compileInsert(Builder $query, array $values)
	{
		$table = $this->wrapTable($query->from);//表名

		if ( ! is_array(reset($values)))
		{
			$values = array($values);
		}

		$columns = $this->columnize(array_keys(reset($values)));

		// We need to build a list of parameter place-holders of values that are bound
		// to the query. Each insert should have the exact same amount of parameter
		// bindings so we can just go off the first list of values in this array.
		$parameters = $this->parameterize(reset($values));

		$value = array_fill(0, count($values), "($parameters)");

		$parameters = implode(', ', $value);

		return "insert into $table ($columns) values $parameters";
	}

	/**
	 * Compile an insert and get ID statement into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array   $values
	 * @param  string  $sequence
	 * @return string
	 */
	public function compileInsertGetId(Builder $query, $values, $sequence)
	{
		return $this->compileInsert($query, $values);
	}

	/**
	 * Compile an update statement into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $values
	 * @return string
	 */
	public function compileUpdate(Builder $query, $values)
	{
		$table = $this->wrapTable($query->from);//表名
		$columns = array();
		foreach ($values as $key => $value)
		{//$columns[] = '字段名=值';
			$columns[] = $this->wrap($key).' = '.$this->parameter($value);
		}

		$columns = implode(', ', $columns);
		if (isset($query->joins))
		{
			$joins = ' '.$this->compileJoins($query, $query->joins);
		}
		else
		{
			$joins = '';
		}

		// 拼接where条件
		$where = $this->compileWheres($query);

		return trim("update {$table}{$joins} set $columns $where");
	}

	/**
	 * Compile a delete statement into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @return string
	 */
	public function compileDelete(Builder $query)
	{
		$table = $this->wrapTable($query->from);//表名
        //拼接where条件
		$where = is_array($query->wheres) ? $this->compileWheres($query) : '';

		return trim("delete from $table ".$where);
	}

	/**
	 * Compile a truncate table statement into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @return array
	 */
	public function compileTruncate(Builder $query)
	{
		return array('truncate '.$this->wrapTable($query->from) => array());
	}

	/**
	 * Compile the lock into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  bool|string  $value
	 * @return string
	 */
	protected function compileLock(Builder $query, $value)
	{
		return is_string($value) ? $value : '';
	}

	/**
	 * Concatenate an array of segments, removing empties.
	 * 去掉多余的空格值单元并把有值的单元用空格合并
	 * @param  array   $segments
	 * @return string
	 */
	protected function concatenate($segments)
	{
		return implode(' ', array_filter($segments, function($value)
		{
			return (string) $value !== '';
		}));
	}

	/**
	 * Remove the leading boolean from a statement.
	 *
	 * @param  string  $value
	 * @return string
	 */
	protected function removeLeadingBoolean($value)
	{
		return preg_replace('/and |or /', '', $value, 1);
	}

}
