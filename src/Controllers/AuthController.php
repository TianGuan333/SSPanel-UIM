<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\EmailVerify;
use App\Models\InviteCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth;
use App\Services\Captcha;
use App\Services\Mail;
use App\Utils\Check;
use App\Utils\GA;
use App\Utils\Hash;
use App\Utils\ResponseHelper;
use App\Utils\TelegramSessionManager;
use App\Utils\Tools;
use Exception;
use Ramsey\Uuid\Uuid;
use Slim\Http\Request;
use Slim\Http\Response;
use voku\helper\AntiXSS;

/**
 *  AuthController
 */
final class AuthController extends BaseController
{
    /**
     * @param array     $args
     */
    public function login(Request $request, Response $response, array $args)
    {
        $captcha = Captcha::generate();

        if ($_ENV['enable_telegram_login'] === true) {
            $login_text = TelegramSessionManager::addLoginSession();
            $login = explode('|', $login_text);
            $login_token = $login[0];
            $login_number = $login[1];
        } else {
            $login_token = '';
            $login_number = '';
        }

        if (Setting::obtain('enable_login_captcha') === true) {
            $geetest_html = $captcha['geetest'];
        } else {
            $geetest_html = null;
        }

        return $this->view()
            ->assign('login_token', $login_token)
            ->assign('geetest_html', $geetest_html)
            ->assign('login_number', $login_number)
            ->assign('base_url', $_ENV['baseUrl'])
            ->assign('telegram_bot', $_ENV['telegram_bot'])
            ->assign('recaptcha_sitekey', $captcha['recaptcha'])
            ->display('auth/login.tpl');
    }

    /**
     * @param array     $args
     */
    public function getCaptcha(Request $request, Response $response, array $args)
    {
        $captcha = Captcha::generate();
        return $response->withJson([
            'recaptchaKey' => $captcha['recaptcha'],
            'GtSdk' => $captcha['geetest'],
            'respon' => 1,
        ]);
    }

