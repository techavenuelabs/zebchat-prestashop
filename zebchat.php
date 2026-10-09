<?php
/**
 * ZebChat for PrestaShop. Sign in, add this shop, and turn the chat on or off.
 *
 * @package   ZebChat
 * @copyright (C) 2026 Tech Avenue Labs
 * @license   GNU General Public License version 2 or later; see https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/lib/ZebChatApp.php';

class Zebchat extends Module
{
    public function __construct()
    {
        $this->name = 'zebchat';
        $this->tab = 'front_office_features';
        $this->version = '1.1.2';
        $this->author = 'Tech Avenue Labs';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'ZebChat';
        $this->description = 'Add the ZebChat live chat widget. Sign in or create an account, add this shop, and turn the chat on or off.';
        $this->ps_versions_compliancy = array('min' => '1.7.7.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return parent::install() && $this->registerHook('displayFooter');
    }

    public function uninstall()
    {
        Configuration::deleteByName('ZEBCHAT_STATE');
        return parent::uninstall();
    }

    public function getContent()
    {
        $app = $this->app();
        $host = ZebChatApp::host($this->context->shop->getBaseURL(true));
        $back = $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name
            . '&module_name=' . $this->name;
        if (Tools::isSubmit('zebchat_action')) {
            $app->handle(
                $_POST,
                $host,
                ZebChatApp::allowedHosts($host),
                (string) Configuration::get('PS_SHOP_NAME')
            );
            if (method_exists('Tools', 'clearSmartyCache')) {
                Tools::clearSmartyCache();
            }
            Tools::redirectAdmin($back);
        }
        return $app->render($host, (string) Configuration::get('PS_SHOP_NAME'), $back, '');
    }

    public function hookDisplayFooter()
    {
        return $this->app()->scriptTag();
    }

    /**
     * @return ZebChatApp
     */
    private function app()
    {
        return new ZebChatApp(
            static function () {
                $raw = Configuration::get('ZEBCHAT_STATE');
                $decoded = is_string($raw) ? json_decode($raw, true) : array();
                return is_array($decoded) ? $decoded : array();
            },
            static function ($bag) {
                $json = json_encode($bag);
                if (is_string($json)) {
                    Configuration::updateValue('ZEBCHAT_STATE', $json, true);
                }
            },
            'ZebChat-PrestaShop/' . ZebChatApp::VERSION
        );
    }
}
