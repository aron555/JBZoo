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
 */

// no direct access
defined('_JEXEC') or die('Restricted access');
use Joomla\String\StringHelper;

$view = $this->getView();

$message = StringHelper::str_ireplace('$1', $view->order->id, JText::_('JBZOO_PAYMENT_FAIL_MESSAGE'));

?>
<p><?php echo $message; ?></p>