    /**
     * @param array     $args
     */
    public function loginHandle(Request $request, Response $response, array $args)
    {
        $code = $request->getParam('code');
        $passwd = $request->getParam('passwd');
        $rememberMe = $request->getParam('remember_me');
        $email = strtolower(trim($request->getParam('email')));

        if (Setting::obtain('enable_login_captcha') === true) {
            $ret = Captcha::verify($request->getParams());
            if (! $ret) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '??????????????????????????????????????????????????????????????????',
                ]);
            }
        }

        $user = User::where('email', $email)->first();
        if ($user === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '???????????????',
            ]);
        }

        if (! Hash::checkPassword($user->pass, $passwd)) {
            // ??????????????????
            $user->collectLoginIP($_SERVER['REMOTE_ADDR'], 1);
            return $response->withJson([
                'ret' => 0,
                'msg' => '????????????????????????',
            ]);
        }

        if ($user->ga_enable === 1) {
            $ga = new GA();
            $rcode = $ga->verifyCode($user->ga_token, $code);
            if (! $rcode) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '??????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????',
                ]);
            }
        }

        $time = 3600 * 24;
        if ($rememberMe) {
            $time = 3600 * 24 * ($_ENV['rememberMeDuration'] ?: 7);
        }

        Auth::login($user->id, $time);
        // ??????????????????
        $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

        return $response->withJson([
            'ret' => 1,
            'msg' => '????????????',
        ]);
    }

    /**
     * @param array     $args
     */
    public function qrcodeLoginHandle(Request $request, Response $response, array $args)
    {
        $token = $request->getParam('token');
        $number = $request->getParam('number');

        $ret = TelegramSessionManager::step2VerifyLoginSession($token, $number);
        if ($ret === 0) {
            return ResponseHelper::error($response, '???????????????????????????');
        }

        $user = User::find($ret);

        Auth::login($user->id, 3600 * 24);
        // ??????????????????
        $user->collectLoginIP($_SERVER['REMOTE_ADDR']);
        return ResponseHelper::successfully($response, '????????????');
    }

    /**
     * @param array     $args
     */
    public function register(Request $request, Response $response, $next)
    {
        $ary = $request->getQueryParams();
        $code = '';
        if (isset($ary['code'])) {
            $antiXss = new AntiXSS();
            $code = $antiXss->xss_clean($ary['code']);
        }

        $captcha = Captcha::generate();

        if ($_ENV['enable_telegram_login'] === true) {
            $login_text = TelegramSessionManager::addLoginSession();
            $login = explode('|', $login_text);
            $login_token = $login[0];
            $login_number = $login[1];
        } else {
            $login_token = '';
            $login_number = '';
        }

        if (Setting::obtain('enable_reg_captcha') === true) {
            $geetest_html = $captcha['geetest'];
        } else {
            $geetest_html = null;
        }

        return $this->view()
            ->assign('code', $code)
            ->assign('base_url', $_ENV['baseUrl'])
            ->assign('login_token', $login_token)
            ->assign('login_number', $login_number)
            ->assign('geetest_html', $geetest_html)
            ->assign('telegram_bot', $_ENV['telegram_bot'])
            ->assign('recaptcha_sitekey', $captcha['recaptcha'])
            ->assign('enable_email_verify', Setting::obtain('reg_email_verify'))
            ->display('auth/register.tpl');
    }

    /**
     * @param array     $args
     */
    public function sendVerify(Request $request, Response $response, $next)
    {
        if (Setting::obtain('reg_email_verify')) {
            $email = trim($request->getParam('email'));
            $email = strtolower($email);
            if ($email === '') {
                return ResponseHelper::error($response, '???????????????');
            }
            // check email format
            $check_res = Check::isEmailLegal($email);
            if ($check_res['ret'] === 0) {
                return $response->withJson($check_res);
            }
            $user = User::where('email', $email)->first();
            if ($user !== null) {
                return ResponseHelper::error($response, '?????????????????????');
            }
            $ipcount = EmailVerify::where('ip', '=', $_SERVER['REMOTE_ADDR'])
                ->where('expire_in', '>', time())
                ->count();
            if ($ipcount >= Setting::obtain('email_verify_ip_limit')) {
                return ResponseHelper::error($response, '???IP??????????????????');
            }
            $mailcount = EmailVerify::where('email', '=', $email)
                ->where('expire_in', '>', time())
                ->count();
            if ($mailcount >= 3) {
                return ResponseHelper::error($response, '???????????????????????????');
            }
            $code = Tools::genRandomNum(6);
            $ev = new EmailVerify();
            $ev->expire_in = time() + Setting::obtain('email_verify_ttl');
            $ev->ip = $_SERVER['REMOTE_ADDR'];
            $ev->email = $email;
            $ev->code = $code;
            $ev->save();
            try {
                Mail::send(
                    $email,
                    $_ENV['appName'] . '- ????????????',
                    'auth/verify.tpl',
                    [
                        'code' => $code,
                        'expire' => date('Y-m-d H:i:s', time() + Setting::obtain('email_verify_ttl')),
                    ],
                    []
                );
            } catch (Exception $e) {
                return ResponseHelper::error($response, '????????????????????????????????????????????????');
            }
            return ResponseHelper::successfully($response, '??????????????????????????????????????????');
        }
        return ResponseHelper::error($response, ' ???????????????');
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    public function registerHelper($response, $name, $email, $passwd, $code, $imtype, $imvalue, $telegram_id)
    {
        if (Setting::obtain('reg_mode') === 'close') {
            return ResponseHelper::error($response, '???????????????????????????');
        }

        if ($code === '') {
            return ResponseHelper::error($response, '???????????????????????????');
        }

        $c = InviteCode::where('code', $code)->first();
        if ($c === null) {
            if (Setting::obtain('reg_mode') === 'invite') {
                return ResponseHelper::error($response, '????????????????????????');
            }
        } elseif ($c->user_id !== 0) {
            $gift_user = User::where('id', $c->user_id)->first();
            if ($gift_user === null) {
                return ResponseHelper::error($response, '??????????????????');
            }

            if ($gift_user->invite_num === 0) {
                return ResponseHelper::error($response, '??????????????????');
            }
        }

        $configs = Setting::getClass('register');
        // do reg user
        $user = new User();
        $antiXss = new AntiXSS();
        $current_timestamp = time();

        $user->user_name = $antiXss->xss_clean($name);
        $user->email = $email;
        $user->remark = '';
        $user->pass = Hash::passwordHash($passwd);
        $user->passwd = Tools::genRandomChar(16);
        $user->uuid = Uuid::uuid3(Uuid::NAMESPACE_DNS, $email . '|' . $current_timestamp);
        $user->port = Tools::getAvPort();
        $user->t = 0;
        $user->u = 0;
        $user->d = 0;
        $user->method = $configs['sign_up_for_method'];
        $user->protocol = $configs['sign_up_for_protocol'];
        $user->protocol_param = $configs['sign_up_for_protocol_param'];
        $user->obfs = $configs['sign_up_for_obfs'];
        $user->obfs_param = $configs['sign_up_for_obfs_param'];
        $user->forbidden_ip = $_ENV['reg_forbidden_ip'];
        $user->forbidden_port = $_ENV['reg_forbidden_port'];
        $user->im_type = $imtype;
        $user->im_value = $antiXss->xss_clean($imvalue);

        $user->transfer_enable = Tools::toGB($configs['sign_up_for_free_traffic']);
        $user->invite_num = $configs['sign_up_for_invitation_codes'];
        $user->auto_reset_day = $_ENV['free_user_reset_day'];
        $user->auto_reset_bandwidth = $_ENV['free_user_reset_bandwidth'];
        $user->money = 0;
        $user->sendDailyMail = $configs['sign_up_for_daily_report'];

        //dumplin???????????????????????????????????????
        $user->ref_by = 0;
        if ($c !== null && $c->user_id !== 0) {
            $invitation = Setting::getClass('invite');
            // ???????????????
            $user->ref_by = $c->user_id;
            $user->money = $invitation['invitation_to_register_balance_reward'];
            // ?????????????????????
            $gift_user->transfer_enable += $invitation['invitation_to_register_traffic_reward'] * 1024 * 1024 * 1024;
            if ($gift_user->invite_num - 1 >= 0) {
                --$gift_user->invite_num;
                // ?????????????????????????????????????????? -1 ????????????
            }
            $gift_user->save();
        }

        if ($telegram_id) {
            $user->telegram_id = $telegram_id;
        }

        $ga = new GA();
        $secret = $ga->createSecret();
        $user->ga_token = $secret;
        $user->ga_enable = 0;
        $user->class_expire = date('Y-m-d H:i:s', time() + $configs['sign_up_for_class_time'] * 86400);
        $user->class = $configs['sign_up_for_class'];
        $user->node_connector = $configs['connection_device_limit'];
        $user->node_speedlimit = $configs['connection_rate_limit'];
        $user->expire_in = date('Y-m-d H:i:s', time() + $configs['sign_up_for_free_time'] * 86400);
        $user->reg_date = date('Y-m-d H:i:s');
        $user->reg_ip = $_SERVER['REMOTE_ADDR'];
        $user->theme = $_ENV['theme'];
        $groups = explode(',', $_ENV['random_group']);
        $user->node_group = $groups[array_rand($groups)];

        if ($user->save()) {
            Auth::login($user->id, 3600);
            $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

            return ResponseHelper::successfully($response, '???????????????????????????????????????');
        }

        return ResponseHelper::error($response, '????????????');
    }

    /**
     * @param array     $args
     */
    public function registerHandle(Request $request, Response $response, array $args)
    {
        if (Setting::obtain('reg_mode') === 'close') {
            return ResponseHelper::error($response, '??????????????????');
        }

        $name = $request->getParam('name');
        $email = $request->getParam('email');
        $email = trim($email);
        $email = strtolower($email);
        $passwd = $request->getParam('passwd');
        $repasswd = $request->getParam('repasswd');
        $code = trim($request->getParam('code'));

        if ($_ENV['enable_reg_im'] === true) {
            $imtype = $request->getParam('im_type');
            $imvalue = $request->getParam('im_value');
            if ($imtype === '' || $imvalue === '') {
                return ResponseHelper::error($response, '???????????????????????????');
            }
            $user = User::where('im_value', $imvalue)->where('im_type', $imtype)->first();
            if ($user !== null) {
                return ResponseHelper::error($response, '????????????????????????');
            }
        } else {
            $imtype = 1;
            $imvalue = '';
        }

        if (Setting::obtain('enable_reg_captcha') === true) {
            $ret = Captcha::verify($request->getParams());
            if (! $ret) {
                return ResponseHelper::error($response, '??????????????????????????????????????????????????????????????????');
            }
        }

        // check email format
        $check_res = Check::isEmailLegal($email);
        if ($check_res['ret'] === 0) {
            return $response->withJson($check_res);
        }
        // check email
        $user = User::where('email', $email)->first();
        if ($user !== null) {
            return ResponseHelper::error($response, '????????????????????????');
        }

        if (Setting::obtain('reg_email_verify')) {
            $email_code = trim($request->getParam('emailcode'));
            $mailcount = EmailVerify::where('email', '=', $email)
                ->where('code', '=', $email_code)
                ->where('expire_in', '>', time())
                ->first();
            if ($mailcount === null) {
                return ResponseHelper::error($response, '??????????????????????????????');
            }
        }

        // check pwd length
        if (strlen($passwd) < 8) {
            return ResponseHelper::error($response, '???????????????8???');
        }

        // check pwd re
        if ($passwd !== $repasswd) {
            return ResponseHelper::error($response, '????????????????????????');
        }

        if (Setting::obtain('reg_email_verify')) {
            EmailVerify::where('email', $email)->delete();
        }

        return $this->registerHelper($response, $name, $email, $passwd, $code, $imtype, $imvalue, 0);
    }

    /**
     * @param array     $args
     */
    public function logout(Request $request, Response $response, $next)
    {
        Auth::logout();
        return $response->withStatus(302)
            ->withHeader('Location', '/auth/login');
    }

    /**
     * @param array     $args
     */
    public function qrcodeCheck(Request $request, Response $response, array $args)
    {
        $token = $request->getParam('token');
        $number = $request->getParam('number');
        $user = Auth::getUser();
        if ($user->isLogin) {
            return ResponseHelper::error($response, '???????????????');
        }
        if ($_ENV['enable_telegram_login'] === true) {
            $ret = TelegramSessionManager::checkLoginSession($token, $number);
            return $response->withJson([
                'ret' => $ret,
            ]);
        }
        return ResponseHelper::error($response, '????????? QRCode ??????');
    }

    /**
     * @param array     $args
     */
    public function telegramOauth(Request $request, Response $response, array $args)
    {
        if ($_ENV['enable_telegram_login'] === true) {
            $auth_data = $request->getQueryParams();
            if ($this->telegramOauthCheck($auth_data) === true) { // Looks good, proceed.
                $telegram_id = $auth_data['id'];
                $user = User::query()->where('telegram_id', $telegram_id)->firstOrFail(); // Welcome Back :)
                if ($user === null) {
                    return $this->view()
                        ->assign('title', '???????????????????????????????????????Telegram????????????????????????')
                        ->assign('message', '??????????????????????????????????????????')
                        ->assign('redirect', '/auth/login')
                        ->display('telegram_error.tpl');
                }
                Auth::login($user->id, 3600);
                $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

                return $this->view()
                    ->assign('title', '????????????')
                    ->assign('message', '?????????????????????')
                    ->assign('redirect', '/user')
                    ->display('telegram_success.tpl');
            }
            return $this->view()
                ->assign('title', '?????????????????????????????????')
                ->assign('message', '??????????????????????????????????????????')
                ->assign('redirect', '/auth/login')
                ->display('telegram_error.tpl');
        }
        return $response->withRedirect('/404');
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    private function telegramOauthCheck($auth_data)
    {
        $check_hash = $auth_data['hash'];
        $bot_token = $_ENV['telegram_token'];
        unset($auth_data['hash']);
        $data_check_arr = [];
        foreach ($auth_data as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        sort($data_check_arr);
        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = hash('sha256', $bot_token, true);
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);
        if (strcmp($hash, $check_hash) !== 0) {
            return false; // Bad Data :(
        }
        if (time() - $auth_data['auth_date'] > 300) { // Expire @ 5mins
            return false;
        }
        return true; // Good to Go
    }
}
