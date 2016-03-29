<?php

namespace Sintattica\Atk\Relations;

use Exception;
use Sintattica\Atk\Attributes\Attribute;
use Sintattica\Atk\Core\Config;
use Sintattica\Atk\Core\Node;
use Sintattica\Atk\Core\Tools;
use Sintattica\Atk\DataGrid\DataGrid;
use Sintattica\Atk\Db\Db;
use Sintattica\Atk\Db\Query;
use Sintattica\Atk\RecordList\ColumnConfig;
use Sintattica\Atk\Session\SessionManager;
use Sintattica\Atk\Ui\Page;
use Sintattica\Atk\Utils\StringParser;

/**
 * A N:1 relation between two classes.
 *
 * For example, projects all have one coordinator, but one
 * coordinator can have multiple projects. So in the project
 * class, there's a ManyToOneRelation to a coordinator.
 *
 * This relation essentially creates a dropdown box, from which
 * you can select from a set of records.
 *
 * @author Ivo Jansch <ivo@achievo.org>
 */
class ManyToOneRelation extends Relation
{
    /**
     * Create edit/view links for the items in a manytoonerelation dropdown.
     */
    const AF_RELATION_AUTOLINK = 33554432;

    /**
     * Create edit/view links for the items in a manytoonerelation dropdown.
     */
    const AF_MANYTOONE_AUTOLINK = 33554432;

    /**
     * Do not add null option under any circumstance.
     */
    const AF_RELATION_NO_NULL_ITEM = 67108864;

    /**
     * Do not add null option ever.
     */
    const AF_MANYTOONE_NO_NULL_ITEM = 67108864;

    /**
     * Use auto-completition instead of drop-down / selection page.
     */
    const AF_RELATION_AUTOCOMPLETE = 134217728;

    /**
     * Use auto-completition instead of drop-down / selection page.
     */
    const AF_MANYTOONE_AUTOCOMPLETE = 134217728;

    /**
     * Lazy load.
     */
    const AF_MANYTOONE_LAZY = 268435456;

    /**
     * Add a default null option to obligatory relations.
     */
    const AF_MANYTOONE_OBLIGATORY_NULL_ITEM = 536870912;

    const SEARCH_MODE_EXACT = 'exact';
    const SEARCH_MODE_STARTSWITH = 'startswith';
    const SEARCH_MODE_CONTAINS = 'contains';

    /*
     * By default, we do a left join. this means that records that don't have
     * a record in this relation, will be displayed anyway. NOTE: set  this to
     * false only if you know what you're doing. When in doubt, 'true' is
     * usually the best option.
     * @var boolean
     */
    public $m_leftjoin = true;

    /*
     * The array of referential key fields.
     * @access private
     * @var array
     */
    public $m_refKey = array();

    /*
     * SQL statement with extra filter for the join that retrieves the
     * selected record.
     * @var String
     */
    public $m_joinFilter = '';

    /*
     * Hide the relation when there are no records to select.
     * @access private
     * @var boolean
     */
    public $m_hidewhenempty = false;

    /*
     * List columns.
     * @access private
     * @var array
     */
    public $m_listColumns = array();

    /*
     * Always show list columns?
     * @access private
     * @var boolean
     */
    public $m_alwaysShowListColumns = false;

    /*
     * Label to use for the 'none' option.
     *
     * @access private
     * @var String
     */
    public $m_noneLabel = null;

    /*
     * Minimum number of character a user needs to enter before auto-completion kicks in.
     *
     * @access private
     * @var int
     */
    public $m_autocomplete_minchars;

    /*
     * An array with the fieldnames of the destination node in which the autocompletion must search
     * for results.
     *
     * @access private
     * @var array
     */
    public $m_autocomplete_searchfields = '';

    /*
     * The search mode of the autocomplete fields. Can be 'startswith', 'exact' or 'contains'.
     *
     * @access private
     * @var String
     */
    public $m_autocomplete_searchmode;

    /*
     * Value determines wether the search of the autocompletion is case-sensitive.
     *
     * @var boolean
     */
    public $m_autocomplete_search_case_sensitive;

    /*
     * Value determines if select link for autocomplete should use atkSubmit or not (for use in admin screen for example)
     *
     * @var boolean
     */
    public $m_autocomplete_saveform = true;

    /*
     * Set the minimal number of records for showing the automcomplete. If there are less records the normal dropdown is shown
     * @var integer
     */
    public $m_autocomplete_minrecords;

    /**
     * Set the size attribute of the autocompletion input element.
     *
     * @var int
     */
    protected $m_autocomplete_size;

    /**
     * Destination node for auto links (edit, new).
     *
     * @var string
     */
    protected $m_autolink_destination = '';
    // override onchangehandler init
    public $m_onchangehandler_init = "newvalue = el.options[el.selectedIndex].value;\n";

    /*
     * Use destination filter for autolink add link?
     *
     * @access private
     * @var boolean
     */
    public $m_useFilterForAddLink = true;

    /*
     * Set a function to use for determining the descriptor in the getConcatFilter function
     *
     * @access private
     * @var string
     */
    public $m_concatDescriptorFunction = '';

    /**
     * When autosearch is set to true, this attribute will automatically submit
     * the search form onchange. This will only happen in the admin action.
     *
     * @var bool
     */
    protected $m_autoSearch = false;

    /**
     * Selectable records for edit mode.
     *
     * @see ManyToOneRelation::preAddToEditArray
     *
     * @var array
     */
    protected $m_selectableRecords = null;


    /**
     * How many items for each ajax call
     * @var int
     */
    protected $m_autocomplete_pagination_limit;

    /**
     * Constructor.
     *
     * @param string $name The name of the attribute. This is the name of the
     *                            field that is the referential key to the
     *                            destination.
     *                            For relations with more than one field in the
     *                            foreign key, you should pass an array of
     *                            referential key fields. The order of the fields
     *                            must match the order of the primary key attributes
     *                            in the destination node.
     * @param string $destination The node we have a relationship with.
     * @param int $flags Flags for the relation
     */
    public function __construct($name, $destination, $flags = 0)
    {
        if (Config::getGlobal('manytoone_autocomplete_default', false)) {
            $flags |= self::AF_RELATION_AUTOCOMPLETE;
        }

        if (Config::getGlobal('manytoone_autocomplete_large', true) && Tools::hasFlag($flags, self::AF_LARGE)) {
            $flags |= self::AF_RELATION_AUTOCOMPLETE;
        }

        $this->m_autocomplete_minchars = Config::getGlobal('manytoone_autocomplete_minchars', 2);
        $this->m_autocomplete_searchmode = Config::getGlobal('manytoone_autocomplete_searchmode', 'contains');
        $this->m_autocomplete_search_case_sensitive = Config::getGlobal('manytoone_autocomplete_search_case_sensitive', false);
        $this->m_autocomplete_size = Config::getGlobal('manytoone_autocomplete_size', 50);
        $this->m_autocomplete_minrecords = Config::getGlobal('manytoone_autocomplete_minrecords', -1);
        $this->m_autocomplete_pagination_limit = Config::getGlobal('manytoone_autocomplete_pagination_limit', 25);

        if (is_array($name)) {
            $this->m_refKey = $name;

            // ATK can't handle an array as name, so we initialize the
            // underlying attribute with the first name of the referential
            // keys.
            // Languagefiles, overrides, etc should use this first name to
            // override the relation.
            parent::__construct($name[0], $destination, $flags);
        } else {
            $this->m_refKey[] = $name;
            parent::__construct($name, $destination, $flags);
        }

        if ($this->hasFlag(self::AF_MANYTOONE_LAZY) && (count($this->m_refKey) > 1 || $this->m_refKey[0] != $this->fieldName())) {
            Tools::atkerror('self::AF_MANYTOONE_LAZY flag is not supported for multi-column reference key or a reference key that uses another column.');
        }
    }

    /**
     * Adds a flag to the manyToOne relation
     * Note that adding flags at any time after the constructor might not
     * always work. There are flags that are processed only at
     * constructor time.
     *
     * @param int $flag The flag to add to the attribute
     *
     * @return ManyToOneRelation The instance of this ManyToOneRelation
     */
    public function addFlag($flag)
    {
        parent::addFlag($flag);
        if (Config::getGlobal('manytoone_autocomplete_large', true) && Tools::hasFlag($flag, self::AF_LARGE)) {
            $this->m_flags |= self::AF_RELATION_AUTOCOMPLETE;
        }

        return $this;
    }

    /**
     * When autosearch is set to true, this attribute will automatically submit
     * the search form onchange. This will only happen in the admin action.
     *
     * @param bool $auto
     */
    public function setAutoSearch($auto = false)
    {
        $this->m_autoSearch = $auto;
    }

    /**
     * Set join filter.
     *
     * @param string $filter join filter
     */
    public function setJoinFilter($filter)
    {
        $this->m_joinFilter = $filter;
    }

    /**
     * Set the searchfields for the autocompletion.
     *
     * @param array $searchfields
     */
    public function setAutoCompleteSearchFields($searchfields)
    {
        $this->m_autocomplete_searchfields = $searchfields;
    }

    /**
     * Set the searchmode for the autocompletion:
     * exact, startswith(default) or contains.
     *
     * @param array $mode
     */
    public function setAutoCompleteSearchMode($mode)
    {
        $this->m_autocomplete_searchmode = $mode;
    }

    /**
     * Set the case-sensitivity for the autocompletion search (true or false).
     *
     * @param array $case_sensitive
     */
    public function setAutoCompleteCaseSensitive($case_sensitive)
    {
        $this->m_autocomplete_search_case_sensitive = $case_sensitive;
    }

    /**
     * Sets the minimum number of characters before auto-completion kicks in.
     *
     * @param int $chars
     */
    public function setAutoCompleteMinChars($chars)
    {
        $this->m_autocomplete_minchars = $chars;
    }

    /**
     * Set if the select link should save form (atkSubmit) or not (for use in admin screen for example).
     *
     * @param bool $saveform
     */
    public function setAutoCompleteSaveForm($saveform = true)
    {
        $this->m_autocomplete_saveform = $saveform;
    }

    /**
     * Set the minimal number of records for the autocomplete to show
     * If there are less records the normal dropdown is shown.
     *
     * @param int $minrecords
     */
    public function setAutoCompleteMinRecords($minrecords)
    {
        $this->m_autocomplete_minrecords = $minrecords;
    }

    /**
     * Set the size of the rendered autocompletion input element.
     *
     * @param int $size
     */
    public function setAutoCompleteSize($size)
    {
        $this->m_autocomplete_size = $size;
    }

