<?php
/**
 * JBZoo Application
 *
 * This file is part of the JBZoo CCK package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    Application
 * @license    GPL-2.0
 * @copyright  Copyright (C) JBZoo.com, All rights reserved.
 * @link       https://github.com/JBZoo/JBZoo
 * @author     Denis Smetannikov <denis@jbzoo.com>
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

/**
 * Class JBSefHelper
 */
class JBSefHelper extends AppHelper
{
    /**
     * DON'T SET EMPTY PREFIX!
     * Or possible conflicts with paginations and routing
     */
    const PREFIX_ITEM     = 'i';
    const PREFIX_CATEGORY = 'c';

    /**
     * @var JBModelConfig
     */
    protected $_config = null;

    /**
     * @var AppAlias
     */
    protected $_aliasCat = null;

    /**
     * @var AppAlias
     */
    protected $_aliasItem = null;

    /**
     * @var AppAlias
     */
    protected $_aliasApp = null;

    /**
     * @var JApplicationSite
     */
    protected $_joomlaApp = null;

    /**
     * @var JDocumentHTML
     */
    protected $_joomlaDoc = null;

    /**
     * @var StringHelper
     */
    protected $_string = null;

    /**
     * @var JBRouterHelper
     */
    protected $_jbrouter = null;

    /**
     * @var JBDebugHelper
     */
    protected $_jbdebug = null;

    /**
     * @var JBRequestHelper
     */
    protected $_jbrequest = null;

    /**
     * @var ItemTable
     */
    protected $_itemTable = null;

    /**
     * @var CategoryTable
     */
    protected $_catTable = null;

    /**
     * @var ApplicationTable
     */
    protected $_appTable = null;

    /**
     * @param App $app
     */
    public function __construct($app)
    {
        parent::__construct($app);

        // get sef configs
        $this->_config = JBModelConfig::model()->getGroup('config.sef', array());

        // helpers for alias
        $this->_aliasCat  = $this->app->alias->category;
        $this->_aliasItem = $this->app->alias->item;
        $this->_aliasApp  = $this->app->alias->application;

        // get other helpers
        $this->_string    = $this->app->string;
        $this->_joomlaApp = $this->app->system->application;
        $this->_joomlaDoc = $this->app->system->document;
        $this->_jbrequest = $this->app->jbrequest;
        $this->_jbrouter  = $this->app->jbrouter;
        $this->_jbdebug   = $this->app->jbdebug;

        // get links to tables
        $this->_itemTable = $this->app->table->item;
        $this->_catTable  = $this->app->table->category;
        $this->_appTable  = $this->app->table->application;
    }

    /**
     * Build route handler. Make correct url to item, categories and another stuff with params
     * @param AppEvent $event
     */
    public function sefBuildRoute($event)
    {
        //$this->_jbdebug->mark('jbzoo-sef::sefBuildRoute::start');

        // get url params
        $params = $event->getParameters();

        // fix menu
        if ($this->_config->get('fix_menu', 0)) {
            $params = $this->_buildSefUrl($params);
        }

        // build new url by rules
        $params = $this->_buildItemUrl($params);
        $params = $this->_buildCategoryUrl($params);
        $params = $this->_buildFeedUrl($params);
        $params = $this->_removeCategorId($params);

        // set new params
        $params['segments'] = array_values($params['segments']);
        $event->setReturnValue($params);

        //$this->_jbdebug->mark('jbzoo-sef::sefBuildRoute::finish');
    }

