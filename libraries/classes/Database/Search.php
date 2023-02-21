<?php
/**
 * Handles Database Search
 */

declare(strict_types=1);

namespace PhpMyAdmin\Database;

use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Template;
use PhpMyAdmin\Util;

use function __;
use function array_intersect;
use function array_key_exists;
use function explode;
use function implode;
use function is_array;
use function is_string;

/**
 * Class to handle database search
 */
class Search
{
    /**
     * Database name
     *
     * @var string
     */
    private $db;

    /**
     * Table Names
     *
     * @var array
     */
    private $tablesNamesOnly;

    /**
     * Type of search
     */
    private array $searchTypes;

    /**
     * Already set search type
     *
     * @var int
     */
    private $criteriaSearchType;

    /**
     * Already set search type's description
     *
     * @var string
     */
    private $searchTypeDescription;

    /**
     * Search string/regexp
     *
     * @var string
     */
    private $criteriaSearchString;

    /**
     * Criteria Tables to search in
     *
     * @var string[]
     */
    private $criteriaTables;

    /**
     * Restrict the search to this column
     *
     * @var string
     */
    private $criteriaColumnName;

    /** @var Template */
    public $template;

    /**
     * @param string $db Database name
     */
    public function __construct(private DatabaseInterface $dbi, $db, Template $template)
    {
        $this->db = $db;
        $this->searchTypes = [
            '1' => __('at least one of the words'),
            '2' => __('all of the words'),
            '3' => __('the exact phrase as substring'),
            '4' => __('the exact phrase as whole field'),
            '5' => __('as regular expression'),
        ];
        $this->template = $template;
        // Sets criteria parameters
        $this->setSearchParams();
    }

    /**
     * Sets search parameters
     */
    private function setSearchParams(): void
    {
        $this->tablesNamesOnly = $this->dbi->getTables($this->db);

        if (
            empty($_POST['criteriaSearchType'])
            || ! is_string($_POST['criteriaSearchType'])
            || ! array_key_exists($_POST['criteriaSearchType'], $this->searchTypes)
        ) {
            $this->criteriaSearchType = 1;
            unset($_POST['submit_search']);
        } else {
            $this->criteriaSearchType = (int) $_POST['criteriaSearchType'];
            $this->searchTypeDescription = $this->searchTypes[$_POST['criteriaSearchType']];
        }

        if (empty($_POST['criteriaSearchString']) || ! is_string($_POST['criteriaSearchString'])) {
            $this->criteriaSearchString = '';
            unset($_POST['submit_search']);
        } else {
            $this->criteriaSearchString = $_POST['criteriaSearchString'];
        }

        $this->criteriaTables = [];
        if (empty($_POST['criteriaTables']) || ! is_array($_POST['criteriaTables'])) {
            unset($_POST['submit_search']);
        } else {
            $this->criteriaTables = array_intersect($_POST['criteriaTables'], $this->tablesNamesOnly);
        }

        if (empty($_POST['criteriaColumnName']) || ! is_string($_POST['criteriaColumnName'])) {
            $this->criteriaColumnName = '';
        } else {
            $this->criteriaColumnName = $_POST['criteriaColumnName'];
        }
    }

    /**
     * Builds the SQL search query
     *
     * @param string $table The table name
     *
     * @return string[] 3 SQL queries (for count, display and delete results)
     *
     * @todo    can we make use of fulltextsearch IN BOOLEAN MODE for this?
     */
    private function getSearchSqls(string $table): array
    {
        // Statement types
        $sqlstr_select = 'SELECT';
        $sqlstr_delete = 'DELETE';
        // Table to use
        $sqlstr_from = ' FROM ' . Util::backquote($GLOBALS['db']) . '.' . Util::backquote($table);
        // Gets where clause for the query
        $where_clause = $this->getWhereClause($table);
        // Builds complete queries
        $sql = [];
        $sql['select_columns'] = $sqlstr_select . ' *' . $sqlstr_from . $where_clause;
        // here, I think we need to still use the COUNT clause, even for
        // VIEWs, anyway we have a WHERE clause that should limit results
        $sql['select_count'] = $sqlstr_select . ' COUNT(*) AS `count`' . $sqlstr_from . $where_clause;
        $sql['delete'] = $sqlstr_delete . $sqlstr_from . $where_clause;

        return $sql;
    }