    /**
     * Use destination filter for auto add link?
     *
     * @param bool $useFilter use destnation filter for add link?
     */
    public function setUseFilterForAddLink($useFilter)
    {
        $this->m_useFilterForAddLink = $useFilter;
    }

    /**
     * Set the function for determining the descriptor in the getConcatFilter function
     * This function should be implemented in the destination node.
     *
     * @param string $function
     */
    public function setConcatDescriptorFunction($function)
    {
        $this->m_concatDescriptorFunction = $function;
    }

    /**
     * Return the function for determining the descriptor in the getConcatFilter function.
     *
     * @return string
     */
    public function getConcatDescriptorFunction()
    {
        return $this->m_concatDescriptorFunction;
    }

    /**
     * Add list column. An attribute of the destination node
     * that (only) will be displayed in the recordlist.
     *
     * @param string $attr The attribute to add to the listcolumn
     *
     * @return ManyToOneRelation The instance of this ManyToOneRelation
     */
    public function addListColumn($attr)
    {
        $this->m_listColumns[] = $attr;

        return $this;
    }

    /**
     * Add multiple list columns. Attributes of the destination node
     * that (only) will be displayed in the recordlist.
     *
     * @return ManyToOneRelation The instance of this ManyToOneRelation
     */
    public function addListColumns()
    {
        $attrs = func_get_args();
        foreach ($attrs as $attr) {
            $this->m_listColumns[] = $attr;
        }

        return $this;
    }

    public function getListColumns()
    {
        return $this->m_listColumns;
    }

    /**
     * Reset the list columns and add multiple list columns. Attributes of the
     * destination node that (only) will be displayed in the recordlist.
     *
     * @return ManyToOneRelation The instance of this ManyToOneRelation
     */
    public function setListColumns()
    {
        $this->m_listColumns = array();

        $attrs = func_get_args();
        if (count($attrs) === 1 && is_array($attrs[0])) {
            $columns = $attrs[0];
        } else {
            $columns = $attrs;
        }

        foreach ($columns as $column) {
            $this->m_listColumns[] = $column;
        }

        return $this;
    }

    /**
     * Always show list columns in list view,
     * even if the attribute itself is hidden?
     *
     * @param bool $value always show list columns?
     *
     * @return ManyToOneRelation The instance of this ManyToOneRelation
     */
    public function setAlwaysShowListColumns($value)
    {
        $this->m_alwaysShowListColumns = $value;
        if ($this->m_alwaysShowListColumns) {
            $this->addFlag(self::AF_FORCE_LOAD);
        }

        return $this;
    }

    /**
     * Set the maximum rows of each ajax call
     * @param int $limit
     */
    public function setPaginationLimit($limit)
    {
        $this->m_autocomplete_pagination_limit = $limit;
    }

    /**
     * Convert value to DataBase value.
     *
     * @param array $rec Record to convert
     *
     * @return int Database safe value
     */
    public function value2db($rec)
    {
        if ($this->isEmpty($rec)) {
            Tools::atkdebug($this->fieldName().' IS EMPTY!');

            return;
        } else {
            if ($this->createDestination()) {
                if (is_array($rec[$this->fieldName()])) {
                    $pkfield = $this->m_destInstance->m_primaryKey[0];
                    $pkattr = $this->m_destInstance->getAttribute($pkfield);

                    return $pkattr->value2db($rec[$this->fieldName()]);
                } else {
                    return $rec[$this->fieldName()];
                }
            }
        }

        // This never happens, does it?
        return '';
    }

    /**
     * Fetch value out of record.
     *
     * @param array $postvars Postvars
     *
     * @return string decoded value
     */
    public function fetchValue($postvars)
    {
        if ($this->isPosted($postvars)) {
            $result = array();

            // support specifying the value as a single number if the
            // destination's primary key consists of a single field
            if (is_numeric($postvars[$this->fieldName()])) {
                $result[$this->getDestination()->primaryKeyField()] = $postvars[$this->fieldName()];
            } else {
                // Split the primary key of the selected record into its
                // referential key elements.
                $keyelements = Tools::decodeKeyValueSet($postvars[$this->fieldName()]);
                foreach ($keyelements as $key => $value) {
                    // Tablename must be stripped out because it is in the way..
                    if (strpos($key, '.') > 0) {
                        $field = substr($key, strrpos($key, '.') + 1);
                    } else {
                        $field = $key;
                    }
                    $result[$field] = $value;
                }
            }

            if (count($result) == 0) {
                return;
            }

            // add descriptor fields, this means they can be shown in the title
            // bar etc. when updating failed for example
            $record = array($this->fieldName() => $result);
            $this->populate($record);
            $result = $record[$this->fieldName()];

            return $result;
        }

        return;
    }

    /**
     * Converts DataBase value to normal value.
     *
     * @param array $rec Record
     *
     * @return string decoded value
     */
    public function db2value($rec)
    {
        $this->createDestination();

        if (isset($rec[$this->fieldName()]) && is_array($rec[$this->fieldName()]) && (!isset($rec[$this->fieldName()][$this->m_destInstance->primaryKeyField()]) || empty($rec[$this->fieldName()][$this->m_destInstance->primaryKeyField()]))) {
            return;
        }

        if (isset($rec[$this->fieldName()])) {
            $myrec = $rec[$this->fieldName()];
            if (is_array($myrec)) {
                $result = array();
                if ($this->createDestination()) {
                    foreach (array_keys($this->m_destInstance->m_attribList) as $attrName) {
                        $attr = &$this->m_destInstance->m_attribList[$attrName];
                        if ($attr) {
                            $result[$attrName] = $attr->db2value($myrec);
                        } else {
                            Tools::atkerror("m_attribList['{$attrName}'] not defined");
                        }
                    }
                }

                return $result;
            } else {
                // if the record is not an array, probably only the value of the primary key was loaded.
                // This workaround only works for single-field primary keys.
                if ($this->createDestination()) {
                    return array($this->m_destInstance->primaryKeyField() => $myrec);
                }
            }
        }
    }

    /**
     * Set none label.
     *
     * @param string $label The label to use for the "none" option
     */
    public function setNoneLabel($label)
    {
        $this->m_noneLabel = $label;
    }

    /**
     * Get none label.
     * @param string $mode
     * @return string The label for the "none" option
     */
    public function getNoneLabel($mode = '')
    {
        if ($this->m_noneLabel !== null) {
            return $this->m_noneLabel;
        }

        $text_key = 'select_none';
        if (in_array($mode, array('add', 'edit')) && $this->hasFlag(self::AF_OBLIGATORY)) {
            if ((($mode == 'add' && !$this->hasFlag(self::AF_READONLY_ADD)) || ($mode == 'edit' && !$this->hasFlag(self::AF_READONLY_EDIT)))) {
                $text_key = 'select_none_obligatory';
            }
        } else {
            if ($mode == 'search') {
                $text_key = 'search_none';
            }
        }

        $nodename = $this->m_destInstance->m_type;
        $modulename = $this->m_destInstance->m_module;
        $ownermodulename = $this->m_ownerInstance->m_module;
        $label = Tools::atktext($this->fieldName().'_'.$text_key, $ownermodulename, $this->m_owner, '', '', true);
        if ($label == '') {
            $label = Tools::atktext($text_key, $modulename, $nodename);
        }

        return $label;
    }

    /**
     * Returns a displayable string for this value.
     *
     * @param array $record The record that holds the value for this attribute
     * @param string $mode The display mode ("view" for viewpages, or "list"
     *                       for displaying in recordlists, "edit" for
     *                       displaying in editscreens, "add" for displaying in
     *                       add screens. "csv" for csv files. Applications can
     *                       use additional modes.
     *
     * @return string a displayable string
     */
    public function display($record, $mode)
    {
        if ($this->createDestination()) {
            if (count($record[$this->fieldName()]) == count($this->m_refKey)) {
                $this->populate($record);
            }

            if (!$this->isEmpty($record)) {
                $result = $this->m_destInstance->descriptor($record[$this->fieldName()]);
                if ($this->hasFlag(self::AF_RELATION_AUTOLINK) && (!in_array($mode, array('csv', 'plain')))) { // create link to edit/view screen
                    if (($this->m_destInstance->allowed('view')) && !$this->m_destInstance->hasFlag(Node::NF_NO_VIEW) && $result != '') {
                        $saveForm = $mode == 'add' || $mode == 'edit';
                        $result = Tools::href(Tools::dispatch_url($this->m_destination, 'view',
                            array('atkselector' => $this->m_destInstance->primaryKey($record[$this->fieldName()]))), $result, SessionManager::SESSION_NESTED,
                            $saveForm);
                    }
                }
            } else {
                $result = !in_array($mode, array('csv', 'plain', 'list')) ? $this->getNoneLabel($mode) : ''; // no record
            }

            return $result;
        } else {
            Tools::atkdebug("Can't create destination! ($this -> m_destination");
        }

        return '';
    }

    /**
     * Populate the record with the destination record data.
     *
     * @param array $record record
     * @param mixed $fullOrFields load all data, only the given fields or only the descriptor fields?
     */
    public function populate(&$record, $fullOrFields = false)
    {
        if (!is_array($record) || $record[$this->fieldName()] == '') {
            return;
        }

        Tools::atkdebug('Delayed loading of '.($fullOrFields || is_array($fullOrFields) ? '' : 'descriptor ').'fields for '.$this->m_name);
        $this->createDestination();

        $includes = '';
        if (is_array($fullOrFields)) {
            $includes = array_merge($this->m_destInstance->m_primaryKey, $fullOrFields);
        } else {
            if (!$fullOrFields) {
                $includes = $this->m_destInstance->descriptorFields();
            }
        }

        $result = $this->m_destInstance->select($this->m_destInstance->primaryKey($record[$this->fieldName()]))->orderBy($this->m_destInstance->getColumnConfig()->getOrderByStatement())->includes($includes)->getFirstRow();

        if ($result != null) {
            $record[$this->fieldName()] = $result;
        }
    }

