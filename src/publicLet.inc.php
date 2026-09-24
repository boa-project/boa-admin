<?php
// This file is part of BoA - https://github.com/boa-project
//
// BoA is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// BoA is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with BoA.  If not, see <http://www.gnu.org/licenses/>.
//
// The latest code can be found at <https://github.com/boa-project/>.
 
/**
 * This is a one-line short description of the file/class.
 *
 * You can have a rather longer description of the file/class as well,
 * if you like, and it can span multiple lines.
 *
 * @package    [PACKAGE]
 * @category   [CATEGORY]
 * @copyright  2017 BoA Project
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero GPL v3 or later
 *
 * Description : Specific inclusion to run publiclet scripts
 */
require_once("base.conf.php");

use BoA\Core\Services\ConfService;
use BoA\Core\Services\PluginsService;
use BoA\Core\Security\Crypto;
use BoA\Plugins\Action\Share\ShareCenter;

$pServ = PluginsService::getInstance();
ConfService::init();
$confPlugin = ConfService::getInstance()->confPluginSoftLoad($pServ);
$pServ->loadPluginsRegistry(APP_PLUGINS_FOLDER, $confPlugin);
ConfService::start();
$authDriver = ConfService::getAuthDriverImpl();
$confDriver = ConfService::getConfStorageImpl();

require_once($confDriver->getUserClassFileName());
require_once(APP_PLUGINS_FOLDER."/action.share/ShareCenter.class.php");

// Publiclets call Crypto::decrypt and bare ShareCenter::* after this include.
// APP_autoload (from bootstrap_context) resolves Crypto.class.php; require explicitly
// if autoload missed, and alias ShareCenter for generated publiclet scripts.
if (!class_exists(Crypto::class, false)) {
    require_once APP_BIN_FOLDER.'/Core/Security/Crypto.class.php';
}
if (!class_exists('ShareCenter', false)) {
    class_alias(ShareCenter::class, 'ShareCenter');
}