    /**
     * Parse current url (usually) and fix some urls bugs (from classical Zoo)
     * @param AppEvent $event
     */
    public function sefParseRoute($event)
    {
        $this->_jbdebug->mark('jbzoo-sef::sefParseRoute::start');

        $params = $event->getParameters();

        // fix menu
        if ($this->_config->get('fix_menu', 0)) {
            $params = $this->_parseSefUrl($params);
        }

        // parse category or item by priority order
        if ($this->_config->get('parse_priority', 'item') == 'category') {

            // try to find category
            if (empty($params['vars']) || (isset($params['vars']['category_id']) && $params['vars']['category_id'] == 0)) {
                $params = $this->_parseCategoryUrl($params);
            }

            // try to find item
            if (empty($params['vars']) || (isset($params['vars']['item_id']) && $params['vars']['item_id'] == 0)) {
                $params = $this->_parseItemUrl($params);
            }

        } else {

            // try to find item
            if (empty($params['vars']) || (isset($params['vars']['item_id']) && $params['vars']['item_id'] == 0)) {
                $params = $this->_parseItemUrl($params);
            }

            // try to find category
            if (empty($params['vars']) || (isset($params['vars']['category_id']) && $params['vars']['category_id'] == 0)) {
                $params = $this->_parseCategoryUrl($params);
            }
        }

        // feed
        if (empty($params['vars']) && $this->_config->get('fix_feed', 0)) {
            $params = $this->_parseFeedUrl($params);
        }

        // redirect to correct url
        if ($this->_config->get('redirect', 0)) {
            $this->_checkRedirect($params);
        }

        // set new params
        $event->setReturnValue($params);

        $this->_jbdebug->mark('jbzoo-sef::sefParseRoute::finish');
    }

    /**
     * Fix link in the canonical metatag
     * It works only with
     * - item
     * - category
     */
    public function canonicalFix()
    {

        $glconfig = JBModelConfig::model()->getGroup('config.sef', array());

        if (!$this->_jbrequest->is('option', 'com_zoo')) {
            return null;
        }

        $this->_jbdebug->mark('jbzoo-sef::canonicalFix::start');
        $flags = array(
            $this->_jbrequest->getWord('task'),
            $this->_jbrequest->getWord('view'),
            $this->_jbrequest->getWord('layout'),
        );

        $newCanUrl = null;
        
        if (in_array('item', $flags)) {
            $itemId    = $this->_jbrequest->getSystem('item');
            $newCanUrl = $this->_getUrl($this->_itemTable->get($itemId), 'item');

            if (substr($newCanUrl, 0, 1) === "/") {
                // Удаляем первый символ "/"
                $newCanUrl = substr($newCanUrl, 1);
            }
            
            //j4fix

       if ($glconfig->get('fix_canonical_v1')) {

            $newCanUrl = App::getInstance('zoo')->zoo->getApplication()->alias .  '/' . $this->_itemTable->get((int)$itemId)->alias;

       }

       if ($glconfig->get('fix_canonical_v2')) {

        $item_get_fix = $this->_itemTable->get($itemId);

        $items_get_fix   = [];
        $items_get_fix[] = (object) [
            'id'        => $item_get_fix->id,
            'alias'     => $item_get_fix->alias,
            'name'      => $item_get_fix->name,
            'published' => $item_get_fix->state,
            'url'       => App::getInstance('zoo')->route->item($item_get_fix, 0),
            'type'      => JText::_('ITEM'),
        ];

        $cats = $item_get_fix->getRelatedCategories();

        foreach ($cats as $cat)
        {
            $items_get_fix[] = (object) [
                'id'        => $cat->id,
                'alias'     => $cat->alias,
                'name'      => $cat->name,
                'published' => $cat->published,
                'url'       => App::getInstance('zoo')->route->category($cat, 0),
                'type'      => JText::_('CATEGORY'),
            ];
        }

        $primary_category_obj = $this->app->table->category->get($item_get_fix->getPrimarycategory()->id); 

        // Переменная для хранения ключа найденного элемента
        $key = null;

        // Поиск значения в массиве
        foreach ($items_get_fix as $index => $item) {
            if ($item->alias === $primary_category_obj->alias) {
                $key = $index;
                break; // Прерываем цикл после нахождения первого совпадения
            }
        }

        if (NULL !== $key) {
            $fix_url_v2 = $items_get_fix[$key]->url;
        }
        else {
            $fix_url_v2 = end($items_get_fix)->url;
        }

        // Проверяем наличие item_id
        if (strpos($fix_url_v2, 'item_id=') === false) {
            // Если item_id отсутствует, производим замену
            $fix_url_v2 = str_replace(
                'view=category&layout=category',
                'task=item&item_id='.$itemId,
                $fix_url_v2
            );
        }

        $fix_url_v2_sef = JRoute::_(str_replace("&amp;", "&", $fix_url_v2), true, false);
        $fix_url_v2_sef = str_replace("//", "/", $fix_url_v2_sef);

        // Проверяем, начинается ли строка с символа "/"
        if (substr($fix_url_v2_sef, 0, 1) === "/") {
            // Удаляем первый символ "/"
            $fix_url_v2_sef = substr($fix_url_v2_sef, 1);
        }

        $newCanUrl = $fix_url_v2_sef;

       }

        } elseif (in_array('frontpage', $flags)) {
            $appId     = $this->app->zoo->getApplication()->id;
            $newCanUrl = $this->_getUrl($appId, 'frontpage');

        } elseif (in_array('category', $flags)) {
            $categoryId = $this->_jbrequest->getSystem('category');
            $newCanUrl  = $this->_getUrl($this->_catTable->get($categoryId), 'category');
        }

        if ($newCanUrl) {
            // remove all canocical link
            $headData = $this->_joomlaDoc->getHeadData();
            $canKey   = array_search(array('relation' => 'canonical', 'relType' => 'rel', 'attribs' => array()), $headData['links']);
            unset($headData['links'][$canKey]);
            $this->_joomlaDoc->setHeadData($headData);

            // set new url
            $baseUrl = $this->_jbrouter->getHostUrl();
            // $this->_joomlaDoc->addHeadLink($baseUrl . $newCanUrl, 'canonical');
            $this->_joomlaDoc->addHeadLink($baseUrl . '/' . $newCanUrl, 'canonical');
        }

        $this->_jbdebug->mark('jbzoo-sef::canonicalFix::finish');
    }