    /**
     * Creates HTML for the selection and auto links.
     *
     * @param string $id attribute id
     * @param array $record record
     *
     * @return string
     */
    public function createSelectAndAutoLinks($id, $record)
    {
        $links = array();

        $newsel = $id;
        $filter = $this->parseFilter($this->m_destinationFilter, $record);
        $links[] = $this->_getSelectLink($newsel, $filter);
        if ($this->hasFlag(self::AF_RELATION_AUTOLINK)) { // auto edit/view link
            $sm = SessionManager::getInstance();

            if ($this->m_destInstance->allowed('add') && !$this->m_destInstance->hasFlag(Node::NF_NO_ADD)) {
                $links[] = Tools::href(Tools::dispatch_url($this->getAutoLinkDestination(), 'add', array(
                    'atkpkret' => $id,
                    'atkfilter' => ($filter != '' ? $filter : ''),
                )), Tools::atktext('new'), SessionManager::SESSION_NESTED, true);
            }

            if ($this->m_destInstance->allowed('edit') && !$this->m_destInstance->hasFlag(Node::NF_NO_EDIT) && $record[$this->fieldName()] != null) {
                //we laten nu altijd de edit link zien, maar eigenlijk mag dat niet, want
                //de app crasht als er geen waarde is ingevuld.
                $editUrl = $sm->sessionUrl(Tools::dispatch_url($this->getAutoLinkDestination(), 'edit', array('atkselector' => 'REPLACEME')),
                    SessionManager::SESSION_NESTED);
                $links[] = '<span id="'.$id."_edit\" style=\"\"><a href='javascript:atkSubmit(mto_parse(\"".Tools::atkurlencode($editUrl).'", document.entryform.'.$id.".value), true)' class=\"atkmanytoonerelation\">".Tools::atktext('edit').'</a></span>';
            }
        }

        return implode('&nbsp;', $links);
    }

    /**
     * Set destination node for the Autolink links (new/edit).
     *
     * @param string $node
     */
    public function setAutoLinkDestination($node)
    {
        $this->m_autolink_destination = $node;
    }

    /**
     * Get destination node for the Autolink links (new/edit).
     *
     * @return string
     */
    public function getAutoLinkDestination()
    {
        if (!empty($this->m_autolink_destination)) {
            return $this->m_autolink_destination;
        }

        return $this->m_destination;
    }

    /**
     * Prepare for editing, make sure we already have the selectable records
     * loaded and update the record with the possible selection of the first
     * record.
     *
     * @param array $record reference to the record
     * @param string $fieldPrefix field prefix
     * @param string $mode edit mode
     */
    public function preAddToEditArray(&$record, $fieldPrefix, $mode)
    {
        if ($mode == 'edit' && ($this->hasFlag(self::AF_READONLY_EDIT) || $this->hasFlag(self::AF_HIDE_EDIT))) {
            // in this case we don't want that the destination filters are activated
            return;
        }

        if ((!$this->hasFlag(self::AF_RELATION_AUTOCOMPLETE) && !$this->hasFlag(self::AF_LARGE)) || $this->m_autocomplete_minrecords > -1) {
            $this->m_selectableRecords = $this->_getSelectableRecords($record, $mode);

            if (count($this->m_selectableRecords) > 0 && !Config::getGlobal('list_obligatory_null_item') && (($this->hasFlag(self::AF_OBLIGATORY) && !$this->hasFlag(self::AF_MANYTOONE_OBLIGATORY_NULL_ITEM)) || (!$this->hasFlag(self::AF_OBLIGATORY) && $this->hasFlag(self::AF_RELATION_NO_NULL_ITEM)))) {
                if (!isset($record[$this->fieldName()]) || !is_array($record[$this->fieldName()])) {
                    $record[$this->fieldName()] = $this->m_selectableRecords[0];
                } else {
                    if (!$this->_isSelectableRecord($record, $mode)) {
                        $record[$this->fieldName()] = $this->m_selectableRecords[0];
                    } else {
                        $current = $this->getDestination()->primaryKey($record[$this->fieldName()]);
                        $record[$this->fieldName()] = null;
                        foreach ($this->m_selectableRecords as $selectable) {
                            if ($this->getDestination()->primaryKey($selectable) == $current) {
                                $record[$this->fieldName()] = $selectable;
                                break;
                            }
                        }
                    }
                }
            }
        } else {
            if (is_array($record[$this->fieldName()]) && !$this->_isSelectableRecord($record, $mode)) {
                $record[$this->fieldName()] = null;
            } else {
                if (is_array($record[$this->fieldName()])) {
                    $this->populate($record);
                }
            }
        }
    }

    public function edit($record, $fieldprefix, $mode)
    {
        if (!$this->createDestination()) {
            Tools::atkerror("Could not create destination for destination: $this -> m_destination!");

            return;
        }

        $recordset = $this->m_selectableRecords;

        // load records for bwc
        if ($recordset === null && $this->hasFlag(self::AF_RELATION_AUTOCOMPLETE) && $this->m_autocomplete_minrecords > -1) {
            $recordset = $this->_getSelectableRecords($record, $mode);
        }

        if ($this->hasFlag(self::AF_RELATION_AUTOCOMPLETE) && (is_object($this->m_ownerInstance)) && ((is_array($recordset) && count($recordset) > $this->m_autocomplete_minrecords) || $this->m_autocomplete_minrecords == -1)) {
            return $this->drawAutoCompleteBox($record, $fieldprefix, $mode);
        }

        $id = $fieldprefix.$this->fieldName();
        $editflag = true;

        $value = isset($record[$this->fieldName()]) ? $record[$this->fieldName()] : null;
        $currentPk = $value != null ? $this->getDestination()->primaryKey($value) : null;

        if (!$this->hasFlag(self::AF_LARGE)) { // normal dropdown..
            // load records for bwc
            if ($recordset == null) {
                $recordset = $this->_getSelectableRecords($record, $mode);
            }

            if (count($recordset) == 0) {
                $editflag = false;
            }

            $onchange = '';
            if (count($this->m_onchangecode)) {
                $onchange = 'onChange="'.$id.'_onChange(this);"';
                $this->_renderChangeHandler($fieldprefix);
            }

            // autoselect if there is only one record (if obligatory is not set,
            // we don't autoselect, since user may wist to select 'none' instead
            // of the 1 record.
            if (count($recordset) == 0) {
                $result = $this->getNoneLabel();
            } else {
                $this->registerJavaScriptObservers($id);

                $result = '<select id="'.$id.'" name="'.$id.'" class="form-control atkmanytoonerelation '.$this->get_class_name().'" '.$onchange.'>';

                // relation may be empty, so we must provide an empty selectable..
                $hasNullOption = false;
                $noneLabel = '';
                if ($this->hasFlag(self::AF_MANYTOONE_OBLIGATORY_NULL_ITEM) || (!$this->hasFlag(self::AF_OBLIGATORY) && !$this->hasFlag(self::AF_RELATION_NO_NULL_ITEM)) || (Config::getGlobal('list_obligatory_null_item') && !is_array($value))) {
                    $hasNullOption = true;
                    $noneLabel = $this->getNoneLabel($mode);
                    $result .= '<option value="">'.$noneLabel.'</option>';
                }

                foreach ($recordset as $selectable) {
                    $pk = $this->getDestination()->primaryKey($selectable);
                    $sel = $pk == $currentPk ? 'selected="selected"' : '';
                    $result .= '<option value="'.$pk.'" '.$sel.'>'.str_replace(' ', '&nbsp;',
                            htmlentities(strip_tags($this->m_destInstance->descriptor($selectable)))).'</option>';
                }

                $result .= '</select>';

                $selectOptions = [];
                if ($hasNullOption) {
                    $selectOptions['allowClear'] = true;
                    $selectOptions['placeholder'] = $noneLabel;
                }

                $result .= "<script>jQuery('#$id').select2(".json_encode($selectOptions).");</script>";
                $result .= $this->getSpinner();
            }
        } else {
            // TODO configurable?
            $editflag = false;
            $result = '';

            $destrecord = $record[$this->fieldName()];
            if (is_array($destrecord)) {
                $result = '<span id="'.$id.'_current" >';

                if ($this->hasFlag(self::AF_RELATION_AUTOLINK) && $this->m_destInstance->allowed('view') && !$this->m_destInstance->hasFlag(Node::NF_NO_VIEW)) {
                    $result .= Tools::href(Tools::dispatch_url($this->m_destination, 'view',
                        array('atkselector' => $this->m_destInstance->primaryKey($record[$this->fieldName()]))), $this->m_destInstance->descriptor($destrecord),
                        SessionManager::SESSION_NESTED, true);
                } else {
                    $result .= $this->m_destInstance->descriptor($destrecord);
                }

                $result .= '&nbsp;';

                if (!$this->hasFlag(self::AF_OBLIGATORY)) {
                    $result .= '<a href="#" onClick="jQuery(\'#'.$id.'\').val(\'\');jQuery(\'#'.$id.'_current\').hide();" class="atkmanytoonerelation">'.Tools::atktext('unselect').'</a>&nbsp;';
                }
                $result .= '&nbsp;</span>';
            }

            $result .= $this->hide($record, $fieldprefix, $mode);
            $result .= $this->_getSelectLink($id, $this->parseFilter($this->m_destinationFilter, $record));
        }

        $autolink = $this->getRelationAutolink($id, $this->parseFilter($this->m_destinationFilter, $record));
        $result .= $editflag && isset($autolink['edit']) ? $autolink['edit'] : '';
        $result .= isset($autolink['add']) ? $autolink['add'] : '';

        return $result;
    }

    /**
     * Get the select link to select the value using a select action on the destination node.
     *
     * @param string $selname
     * @param string $filter
     *
     * @return string HTML-code with the select link
     */
    public function _getSelectLink($selname, $filter)
    {
        $result = '';
        // we use the current level to automatically return to this page
        // when we come from the select..
        $sm = SessionManager::getInstance();
        $atktarget = Tools::atkurlencode(Config::getGlobal('dispatcher').'?atklevel='.$sm->atkLevel().'&'.$selname.'=[atkprimkey]');
        $linkname = Tools::atktext('link_select_'.Tools::getNodeType($this->m_destination), $this->getOwnerInstance()->getModule(),
            $this->getOwnerInstance()->getType(), '', '', true);
        if (!$linkname) {
            $linkname = Tools::atktext('link_select_'.Tools::getNodeType($this->m_destination), Tools::getNodeModule($this->m_destination),
                Tools::getNodeType($this->m_destination), '', '', true);
        }
        if (!$linkname) {
            $linkname = Tools::atktext('select_a');
        } // . ' ' . strtolower(Tools::atktext(Module::getNodeType($this->m_destination), Module::getNodeModule($this->m_destination), Module::getNodeType($this->m_destination)));
        if ($this->m_destinationFilter != '') {
            $result .= Tools::href(Tools::dispatch_url($this->m_destination, 'select', array('atkfilter' => $filter, 'atktarget' => $atktarget)), $linkname,
                SessionManager::SESSION_NESTED, $this->m_autocomplete_saveform, 'class="atkmanytoonerelation"');
        } else {
            $result .= Tools::href(Tools::dispatch_url($this->m_destination, 'select', array('atktarget' => $atktarget)), $linkname,
                SessionManager::SESSION_NESTED, $this->m_autocomplete_saveform, 'class="atkmanytoonerelation"');
        }

        return $result;
    }