    /**
     * Provides where clause for building SQL query
     *
     * @param string $table The table name
     *
     * @return string The generated where clause
     */
    private function getWhereClause(string $table): string
    {
        // Columns to select
        $allColumns = $this->dbi->getColumns($GLOBALS['db'], $table);
        $likeClauses = [];
        // Based on search type, decide like/regex & '%'/''
        $like_or_regex = ($this->criteriaSearchType == 5 ? 'REGEXP' : 'LIKE');
        $automatic_wildcard = ($this->criteriaSearchType < 4 ? '%' : '');
        // For "as regular expression" (search option 5), LIKE won't be used
        // Usage example: If user is searching for a literal $ in a regexp search,
        // they should enter \$ as the value.
        // Extract search words or pattern
        $search_words = $this->criteriaSearchType > 2
            ? [$this->criteriaSearchString]
            : explode(' ', $this->criteriaSearchString);

        foreach ($search_words as $search_word) {
            // Eliminates empty values
            if ($search_word === '') {
                continue;
            }

            $likeClausesPerColumn = [];
            // for each column in the table
            foreach ($allColumns as $column) {
                if (
                    $this->criteriaColumnName !== ''
                    && $column['Field'] != $this->criteriaColumnName
                ) {
                    continue;
                }

                $column = 'CONVERT(' . Util::backquote($column['Field']) . ' USING utf8)';
                $likeClausesPerColumn[] = $column . ' ' . $like_or_regex . ' '
                    . $this->dbi->quoteString($automatic_wildcard . $search_word . $automatic_wildcard);
            }

            if ($likeClausesPerColumn === []) {
                continue;
            }

            $likeClauses[] = implode(' OR ', $likeClausesPerColumn);
        }

        if ($likeClauses === []) {
            // this could happen when the "inside column" does not exist
            // in any selected tables
            return ' WHERE FALSE';
        }

        // Use 'OR' if 'at least one word' is to be searched, else use 'AND'
        $implode_str = ($this->criteriaSearchType == 1 ? ' OR ' : ' AND ');

        return ' WHERE (' . implode(') ' . $implode_str . ' (', $likeClauses) . ')';
    }

    /**
     * Displays database search results
     *
     * @return string HTML for search results
     */
    public function getSearchResults(): string
    {
        $resultTotal = 0;
        $rows = [];
        // For each table selected as search criteria
        foreach ($this->criteriaTables as $eachTable) {
            // Gets the SQL statements
            $newSearchSqls = $this->getSearchSqls($eachTable);
            // Executes the "COUNT" statement
            $resultCount = (int) $this->dbi->fetchValue($newSearchSqls['select_count']);
            $resultTotal += $resultCount;
            // Gets the result row's HTML for a table
            $rows[] = [
                'table' => $eachTable,
                'new_search_sqls' => $newSearchSqls,
                'result_count' => $resultCount,
            ];
        }

        return $this->template->render('database/search/results', [
            'db' => $this->db,
            'rows' => $rows,
            'result_total' => $resultTotal,
            'criteria_tables' => $this->criteriaTables,
            'criteria_search_string' => $this->criteriaSearchString,
            'search_type_description' => $this->searchTypeDescription,
        ]);
    }

    /**
     * Provides the main search form's html
     *
     * @return string HTML for selection form
     */
    public function getMainHtml()
    {
        return $this->template->render('database/search/main', [
            'db' => $this->db,
            'criteria_search_string' => $this->criteriaSearchString,
            'criteria_search_type' => $this->criteriaSearchType,
            'criteria_tables' => $this->criteriaTables,
            'tables_names_only' => $this->tablesNamesOnly,
            'criteria_column_name' => $this->criteriaColumnName,
        ]);
    }
}