    /**
     * @return bool
     */
    public function canonicalRedirect()
    {
        $isJBZoo = $this->app->jbrequest->is('option', 'com_zoo');
        $isItem  = $this->app->jbrequest->getSystem('item') > 0;
        $isCat   = $this->app->jbrequest->getSystem('category') && !$this->app->jbrequest->is('task', 'filter');
        $isSEF   = (int)JFactory::getConfig()->get('sef');
        $mode    = $this->_config->get('canonical_redirect', 'none');
        $page    = (int)$this->app->jbrequest->get('page', 1);

        if (!$mode || $mode == 'none' || !$isJBZoo || !$isSEF) {
            return false;
        }

        if (!$isItem && !$isCat) {
            return false;
        }

        $currentUrl   = $this->app->jbenv->getCurrentUrl();
        $canonicalUrl = null;

        $data = $this->_joomlaDoc->getHeadData();
        if (isset($data['links'])) {
            foreach ($data['links'] as $link => $linkData) {
                if ($linkData['relation'] == 'canonical') {
                    $canonicalUrl = $link;
                }
            }
        }

        if (!$canonicalUrl || !$currentUrl) {
            return false;
        }

        $rootUrl      = new JUri(JUri::root());
        $currentUrl   = new JUri(trim($currentUrl, '/'));
        $canonicalUrl = new JUri(trim($canonicalUrl, '/'));

        if ($mode === '404_noquery' || $mode === '301_noquery') {
            $currentUrl->setQuery('');
            $canonicalUrl->setQuery('');
        }

        $canonicalUrl->setHost($rootUrl->getHost());
        $currentUrl->setHost($rootUrl->getHost());

        // Normalized urls
        $canonicalUrl = trim((string)$canonicalUrl, '/') . ($page >= 2 ? "/{$page}" : '');
        $currentUrl   = trim((string)$currentUrl, '/');
        $rootUrl      = trim((string)$rootUrl, '/');

        // Check it
        if (
            ($canonicalUrl !== $currentUrl) && // No loop redirect
            ($canonicalUrl !== $rootUrl)
        ) {
            if ($mode === '301_strict' || $mode === '301_noquery') {
                $this->_redirect($canonicalUrl);
            }

            if ($mode === '404_strict' || $mode === '404_noquery') {
                return $this->app->error->raiseError(404, JText::_('Item not found'));
            }
        }
    }