    /**
     * Creates and returns the auto edit/view links.
     *
     * @param string $id The field id
     * @param string $filter Filter that we want to apply on the destination node
     *
     * @return array The HTML code for the autolink links
     */
    public function getRelationAutolink($id, $filter)
    {
        $autolink = array();
        if ($this->hasFlag(self::AF_RELATION_AUTOLINK)) { // auto edit/view link
            $page = Page::getInstance();
            $page->register_script(Config::getGlobal('assets_url').'javascript/class.atkmanytoonerelation.js');
            $sm = SessionManager::getInstance();

            if ($this->m_destInstance->allowed('edit')) {
                $editlink = $sm->sessionUrl(Tools::dispatch_url($this->getAutoLinkDestination(), 'edit', array('atkselector' => 'REPLACEME')),
                    SessionManager::SESSION_NESTED);
                $autolink['edit'] = "&nbsp;<a href='javascript:atkSubmit(mto_parse(\"".Tools::atkurlencode($editlink).'", document.entryform.'.$id.".value),true)' class='atkmanytoonerelation'>".Tools::atktext('edit').'</a>';
            }
            if ($this->m_destInstance->allowed('add')) {
                $autolink['add'] = '&nbsp;'.Tools::href(Tools::dispatch_url($this->getAutoLinkDestination(), 'add', array(
                        'atkpkret' => $id,
                        'atkfilter' => ($this->m_useFilterForAddLink && $filter != '' ? $filter : ''),
                    )), Tools::atktext('new'), SessionManager::SESSION_NESTED, true, 'class="atkmanytoonerelation"');
            }
        }

        return $autolink;
    }

    /**
     * Returns a piece of html code for hiding this attribute in an HTML form,
     * while still posting its value. (<input type="hidden">).
     *
     * @param array $record
     * @param string $fieldprefix
     * @param string $mode
     *
     * @return string html
     */
    public function hide($record, $fieldprefix, $mode)
    {
        if (!$this->createDestination()) {
            return '';
        }

        $currentPk = '';
        if (isset($record[$this->fieldName()]) && $record[$this->fieldName()] != null) {
            $this->fixDestinationRecord($record);
            $currentPk = $this->m_destInstance->primaryKey($record[$this->fieldName()]);
        }

        $result = '<input type="hidden" id="'.$fieldprefix.$this->fieldName().'"
                name="'.$fieldprefix.$this->fieldName().'"
                value="'.$currentPk.'">';

        return $result;
    }

    /**
     * Support for destination "records" where only the id is set and the
     * record itself isn't converted to a real record (array) yet.
     *
     * @param array $record The record to fix
     */
    public function fixDestinationRecord(&$record)
    {
        if ($this->createDestination() && isset($record[$this->fieldName()]) && $record[$this->fieldName()] != null && !is_array($record[$this->fieldName()])) {
            $record[$this->fieldName()] = array($this->m_destInstance->primaryKeyField() => $record[$this->fieldName()]);
        }
    }

    /**
     * Retrieve the html code for placing this attribute in an edit page.
     *
     * The difference with the edit() method is that the edit() method just
     * generates the HTML code for editing the attribute, while the getEdit()
     * method is 'smart', and implements a hide/readonly policy based on
     * flags and/or custom override methodes in the node.
     * (<attributename>_edit() and <attributename>_display() methods)
     *
     * Framework method, it should not be necessary to call this method
     * directly.
     *
     * @param string $mode The edit mode ("add" or "edit")
     * @param array $record The record holding the values for this attribute
     * @param string $fieldprefix The fieldprefix to put in front of the name
     *                            of any html form element for this attribute.
     *
     * @return string the HTML code for this attribute that can be used in an
     *                editpage.
     */
    public function getEdit($mode, &$record, $fieldprefix)
    {
        $this->fixDestinationRecord($record);

        return parent::getEdit($mode, $record, $fieldprefix);
    }

    /**
     * Converts a record filter to a record array.
     *
     * @param string $filter filter string
     *
     * @return array record
     */
    protected function filterToArray($filter)
    {
        $result = array();

        $values = Tools::decodeKeyValueSet($filter);
        foreach ($values as $field => $value) {
            $parts = explode('.', $field);
            $ref = &$result;

            foreach ($parts as $part) {
                $ref = &$ref[$part];
            }

            $ref = $value;
        }

        return $result;
    }

    public function search($record, $extended = false, $fieldprefix = '', DataGrid $grid = null)
    {
        $useautocompletion = Config::getGlobal('manytoone_search_autocomplete', true) && $this->hasFlag(self::AF_RELATION_AUTOCOMPLETE);
        $id = $this->getSearchFieldName($fieldprefix);

        if (!$this->hasFlag(self::AF_LARGE) && !$useautocompletion) {
            if ($this->createDestination()) {

                $recordset = $this->_getSelectableRecords($record, 'search');

                if (isset($record[$this->fieldName()][$this->fieldName()])) {
                    $record[$this->fieldName()] = $record[$this->fieldName()][$this->fieldName()];
                }


                $result = '<select multiple class="form-control '.$this->get_class_name().'" id="'.$id.'" name="'.$id.'[]">';

                $pkfield = $this->m_destInstance->primaryKeyField();

                $selValues = $record[$this->fieldName()];

                if (!is_array($selValues)) {
                    $selValues = [$selValues];
                }

                if (in_array('', $selValues)) {
                    $selValues = [''];
                }

                // "search all" option
                $selected = $selValues[0] == '' ? ' selected' : '';
                $result .= sprintf('<option value=""%s>%s</option>', $selected, Tools::atktext('search_all'));

                // "none" option
                if (!$this->hasFlag(self::AF_OBLIGATORY) && !$this->hasFlag(self::AF_RELATION_NO_NULL_ITEM)) {
                    $selected = Tools::atk_in_array('__NONE__', $selValues) ? ' selected' : '';
                    $result .= sprintf('<option value="__NONE__"%s>%s</option>', $selected, $this->getNoneLabel('search'));
                }

                // normal options
                for ($i = 0; $i < count($recordset); ++$i) {
                    $pk = $recordset[$i][$pkfield];
                    $selected = Tools::atk_in_array($pk, $selValues) ? ' selected' : '';
                    $option = htmlspecialchars($this->m_destInstance->descriptor($recordset[$i]));
                    $result .= sprintf('<option value="%s"%s>%s</option>', $pk, $selected, $option);
                }

                $result .= '</select>';

                $selectOptions = [];
                $selectOptions['width'] = '100%';
                $script = "jQuery('#$id').select2(".json_encode($selectOptions).")";

                // if we use autosearch, register an onchange event that submits the grid
                if (!is_null($grid) && !$extended && $this->m_autoSearch) {
                    $onchange = $grid->getUpdateCall(['atkstartat' => 0], [], 'ATK.DataGrid.extractSearchOverrides');
                    $script .= '.on("change", function(){'.$onchange.'})';
                }
                $result .= '<script>'.$script.';</script>';

                return $result;
            }

            return '';
        } else {
            if (is_array($record[$this->fieldName()]) && isset($record[$this->fieldName()][$this->fieldName()])) {
                $record[$this->fieldName()] = $record[$this->fieldName()][$this->fieldName()];
            }
            $current = $record[$this->fieldName()];

            if ($useautocompletion) {
                $noneLabel = $this->getNoneLabel('search');
                $class = $this->getCSSClassAttribute('form-control');

                $result = '<select id="'.$id.'" '.$class.' name="'.$id.'">';
                $result .= '<option value="">'.$noneLabel.'</option>';

                if ($current) {
                    $result .= '<option value="'.htmlspecialchars($current).'" selected>'.$current.'</option>';
                }

                $result .= '</select>';

                $page = $this->m_ownerInstance->getPage();
                $page->register_script(Config::getGlobal('assets_url').'javascript/class.atkmanytoonerelation.js');

                $url = Tools::partial_url($this->m_ownerInstance->atkNodeUri(), $this->m_ownerInstance->m_action,
                    'attribute.'.$this->fieldName().'.autocomplete_search');

                $selectOptions = [];
                $selectOptions['tags'] = true;
                $selectOptions['ajax']['url'] = $url;
                $selectOptions['minimumInputLength'] = $this->m_autocomplete_minchars;
                $selectOptions['allowClear'] = true;
                $selectOptions['placeholder'] = $noneLabel;
                $selectOptions['width'] = '100%';

                $code = "ATK.ManyToOneRelation.completeSearch('{$id}', ".json_encode($selectOptions).");";
                $result .= '<script>jQuery(function(){'.$code.'});</script>';
            } else {
                //normal input field
                $result = '<input type="text" id="'.$id.'" class="form-control '.$this->get_class_name().'" name="'.$id.'" value="'.$current.'"'.($useautocompletion ? ' onchange=""' : '').($this->m_searchsize > 0 ? ' size="'.$this->m_searchsize.'"' : '').($this->m_maxsize > 0 ? ' maxlength="'.$this->m_maxsize.'"' : '').'>';
            }

            return $result;
        }
    }

    /**
     * Retrieve the list of searchmodes supported by the attribute.
     *
     * Note that not all modes may be supported by the database driver.
     * Compare this list to the one returned by the databasedriver, to
     * determine which searchmodes may be used.
     *
     * @return array List of supported searchmodes
     */
    public function getSearchModes()
    {
        if ($this->hasFlag(self::AF_LARGE) || $this->hasFlag(self::AF_MANYTOONE_AUTOCOMPLETE)) {
            return array('substring', 'exact', 'wildcard', 'regex');
        }

        return array('exact'); // only support exact search when searching with dropdowns
    }

    /**
     * Creates a smart search condition for a given search value, and adds it
     * to the query that will be used for performing the actual search.
     *
     * @param int $id The unique smart search criterium identifier.
     * @param int $nr The element number in the path.
     * @param array $path The remaining attribute path.
     * @param Query $query The query to which the condition will be added.
     * @param string $ownerAlias The owner table alias to use.
     * @param mixed $value The value the user has entered in the searchbox.
     * @param string $mode The searchmode to use.
     */
    public function smartSearchCondition($id, $nr, $path, &$query, $ownerAlias, $value, $mode)
    {
        if (count($path) > 0) {
            $this->createDestination();

            $destAlias = "ss_{$id}_{$nr}_".$this->fieldName();

            $query->addJoin($this->m_destInstance->m_table, $destAlias, $this->getJoinCondition($query, $ownerAlias, $destAlias), false);

            $attrName = array_shift($path);
            $attr = &$this->m_destInstance->getAttribute($attrName);

            if (is_object($attr)) {
                $attr->smartSearchCondition($id, $nr + 1, $path, $query, $destAlias, $value, $mode);
            }
        } else {
            $this->searchCondition($query, $ownerAlias, $value, $mode);
        }
    }

