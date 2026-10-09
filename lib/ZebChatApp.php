<?php
/**
 * ZebChat connection shared by the Joomla, Drupal, Magento, PrestaShop, OpenCart and
 * WHMCS extensions. Each package ships its own copy so the upload stands alone.
 *
 * PHP 7.4 or newer. The caller checks the admin permission and the CSRF token
 * before handle(). Tokens stay in the host app's storage, never in the page source
 * except the public site key on the storefront script.
 *
 * @package   ZebChat
 * @copyright (C) 2026 Tech Avenue Labs
 * @license   GNU General Public License version 2 or later; see https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('_PS_VERSION_')) {
	exit;
}

if (!class_exists('ZebChatApp', false)) {
	/**
	 * Sign-in, website picker and the storefront script tag.
	 */
	class ZebChatApp {

		const VERSION = '1.1.2';

		/** `source` values of POST /auth/register (the plugin creates the website itself). */
		const SOURCES = array('drupal', 'joomla', 'magento', 'opencart', 'prestashop', 'whmcs', 'wordpress');

		const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -1 100.0 100" width="40" height="40" aria-hidden="true"><defs><linearGradient id="zc-mark-amber" x1="0" y1="8" x2="0" y2="66" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#FDD302"/><stop offset="1" stop-color="#FD9D01"/></linearGradient><mask id="zc-mark-gap" maskUnits="userSpaceOnUse" x="-10" y="-10" width="120" height="120"><rect x="-10" y="-10" width="120" height="120" fill="#fff"/><path d="M21 26 H55 A13 13 0 0 1 68 39 V61 A13 13 0 0 1 55 74 H34 L18 90 V74 A13 13 0 0 1 8 61 V39 A13 13 0 0 1 21 26 Z" fill="#000" stroke="#000" stroke-width="7" stroke-linejoin="round"/></mask></defs><path d="M46 8 H80 A12 12 0 0 1 92 20 V66 L80 54 H46 A12 12 0 0 1 34 42 V20 A12 12 0 0 1 46 8 Z" fill="url(#zc-mark-amber)" mask="url(#zc-mark-gap)"/><path d="M21 26 H55 A13 13 0 0 1 68 39 V61 A13 13 0 0 1 55 74 H34 L18 90 V74 A13 13 0 0 1 8 61 V39 A13 13 0 0 1 21 26 Z" fill="#0154F9"/><path d="M26.50 37.50 L49.50 37.50 L49.50 42.90 L35.95 57.10 L49.50 57.10 L49.50 62.50 L26.50 62.50 L26.50 57.10 L40.05 42.90 L26.50 42.90 Z" fill="#FFFFFF" stroke="#FFFFFF" stroke-width="1.2" stroke-linejoin="round"/></svg>';

		const SCRIPT_URL = 'https://cdn.zebchat.com/widget/v1/widget.js';

		const APP_STORE = 'https://apps.apple.com/us/app/zebchat-live-chat-for-websites/id6820005939';

		const PLAY_STORE = 'https://play.google.com/store/apps/details?id=com.zebchat.app';

		/** @var callable */
		private $load;

		/** @var callable */
		private $save;

		/** @var string */
		private $apiBase;

		/** @var string */
		private $appUrl;

		/** @var string */
		private $scriptUrl;

		/** @var string */
		private $userAgent;

		/** @var callable|null Replaces curl in the CLI self-test (check.php). */
		private $transport = null;

		/**
		 * @param callable $load Returns the stored bag (array).
		 * @param callable $save Accepts the bag (array).
		 * @param string   $userAgent HTTP User-Agent for this extension.
		 */
		public function __construct($load, $save, $userAgent) {
			$this->load = $load;
			$this->save = $save;
			$this->userAgent = $userAgent;
			$api = getenv('ZEBCHAT_API_BASE');
			$app = getenv('ZEBCHAT_APP_URL');
			$script = getenv('ZEBCHAT_WIDGET_URL');
			$this->apiBase = is_string($api) && $api !== '' ? rtrim($api, '/') : 'https://api.zebchat.com/api/v1';
			$this->appUrl = is_string($app) && $app !== '' ? rtrim($app, '/') : 'https://app.zebchat.com';
			$this->scriptUrl = is_string($script) && $script !== '' ? $script : self::SCRIPT_URL;
		}

		/**
		 * Swaps the HTTP layer, for the CLI self-test only. The callable gets
		 * ($method, $path, $body, $token) and returns array(status, raw JSON body).
		 *
		 * @param callable $transport Fake transport.
		 */
		public function useTransport($transport) {
			$this->transport = $transport;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function bag() {
			$stored = call_user_func($this->load);
			return self::normalize(is_array($stored) ? $stored : array());
		}

		/**
		 * @param array<string, mixed> $bag Bag.
		 */
		public function persist($bag) {
			call_user_func($this->save, self::normalize($bag));
		}

		/**
		 * @return bool
		 */
		public function isConnected() {
			$auth = $this->bag()['auth'];
			return is_array($auth) && !empty($auth['refresh_token']);
		}

		/**
		 * Script tag for the storefront, or an empty string when chat is off.
		 *
		 * @return string
		 */
		public function scriptTag() {
			$widget = $this->bag()['widget'];
			if (!is_array($widget) || empty($widget['enabled'])) {
				return '';
			}
			$key = isset($widget['site_key']) ? (string) $widget['site_key'] : '';
			if (1 !== preg_match('/\Azc_[A-Za-z0-9]+\z/', $key)) {
				return '';
			}
			return '<script src="' . self::e($this->scriptUrl) . '" data-site="' . self::e($key) . '" async></script>';
		}

		/**
		 * One-time notice, then clears it.
		 *
		 * @return array{type: string, message: string}|null
		 */
		public function takeNotice() {
			$bag = $this->bag();
			$notice = $bag['notice'];
			if (!is_array($notice) || empty($notice['message'])) {
				return null;
			}
			$bag['notice'] = array('type' => '', 'message' => '');
			$this->persist($bag);
			return array(
				'type' => isset($notice['type']) ? (string) $notice['type'] : 'success',
				'message' => (string) $notice['message'],
			);
		}

		/**
		 * Hostname without a port, or an empty string.
		 *
		 * @param string $url Absolute URL.
		 * @return string
		 */
		public static function host($url) {
			$parts = parse_url($url);
			$host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';
			$host = preg_replace('/[^a-z0-9.-]/', '', $host);
			return is_string($host) ? $host : '';
		}

		/**
		 * The hostname plus its www / bare pair.
		 *
		 * @param string $host Hostname.
		 * @return string[]
		 */
		public static function allowedHosts($host) {
			$host = strtolower($host);
			if ($host === '' || 1 !== preg_match('/\A[a-z0-9.-]+\z/', $host)) {
				return array();
			}
			$names = array($host);
			if (strpos($host, 'www.') === 0) {
				$names[] = substr($host, 4);
			} else {
				$names[] = 'www.' . $host;
			}
			return array_values(array_unique($names));
		}

		/**
		 * Settings form. The caller has already checked the admin and the CSRF token.
		 *
		 * @param array<string, mixed> $post Posted fields.
		 * @param string               $domain Store hostname.
		 * @param string[]             $allowed Hostnames the widget may load on.
		 * @param string               $siteName Default name for a new website.
		 */
		public function handle($post, $domain, $allowed, $siteName) {
			$action = isset($post['zebchat_action']) ? (string) $post['zebchat_action'] : '';
			$email = isset($post['zebchat_email']) ? trim((string) $post['zebchat_email']) : '';
			$name = isset($post['zebchat_name']) ? trim((string) $post['zebchat_name']) : '';
			$org = isset($post['zebchat_organization']) ? trim((string) $post['zebchat_organization']) : '';
			$orgName = isset($post['zebchat_organization_name']) ? trim((string) $post['zebchat_organization_name']) : '';
			$website = isset($post['zebchat_website']) ? trim((string) $post['zebchat_website']) : '';
			$site = isset($post['zebchat_website_name']) ? trim((string) $post['zebchat_website_name']) : '';
			$code = isset($post['zebchat_code']) ? trim((string) $post['zebchat_code']) : '';
			$password = isset($post['zebchat_password']) ? (string) $post['zebchat_password'] : '';
			$enabled = !empty($post['zebchat_enabled']);
			$error = null;
			if ($action === 'login') {
				$error = $this->login($email, $password);
			} elseif ($action === 'mfa') {
				$error = $this->login2fa($code);
			} elseif ($action === 'mfa_cancel') {
				$bag = $this->bag();
				$bag['mfa'] = array('token' => '', 'email' => '', 'expires' => 0);
				$this->persist($bag);
			} elseif ($action === 'register') {
				$error = $this->register($name, $email, $password, $org);
			} elseif ($action === 'resend') {
				$error = $this->resendVerification($email);
			} elseif ($action === 'verify_cancel') {
				$bag = $this->bag();
				$bag['verify'] = array('email' => '');
				$this->persist($bag);
			} elseif ($action === 'disconnect') {
				$bag = $this->bag();
				$bag['auth'] = self::emptyAuth();
				$bag['mfa'] = array('token' => '', 'email' => '', 'expires' => 0);
				$bag['verify'] = array('email' => '');
				$this->persist($bag);
				$this->flash('success', 'Disconnected from ZebChat. The chat on this site is unchanged.');
			} elseif ($action === 'switch') {
				$error = $this->switchOrganization($org);
			} elseif ($action === 'save') {
				$error = $this->saveWidget($org, $website, $enabled);
			} elseif ($action === 'create') {
				$error = $this->createWebsite($org, $site !== '' ? $site : $siteName, $domain, $allowed);
			} elseif ($action === 'create_org') {
				$error = $this->createOrganization($orgName);
			}
			if (is_string($error)) {
				$this->flash('error', $error);
			}
		}

		/**
		 * Settings screen HTML. Values are escaped.
		 *
		 * @param string $domain Store hostname.
		 * @param string $siteName Suggested website name.
		 * @param string $action Form action URL.
		 * @param string $extraHidden Extra hidden inputs (CSRF), already escaped by the caller.
		 * @return string
		 */
		public function render($domain, $siteName, $action, $extraHidden) {
			$bag = $this->bag();
			$notice = $bag['notice'];
			$html = '<div class="zebchat">';
			$html .= '<style>' . $this->css() . '</style>';
			$widgetNow = is_array($bag['widget']) ? $bag['widget'] : array();
			$liveNow = $this->isConnected() && !empty($widgetNow['enabled']) && isset($widgetNow['site_key']) && 1 === preg_match('/\Azc_[A-Za-z0-9]+\z/', (string) $widgetNow['site_key']);
			$status = $liveNow ? array('live', 'Live chat is on') : ($this->isConnected() ? array('off', 'Live chat is off') : array('none', 'Not connected'));
			$html .= '<header class="zebchat-top">' . self::LOGO . '<div class="zebchat-top-text"><h1>ZebChat</h1><p>Live chat, AI assistant and visitor insights for this site.</p></div>';
			$html .= '<span class="zebchat-status zebchat-status-' . $status[0] . '"><span class="zebchat-dot"></span>' . self::e($status[1]) . '</span></header>';
			if (is_array($notice) && !empty($notice['message'])) {
				$kind = (isset($notice['type']) && $notice['type'] === 'error') ? 'error' : 'success';
				$html .= '<p class="zebchat-notice zebchat-notice-' . $kind . '" role="status">' . self::e((string) $notice['message']) . '</p>';
				$bag['notice'] = array('type' => '', 'message' => '');
				$this->persist($bag);
				$bag = $this->bag();
			}
			$mfa = $bag['mfa'];
			$pending = is_array($mfa) && !empty($mfa['token']) && isset($mfa['expires']) && (int) $mfa['expires'] > time();
			if ($pending) {
				$html .= $this->renderMfa($action, $extraHidden, isset($mfa['email']) ? (string) $mfa['email'] : '');
			} elseif (!$this->isConnected()) {
				$verify = is_array($bag['verify']) && isset($bag['verify']['email']) ? (string) $bag['verify']['email'] : '';
				if ($verify !== '') {
					$html .= $this->renderCheckEmail($action, $extraHidden, $verify);
				}
				$html .= $this->renderSignedOut($action, $extraHidden, $verify);
			} else {
				$html .= '<div class="zebchat-columns"><div class="zebchat-main">' . $this->renderSignedIn($action, $extraHidden, $domain, $siteName) . '</div>';
				$html .= '<div class="zebchat-side">' . $this->renderLinks(is_array($bag['widget']) && isset($bag['widget']['website_id']) ? (string) $bag['widget']['website_id'] : '') . '</div></div>';
			}
			$html .= '<script>' . $this->script() . '</script></div>';
			return $html;
		}

		/**
		 * @param string $email Email.
		 * @param string $password Password.
		 * @return string|null Error sentence.
		 */
		private function login($email, $password) {
			if ($email === '' || $password === '') {
				return 'Enter your email and password.';
			}
			$result = $this->request('POST', '/auth/login', array('email' => $email, 'password' => $password), '');
			if (isset($result['error'])) {
				if (isset($result['code']) && $result['code'] === 'email_not_verified') {
					// The right password, but the account hasn't confirmed its email yet.
					$this->awaitVerification($email);
				}
				return $result['error'];
			}
			$data = $result['data'];
			if (!empty($data['mfaRequired'])) {
				if (empty($data['mfaToken']) || !is_string($data['mfaToken'])) {
					return 'ZebChat did not return a session. Try again.';
				}
				$bag = $this->bag();
				$bag['mfa'] = array('token' => $data['mfaToken'], 'email' => $email, 'expires' => time() + 300);
				$this->persist($bag);
				return null;
			}
			$accepted = $this->acceptAuth($data);
			if ($accepted === null) {
				$this->flash('success', 'Signed in to ZebChat.');
			}
			return $accepted;
		}

		/**
		 * @param string $code Authenticator or recovery code.
		 * @return string|null
		 */
		private function login2fa($code) {
			$bag = $this->bag();
			$mfa = $bag['mfa'];
			if (!is_array($mfa) || empty($mfa['token']) || empty($mfa['expires']) || (int) $mfa['expires'] <= time()) {
				$bag['mfa'] = array('token' => '', 'email' => '', 'expires' => 0);
				$this->persist($bag);
				return 'The sign-in expired. Enter your email and password again.';
			}
			if ($code === '') {
				return 'Enter the code from your authenticator app.';
			}
			$field = 1 === preg_match('/\A[\d\s-]{6,9}\z/', $code) ? 'code' : 'recoveryCode';
			$result = $this->request(
				'POST',
				'/auth/login/2fa',
				array('mfaToken' => (string) $mfa['token'], $field => $code),
				''
			);
			if (isset($result['error'])) {
				if (strpos($result['error'], 'expired') !== false) {
					$bag['mfa'] = array('token' => '', 'email' => '', 'expires' => 0);
					$this->persist($bag);
				}
				return $result['error'];
			}
			$bag['mfa'] = array('token' => '', 'email' => '', 'expires' => 0);
			$this->persist($bag);
			$accepted = $this->acceptAuth($result['data']);
			if ($accepted === null) {
				$this->flash('success', 'Signed in to ZebChat.');
			}
			return $accepted;
		}

		/**
		 * @param string $name Person's name.
		 * @param string $email Email.
		 * @param string $password Password.
		 * @param string $org Organization name.
		 * @return string|null
		 */
		private function register($name, $email, $password, $org) {
			if ($name === '' || $email === '' || $password === '' || $org === '') {
				return 'Fill in your name, email, password and organization.';
			}
			if (strlen($password) < 8) {
				return 'Use a password of at least 8 characters.';
			}
			$result = $this->request(
				'POST',
				'/auth/register',
				$this->registerBody($name, $email, $password, $org),
				''
			);
			if (isset($result['error'])) {
				return $result['error'];
			}
			if (!empty($result['data']['verificationRequired'])) {
				// New accounts confirm their email first; the link signs them in on the dashboard.
				$sentTo = isset($result['data']['email']) && is_string($result['data']['email']) ? $result['data']['email'] : $email;
				$this->awaitVerification($sentTo);
				return null;
			}
			$accepted = $this->acceptAuth($result['data']);
			if ($accepted === null) {
				$this->flash('success', 'Your ZebChat account is ready. Add this site below to turn the chat on.');
			}
			return $accepted;
		}

		/**
		 * The register body. `source` tells ZebChat this plugin signed the user up: the new
		 * organization skips the dashboard's setup screens (the plugin adds the website).
		 *
		 * @param string $name Person's name.
		 * @param string $email Email.
		 * @param string $password Password.
		 * @param string $org Organization name.
		 * @return array<string, string>
		 */
		private function registerBody($name, $email, $password, $org) {
			$body = array(
				'name' => $name,
				'email' => $email,
				'password' => $password,
				'organizationName' => $org,
			);
			$source = self::sourceOf($this->userAgent);
			if ($source !== '') {
				$body['source'] = $source;
			}
			return $body;
		}

		/**
		 * `drupal` for `ZebChat-Drupal/1.0.1`, or an empty string.
		 *
		 * @param string $userAgent User-Agent of this extension.
		 * @return string
		 */
		public static function sourceOf($userAgent) {
			if (1 !== preg_match('/\AZebChat-([A-Za-z]+)\//', $userAgent, $match)) {
				return '';
			}
			$source = strtolower($match[1]);
			return in_array($source, self::SOURCES, true) ? $source : '';
		}

		/**
		 * Shows "check your email" for `$email` (after signing up, or signing in unconfirmed).
		 *
		 * @param string $email Where the confirmation link went.
		 */
		private function awaitVerification($email) {
			$bag = $this->bag();
			$bag['verify'] = array('email' => $email);
			$this->persist($bag);
		}

		/**
		 * Emails a new confirmation link (the API answers the same for every address).
		 *
		 * @param string $email Email.
		 * @return string|null
		 */
		private function resendVerification($email) {
			if ($email === '') {
				$bag = $this->bag();
				$email = is_array($bag['verify']) && isset($bag['verify']['email']) ? (string) $bag['verify']['email'] : '';
			}
			if ($email === '') {
				return 'Enter your email.';
			}
			$result = $this->request('POST', '/auth/verify-email/resend', array('email' => $email), '');
			if (isset($result['error'])) {
				return $result['error'];
			}
			if ((int) $result['status'] === 429) {
				return 'Too many emails for now. Wait a few minutes, then try again.';
			}
			if ((int) $result['status'] >= 400) {
				return $this->message($result['data']);
			}
			$this->awaitVerification($email);
			$this->flash('success', 'We sent a new confirmation link to ' . $email . '.');
			return null;
		}

		/**
		 * @param array<string, mixed> $data Login or register body.
		 * @return string|null
		 */
		private function acceptAuth($data) {
			$tokens = isset($data['tokens']) && is_array($data['tokens']) ? $data['tokens'] : array();
			if (empty($tokens['accessToken']) || empty($tokens['refreshToken'])) {
				return 'ZebChat did not return a session. Try again from the dashboard.';
			}
			$user = isset($data['user']) && is_array($data['user']) ? $data['user'] : array();
			$bag = $this->bag();
			$bag['verify'] = array('email' => '');
			$this->persist($bag);
			$this->storeTokens($tokens, $user);
			$me = $this->authorized('GET', '/auth/me', null);
			if (isset($me['error'])) {
				$bag = $this->bag();
				$bag['auth'] = self::emptyAuth();
				$this->persist($bag);
				return $me['error'];
			}
			return null;
		}

		/**
		 * @param string $orgId Public organization id.
		 * @return string|null
		 */
		private function switchOrganization($orgId) {
			$me = $this->authorized('GET', '/auth/me', null);
			if (isset($me['error'])) {
				return $me['error'];
			}
			if ($this->membership($me['data'], $orgId) === null) {
				return 'Choose an organization you belong to.';
			}
			$bag = $this->bag();
			$bag['selected_org'] = $orgId;
			$this->persist($bag);
			return null;
		}

		/**
		 * @param string $orgId Public organization id.
		 * @param string $websiteId Public website id, or empty.
		 * @param bool   $enabled Whether to show the widget.
		 * @return string|null
		 */
		private function saveWidget($orgId, $websiteId, $enabled) {
			$me = $this->authorized('GET', '/auth/me', null);
			if (isset($me['error'])) {
				return $me['error'];
			}
			if ($this->membership($me['data'], $orgId) === null) {
				return 'Choose an organization you belong to.';
			}
			$widget = array(
				'enabled' => false,
				'organization_id' => $orgId,
				'website_id' => '',
				'website_name' => '',
				'site_key' => '',
			);
			if ($websiteId !== '') {
				$websites = $this->websites($orgId);
				if (isset($websites['error'])) {
					return $websites['error'];
				}
				$chosen = null;
				foreach ($websites['data'] as $website) {
					if (is_array($website) && isset($website['id']) && (string) $website['id'] === $websiteId) {
						$chosen = $website;
						break;
					}
				}
				if ($chosen === null || empty($chosen['siteKey'])) {
					return 'Choose a website from the list, or add this site.';
				}
				$widget['website_id'] = (string) $chosen['id'];
				$widget['website_name'] = isset($chosen['name']) ? (string) $chosen['name'] : '';
				$widget['site_key'] = (string) $chosen['siteKey'];
				$widget['enabled'] = $enabled;
			}
			$bag = $this->bag();
			$bag['widget'] = $widget;
			$bag['selected_org'] = '';
			$this->persist($bag);
			$this->flash('success', $widget['enabled'] ? 'Saved. Live chat is on.' : 'Saved. Live chat is off.');
			return null;
		}

		/**
		 * Creates an organization for an account that has none yet (signed up on the
		 * dashboard without finishing setup). The account becomes its owner.
		 *
		 * @param string $name Organization name.
		 * @return string|null
		 */
		private function createOrganization($name) {
			$length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
			if ($length < 2 || $length > 80) {
				return 'Enter an organization name of 2 to 80 characters.';
			}
			$me = $this->authorized('GET', '/auth/me', null);
			if (isset($me['error'])) {
				return $me['error'];
			}
			if (!empty($me['data']['memberships'])) {
				// Already has one (a second click, or another tab): go straight to the website picker.
				return null;
			}
			$result = $this->authorized('POST', '/organizations', array('name' => $name));
			if (isset($result['error'])) {
				return $result['error'];
			}
			$organization = isset($result['data']['organization']) && is_array($result['data']['organization']) ? $result['data']['organization'] : array();
			if (empty($organization['id'])) {
				return 'ZebChat did not return the new organization.';
			}
			$bag = $this->bag();
			$bag['selected_org'] = (string) $organization['id'];
			$this->persist($bag);
			$this->flash('success', 'Organization created. Add this site below to turn the chat on.');
			return null;
		}

		/**
		 * @param string   $orgId Public organization id.
		 * @param string   $name Website name.
		 * @param string   $domain Hostname.
		 * @param string[] $allowed Allowed hostnames.
		 * @return string|null
		 */
		private function createWebsite($orgId, $name, $domain, $allowed) {
			$me = $this->authorized('GET', '/auth/me', null);
			if (isset($me['error'])) {
				return $me['error'];
			}
			$membership = $this->membership($me['data'], $orgId);
			if ($membership === null) {
				return 'Choose an organization you belong to.';
			}
			if (!$this->canManage($membership)) {
				return 'Your role cannot add websites. Ask an owner, or add it in the ZebChat dashboard.';
			}
			if ($domain === '') {
				return 'This site has no hostname ZebChat can use.';
			}
			$result = $this->authorized(
				'POST',
				'/organizations/' . rawurlencode($orgId) . '/websites',
				array(
					'name' => $name !== '' ? $name : $domain,
					'domain' => $domain,
					'allowedDomains' => array_values($allowed),
				)
			);
			if (isset($result['error'])) {
				return $result['error'];
			}
			$created = $result['data'];
			if (empty($created['id']) || empty($created['siteKey'])) {
				return 'ZebChat did not return the new website.';
			}
			$bag = $this->bag();
			$bag['widget'] = array(
				'enabled' => true,
				'organization_id' => $orgId,
				'website_id' => (string) $created['id'],
				'website_name' => isset($created['name']) ? (string) $created['name'] : $name,
				'site_key' => (string) $created['siteKey'],
			);
			$bag['selected_org'] = '';
			$this->persist($bag);
			$this->flash('success', 'Website added. Live chat is on — open your site to see it.');
			return null;
		}

		/**
		 * @param string                    $method HTTP method.
		 * @param string                    $path Path beginning with /.
		 * @param array<string, mixed>|null $body JSON body.
		 * @return array{data: array<string, mixed>}|array{error: string}
		 */
		private function authorized($method, $path, $body) {
			$bag = $this->bag();
			$auth = $bag['auth'];
			if (!is_array($auth) || empty($auth['refresh_token'])) {
				return array('error' => 'Sign in to ZebChat first.');
			}
			if ($this->accessExpired($auth)) {
				$refreshed = $this->refresh((string) $auth['refresh_token']);
				if (isset($refreshed['error'])) {
					return $refreshed;
				}
				$auth = $refreshed;
			}
			$result = $this->request($method, $path, $body, (string) $auth['access_token']);
			if (isset($result['status']) && (int) $result['status'] === 401) {
				$refreshed = $this->refresh((string) $auth['refresh_token']);
				if (isset($refreshed['error'])) {
					return $refreshed;
				}
				$result = $this->request($method, $path, $body, (string) $refreshed['access_token']);
			}
			if (isset($result['error'])) {
				return array('error' => $result['error']);
			}
			if (isset($result['status']) && (int) $result['status'] >= 400) {
				return array('error' => $this->message($result['data']));
			}
			return array('data' => $result['data']);
		}

		/**
		 * @param string $refreshToken Refresh token.
		 * @return array<string, string>|array{error: string}
		 */
		private function refresh($refreshToken) {
			$result = $this->request('POST', '/auth/refresh', array('refreshToken' => $refreshToken), '');
			if (isset($result['error'])) {
				$bag = $this->bag();
				$bag['auth'] = self::emptyAuth();
				$this->persist($bag);
				return array('error' => $result['error']);
			}
			$data = $result['data'];
			if (empty($data['accessToken']) || empty($data['refreshToken'])) {
				$bag = $this->bag();
				$bag['auth'] = self::emptyAuth();
				$this->persist($bag);
				return array('error' => 'Your ZebChat session expired. Sign in again.');
			}
			$this->storeTokens($data, $this->bag()['auth']);
			$stored = $this->bag()['auth'];
			return is_array($stored) ? $stored : array('error' => 'Your ZebChat session expired. Sign in again.');
		}

		/**
		 * @param array<string, mixed> $tokens Token payload.
		 * @param array<string, mixed> $user User fields, or the previous session.
		 */
		private function storeTokens($tokens, $user) {
			$previous = $this->bag()['auth'];
			if (!is_array($previous)) {
				$previous = array();
			}
			if (!is_array($user)) {
				$user = array();
			}
			$bag = $this->bag();
			$bag['auth'] = array(
				'access_token' => isset($tokens['accessToken']) ? (string) $tokens['accessToken'] : '',
				'access_expires_at' => isset($tokens['accessTokenExpiresAt']) ? (string) $tokens['accessTokenExpiresAt'] : '',
				'refresh_token' => isset($tokens['refreshToken']) ? (string) $tokens['refreshToken'] : '',
				'refresh_expires_at' => isset($tokens['refreshTokenExpiresAt']) ? (string) $tokens['refreshTokenExpiresAt'] : '',
				'email' => isset($user['email']) ? (string) $user['email'] : (isset($previous['email']) ? (string) $previous['email'] : ''),
				'name' => isset($user['name']) ? (string) $user['name'] : (isset($previous['name']) ? (string) $previous['name'] : ''),
			);
			$this->persist($bag);
		}

		/**
		 * @param array<string, mixed> $auth Stored auth.
		 * @return bool
		 */
		private function accessExpired($auth) {
			if (empty($auth['access_token']) || empty($auth['access_expires_at'])) {
				return true;
			}
			$expires = strtotime((string) $auth['access_expires_at']);
			return $expires === false || $expires <= (time() + 60);
		}

		/**
		 * @param string $orgId Public organization id.
		 * @return array{data: array<int, array<string, mixed>>}|array{error: string}
		 */
		private function websites($orgId) {
			$result = $this->authorized('GET', '/organizations/' . rawurlencode($orgId) . '/websites', null);
			if (isset($result['error'])) {
				return $result;
			}
			$list = array();
			foreach ($result['data'] as $key => $row) {
				if (is_int($key) && is_array($row)) {
					$list[] = $row;
				}
			}
			return array('data' => $list);
		}

		/**
		 * @param string $type success or error.
		 * @param string $message Plain sentence.
		 */
		private function flash($type, $message) {
			$bag = $this->bag();
			$bag['notice'] = array('type' => $type, 'message' => $message);
			$this->persist($bag);
		}

		/**
		 * @param array<string, mixed> $me GET /auth/me body.
		 * @param string               $orgId Public organization id.
		 * @return array<string, mixed>|null
		 */
		private function membership($me, $orgId) {
			if (!isset($me['memberships']) || !is_array($me['memberships'])) {
				return null;
			}
			foreach ($me['memberships'] as $membership) {
				if (!is_array($membership)) {
					continue;
				}
				$organization = isset($membership['organization']) && is_array($membership['organization']) ? $membership['organization'] : array();
				if (isset($organization['id']) && (string) $organization['id'] === $orgId) {
					return $membership;
				}
			}
			return null;
		}

		/**
		 * @param array<string, mixed> $membership Membership.
		 * @return bool
		 */
		private function canManage($membership) {
			$permissions = isset($membership['permissions']) && is_array($membership['permissions']) ? $membership['permissions'] : array();
			return in_array('websites:manage', $permissions, true);
		}

		/**
		 * @param string                    $method HTTP method.
		 * @param string                    $path Path.
		 * @param array<string, mixed>|null $body JSON body.
		 * @param string                    $token Bearer token.
		 * @return array{data: array<string, mixed>, status: int}|array{error: string, code?: string}
		 */
		private function request($method, $path, $body, $token) {
			if ($this->transport !== null) {
				$fake = call_user_func($this->transport, $method, $path, $body, $token);
				return $this->decode($method, $path, (int) $fake[0], (string) $fake[1]);
			}
			if (!function_exists('curl_init')) {
				return array('error' => 'This server needs the PHP curl extension to reach ZebChat.');
			}
			$headers = array('Accept: application/json', 'User-Agent: ' . $this->userAgent);
			if ($token !== '') {
				$headers[] = 'Authorization: Bearer ' . $token;
			}
			$payload = null;
			if ($body !== null) {
				$headers[] = 'Content-Type: application/json';
				$payload = json_encode($body);
			}
			$ch = curl_init($this->apiBase . $path);
			if ($ch === false) {
				return array('error' => 'Could not reach ZebChat. Check this server’s connection and try again.');
			}
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 20);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
			if ($payload !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
			}
			$raw = curl_exec($ch);
			$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$failed = curl_errno($ch) !== 0;
			curl_close($ch);
			if ($failed || !is_string($raw)) {
				return array('error' => 'Could not reach ZebChat. Check this server’s connection and try again.');
			}
			return $this->decode($method, $path, $status, $raw);
		}

		/**
		 * @param string $method HTTP method.
		 * @param string $path Path.
		 * @param int    $status HTTP status.
		 * @param string $raw Response body.
		 * @return array{data: array<string, mixed>, status: int}|array{error: string, code?: string}
		 */
		private function decode($method, $path, $status, $raw) {
			$decoded = json_decode($raw, true);
			if (!is_array($decoded)) {
				$decoded = array();
			}
			$authPaths = array('/auth/login', '/auth/login/2fa', '/auth/register', '/auth/refresh');
			if ($status >= 400 && $method === 'POST' && in_array($path, $authPaths, true)) {
				$code = isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : '';
				return array('error' => $this->message($decoded), 'code' => $code);
			}
			return array('data' => $decoded, 'status' => $status);
		}

		/**
		 * @param array<string, mixed> $data Decoded body.
		 * @return string
		 */
		private function message($data) {
			if (isset($data['code']) && $data['code'] === 'mfa_code_invalid') {
				return 'That code is not right. Check your authenticator app and try again.';
			}
			if (isset($data['code']) && $data['code'] === 'mfa_token_invalid') {
				return 'The sign-in expired. Enter your email and password again.';
			}
			if (isset($data['code']) && $data['code'] === 'signups_disabled') {
				return 'New ZebChat accounts are closed right now. Sign in if you already have one.';
			}
			if (isset($data['code']) && $data['code'] === 'email_not_verified') {
				return 'Confirm your email first: open the link we emailed you, then sign in here.';
			}
			if (!isset($data['message'])) {
				return 'ZebChat could not complete that request.';
			}
			$message = $data['message'];
			if (is_array($message)) {
				$message = implode(' ', $message);
			}
			$message = trim(strip_tags((string) $message));
			return $message !== '' ? $message : 'ZebChat could not complete that request.';
		}

		/**
		 * "Check your email": the account must confirm its email before it can sign in.
		 *
		 * @param string $action Form action.
		 * @param string $extraHidden Hidden inputs.
		 * @param string $email Where the link went.
		 * @return string
		 */
		private function renderCheckEmail($action, $extraHidden, $email) {
			$html = '<form method="post" action="' . self::e($action) . '" class="zebchat-card zebchat-verify" id="zebchat-check-email">';
			$html .= $extraHidden;
			$html .= '<input type="hidden" name="zebchat_email" value="' . self::e($email) . '" />';
			$html .= '<h2>Check your email to confirm your account</h2>';
			$html .= '<p>We sent a confirmation link to <strong>' . self::e($email) . '</strong>. Open it to confirm your account, then sign in here.</p>';
			$html .= '<p class="zebchat-muted">Nothing there? Check your spam folder, or send the link again.</p>';
			$html .= '<p class="zebchat-actions"><button class="zebchat-secondary" name="zebchat_action" value="resend" formnovalidate>Resend email</button>';
			$html .= ' <button class="zebchat-link" name="zebchat_action" value="verify_cancel" formnovalidate>Use a different email</button></p></form>';
			return $html;
		}

		/**
		 * @param string $action Form action.
		 * @param string $extraHidden Hidden inputs.
		 * @param string $email Prefilled sign-in email (waiting for confirmation), or ''.
		 * @return string
		 */
		private function renderSignedOut($action, $extraHidden, $email = '') {
			$html = '<div class="zebchat-intro"><h2>Talk to your visitors in real time</h2><ul class="zebchat-benefits">';
			foreach (array('A fast chat widget that matches your brand', 'See who is on your site and which page they are reading', 'Answer from the web dashboard or the iOS and Android apps', 'Free plan to start, no card needed') as $benefit) {
				$html .= '<li><span class="zebchat-check">&#10003;</span>' . self::e($benefit) . '</li>';
			}
			$html .= '</ul></div><div class="zebchat-grid">';
			$html .= '<form method="post" action="' . self::e($action) . '" class="zebchat-card">';
			$html .= $extraHidden;
			$html .= '<h2>Create a free account</h2><p class="zebchat-muted">Takes a minute. You can add this site right after.</p>';
			$html .= $this->field('zebchat-name', 'Your name', '<input id="zebchat-name" name="zebchat_name" type="text" required maxlength="80" autocomplete="name" />');
			$html .= $this->field('zebchat-signup-email', 'Work email', '<input id="zebchat-signup-email" name="zebchat_email" type="email" required maxlength="200" autocomplete="email" />');
			$html .= $this->field('zebchat-signup-password', 'Password', '<input id="zebchat-signup-password" name="zebchat_password" type="password" required minlength="8" maxlength="128" autocomplete="new-password" />');
			$html .= $this->field('zebchat-org', 'Company or site name', '<input id="zebchat-org" name="zebchat_organization" type="text" required maxlength="80" />');
			$html .= '<p class="zebchat-actions"><button class="zebchat-primary zebchat-wide" name="zebchat_action" value="register">Create account</button></p>';
			$html .= '<p class="zebchat-small">By creating an account you agree to the <a href="https://www.zebchat.com/terms/" target="_blank" rel="noopener noreferrer">Terms</a> and <a href="https://www.zebchat.com/privacy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.</p></form>';
			$html .= '<form method="post" action="' . self::e($action) . '" class="zebchat-card">';
			$html .= $extraHidden;
			$html .= '<h2>Already use ZebChat?</h2><p class="zebchat-muted">Sign in with your ZebChat account.</p>';
			$html .= $this->field('zebchat-email', 'Email', '<input id="zebchat-email" name="zebchat_email" type="email" required maxlength="200" autocomplete="username" value="' . self::e($email) . '" />');
			$html .= $this->field('zebchat-password', 'Password', '<input id="zebchat-password" name="zebchat_password" type="password" required maxlength="128" autocomplete="current-password" />');
			$html .= '<p class="zebchat-actions"><button class="zebchat-secondary zebchat-wide" name="zebchat_action" value="login">Sign in</button></p>';
			$html .= '<p class="zebchat-small"><a href="' . self::e($this->appUrl . '/forgot-password/') . '" target="_blank" rel="noopener noreferrer">Forgot your password?</a></p></form>';
			$html .= '</div>';
			return $html;
		}

		/**
		 * @param string $action Form action.
		 * @param string $extraHidden Hidden inputs.
		 * @param string $email Account email.
		 * @return string
		 */
		private function renderMfa($action, $extraHidden, $email) {
			$html = '<form method="post" action="' . self::e($action) . '" class="zebchat-card">';
			$html .= $extraHidden;
			$html .= '<h2>Two-factor authentication</h2>';
			$html .= '<p>Enter the code from your authenticator app for ' . self::e($email) . '.</p>';
			$html .= $this->field('zebchat-code', 'Code', '<input id="zebchat-code" name="zebchat_code" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="20" />');
			$html .= '<p class="zebchat-actions"><button class="zebchat-primary" name="zebchat_action" value="mfa">Verify and sign in</button> <button class="zebchat-link" name="zebchat_action" value="mfa_cancel" formnovalidate>Use another account</button></p></form>';
			return $html;
		}

		/**
		 * @param string $action Form action.
		 * @param string $extraHidden Hidden inputs.
		 * @param string $domain Hostname.
		 * @param string $siteName Suggested name.
		 * @return string
		 */
		private function renderSignedIn($action, $extraHidden, $domain, $siteName) {
			$bag = $this->bag();
			$auth = is_array($bag['auth']) ? $bag['auth'] : array();
			$widget = is_array($bag['widget']) ? $bag['widget'] : array();
			$me = $this->authorized('GET', '/auth/me', null);
			if (isset($me['error'])) {
				return '<p class="zebchat-notice zebchat-notice-error" role="alert">' . self::e($me['error']) . '</p>';
			}
			$memberships = isset($me['data']['memberships']) && is_array($me['data']['memberships']) ? $me['data']['memberships'] : array();
			$orgId = isset($bag['selected_org']) && is_string($bag['selected_org']) && $bag['selected_org'] !== ''
				? $bag['selected_org']
				: (isset($widget['organization_id']) ? (string) $widget['organization_id'] : '');
			if ($orgId === '' && isset($memberships[0]) && is_array($memberships[0])) {
				$first = isset($memberships[0]['organization']) && is_array($memberships[0]['organization']) ? $memberships[0]['organization'] : array();
				$orgId = isset($first['id']) ? (string) $first['id'] : '';
			}
			if (count($memberships) === 0) {
				return $this->renderCreateOrganization($action, $extraHidden, $siteName, isset($auth['email']) ? (string) $auth['email'] : '');
			}
			$membership = $this->membership($me['data'], $orgId);
			if ($membership === null && isset($memberships[0]) && is_array($memberships[0])) {
				// The saved organization is gone (removed from it): fall back to the first one.
				$first = isset($memberships[0]['organization']) && is_array($memberships[0]['organization']) ? $memberships[0]['organization'] : array();
				$orgId = isset($first['id']) ? (string) $first['id'] : '';
				$membership = $this->membership($me['data'], $orgId);
			}
			$websites = array();
			if ($orgId !== '') {
				$listed = $this->websites($orgId);
				if (isset($listed['data'])) {
					$websites = $listed['data'];
				}
			}
			$match = null;
			$names = self::allowedHosts($domain);
			foreach ($websites as $website) {
				$siteDomain = is_array($website) && isset($website['domain']) ? strtolower((string) $website['domain']) : '';
				if ($siteDomain !== '' && in_array($siteDomain, $names, true)) {
					$match = $website;
					break;
				}
			}
			$selected = isset($widget['website_id']) ? (string) $widget['website_id'] : '';
			if ($selected === '' && is_array($match) && isset($match['id'])) {
				$selected = (string) $match['id'];
			}
			$html = '<form method="post" action="' . self::e($action) . '" class="zebchat-card" id="zebchat-settings">';
			$html .= $extraHidden;
			$html .= '<div class="zebchat-card-head"><h2>Live chat on this site</h2><span class="zebchat-muted">' . self::e(isset($auth['email']) ? (string) $auth['email'] : '') . '</span></div>';
			if (count($memberships) > 1) {
				$options = '';
				foreach ($memberships as $item) {
					if (!is_array($item)) {
						continue;
					}
					$organization = isset($item['organization']) && is_array($item['organization']) ? $item['organization'] : array();
					$id = isset($organization['id']) ? (string) $organization['id'] : '';
					$label = isset($organization['name']) ? (string) $organization['name'] : $id;
					$options .= '<option value="' . self::e($id) . '"' . ($orgId === $id ? ' selected' : '') . '>' . self::e($label) . '</option>';
				}
				$html .= $this->field('zebchat-organization', 'Organization', '<select id="zebchat-organization" name="zebchat_organization" data-zebchat-switch>' . $options . '</select>');
			} else {
				$html .= '<input type="hidden" name="zebchat_organization" value="' . self::e($orgId) . '" />';
			}
			$options = '<option value="">Select a website</option>';
			foreach ($websites as $website) {
				if (!is_array($website)) {
					continue;
				}
				$id = isset($website['id']) ? (string) $website['id'] : '';
				$siteDomain = isset($website['domain']) ? (string) $website['domain'] : '';
				$label = isset($website['name']) ? (string) $website['name'] : $siteDomain;
				if ($siteDomain !== '') {
					$label .= ' — ' . $siteDomain;
				}
				$options .= '<option value="' . self::e($id) . '"' . ($selected === $id ? ' selected' : '') . '>' . self::e($label) . '</option>';
			}
			$html .= $this->field('zebchat-website', 'ZebChat website', '<select id="zebchat-website" name="zebchat_website">' . $options . '</select>');
			$checked = !empty($widget['enabled']) || ($selected !== '' && (string) (isset($widget['website_id']) ? $widget['website_id'] : '') === '' && $match !== null);
			$html .= '<label class="zebchat-toggle"><input type="checkbox" name="zebchat_enabled" value="1"' . ($checked ? ' checked' : '') . ' /><span class="zebchat-switch"></span>';
			$html .= '<span><strong>Show the chat widget on this site</strong><br /><span class="zebchat-muted">Visitors see the chat button on every page. Turn it off any time.</span></span></label>';
			$html .= '<p class="zebchat-actions"><button class="zebchat-primary" name="zebchat_action" value="save">Save changes</button>';
			$html .= '<button class="zebchat-link zebchat-danger" name="zebchat_action" value="disconnect" formnovalidate>Disconnect account</button></p>';
			$key = isset($widget['site_key']) ? (string) $widget['site_key'] : '';
			if (1 === preg_match('/\Azc_[A-Za-z0-9]+\z/', $key)) {
				$snippet = '<script src="' . $this->scriptUrl . '" data-site="' . $key . '" async></script>';
				$html .= '<details><summary>Install code</summary><p>This extension adds the chat for you while it is on. Use the code only on another site.</p>';
				$html .= '<textarea readonly rows="3">' . self::e($snippet) . '</textarea></details>';
			}
			$html .= '</form>';
			if ($match === null && is_array($membership) && $this->canManage($membership)) {
				$html .= '<form method="post" action="' . self::e($action) . '" class="zebchat-card zebchat-add">';
				$html .= $extraHidden;
				$html .= '<input type="hidden" name="zebchat_organization" value="' . self::e($orgId) . '" />';
				$html .= '<h2>Add this site to ZebChat</h2>';
				$html .= '<p>Creates a ZebChat website for ' . self::e($domain) . ' and turns the chat on.</p>';
				$html .= $this->field('zebchat-website-name', 'Website name', '<input id="zebchat-website-name" name="zebchat_website_name" type="text" maxlength="80" value="' . self::e($siteName) . '" />');
				$html .= '<p class="zebchat-actions"><button class="zebchat-primary" name="zebchat_action" value="create">Add site and turn on chat</button></p></form>';
			}
			return $html;
		}

		/**
		 * "Name your organization": the account has no organization yet.
		 *
		 * @param string $action Form action.
		 * @param string $extraHidden Hidden inputs.
		 * @param string $siteName Suggested organization name.
		 * @param string $email Account email.
		 * @return string
		 */
		private function renderCreateOrganization($action, $extraHidden, $siteName, $email) {
			$html = '<form method="post" action="' . self::e($action) . '" class="zebchat-card" id="zebchat-create-organization">';
			$html .= $extraHidden;
			$html .= '<div class="zebchat-card-head"><h2>Name your organization</h2><span class="zebchat-muted">' . self::e($email) . '</span></div>';
			$html .= '<p class="zebchat-muted">Your ZebChat account has no organization yet. Create one to add this site; you will be its owner.</p>';
			$html .= $this->field('zebchat-organization-name', 'Organization name', '<input id="zebchat-organization-name" name="zebchat_organization_name" type="text" required minlength="2" maxlength="80" value="' . self::e($siteName) . '" />');
			$html .= '<p class="zebchat-actions"><button class="zebchat-primary" name="zebchat_action" value="create_org">Create organization</button>';
			$html .= '<button class="zebchat-link zebchat-danger" name="zebchat_action" value="disconnect" formnovalidate>Disconnect account</button></p></form>';
			return $html;
		}

		/**
		 * @param string $websiteId Public website id.
		 * @return string
		 */
		private function renderLinks($websiteId) {
			$pages = array(
				array($this->appUrl . '/inbox/', 'Inbox'),
				array($this->appUrl . '/visitors/', 'Visitors'),
				array($this->appUrl . '/live-map/', 'Live map'),
				array($this->appUrl . '/reports/', 'Reports'),
				array($this->appUrl . '/team/', 'Team'),
			);
			$appearance = 1 === preg_match('/\Aweb_[0-9a-f-]{36}\z/i', $websiteId)
				? $this->appUrl . '/websites/' . rawurlencode($websiteId) . '/'
				: $this->appUrl . '/websites/';
			$pages[] = array($appearance, 'Widget look & forms');
			$html = '<div class="zebchat-card"><h2>Open ZebChat</h2><ul class="zebchat-links">';
			foreach ($pages as $page) {
				$html .= '<li><a href="' . self::e($page[0]) . '" target="_blank" rel="noopener noreferrer">' . self::e($page[1]) . '</a></li>';
			}
			$html .= '</ul><a class="zebchat-button zebchat-wide" href="' . self::e($this->appUrl . '/') . '" target="_blank" rel="noopener noreferrer">Open the dashboard</a></div>';
			$html .= '<div class="zebchat-card"><h2>Answer from your phone</h2><p class="zebchat-muted">Free iOS and Android apps with push notifications for new chats.</p><p class="zebchat-apps">';
			$html .= '<a class="zebchat-outline" href="' . self::e(self::APP_STORE) . '" target="_blank" rel="noopener noreferrer">App Store</a>';
			$html .= '<a class="zebchat-outline" href="' . self::e(self::PLAY_STORE) . '" target="_blank" rel="noopener noreferrer">Google Play</a>';
			$html .= '</p></div>';
			return $html;
		}

		/**
		 * @param string $id Control id.
		 * @param string $label Label.
		 * @param string $control Control HTML built with escaped values.
		 * @return string
		 */
		private function field($id, $label, $control) {
			return '<p class="zebchat-field"><label for="' . self::e($id) . '">' . self::e($label) . '</label>' . $control . '</p>';
		}

		/**
		 * @return string
		 */
		private function css() {
			return '.zebchat{--zc-primary:#0154f9;--zc-text:#172a3e;--zc-muted:#5b6b7f;--zc-border:#e2e8f0;max-width:1080px;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:var(--zc-text);box-sizing:border-box}.zebchat *,.zebchat *::before,.zebchat *::after{box-sizing:border-box}.zebchat h1{margin:0;font-size:22px;font-weight:700;line-height:1.2}.zebchat h2{margin:0 0 4px;font-size:16px;font-weight:600}.zebchat :where(p){margin:0}.zebchat-top{display:flex;align-items:center;gap:14px;margin:0 0 16px;padding:18px 22px;background:#fff;border:1px solid var(--zc-border);border-radius:12px}.zebchat-top svg{flex:none}.zebchat-top-text{flex:1;min-width:0}.zebchat-top-text p,.zebchat-muted{color:var(--zc-muted)}.zebchat-status{display:inline-flex;flex:none;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;font-size:13px;font-weight:600;background:#f1f5f9;color:var(--zc-muted)}.zebchat-dot{width:8px;height:8px;border-radius:50%;background:#94a3b8}.zebchat-status-live{background:#dcfce7;color:#166534}.zebchat-status-live .zebchat-dot{background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.2)}.zebchat-notice{margin:0 0 16px;padding:10px 14px;border-radius:10px;border-left:4px solid #16a34a;background:#f0fdf4}.zebchat-notice-error{border-left-color:#dc2626;background:#fef2f2}.zebchat-intro{margin:0 0 16px}.zebchat-intro h2{font-size:18px;margin-bottom:8px}.zebchat-benefits{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:6px 18px}.zebchat-check{color:#16a34a;font-weight:700;margin-right:6px}.zebchat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}.zebchat-columns{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}.zebchat-main,.zebchat-side{display:grid;gap:16px;min-width:0}@media (max-width:960px){.zebchat-columns{grid-template-columns:minmax(0,1fr)}}.zebchat-card{min-width:0;padding:20px 22px;background:#fff;border:1px solid var(--zc-border);border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}.zebchat-add{border-style:dashed;background:#fafcff}.zebchat-verify{margin:0 0 16px;border-color:#93b4fd;background:#f5f8ff}.zebchat-verify p{margin:8px 0 0}.zebchat-card-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:4px 12px;margin-bottom:6px}.zebchat-card-head span{overflow-wrap:anywhere}.zebchat-field{display:grid;gap:6px;margin:14px 0 0}.zebchat-field label{font-weight:600}.zebchat input[type=text],.zebchat input[type=email],.zebchat input[type=password],.zebchat select,.zebchat textarea{width:100%;max-width:100%;min-height:38px;padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:var(--zc-text);font:inherit}.zebchat textarea{font-family:Consolas,Monaco,monospace;font-size:12px}.zebchat-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:18px 0 0}.zebchat :where(button),.zebchat-button,.zebchat-outline{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 16px;border-radius:8px;border:1px solid transparent;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.zebchat-primary,.zebchat-button{background:var(--zc-primary);color:#fff}.zebchat-primary:hover,.zebchat-button:hover{background:#0043cc;color:#fff}.zebchat-secondary,.zebchat-outline{background:#fff;color:var(--zc-primary);border-color:var(--zc-primary)}.zebchat-wide{width:100%}.zebchat-link{background:transparent;color:var(--zc-primary);padding:0;min-height:0;border:0;text-decoration:underline}.zebchat-danger{margin-left:auto;color:#b32d2e}.zebchat-small{margin-top:8px;font-size:12px;color:var(--zc-muted)}.zebchat-small a{color:var(--zc-primary)}.zebchat-toggle{display:flex;align-items:flex-start;gap:12px;margin:18px 0 4px;padding:14px;border:1px solid var(--zc-border);border-radius:10px;cursor:pointer;position:relative}.zebchat-toggle input{position:absolute;opacity:0;width:1px;height:1px}.zebchat-switch{position:relative;flex:none;width:40px;height:22px;margin-top:1px;border-radius:999px;background:#cbd5e1;transition:background .15s}.zebchat-switch::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .15s}.zebchat-toggle input:checked+.zebchat-switch{background:var(--zc-primary)}.zebchat-toggle input:checked+.zebchat-switch::after{transform:translateX(18px)}.zebchat-toggle input:focus-visible+.zebchat-switch{box-shadow:0 0 0 2px #fff,0 0 0 4px var(--zc-primary)}.zebchat details{margin-top:18px;padding-top:14px;border-top:1px solid var(--zc-border)}.zebchat summary{cursor:pointer;font-weight:600}.zebchat details p{margin:8px 0;color:var(--zc-muted)}.zebchat-links{list-style:none;margin:10px 0 14px;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:6px}.zebchat-links a{display:block;padding:8px 10px;border-radius:8px;background:#f8fafc;color:var(--zc-text);text-decoration:none}.zebchat-links a:hover{background:#e8efff;color:var(--zc-primary)}.zebchat-apps{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}@media (max-width:600px){.zebchat-top{flex-wrap:wrap}.zebchat-links{grid-template-columns:1fr}}';
		}

		/**
		 * @return string
		 */
		private function script() {
			return 'document.querySelectorAll("[data-zebchat-switch]").forEach(function(select){select.addEventListener("change",function(){var input=document.createElement("input");input.type="hidden";input.name="zebchat_action";input.value="switch";select.form.appendChild(input);select.form.submit();});});';
		}

		/**
		 * @param array<string, mixed> $stored Stored bag.
		 * @return array<string, mixed>
		 */
		private static function normalize($stored) {
			$auth = isset($stored['auth']) && is_array($stored['auth']) ? $stored['auth'] : array();
			$widget = isset($stored['widget']) && is_array($stored['widget']) ? $stored['widget'] : array();
			$mfa = isset($stored['mfa']) && is_array($stored['mfa']) ? $stored['mfa'] : array();
			$notice = isset($stored['notice']) && is_array($stored['notice']) ? $stored['notice'] : array();
			$verify = isset($stored['verify']) && is_array($stored['verify']) ? $stored['verify'] : array();
			return array(
				'auth' => array_merge(self::emptyAuth(), $auth),
				'widget' => array(
					'enabled' => !empty($widget['enabled']),
					'organization_id' => isset($widget['organization_id']) ? (string) $widget['organization_id'] : '',
					'website_id' => isset($widget['website_id']) ? (string) $widget['website_id'] : '',
					'website_name' => isset($widget['website_name']) ? (string) $widget['website_name'] : '',
					'site_key' => isset($widget['site_key']) ? (string) $widget['site_key'] : '',
				),
				'mfa' => array(
					'token' => isset($mfa['token']) ? (string) $mfa['token'] : '',
					'email' => isset($mfa['email']) ? (string) $mfa['email'] : '',
					'expires' => isset($mfa['expires']) ? (int) $mfa['expires'] : 0,
				),
				'notice' => array(
					'type' => isset($notice['type']) ? (string) $notice['type'] : '',
					'message' => isset($notice['message']) ? (string) $notice['message'] : '',
				),
				'selected_org' => isset($stored['selected_org']) ? (string) $stored['selected_org'] : '',
				'verify' => array(
					'email' => isset($verify['email']) ? (string) $verify['email'] : '',
				),
			);
		}

		/**
		 * @return array<string, string>
		 */
		private static function emptyAuth() {
			return array(
				'access_token' => '',
				'access_expires_at' => '',
				'refresh_token' => '',
				'refresh_expires_at' => '',
				'email' => '',
				'name' => '',
			);
		}

		/**
		 * @param string $value Raw text.
		 * @return string
		 */
		private static function e($value) {
			return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
		}
	}
}