    /**
     * Remove variable "category_id" from GET query
     * @param $params
     */
    protected function _removeCategorId($params)
    {
        if ($this->_config->get('fix_category_id', 0) &&
            isset($params['query']['category_id'])
        ) {
            unset($params['query']['category_id']);
        }

        return $params;
    }

    /**
     * Check, if current url has old format - execute 301 redirect to correct address
     * @param $params
     */
    protected function _checkRedirect($params)
    {
        // get vars
        $segments = $params['segments'];
        $segCout  = count($segments);

        // item url
        if ('item' == $segments[0] && 2 == $segCout) {
            $itemId = $this->_aliasItem->translateAliasToID($segments[1]);
            $item   = $this->_itemTable->get((int)$itemId);
            $newUrl = $this->_getUrl($item, 'item');
            $this->_redirect($newUrl);
        }

        // for excess get param category_id
        if ($this->_config->get('fix_category_id', 0)) {
            $reg    = '#' . $segments[0] . '\?category_id=\d*$#i';
            $curUrl = $this->app->jbenv->getCurrentUrl();
            if (preg_match($reg, $curUrl, $matches)) {
                $itemId = $this->_aliasItem->translateAliasToID($segments[0]);
                $item   = $this->_itemTable->get((int)$itemId);
                $newUrl = $this->_getUrl($item, 'item');
                $this->_redirect($newUrl);
            }
        }

        // category url
        if ('category' == $segments[0] && (2 == $segCout || 3 == $segCout)) {
            // simple variant
            if (2 == $segCout) {
                $catId    = $this->_aliasCat->translateAliasToID($segments[1]);
                $category = $this->_catTable->get((int)$catId);
                $newUrl   = $this->_getUrl($category, 'category');
                $this->_redirect($newUrl);

            } else if (3 == $segCout) { // with pagination
                $catId    = $this->_aliasCat->translateAliasToID($segments[1]);
                $category = $this->_catTable->get((int)$catId);
                $newUrl   = $this->_getUrl($category, 'category') . (int)$segments[2];
                $this->_redirect($newUrl);
            }
        }

        // feed
        if ('feed' == $segments[0] &&
            ('rss' == $segments[1] || 'atom' == $segments[1]) &&
            (3 == $segCout || 4 == $segCout)
        ) {
            $appId = $this->_aliasApp->translateAliasToID($segments[2]);
            if ($appId) {

                if (isset($segments[3])) {
                    $categoryId = $this->_aliasCat->translateAliasToID($segments[3]);
                    $category   = $this->_catTable->get($categoryId);
                } else {
                    $category        = $this->app->object->create('category');
                    $category->id    = 0;
                    $category->name  = 'ROOT';
                    $category->alias = '_root';
                }

                $newUrl = $this->_getUrl($category, 'feed', $segments[1]);
                $this->_redirect($newUrl);
            }
        }
    }

    /**
     * Get abs url to object
     * @param mixed  $object
     * @param string $type
     * @param string $param
     * @return string
     */
    protected function _getUrl($object, $type, $param = null)
    {
        $url = null;
        if (empty($object)) {
            return $url;
        }

        if ($type == 'item') {
            $url = $this->app->route->item($object);

        } else if ($type == 'category') {
            $url = $this->app->route->category($object);

        } else if ($type == 'frontpage') {
            $url = $this->app->route->frontpage($object);

        } else if ($type == 'feed') {
            $url = $this->app->route->feed($object, $param);
        }

        if ($url) {
            return JRoute::_($url, true, false);
        }
    }