    public function getSearchCondition(Query $query, $table, $value, $searchmode, $fieldname = '')
    {
        if (!$this->createDestination()) {
            return;
        }

        if (is_array($value)) {
            foreach ($this->m_listColumns as $attr) {
                $attrValue = $value[$attr];
                if (!empty($attrValue)) {
                    $p_attrib = $this->m_destInstance->m_attribList[$attr];
                    if (!$p_attrib == null) {
                        $p_attrib->searchCondition($query, $this->fieldName(), $attrValue, $this->getChildSearchMode($searchmode, $p_attrib->fieldName()));
                    }
                }
            }

            if (isset($value[$this->fieldName()])) {
                $value = $value[$this->fieldName()];
            }
        }

        if (empty($value)) {
            return '';
        } else {
            if (!$this->hasFlag(self::AF_LARGE) && !$this->hasFlag(self::AF_RELATION_AUTOCOMPLETE)) {
                // We only support 'exact' matches.
                // But you can select more than one value, which we search using the IN() statement,
                // which should work in any ansi compatible database.
                if (!is_array($value)) { // This last condition is for when the user selected the 'search all' option, in which case, we don't add conditions at all.
                    $value = array($value);
                }

                if (count($value) == 1) { // exactly one value
                    if ($value[0] == '__NONE__') {
                        return $query->nullCondition($table.'.'.$this->fieldName(), true);
                    } elseif ($value[0] != '') {
                        return $query->exactCondition($table.'.'.$this->fieldName(), $this->escapeSQL($value[0]));
                    }
                } else { // search for more values using IN()
                    return $table.'.'.$this->fieldName()." IN ('".implode("','", $value)."')";
                }
            } else { // AF_LARGE || AF_RELATION_AUTOCOMPLETE
                // If we have a descriptor with multiple fields, use CONCAT
                $attribs = $this->m_destInstance->descriptorFields();
                $alias = $fieldname.$this->fieldName();
                if (count($attribs) > 1) {
                    $searchcondition = $this->getConcatFilter($value, $alias);
                } else {
                    // ask the destination node for it's search condition
                    $searchcondition = $this->m_destInstance->getSearchCondition($query, $alias, $fieldname, $value,
                        $this->getChildSearchMode($searchmode, $this->fieldName()));
                }

                return $searchcondition;
            }
        }
    }

    public function addToQuery($query, $tablename = '', $fieldaliasprefix = '', &$record, $level = 0, $mode = '')
    {
        if ($this->hasFlag(self::AF_MANYTOONE_LAZY)) {
            parent::addToQuery($query, $tablename, $fieldaliasprefix, $record, $level, $mode);

            return;
        }

        if ($this->createDestination()) {
            if ($mode != 'update' && $mode != 'add') {
                $alias = $fieldaliasprefix.$this->fieldName();
                $query->addJoin($this->m_destInstance->m_table, $alias, $this->getJoinCondition($query, $tablename, $alias), $this->m_leftjoin);
                $this->m_destInstance->addToQuery($query, $alias, $level + 1, false, $mode, $this->m_listColumns);
            } else {
                for ($i = 0, $_i = count($this->m_refKey); $i < $_i; ++$i) {
                    if ($record[$this->fieldName()] === null) {
                        $query->addField($this->m_refKey[$i], 'NULL', '', '', false);
                    } else {
                        $value = $record[$this->fieldName()];
                        if (is_array($value)) {
                            $fk = $this->m_destInstance->getAttribute($this->m_destInstance->m_primaryKey[$i]);
                            $value = $fk->value2db($value);
                        }

                        $query->addField($this->m_refKey[$i], $value, '', '', !$this->hasFlag(self::AF_NO_QUOTES));
                    }
                }
            }
        }
    }

    /**
     * Retrieve detail records from the database.
     *
     * Called by the framework to load the detail records.
     *
     * @param Db $db The database used by the node.
     * @param array $record The master record
     * @param string $mode The mode for loading (admin, select, copy, etc)
     *
     * @return array Recordset containing detailrecords, or NULL if no detail
     *               records are present. Note: when $mode is edit, this
     *               method will always return NULL. This is a framework
     *               optimization because in edit pages, the records are
     *               loaded on the fly.
     */
    public function load(&$db, $record, $mode)
    {
        return $this->_getSelectedRecord($record, $mode);
    }

    /**
     * Determine the load type of this attribute.
     *
     * With this method, the attribute tells the framework whether it wants
     * to be loaded in the main query (addToQuery) or whether the attribute
     * has its own load() implementation.
     * For the atkOneToOneRelation, this depends on the presence of the
     * self::AF_ONETOONE_LAZY flag.
     *
     * Framework method. It should not be necesary to call this method
     * directly.
     *
     * @param string $mode The type of load (view,admin,edit etc)
     *
     * @return int Bitmask containing information about load requirements.
     *             self::POSTLOAD|self::ADDTOQUERY when self::AF_ONETOONE_LAZY is set.
     *             self::ADDTOQUERY when self::AF_ONETOONE_LAZY is not set.
     */
    public function loadType($mode)
    {
        if (isset($this->m_loadType[$mode]) && $this->m_loadType[$mode] !== null) {
            return $this->m_loadType[$mode];
        } else {
            if (isset($this->m_loadType[null]) && $this->m_loadType[null] !== null) {
                return $this->m_loadType[null];
            } // Default backwardscompatible behaviour:
            else {
                if ($this->hasFlag(self::AF_MANYTOONE_LAZY)) {
                    return self::POSTLOAD | self::ADDTOQUERY;
                } else {
                    return self::ADDTOQUERY;
                }
            }
        }
    }

    /**
     * Validate if the record we are referring to really exists.
     *
     * @param array $record
     * @param string $mode
     */
    public function validate(&$record, $mode)
    {
        $sessionmanager = SessionManager::getInstance();
        if ($sessionmanager) {
            $storetype = $sessionmanager->stackVar('atkstore');
        }
        if ($storetype !== 'session' && !$this->_isSelectableRecord($record, $mode)) {
            Tools::triggerError($record, $this->fieldName(), 'error_integrity_violation');
        }
    }

    /**
     * Check if two records have the same value for this attribute.
     *
     * @param array $recA Record A
     * @param array $recB Record B
     *
     * @return bool to indicate if the records are equal
     */
    public function equal($recA, $recB)
    {
        if ($this->createDestination()) {
            return ($recA[$this->fieldName()][$this->m_destInstance->primaryKeyField()] == $recB[$this->fieldName()][$this->m_destInstance->primaryKeyField()]) || ($this->isEmpty($recA) && $this->isEmpty($recB));
            // we must also check empty values, because empty values need not necessarily
            // be equal (can be "", NULL or 0.
        }

        return false;
    }

    /**
     * Return the database field type of the attribute.
     *
     * Note that the type returned is a 'generic' type. Each database
     * vendor might have his own types, therefor, the type should be
     * converted to a database specific type using $db->fieldType().
     *
     * If the type was read from the table metadata, that value will
     * be used. Else, the attribute will analyze its flags to guess
     * what type it should be. If self::AF_AUTO_INCREMENT is set, the field
     * is probaly "number". If not, it's probably "string".
     *
     * @return string The 'generic' type of the database field for this
     *                attribute.
     */
    public function dbFieldType()
    {
        // The type of field that we need to store the foreign key, is equal to
        // the type of field of the primary key of the node we have a
        // relationship with.
        if ($this->createDestination()) {
            if (count($this->m_refKey) > 1) {
                $keys = array();
                for ($i = 0, $_i = count($this->m_refKey); $i < $_i; ++$i) {
                    $keys [] = $this->m_destInstance->m_attribList[$this->m_destInstance->m_primaryKey[$i]]->dbFieldType();
                }

                return $keys;
            } else {
                return $this->m_destInstance->m_attribList[$this->m_destInstance->primaryKeyField()]->dbFieldType();
            }
        }

        return '';
    }

    /**
     * Return the size of the field in the database.
     *
     * If 0 is returned, the size is unknown. In this case, the
     * return value should not be used to create table columns.
     *
     * Ofcourse, the size does not make sense for every field type.
     * So only interpret the result if a size has meaning for
     * the field type of this attribute. (For example, if the
     * database field is of type 'date', the size has no meaning)
     *
     * @return int The database field size
     */
    public function dbFieldSize()
    {
        // The size of the field we need to store the foreign key, is equal to
        // the size of the field of the primary key of the node we have a
        // relationship with.
        if ($this->createDestination()) {
            if (count($this->m_refKey) > 1) {
                $keys = array();
                for ($i = 0, $_i = count($this->m_refKey); $i < $_i; ++$i) {
                    $keys [] = $this->m_destInstance->m_attribList[$this->m_destInstance->m_primaryKey[$i]]->dbFieldSize();
                }

                return $keys;
            } else {
                return $this->m_destInstance->m_attribList[$this->m_destInstance->primaryKeyField()]->dbFieldSize();
            }
        }

        return 0;
    }

    /**
     * Returns the selected record for this many-to-one relation. Uses
     * the owner instance $this->fieldName()."_selected" method if it exists.
     *
     * @param array $record The record
     * @param string $mode The mode we're in
     *
     * @return array with the selected record
     */
    public function _getSelectedRecord($record = array(), $mode = '')
    {
        $method = $this->fieldName().'_selected';
        if (method_exists($this->m_ownerInstance, $method)) {
            return $this->m_ownerInstance->$method($record, $mode);
        } else {
            return $this->getSelectedRecord($record, $mode);
        }
    }

    /**
     * Returns the currently selected record.
     *
     * @param array $record The record
     * @param string $mode The mode we're in
     *
     * @return array with the selected record
     */
    public function getSelectedRecord($record = array(), $mode = '')
    {
        $this->createDestination();

        $condition = $this->m_destInstance->m_table.'.'.$this->m_destInstance->primaryKeyField()."='".$record[$this->fieldName()][$this->m_destInstance->primaryKeyField()]."'";

        $filter = $this->createFilter($record, $mode);
        if (!empty($filter)) {
            $condition = $condition.' AND '.$filter;
        }

        $record = $this->m_destInstance->select($condition)->getFirstRow();

        return $record;
    }

    /**
     * Returns the selectable records for this many-to-one relation. Uses
     * the owner instance $this->fieldName()."_selection" method if it exists.
     *
     * @param array $record The record
     * @param string $mode The mode we're in
     *
     * @return array with the selectable records
     */
    public function _getSelectableRecords($record = array(), $mode = '')
    {
        $method = $this->fieldName().'_selection';
        if (method_exists($this->m_ownerInstance, $method)) {
            return $this->m_ownerInstance->$method($record, $mode);
        } else {
            return $this->getSelectableRecords($record, $mode);
        }
    }

    public function _getSelectableRecordsSelector($record = array(), $mode = '')
    {
        $method = $this->fieldName().'_selectionSelector';
        if (method_exists($this->m_ownerInstance, $method)) {
            return $this->m_ownerInstance->$method($record, $mode);
        } else {
            return $this->getSelectableRecordsSelector($record, $mode);
        }
    }

    /**
     * Is selectable record? Uses the owner instance $this->fieldName()."_selectable"
     * method if it exists.
     *
     * @param array $record The record
     * @param string $mode The mode we're in
     *
     * @return bool to indicate if the record is selectable
     */
    public function _isSelectableRecord($record = array(), $mode = '')
    {
        $method = $this->fieldName().'_selectable';
        if (method_exists($this->m_ownerInstance, $method)) {
            return $this->m_ownerInstance->$method($record, $mode);
        } else {
            return $this->isSelectableRecord($record, $mode);
        }
    }

    /**
     * Create the destination filter for the given record.
     *
     * @param array $record
     * @param string $mode (not used here, but usable in derived classes)
     *
     * @return string filter
     */
    public function createFilter($record, $mode)
    {
        if ($this->m_destinationFilter != '') {
            $parser = new StringParser($this->m_destinationFilter);

            return $parser->parse($record);
        } else {
            return '';
        }
    }

    /**
     * Is selectable record?
     *
     * Use this one from your selectable override when needed.
     *
     * @param array $record The record
     * @param string $mode The mode we're in
     *
     * @return bool to indicate if the record is selectable
     */
    public function isSelectableRecord($record = array(), $mode = '')
    {
        if ($record[$this->fieldName()] == null) {
            return false;
        }

        if (in_array($mode, array(
                'edit',
                'update',
            )) && ($this->hasFlag(self::AF_READONLY_EDIT) || $this->hasFlag(self::AF_HIDE_EDIT))
        ) { // || ($this->hasFlag(AF_LARGE) && !$this->hasFlag(AF_MANYTOONE_AUTOCOMPLETE))
            // in this case we want the current value is selectable, regardless the destination filters
            return true;
        }

        $this->createDestination();

        // if the value is set directly in the record field we first
        // need to convert the value to an array
        if (!is_array($record[$this->fieldName()])) {
            $record[$this->fieldName()] = array(
                $this->m_destInstance->primaryKeyField() => $record[$this->fieldName()],
            );
        }

        $selectedKey = $this->m_destInstance->primaryKey($record[$this->fieldName()]);
        if ($selectedKey == null) {
            return false;
        }

        // If custom selection method exists we use this one, although this is
        // way more inefficient, so if you create a selection override you should
        // also think about creating a selectable override!
        $method = $this->fieldName().'_selection';
        if (method_exists($this->m_ownerInstance, $method)) {
            $rows = $this->m_ownerInstance->$method($record, $mode);
            foreach ($rows as $row) {
                $key = $this->m_destInstance->primaryKey($row);
                if ($key == $selectedKey) {
                    return true;
                }
            }

            return false;
        }

        // No selection override exists, simply add the record key to the selector.
        $filter = $this->createFilter($record, $mode);
        $selector = "($selectedKey)".($filter != null ? " AND ($filter)" : '');

        return $this->m_destInstance->select($selector)->getRowCount() > 0;
    }

    /**
     * Returns the selectable records.
     *
     * Use this one from your selection override when needed.
     *
     * @param array $record The record
     * @param string $mode The mode we're in
     *
     * @return array with the selectable records
     */
    public function getSelectableRecords($record = array(), $mode = '')
    {
        return $this->getSelectableRecordsSelector($record, $mode)->getAllRows();
    }

    public function getSelectableRecordsSelector($record = array(), $mode = '')
    {
        $this->createDestination();

        $selector = $this->createFilter($record, $mode);
        $result = $this->m_destInstance->select($selector)->orderBy($this->getDestination()->getOrder())->includes(Tools::atk_array_merge($this->m_destInstance->descriptorFields(),
            $this->m_destInstance->m_primaryKey));

        return $result;
    }

    /**
     * Returns the condition (SQL) that should be used when we want to join a relation's
     * owner node with the parent node.
     *
     * @param Query $query The query object
     * @param string $tablename The tablename on which to join
     * @param string $fieldalias The fieldalias
     *
     * @return string SQL string for joining the owner with the destination.
     *                Returns false when impossible (f.e. attrib is not a relation).
     */
    public function getJoinCondition(&$query, $tablename = '', $fieldalias = '')
    {
        if (!$this->createDestination()) {
            return false;
        }

        if ($tablename != '') {
            $realtablename = $tablename;
        } else {
            $realtablename = $this->m_ownerInstance->m_table;
        }
        $joinconditions = array();

        for ($i = 0, $_i = count($this->m_refKey); $i < $_i; ++$i) {
            $joinconditions[] = $realtablename.'.'.$this->m_refKey[$i].'='.$fieldalias.'.'.$this->m_destInstance->m_primaryKey[$i];
        }

        if ($this->m_joinFilter != '') {
            $parser = new StringParser($this->m_joinFilter);
            $filter = $parser->parse(array(
                'table' => $realtablename,
                'owner' => $realtablename,
                'destination' => $fieldalias,
            ));
            $joinconditions[] = $filter;
        }

        return implode(' AND ', $joinconditions);
    }

    /**
     * Make this relation hide itself from the form when there are no items to select.
     *
     * @param bool $hidewhenempty true - hide when empty, false - always show
     */
    public function setHideWhenEmpty($hidewhenempty)
    {
        $this->m_hidewhenempty = $hidewhenempty;
    }

    /**
     * Adds the attribute's edit / hide HTML code to the edit array.
     *
     * This method is called by the node if it wants the data needed to create
     * an edit form.
     *
     * This is a framework method, it should never be called directly.
     *
     * @param string $mode the edit mode ("add" or "edit")
     * @param array $arr pointer to the edit array
     * @param array $defaults pointer to the default values array
     * @param array $error pointer to the error array
     * @param string $fieldprefix the fieldprefix
     */
    public function addToEditArray($mode, &$arr, &$defaults, &$error, $fieldprefix)
    {
        if ($this->createDestination()) {
            // check if destination table is empty
            // only check if hidewhenempty is set to true
            if ($this->m_hidewhenempty) {
                $recs = $this->_getSelectableRecords($defaults, $mode);
                if (count($recs) == 0) {
                    return $this->hide($defaults, $fieldprefix, $mode);
                }
            }
        }

        return parent::addToEditArray($mode, $arr, $defaults, $error, $fieldprefix);
    }

    /**
     * Retrieves the ORDER BY statement for the relation.
     *
     * @param array $extra A list of attribute names to add to the order by
     *                          statement
     * @param string $table The table name (if not given uses the owner node's table name)
     * @param string $direction Sorting direction (ASC or DESC)
     *
     * @return string The ORDER BY statement for this attribute
     */
    public function getOrderByStatement($extra = '', $table = '', $direction = 'ASC')
    {
        if (!$this->createDestination()) {
            return parent::getOrderByStatement();
        }

        if (!empty($table)) {
            $table = $table.'_AE_'.$this->fieldName();
        } else {
            $table = $this->fieldName();
        }

        if (!empty($extra) && in_array($extra, $this->m_listColumns)) {
            return $this->getDestination()->getAttribute($extra)->getOrderByStatement('', $table, $direction);
        }

        $order = $this->m_destInstance->getOrder();

        if (!empty($order)) {
            $newParts = array();
            $parts = explode(',', $order);

            foreach ($parts as $part) {
                $split = preg_split('/\s+/', trim($part));
                $field = isset($split[0]) ? $split[0] : null;
                $fieldDirection = empty($split[1]) ? 'ASC' : strtoupper($split[1]);

                // if our default direction is DESC (the opposite of the default ASC)
                // we always have to switch the given direction to be the opposite, e.g.
                // DESC => ASC and ASC => DESC, this way we respect the default ordering
                // in the destination node even if the default is descending
                if ($fieldDirection == 'DESC') {
                    $fieldDirection = $direction == 'DESC' ? 'ASC' : 'DESC';
                } else {
                    $fieldDirection = $direction;
                }

                if (strpos($field, '.') !== false) {
                    list(, $field) = explode('.', $field);
                }

                $newPart = $this->getDestination()->getAttribute($field)->getOrderByStatement('', $table, $fieldDirection);

                // realias if destination order contains the wrong tablename.
                if (strpos($newPart, $this->m_destInstance->m_table.'.') !== false) {
                    $newPart = str_replace($this->m_destInstance->m_table.'.', $table.'.', $newPart);
                }
                $newParts[] = $newPart;
            }

            return implode(', ', $newParts);
        } else {
            $fields = $this->m_destInstance->descriptorFields();
            if (count($fields) == 0) {
                $fields = array($this->m_destInstance->primaryKeyField());
            }

            $order = '';
            foreach ($fields as $field) {
                $order .= (empty($order) ? '' : ', ').$table.'.'.$field;
            }

            return $order;
        }
    }