    /**
     * Execute 301 redirect with cycle checking
     * @param $newUrl
     * @return null
     */
    protected function _redirect($newUrl)
    {
        if (!$newUrl) {
            return null;
        }

        $juri       = JUri::getInstance();
        $fullCurUrl = urldecode($juri->toString());
        $curUrl     = urldecode($juri->toString(array('path', 'query', 'fragment')));

        // checking for cycle redirect
        if ($newUrl !== $curUrl && $newUrl !== $fullCurUrl) {
            if ($this->app->jbversion->joomla(3)) {
                $this->_joomlaApp->redirect($newUrl, true);
            } else {
                $this->_joomlaApp->redirect($newUrl, '', 'message', true);
            }
        }
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _parseItemUrl($params)
    {
        // get current seg
        $checkedSeg = $params['segments'][0];
        if (!$this->_config->get('fix_item', 0)) {
            $checkedSeg = isset($params['segments'][1]) ? $params['segments'][1] : null;
        }

        if ($checkedSeg) {
            // get ItemId by seg
            if ($this->_config->get('item_alias_id') &&
                preg_match('#^' . self::PREFIX_ITEM . '(\d+)#i', $checkedSeg, $parsed)
            ) {
                $itemId = $parsed[1];

            } else {
                $itemId = $this->_aliasItem->translateAliasToID($params['segments'][0]);
            }

            // set request
            if ($itemId) {
                $params['vars']['task']    = 'item';
                $params['vars']['item_id'] = (int)$itemId;
            }
        }

        return $params;
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _parseCategoryUrl($params)
    {
        // get current seg
        $checkedSeg = $params['segments'][0];
        if (!$this->_config->get('fix_category', 0)) {
            $checkedSeg = isset($params['segments'][1]) ? $params['segments'][1] : null;
        }

        if ($checkedSeg) {
            // get ItemId by seg
            if ($this->_config->get('category_alias_id') &&
                preg_match('#^' . self::PREFIX_CATEGORY . '(\d+)#i', $checkedSeg, $parsed)
            ) {
                $categoryId = $parsed[1];
            } else {
                $categoryId = $this->_aliasCat->translateAliasToID($params['segments'][0]);
            }

            // set request
            if ($categoryId) {
                $params['vars']['task']        = 'category';
                $params['vars']['category_id'] = (int)$categoryId;

                // check pagination
                if (isset($params['segments'][1])) {
                    $params['vars']['page'] = (int)$params['segments'][1];
                }
            }

        }

        return $params;
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _parseFeedUrl($params)
    {
        if ($params['segments'][0] == 'rss' || $params['segments'][0] == 'atom') {

            $params['vars']['task'] = 'feed';
            $params['vars']['type'] = $params['segments'][0];

            // get application id
            $appId = (int)$this->_aliasApp->translateAliasToID($params['segments'][1]);
            if ($appId) {
                $params['vars']['app_id'] = $appId;
            }

            // get category id
            if (isset($params['segments'][2])) {
                $categoryId = (int)$this->_aliasCat->translateAliasToID($params['segments'][2]);
                if ($categoryId) {
                    $params['vars']['category_id'] = $categoryId;
                }
            }
        }

        return $params;
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _buildItemUrl($params)
    {
        if (isset($params['segments'][0]) && $params['segments'][0] == 'item') {

            if ($this->_config->get('fix_item', 0)) {
                unset($params['segments'][0]);
            }

            if ($this->_config->get('item_alias_id', 0)) {
                $params['segments'][1] = self::PREFIX_ITEM . (int)$this->_aliasItem->translateAliasToID($params['segments'][1]);
            }
        }

        return $params;
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _buildCategoryUrl($params)
    {
        if (isset($params['segments'][0]) && $params['segments'][0] == 'category') {

            if ($this->_config->get('fix_category', 0)) {
                unset($params['segments'][0]);
            }

            if ($this->_config->get('category_alias_id', 0)) {
                $params['segments'][1] = self::PREFIX_CATEGORY . (int)$this->_aliasCat->translateAliasToID($params['segments'][1]);
            }
        }

        return $params;
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _buildFeedUrl($params)
    {
        if (isset($params['segments'][0]) && $params['segments'][0] == 'feed') {

            if ($this->_config->get('fix_feed', 0)) {
                unset($params['segments'][0]);
            }

            if (isset($params['query']['format']) && $params['query']['format'] == 'feed') {
                // TODO remove bug with "Class 'JFeedItem' not found"
                // unset($params['query']['format']);
            }
        }

        return $params;
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _buildSefUrl($params)
    {   
        $query = $params['query'];

        // filter
        $task = 'filter';
        $controller = 'searchjbuniversal';

        if ((@$query['task'] == $task || @$query['controller'] == $controller) && @$query['view'] == $task && @$query['layout'] == $task) {
            unset($params['query']['task'], $params['query']['type'], $params['query']['view'], $params['query']['layout'], $params['query']['app_id'], $params['query']['controller']);

            // Unset controller from filter menu pagination
            if (!($menu = $this->app->system->application->getMenu('site')
                    and $menu instanceof JMenu
                    and isset($query['Itemid'])
                    and $item = $menu->getItem($query['Itemid'])
                    and @$item->component == 'com_zoo'
                    // and $app_id = $this->app->zoo->getApplication()->id
                    // and $app_id = $item->params->get('application')
                    and $app_id = $item->getParams()->get('application')
                    // todoj4fix
                    and @$params['query']['app_id'] == $app_id
                    and @$query['controller'] == $controller)) {
                
                unset($params['query']['controller']);
            }
        }

        // basket
        $task = 'index';
        $controller = 'basket';

        if (@$query['task'] == $task || @$query['controller'] == $controller) {
            unset($params['query']['task'], $params['query']['controller'], $params['query']['view'], $params['query']['layout']);
        }

        // orders
        $task = 'orders';
        $controller = 'clientarea';

        if (@$query['task'] == $task || @$query['controller'] == $controller) {
            unset($params['query']['task'], $params['query']['controller']);
        }

        // order
        $task = 'order';
        $controller = 'clientarea';

        if (@$query['task'] == $task || @$query['controller'] == $controller) {
            $params['query']['segments'][] = $task;
            $params['query']['segments'][] = $params['query']['order_id'];
            unset($params['query']['task'], $params['query']['controller'], $params['query']['order_id']);
        }

        // compare
        $task = 'compare';

        if (@$query['task'] == $task) {
            unset($params['query']['task'], $params['query']['type'], $params['query']['app_id'], $params['query']['controller'], $params['query']['layout']);
        }

        // favorite
        $task = 'favorite';

        if (@$query['task'] == $task) {
            unset($params['query']['task'], $params['query']['type'], $params['query']['app_id'], $params['query']['controller'], $params['query']['view'], $params['query']['layout']);
        }

        return $params;
    }

    /**
     * @param $params
     * @return mixed
     */
    protected function _parseSefUrl($params)
    {   
        $count = count($params['segments']);

        // filter
        $task = 'filter';

        if ($count == 1 && $params['segments'][0] == $task) {
            $params['vars']['task'] = $task;
        }

        // orders
        $task = 'order';

        if ($count == 2 && $params['segments'][0] == $task) {
            $params['vars']['controller'] = 'clientarea';
            $params['vars']['task'] = $task;
            $params['vars']['order_id'] = $params['segments'][1];
        }

        return $params;
    }

}