    /**
     * Adds the attribute / field to the list header. This includes the column name and search field.
     *
     * Framework method. It should not be necessary to call this method directly.
     *
     * @param string $action the action that is being performed on the node
     * @param array $arr reference to the the recordlist array
     * @param string $fieldprefix the fieldprefix
     * @param int $flags the recordlist flags
     * @param array $atksearch the current ATK search list (if not empty)
     * @param ColumnConfig $columnConfig Column configuration object
     * @param DataGrid $grid The DataGrid this attribute lives on.
     * @param string $column child column (null for this attribute, * for this attribute and all childs)
     */
    public function addToListArrayHeader(
        $action,
        &$arr,
        $fieldprefix,
        $flags,
        $atksearch,
        $atkorderby,
        DataGrid $grid = null,
        $column = '*'
    ) {
        if ($column == null || $column == '*') {
            $prefix = $fieldprefix.$this->fieldName().'_AE_';
            parent::addToListArrayHeader($action, $arr, $prefix, $flags, $atksearch[$this->fieldName()], $atkorderby, $grid, null);
        }

        if ($column == '*') {
            // only add extra columns when needed
            if ($this->hasFlag(self::AF_HIDE_LIST) && !$this->m_alwaysShowListColumns) {
                return;
            }
            if (!$this->createDestination() || count($this->m_listColumns) == 0) {
                return;
            }

            foreach ($this->m_listColumns as $column) {
                $this->_addColumnToListArrayHeader($column, $action, $arr, $fieldprefix, $flags, $atksearch, $atkorderby, $grid);
            }
        } else {
            if ($column != null) {
                $this->_addColumnToListArrayHeader($column, $action, $arr, $fieldprefix, $flags, $atksearch, $atkorderby, $grid);
            }
        }
    }

    /**
     * Adds the child attribute / field to the list row.
     *
     * Framework method. It should not be necessary to call this method directly.
     *
     * @param string $column child column (null for this attribute, * for this attribute and all childs)
     * @param string $action the action that is being performed on the node
     * @param array $arr reference to the the recordlist array
     * @param string $fieldprefix the fieldprefix
     * @param int $flags the recordlist flags
     * @param array $atksearch the current ATK search list (if not empty)
     * @param string $atkorderby order by
     * @param DataGrid $grid The DataGrid this attribute lives on.
     */
    protected function _addColumnToListArrayHeader(
        $column,
        $action,
        &$arr,
        $fieldprefix,
        $flags,
        $atksearch,
        $atkorderby,
        DataGrid $grid = null
    ) {
        $prefix = $fieldprefix.$this->fieldName().'_AE_';

        $p_attrib = $this->m_destInstance->getAttribute($column);
        if ($p_attrib == null) {
            throw new Exception("Invalid list column {$column} for ManyToOneRelation ".$this->getOwnerInstance()->atkNodeUri().'::'.$this->fieldName());
        }

        $p_attrib->m_flags |= self::AF_HIDE_LIST;
        $p_attrib->m_flags ^= self::AF_HIDE_LIST;
        $p_attrib->addToListArrayHeader($action, $arr, $prefix, $flags, $atksearch[$this->fieldName()], $atkorderby, $grid, null);

        // fix order by clause
        $needle = $prefix.$column;
        foreach (array_keys($arr['heading']) as $key) {
            if (strpos($key, $needle) !== 0) {
                continue;
            }

            if (empty($arr['heading'][$key]['order'])) {
                continue;
            }

            $order = $this->fieldName().'.'.$arr['heading'][$key]['order'];

            if (is_object($atkorderby) && isset($atkorderby->m_colcfg[$this->fieldName()]) && isset($atkorderby->m_colcfg[$this->fieldName()]['extra']) && $atkorderby->m_colcfg[$this->fieldName()]['extra'] == $column) {
                $direction = $atkorderby->getDirection($this->fieldName());
                if ($direction == 'asc') {
                    $order .= ' desc';
                }
            }

            $arr['heading'][$key]['order'] = $order;
        }
    }

    /**
     * Adds the attribute / field to the list row. And if the row is totalisable also to the total.
     *
     * Framework method. It should not be necessary to call this method directly.
     *
     * @param string $action the action that is being performed on the node
     * @param array $arr reference to the the recordlist array
     * @param int $nr the current row number
     * @param string $fieldprefix the fieldprefix
     * @param int $flags the recordlist flags
     * @param bool $edit editing?
     * @param DataGrid $grid data grid
     * @param string $column child column (null for this attribute, * for this attribute and all childs)
     */
    public function addToListArrayRow(
        $action,
        &$arr,
        $nr,
        $fieldprefix,
        $flags,
        $edit = false,
        DataGrid $grid = null,
        $column = '*'
    ) {
        if ($column == null || $column == '*') {
            $prefix = $fieldprefix.$this->fieldName().'_AE_';
            parent::addToListArrayRow($action, $arr, $nr, $prefix, $flags, $edit, $grid, null);
        }

        if ($column == '*') {
            // only add extra columns when needed
            if ($this->hasFlag(self::AF_HIDE_LIST) && !$this->m_alwaysShowListColumns) {
                return;
            }
            if (!$this->createDestination() || count($this->m_listColumns) == 0) {
                return;
            }

            foreach ($this->m_listColumns as $column) {
                $this->_addColumnToListArrayRow($column, $action, $arr, $nr, $fieldprefix, $flags, $edit, $grid);
            }
        } else {
            if ($column != null) {
                $this->_addColumnToListArrayRow($column, $action, $arr, $nr, $fieldprefix, $flags, $edit, $grid);
            }
        }
    }

    /**
     * Adds the child attribute / field to the list row.
     *
     * @param string $column child attribute name
     * @param string $action the action that is being performed on the node
     * @param array $arr reference to the the recordlist array
     * @param int $nr the current row number
     * @param string $fieldprefix the fieldprefix
     * @param int $flags the recordlist flags
     * @param bool $edit editing?
     * @param DataGrid $grid data grid
     */
    protected function _addColumnToListArrayRow(
        $column,
        $action,
        &$arr,
        $nr,
        $fieldprefix,
        $flags,
        $edit = false,
        DataGrid $grid = null
    ) {
        $prefix = $fieldprefix.$this->fieldName().'_AE_';

        // small trick, the destination record is in a subarray. The destination
        // addToListArrayRow will not expect this though, so we have to modify the
        // record a bit before passing it to the detail columns.
        $backup = $arr['rows'][$nr]['record'];
        $arr['rows'][$nr]['record'] = $arr['rows'][$nr]['record'][$this->fieldName()];

        $p_attrib = $this->m_destInstance->getAttribute($column);
        if ($p_attrib == null) {
            throw new Exception("Invalid list column {$column} for ManyToOneRelation ".$this->getOwnerInstance()->atkNodeUri().'::'.$this->fieldName());
        }

        $p_attrib->m_flags |= self::AF_HIDE_LIST;
        $p_attrib->m_flags ^= self::AF_HIDE_LIST;

        $p_attrib->addToListArrayRow($action, $arr, $nr, $prefix, $flags, $edit, $grid, null);

        $arr['rows'][$nr]['record'] = $backup;
    }

    /**
     * Adds the needed searchbox(es) for this attribute to the fields array. This
     * method should only be called by the atkSearchHandler.
     * Overridden method; in the integrated version, we should let the destination
     * attributes hook themselves into the fieldlist instead of hooking the relation
     * in it.
     *
     * @param array $fields The array containing fields to use in the
     *                           extended search
     * @param Node $node The node where the field is in
     * @param array $record A record containing default values to put
     *                           into the search fields.
     * @param array $fieldprefix search / mode field prefix
     */
    public function addToSearchformFields(&$fields, &$node, &$record, $fieldprefix = '')
    {
        $prefix = $fieldprefix.$this->fieldName().'_AE_';

        parent::addToSearchformFields($fields, $node, $record, $prefix);

        // only add extra columns when needed
        if ($this->hasFlag(self::AF_HIDE_LIST) && !$this->m_alwaysShowListColumns) {
            return;
        }
        if (!$this->createDestination() || count($this->m_listColumns) == 0) {
            return;
        }

        foreach ($this->m_listColumns as $attribname) {
            /** @var Attribute $p_attrib */
            $p_attrib = $this->m_destInstance->m_attribList[$attribname];
            $p_attrib->m_flags |= self::AF_HIDE_LIST;
            $p_attrib->m_flags ^= self::AF_HIDE_LIST;

            if (!$p_attrib->hasFlag(self::AF_HIDE_SEARCH)) {
                $p_attrib->addToSearchformFields($fields, $node, $record[$this->fieldName()], $prefix);
            }
        }
    }

    /**
     * Retrieve the sortorder for the listheader based on the
     * ColumnConfig.
     *
     * @param ColumnConfig $columnConfig The config that contains options for
     *                                   extended sorting and grouping to a
     *                                   recordlist.
     *
     * @return string Returns sort order ASC or DESC
     */
    public function listHeaderSortOrder(ColumnConfig &$columnConfig)
    {
        $order = $this->fieldName();

        // only add desc if not one of the listColumns is used for the sorting
        if (isset($columnConfig->m_colcfg[$order]) && empty($columnConfig->m_colcfg[$order]['extra'])) {
            $direction = $columnConfig->getDirection($order);
            if ($direction == 'asc') {
                $order .= ' desc';
            }
        }

        return $order;
    }

    /**
     * Creates and registers the on change handler caller function.
     * This method will be used to message listeners for a change
     * event as soon as a new value is selected.
     *
     * @param string $fieldId
     * @param string $fieldPrefix
     * @param string $none
     *
     * @return string function name
     */
    public function createOnChangeCaller($fieldId, $fieldPrefix, $none = 'null')
    {
        $function = $none;
        if (count($this->m_onchangecode)) {
            $function = "{$fieldId}_callChangeHandler";

            $js = "
          function {$function}() {
          {$fieldId}_onChange(\$('{$fieldId}'));
          }
        ";

            $this->m_onchangehandler_init = "newvalue = el.value;\n";
            $page = $this->m_ownerInstance->getPage();
            $page->register_scriptcode($js);
            $this->_renderChangeHandler($fieldPrefix);
        }

        return $function;
    }

    /**
     * Draw the auto-complete box.
     *
     * @param array $record The record
     * @param string $fieldPrefix The fieldprefix
     * @param string $mode The mode we're in
     * @return string html
     */
    public function drawAutoCompleteBox($record, $fieldPrefix, $mode)
    {
        $this->createDestination();

        // register base JavaScript code
        $page = $this->m_ownerInstance->getPage();

        $page->register_script(Config::getGlobal('assets_url').'javascript/class.atkmanytoonerelation.js');

        $id = $this->getHtmlId($fieldPrefix);

        // validate is this is a selectable record and if so
        // retrieve the display label and hidden value
        if ($this->_isSelectableRecord($record, $mode)) {
            $current = $record[$this->fieldName()];
            $label = $this->m_destInstance->descriptor($record[$this->fieldName()]);
            $value = $this->m_destInstance->primaryKey($record[$this->fieldName()]);
        } else {
            $current = null;
            $label = '';
            $value = '';
        }

        $hasNullOption = false;
        $noneLabel = '';

        // create the widget
        $result = '<select id="'.$id.'" name="'.$id.'" class="form-control atkmanytoonerelation" style="width: 300px;">';

        if ($this->hasFlag(self::AF_MANYTOONE_OBLIGATORY_NULL_ITEM) || (!$this->hasFlag(self::AF_OBLIGATORY) && !$this->hasFlag(self::AF_RELATION_NO_NULL_ITEM)) || (Config::getGlobal('list_obligatory_null_item') && !is_array($value))) {
            $hasNullOption = true;
            $noneLabel = $this->getNoneLabel($mode);
            $result .= '<option value="">'.$noneLabel.'</option>';
        }

        if ($current) {
            $result .= '<option value="'.htmlspecialchars($value).'" selected>'.htmlspecialchars($label).'</option>';
        }
        $result .= '</select>';
        $result .= ' '.$this->createSelectAndAutoLinks($id, $record);

        $result .= $this->getSpinner(); // spinner for dependency execution
        $result .= $this->getSearchSpinner($id); //spinner for ajax search

        // register JavaScript code that attaches the auto-complete behaviour to the search box
        $url = Tools::partial_url($this->m_ownerInstance->atkNodeUri(), $mode, 'attribute.'.$this->fieldName().'.autocomplete');
        $function = $this->createOnChangeCaller($id, $fieldPrefix);
        $minchars = $this->m_autocomplete_minchars;

        $selectOptions = [];
        $selectOptions['ajax']['url'] = $url;
        $selectOptions['minimumInputLength'] = $minchars;
        if ($hasNullOption) {
            $selectOptions['allowClear'] = true;
            $selectOptions['placeholder'] = $noneLabel;
        }
        $code = "ATK.ManyToOneRelation.completeEdit('{$id}', ".json_encode($selectOptions).", '{$id}_spinner', $function);";
        $result .= '<script>jQuery(function(){'.$code.'});</script>';

        return $result;
    }

    public function getSearchSpinner($id)
    {
        return '<div class="atkbusy" id="'.$id.'_spinner"><i class="fa fa-cog fa-spin"></i></div>';
    }

    /**
     * Auto-complete partial.
     *
     * @param string $mode add/edit mode?
     */
    public function partial_autocomplete($mode)
    {
        $this->createDestination();
        $searchvalue = $this->escapeSQL($this->m_ownerInstance->m_postvars['value']);
        $filter = $this->createSearchFilter($searchvalue);
        $this->addDestinationFilter($filter);
        $record = $this->m_ownerInstance->updateRecord();

        $result = "\n";
        $limit = $this->m_autocomplete_pagination_limit;
        $page = 1;
        if (isset($this->m_ownerInstance->m_postvars['page']) && is_numeric($this->m_ownerInstance->m_postvars['page'])) {
            $page = $this->m_ownerInstance->m_postvars['page'];
        }
        $offset = ($page - 1) * $limit;

        $selector = $this->_getSelectableRecordsSelector($record, $mode);
        $selector->limit($limit, $offset);
        $count = $selector->getRowCount();
        $iterator = $selector->getIterator();
        $more = ($offset + $limit > $count) ? 'false' : 'true';

        $result .= '<div id="total">'.$count.'</div>'."\n";
        $result .= '<div id="page">'.$page.'</div>'."\n";
        $result .= '<div id="more">'.$more.'</div>'."\n";

        $result .= '<ul>';
        foreach ($iterator as $rec) {
            $option = $this->m_destInstance->descriptor($rec);
            $value = $this->m_destInstance->primaryKey($rec);
            $result .= '
          <li value="'.htmlentities($value).'">'.htmlentities($option).'</li>';
        }
        $result .= '</ul>';

        return $result;
    }

    /**
     * Auto-complete search partial.
     *
     * @return string HTML code with autocomplete result
     */
    public function partial_autocomplete_search()
    {
        $this->createDestination();
        $searchvalue = $this->escapeSQL($this->m_ownerInstance->m_postvars['value']);
        $filter = $this->createSearchFilter($searchvalue);
        $this->addDestinationFilter($filter);
        $record = [];
        $mode = 'search';

        $result = "\n";
        $limit = $this->m_autocomplete_pagination_limit;
        $page = 1;
        if (isset($this->m_ownerInstance->m_postvars['page']) && is_numeric($this->m_ownerInstance->m_postvars['page'])) {
            $page = $this->m_ownerInstance->m_postvars['page'];
        }
        $offset = ($page - 1) * $limit;

        $selector = $this->_getSelectableRecordsSelector($record, $mode);
        $selector->limit($limit, $offset);
        $count = $selector->getRowCount();
        $iterator = $selector->getIterator();
        $more = ($offset + $limit > $count) ? 'false' : 'true';

        $result .= '<div id="total">'.$count.'</div>'."\n";
        $result .= '<div id="page">'.$page.'</div>'."\n";
        $result .= '<div id="more">'.$more.'</div>'."\n";

        $result .= '<ul>';
        foreach ($iterator as $rec) {
            $option = $this->m_destInstance->descriptor($rec);
            $value = $this->m_destInstance->primaryKey($rec);
            $result .= '
          <li value="'.htmlentities($option).'">'.htmlentities($option).'</li>';
        }
        $result .= '</ul>';

        return $result;
    }


    /**
     * Creates a search filter with the given search value on the given
     * descriptor fields.
     *
     * @param string $searchvalue A searchstring
     *
     * @return string a search string (WHERE clause)
     */
    public function createSearchFilter($searchvalue)
    {
        if ($this->m_autocomplete_searchfields == '') {
            $searchfields = $this->m_destInstance->descriptorFields();
        } else {
            $searchfields = $this->m_autocomplete_searchfields;
        }

        $parts = preg_split('/\s+/', $searchvalue);

        $mainFilter = array();
        foreach ($parts as $part) {
            $filter = array();
            foreach ($searchfields as $attribname) {
                if (strstr($attribname, '.')) {
                    $table = '';
                } else {
                    $table = $this->m_destInstance->m_table.'.';
                }

                if (!$this->m_autocomplete_search_case_sensitive) {
                    $tmp = 'LOWER('.$table.$attribname.')';
                } else {
                    $tmp = $table.$attribname;
                }

                switch ($this->m_autocomplete_searchmode) {
                    case self::SEARCH_MODE_EXACT:
                        if (!$this->m_autocomplete_search_case_sensitive) {
                            $tmp .= " = LOWER('{$part}')";
                        } else {
                            $tmp .= " = '{$part}'";
                        }
                        break;
                    case self::SEARCH_MODE_STARTSWITH:
                        if (!$this->m_autocomplete_search_case_sensitive) {
                            $tmp .= " LIKE LOWER('{$part}%')";
                        } else {
                            $tmp .= " LIKE '{$part}%'";
                        }
                        break;
                    case self::SEARCH_MODE_CONTAINS:
                        if (!$this->m_autocomplete_search_case_sensitive) {
                            $tmp .= " LIKE LOWER('%{$part}%')";
                        } else {
                            $tmp .= " LIKE '%{$part}%'";
                        }
                        break;
                    default:
                        $tmp .= " = LOWER('{$part}')";
                }

                $filter[] = $tmp;
            }

            if (count($filter) > 0) {
                $mainFilter[] = '('.implode(') OR (', $filter).')';
            }
        }

        if (count($mainFilter) > 0) {
            $searchFilter = '('.implode(') AND (', $mainFilter).')';
        } else {
            $searchFilter = '';
        }

        // When no searchfields are specified and we use the CONTAINS mode
        // add a concat filter
        if ($this->m_autocomplete_searchmode == self::SEARCH_MODE_CONTAINS && $this->m_autocomplete_searchfields == '') {
            $filter = $this->getConcatFilter($searchvalue);
            if ($filter) {
                if ($searchFilter != '') {
                    $searchFilter .= ' OR ';
                }
                $searchFilter .= $filter;
            }
        }

        return $searchFilter;
    }

    /**
     * Get Concat filter.
     *
     * @param string $searchValue Search value
     * @param string $fieldaliasprefix Field alias prefix
     *
     * @return string|bool
     */
    public function getConcatFilter($searchValue, $fieldaliasprefix = '')
    {
        // If we have a descriptor with multiple fields, use CONCAT
        $attribs = $this->m_destInstance->descriptorFields();
        if (count($attribs) > 1) {
            $fields = array();
            foreach ($attribs as $attribname) {
                $post = '';
                if (strstr($attribname, '.')) {
                    if ($fieldaliasprefix != '') {
                        $table = $fieldaliasprefix.'_AE_';
                    } else {
                        $table = '';
                    }
                    $post = substr($attribname, strpos($attribname, '.'));
                    $attribname = substr($attribname, 0, strpos($attribname, '.'));
                } elseif ($fieldaliasprefix != '') {
                    $table = $fieldaliasprefix.'.';
                } else {
                    $table = $this->m_destInstance->m_table.'.';
                }

                $p_attrib = $this->m_destInstance->m_attribList[$attribname];
                $fields[$p_attrib->fieldName()] = $table.$p_attrib->fieldName().$post;
            }

            if (is_array($searchValue)) {
                // (fix warning trim function)
                $searchValue = $searchValue[0];
            }

            $value = $this->escapeSQL(trim($searchValue));
            $value = str_replace('  ', ' ', $value);
            if (!$value) {
                return false;
            } else {
                $function = $this->getConcatDescriptorFunction();
                if ($function != '' && method_exists($this->m_destInstance, $function)) {
                    $descriptordef = $this->m_destInstance->$function();
                } elseif ($this->m_destInstance->m_descTemplate != null) {
                    $descriptordef = $this->m_destInstance->m_descTemplate;
                } elseif (method_exists($this->m_destInstance, 'descriptor_def')) {
                    $descriptordef = $this->m_destInstance->descriptor_def();
                } else {
                    $descriptordef = $this->m_destInstance->descriptor();
                }

                $parser = new StringParser($descriptordef);
                $concatFields = $parser->getAllParsedFieldsAsArray($fields, true);
                $concatTags = $concatFields['tags'];
                $concatSeparators = $concatFields['separators'];
                $concatSeparators[] = ' '; //the query removes all spaces, so let's do the same here [Bjorn]
                // to search independent of characters between tags, like spaces and comma's,
                // we remove all these separators so we can search for just the concatenated tags in concat_ws [Jeroen]
                foreach ($concatSeparators as $separator) {
                    $value = str_replace($separator, '', $value);
                }

                $db = $this->getDb();
                $searchcondition = 'UPPER('.$db->func_concat_ws($concatTags, '', true).") LIKE UPPER('%".$value."%')";
            }

            return $searchcondition;
        }

        return false;
    }
}
