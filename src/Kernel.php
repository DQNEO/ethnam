<?php
/**
 *  Kernel.php
 *
 *  @author     Masaki Fujimoto <fujimoto@php.net>
 *  @license    http://www.opensource.org/licenses/bsd-license.php The BSD License
 *  @package    Ethna
 *  @version    $Id$
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

// vim: foldmethod=marker
/**
 *  ClassFactory.php
 *
 *  @author     Masaki Fujimoto <fujimoto@php.net>
 *  @license    http://www.opensource.org/licenses/bsd-license.php The BSD License
 *  @package    Ethna
 *  @version    $Id$
 */

// {{{ Ethna_ClassFactory
/**
 *  Ethnaフレームワークのオブジェクト生成ゲートウェイ
 *
 *  DIコンテナか、ということも考えましたがEthnaではこの程度の単純なものに
 *  留めておきます。アプリケーションレベルDIしたい場合はフィルタチェインを
 *  使って実現することも出来ます。
 *
 *  @author     Masaki Fujimoto <fujimoto@php.net>
 *  @access     public
 *  @package    Ethna
 */
class Ethna_ClassFactory
{
    /**#@+
     *  @access private
     */

    /** @protected    object  Ethna_Kernel    controllerオブジェクト */
    protected $controller;

    /** @protected    object  Ethna_Kernel    controllerオブジェクト(省略形) */
    protected $ctl;

    /** @protected    array   クラス定義 */
    protected $class = array();

    /** @FIXME @protected    array   生成済みオブジェクトキャッシュ */
    public $object = array();

    /** @protected    array   生成済みアプリケーションマネージャオブジェクトキャッシュ */
    protected $manager = array();

    /** @protected    array   メソッド一覧キャッシュ */
    protected $method_list = array();

    /**#@-*/


    /**
     *  Ethna_ClassFactoryクラスのコンストラクタ
     *
     *  @access public
     *  @param  object  Ethna_Kernel    $controller    controllerオブジェクト
     *  @param  array                       $class          クラス定義
     */
    public function __construct($controller, $class)
    {
        $this->controller = $controller;
        $this->ctl = $controller;
        $this->class = $class;
    }

    /**
     *  クラスキーに対応するオブジェクトを返す/クラスキーが未定義の場合はAppObjectを探す
     *  クラスキーとは、[Appid]_Controller#class に定められたもの。
     *
     *  @access public
     *  @param  string  $key    [Appid]_Controller#class に定められたクラスキー
     *                          このキーは大文字小文字を区別する
     *                          (配列のキーとして使われているため)
     *  @param  bool    $ext    オブジェクトが未生成の場合の強制生成フラグ(default: false)
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function getObject($key)
    {
        $object = null;

        // ethna classes
        $class_name = $this->class[$key];

        //  すでにincludeされていなければ、includeを試みる
        if (class_exists($class_name) == false) {
            if ($this->_include($class_name) == false) {
                return null;
            }
        }

        //  Ethna_Kernelで定義されたクラスキーの場合
        //  はメソッド情報を集める
        if (isset($this->method_list[$class_name]) == false) {
            $this->method_list[$class_name] = get_class_methods($class_name);
            for ($i = 0; $i < count($this->method_list[$class_name]); $i++) {
                $this->method_list[$class_name][$i] = strtolower($this->method_list[$class_name][$i]);
            }
        }

        //  以下のルールに従って、キャッシュが利用可能かを判定する
        //  利用可能と判断した場合、キャッシュされていればそれを返す
        //
        //  1. メソッドに getInstance があればキャッシュを利用可能と判断する
        //     この場合、シングルトンかどうかは getInstance 次第
        //  2. weak が true であれば、キャッシュは利用不能と判断してオブジェクトを再生成
        //  3. weak が false であれば、キャッシュは利用可能と判断する(デフォルト)
        if ($this->_isCacheAvailable($class_name, $this->method_list[$class_name], $weak)) {
            if (isset($this->object[$key]) && is_object($this->object[$key])) {
                return $this->object[$key];
            }
        }

        //  インスタンス化のヘルパがあればそれを使う
        $method = sprintf('_getObject_%s', ucfirst($key));
        if (method_exists($this, $method)) {
            $object = $this->$method($class_name);
        } else if (in_array("getinstance", $this->method_list[$class_name])) {
            $object = call_user_func(array($class_name, 'getInstance'));
        } else {
            $object = new $class_name();
        }

        //  クラスキーに定められたクラスのインスタンスは
        //  とりあえずキャッシュする
        if (isset($this->object[$key]) == false || is_object($this->object[$key]) == false) {
            $this->object[$key] = $object;
        }

        return $object;
    }

    /**
     *  オブジェクト生成メソッド(backend)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_Backend($class_name)
    {
        return new $class_name($this->ctl);
    }

    /**
     *  オブジェクト生成メソッド(config)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_Config($class_name)
    {
        return new $class_name($this->ctl);
    }

    /**
     *  オブジェクト生成メソッド(i18n)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_I18n($class_name)
    {
        return new $class_name($this->ctl->getDirectory('locale'), $this->ctl->getAppId());
    }

    /**
     *  オブジェクト生成メソッド(logger)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_Logger($class_name)
    {
        return new $class_name($this->ctl);
    }

    /**
     *  オブジェクト生成メソッド(plugin)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_Plugin($class_name)
    {
        return new $class_name($this->ctl);
    }

    /**
     *  オブジェクト生成メソッド(renderer)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_Renderer($class_name)
    {
        return new $class_name($this->ctl);
    }

    /**
     *  オブジェクト生成メソッド(session)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_Session($class_name)
    {
        return new $class_name($this->ctl, $this->ctl->getAppId());
    }

    /**
     *  オブジェクト生成メソッド(sql)
     *
     *  @access protected
     *  @param  string  $class_name     クラス名
     *  @return object  生成されたオブジェクト(エラーならnull)
     */
    function _getObject_Sql($class_name)
    {
        $_ret_object = new $class_name($this->ctl);
        return $_ret_object;
    }

    /**
     *  指定されたクラスから想定されるファイルをincludeする
     *
     *  @access protected
     */
    public function _include($class_name)
    {
        $file = sprintf("%s.%s", $class_name, $this->controller->getExt('php'));
        if (file_exists_ex($file)) {
            include_once $file;
            return true;
        }

        if (preg_match('/^(\w+?)_(.*)/', $class_name, $match)) {
            // try ethna app style
            // App_Foo_Bar_Baz -> Foo/Bar/App_Foo_Bar_Baz.php
            $tmp = explode("_", $match[2]);
            $tmp[count($tmp)-1] = $class_name;
            $file = sprintf('%s.%s',
                implode(DIRECTORY_SEPARATOR, $tmp),
                $this->controller->getExt('php'));
            if (file_exists_ex($file)) {
                include_once $file;
                return true;
            }

            // try ethna app & pear mixed style
            // App_Foo_Bar_Baz -> Foo/Bar/Baz.php
            $file = sprintf('%s.%s',
                str_replace('_', DIRECTORY_SEPARATOR, $match[2]),
                $this->controller->getExt('php'));
            if (file_exists_ex($file)) {
                include_once $file;
                return true;
            }

            // try ethna master style
            // Ethna_Foo_Bar -> src/Ethna/Foo/Bar.php
            $tmp = explode('_', $match[2]);
            array_unshift($tmp, 'Ethna', 'class');
            $file = sprintf('%s.%s',
                implode(DIRECTORY_SEPARATOR, $tmp),
                $this->controller->getExt('php'));
            if (file_exists_ex($file)) {
                include_once $file;
                return true;
            }

            // try pear style
            // Foo_Bar_Baz -> Foo/Bar/Baz.php
            $file = sprintf('%s.%s',
                str_replace('_', DIRECTORY_SEPARATOR, $class_name),
                $this->controller->getExt('php'));
            if (file_exists_ex($file)) {
                include_once $file;
                return true;
            }
        }
        return false;
    }

    /**
     *  指定されたクラスがキャッシュを利用可能かどうかをチェックする
     *
     *  @access protected
     */
    function _isCacheAvailable($class_name, $method_list, $weak)
    {
        // if we have getInstance(), use this anyway
        if (in_array('getinstance', $method_list)) {
            return false;
        }

        // if not, see if weak or not
        return $weak ? false : true;
    }
}
// }}}


/**
 *  コントローラクラス
 *
 *  @author     Masaki Fujimoto <fujimoto@php.net>
 *  @access     public
 *  @package    Ethna
 */
class Ethna_Kernel implements HttpKernelInterface, TerminableInterface
{
    protected $default_action_name;

    /** @var    string      アプリケーションID */
    protected $appid = 'ETHNA';

    /** @var    string      アプリケーションベースディレクトリ */
    protected $base = '';

    /** @protected    string      アプリケーションベースURL */
    protected $url = '';

    /** @protected    array       アプリケーションディレクトリ */
    protected $directory = array();

    /** @protected    array       拡張子設定 */
    protected $ext = array(
        'php'           => 'php',
        'tpl'           => 'tpl',
    );

    /** @protected    array       クラス設定 */
    public $class = array();


    /**
     * @protected    string ロケール名(e.x ja_JP, en_US 等),
     *                  (ロケール名は ll_cc の形式。ll = 言語コード cc = 国コード)
     */
    protected $locale = 'ja_JP';

    protected $encoding = 'UTF-8';

    /** FIXME: UnitTestCase から動的に変更されるため、public */
    /** @protected    string  現在実行中のアクション名 */
    public $action_name;

    /** @protected    array   アプリケーションマネージャ定義 */
    protected $manager = array();

    /** @protected    object  レンダラー */
    protected $renderer = null;

    /** @protected    object  Ethna_ClassFactory  クラスファクトリオブジェクト */
    public $class_factory = null;

    /** @protected    object  Ethna_ActionForm    フォームオブジェクト */
    protected $action_form = null;

    /** @protected    object  Ethna_View          ビューオブジェクト */
    public $view = null;

    /** @protected    object  Ethna_Logger        ログオブジェクト */
    protected $logger = null;

    /** @protected    object  Ethna_Plugin        プラグインオブジェクト */
    protected $plugin = null;

    protected $actionResolver;

    /**
     *  アプリケーションのエントリポイント
     *
     *  @access public
     *  @param  string  $class_name     アプリケーションコントローラのクラス名
     *  @param  mixed   $action_name    指定のアクション名(省略可)
     *  @static
     */
    public static function main(string $class_name, string $default_action_name = "")
    {
        /** @var Ethna_Kernel $c */
        $c = new $class_name($default_action_name);
        $request = Request::createFromGlobals();
        $response = $c->handle($request);
        $response->send();
        $c->terminate($request, $response);

    }


    /**
     *  フレームワークの処理を実行する(CLI)
     *
     *  @access private
     *  @param  mixed   $default_action_name    指定のアクション名
     */
    public function console($action_name)
    {
        $GLOBALS['_Ethna_controller'] = $this;
        $this->base = BASE;
        $class_factory = $this->class['class'];
        $this->class_factory = new $class_factory($this, $this->class);

        Ethna::setErrorCallback(array($this, 'handleError'));

        // ディレクトリ名の設定(相対パス->絶対パス)
        foreach ($this->directory as $key => $value) {
            if ($key == 'plugins') {
                // Smartyプラグインディレクトリは配列で指定する
                $tmp = array();
                foreach (to_array($value) as $elt) {
                    $tmp[] = $this->base . '/' . $elt;
                }
                $this->directory[$key] = $tmp;
            } else {
                $this->directory[$key] = $this->base . '/' . $value;
            }
        }
        $config = $this->getConfig();
        $this->url = $config->get('url');

        $this->plugin = $this->getPlugin();

        $this->logger = $this->getLogger();
        $this->plugin->setLogger($this->logger);
        $this->logger->begin();

        $this->action_name = $action_name;

        $backend = $this->getBackend();

        $i18n = $this->getI18N();
        $i18n->setLanguage($this->locale);

        $form_class_name = $this->class['form'];
        $this->action_form = new $form_class_name($this);

        $command_class = sprintf("%s_Command_%s", ucfirst(strtolower($this->appid)), ucfirst($action_name));
        require_once $this->directory['command'] . '/' . ucfirst($action_name) . '.php';
        $ac = new $command_class($backend);

        $ac->runcli();
    }

    /**
     *  Ethna_Kernelクラスのコンストラクタ
     *
     *  @access     public
     */
    public function __construct(string $default_action_name = '')
    {
        $this->default_action_name = $default_action_name;
    }

    /**
     *  アプリケーション実行後の後始末を行います。
     */
    public function terminate(Request $request, Response $response)
    {
        $this->logger->end();
    }

    /**
     *  (現在アクティブな)コントローラのインスタンスを返す
     *
     *  @access public
     *  @return object  Ethna_Kernel    コントローラのインスタンス
     *  @static
     */
    public static function getInstance()
    {
        if (isset($GLOBALS['_Ethna_controller'])) {
            return $GLOBALS['_Ethna_controller'];
        } else {
            $_ret_object = null;
            return $_ret_object;
        }
    }

    /**
     *  アプリケーションIDを返す
     *
     *  @access public
     *  @return string  アプリケーションID
     */
    public function getAppId()
    {
        return ucfirst(strtolower($this->appid));
    }

    /**
     *  アプリケーションベースURLを返す
     *
     *  @access public
     *  @return string  アプリケーションベースURL
     */
    public function getURL()
    {
        return $this->url;
    }

    /**
     *  アプリケーションベースディレクトリを返す
     *
     *  @access public
     *  @return string  アプリケーションベースディレクトリ
     */
    public function getBasedir()
    {
        return $this->base;
    }

    /**
     *  クライアントタイプ/言語からテンプレートディレクトリ名を決定する
     *  デフォルトでは [appid]/template/ja_JP/ (ja_JPはロケール名)
     *  ロケール名は _getDefaultLanguage で決定される。
     *
     *  @access public
     *  @return string  テンプレートディレクトリ
     *  @see    Ethna_Kernel#_getDefaultLanguage
     */
    public function getTemplatedir()
    {
        $template = $this->getDirectory('template');

        // 言語別ディレクトリ
        // _getDerfaultLanguageメソッドでロケールが指定されていた場合は、
        // テンプレートディレクトリにも自動的にそれを付加する。
        if (!empty($this->locale)) {
            $template .= '/' . $this->locale;
        }

        return $template;
    }

    /**
     *  ビューディレクトリ名を決定する
     *
     *  @access public
     *  @return string  ビューディレクトリ
     */
    public function getViewdir()
    {
        return $this->directory['view'] . "/";
    }

    /**
     *  (action,view以外の)テストケースを置くディレクトリ名を決定する
     *
     *  @access public
     *  @return string  テストケースを置くディレクトリ
     */
    public function getTestdir()
    {
        return (empty($this->directory['test']) ? ($this->base . (empty($this->base) ? '' : '/')) : ($this->directory['test'] . "/"));
    }

    /**
     *  アプリケーションディレクトリ設定を返す
     *
     *  @access public
     *  @param  string  $key    ディレクトリタイプ("tmp", "template"...)
     *  @return string  $keyに対応したアプリケーションディレクトリ(設定が無い場合はnull)
     */
    public function getDirectory($key)
    {
        if (isset($this->directory[$key]) == false) {
            return null;
        }
        return $this->directory[$key];
    }
    /**
     *  アプリケーションディレクトリ設定を返す
     *
     *  @access public
     *  @param  string  $key    type
     *  @return string  $key    directory
     */
    public function setDirectory($key, $value)
    {
        $this->directory[$key] = $value;
    }


    /**
     *  アプリケーション拡張子設定を返す
     *
     *  @access public
     *  @param  string  $key    拡張子タイプ("php", "tpl"...)
     *  @return string  $keyに対応した拡張子(設定が無い場合はnull)
     */
    public function getExt($key)
    {
        if (isset($this->ext[$key]) == false) {
            return null;
        }
        return $this->ext[$key];
    }

    /**
     *  クラスファクトリオブジェクトのアクセサ(R)
     *
     *  @access public
     *  @return object  Ethna_ClassFactory  クラスファクトリオブジェクト
     */
    public function getClassFactory()
    {
        return $this->class_factory;
    }

    /**
     *  アクションエラーオブジェクトのアクセサ
     *
     *  @access public
     *  @return object  Ethna_ActionError   アクションエラーオブジェクト
     */
    public function getActionError()
    {
        return $this->class_factory->getObject('error');
    }

    /**
     *  Accessor for ActionForm
     *
     *  @access public
     *  @return object  Ethna_ActionForm    アクションフォームオブジェクト
     */
    public function getActionForm()
    {
        // 明示的にクラスファクトリを利用していない
        return $this->action_form;
    }

    /**
     *  Setter for ActionForm
     *  if the ::$action_form class is not null, then cannot set the view
     *
     *  @access public
     *  @return object  Ethna_ActionForm    アクションフォームオブジェクト
     */
    public function setActionForm($af)
    {
        if ($this->action_form !== null) {
            return false;
        }
        $this->action_form = $af;
        return true;
    }


    /**
     *  Accessor for ViewClass
     *
     *  @access public
     *  @return object  Ethna_View          ビューオブジェクト
     */
    public function getView()
    {
        // 明示的にクラスファクトリを利用していない
        return $this->view;
    }

    /**
     *  backendオブジェクトのアクセサ
     *
     *  @access public
     *  @return object  Ethna_Backend   backendオブジェクト
     */
    public function getBackend()
    {
        return $this->class_factory->getObject('backend');
    }

    /**
     *  設定オブジェクトのアクセサ
     *
     *  @access public
     *  @return object  Ethna_Config    設定オブジェクト
     */
    public function getConfig()
    {
        return $this->class_factory->getObject('config');
    }

    /**
     *  i18nオブジェクトのアクセサ(R)
     *
     *  @access public
     *  @return object  Ethna_I18N  i18nオブジェクト
     */
    public function getI18N()
    {
        return $this->class_factory->getObject('i18n');
    }

    /**
     *  ログオブジェクトのアクセサ
     *
     *  @access public
     *  @return object  Ethna_Logger        ログオブジェクト
     */
    public function getLogger()
    {
        return $this->class_factory->getObject('logger');
    }

    /**
     *  セッションオブジェクトのアクセサ
     *
     *  @access public
     *  @return object  Ethna_Session       セッションオブジェクト
     */
    public function getSession()
    {
        return $this->class_factory->getObject('session');
    }

    /**
     *  プラグインオブジェクトのアクセサ
     *
     *  @access public
     *  @return object  Ethna_Plugin    プラグインオブジェクト
     */
    public function getPlugin()
    {
        return $this->class_factory->getObject('plugin');
    }

    /**
     *  URLハンドラオブジェクトのアクセサ
     *
     *  @access public
     *  @return object  Ethna_UrlHandler    URLハンドラオブジェクト
     */
    public function getUrlHandler()
    {
        return $this->class_factory->getObject('url_handler');
    }

    /**
     *  実行中のアクション名を返す
     *
     *  @access public
     *  @return string  実行中のアクション名
     */
    public function getCurrentActionName()
    {
        return $this->action_name;
    }

    /**
     *  ロケール設定、使用言語を取得する
     *
     *  @access public
     *  @return array   ロケール名(e.x ja_JP, en_US 等),
     *                  クライアントエンコーディング名 の配列
     *                  (ロケール名は、ll_cc の形式。ll = 言語コード cc = 国コード)
     *  @see http://www.gnu.org/software/gettext/manual/html_node/Locale-Names.html
     */
    public function getLanguage()
    {
        return array($this->locale, $this->encoding);
    }

    /**
     *  ロケール名へのアクセサ(R)
     *
     *  @access public
     *  @return string  ロケール名(e.x ja_JP, en_US 等),
     *                  (ロケール名は、ll_cc の形式。ll = 言語コード cc = 国コード)
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     *  ロケール名へのアクセサ(W)
     *
     *  @access public
     *  @param $locale ロケール名(e.x ja_JP, en_US 等),
     *                 (ロケール名は、ll_cc の形式。ll = 言語コード cc = 国コード)
     */
    public function setLocale($locale)
    {
        $this->locale = $locale;
        $i18n = $this->getI18N();
        $i18n->setLanguage($this->locale);
    }

    /**
     *  エンコーディング名へのアクセサ(R)
     *
     *  @access public
     *  @return string  $encoding クライアントエンコーディング名
     */
    public function getEncoding()
    {
        return $this->encoding;
    }


    /**
     *  フレームワークの処理を実行する(WWW)
     *
     *  引数$default_action_nameに配列が指定された場合、その配列で指定された
     *  アクション以外は受け付けない(指定されていないアクションが指定された
     *  場合、配列の先頭で指定されたアクションが実行される)
     *
     */
    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true): Response
    {
        $default_action_name = $this->default_action_name;
        $GLOBALS['_Ethna_controller'] = $this;
        $this->base = BASE;
        // クラスファクトリオブジェクトの生成
        $class_factory = $this->class['class'];
        $this->class_factory = new $class_factory($this, $this->class);

        Ethna::setErrorCallback(array($this, 'handleError'));

        // ディレクトリ名の設定(相対パス->絶対パス)
        foreach ($this->directory as $key => $value) {
            if ($key == 'plugins') {
                // Smartyプラグインディレクトリは配列で指定する
                $tmp = array();
                foreach (to_array($value) as $elt) {
                    $tmp[] = $this->base . '/' . $elt;
                }
                $this->directory[$key] = $tmp;
            } else {
                $this->directory[$key] = $this->base . '/' . $value;
            }
        }
        $config = $this->getConfig();
        $this->url = $config->get('url');
        if (empty($this->url) && PHP_SAPI != 'cli') {
            $this->url = Ethna_Util::getUrlFromRequestUri();
            $config->set('url', $this->url);
        }

        $this->plugin = $this->getPlugin();

        $this->logger = $this->getLogger();
        $this->plugin->setLogger($this->logger);
        $this->logger->begin();

        $actionDir = $this->directory['action'] . "/";
        $default_form_class = $this->class['form'];
        $actionResolverClass = $this->class['action_resolver'];
        /** @var Ethna_ActionResolver $actionResolver */
        $this->actionResolver = $actionResolver = new $actionResolverClass($this->getAppId(), $this->logger, $default_form_class, $actionDir);
        // アクション名の取得
        $action_name = $actionResolver->resolveActionName($request, $default_action_name);
        $this->action_name = $action_name;

        // オブジェクト生成
        $backend = $this->getBackend();
        $this->getSession()->restore();

        $i18n = $this->getI18N();
        $i18n->setLanguage($this->locale);

        // アクションフォーム初期化
        // フォーム定義、フォーム値設定
        $this->action_form = $actionResolver->newActionForm($action_name, $this);

        $viewResolver = new Ethna_ViewResolver($backend, $this->logger, $this->getViewdir(), $this->getAppId(), $this->class['view']);
        $callable = $actionResolver->getController($request, $action_name, $backend, $this->action_form, $viewResolver);
        $arguments = [$request];
        $response = call_user_func_array($callable, $arguments);
        return $response;
    }

    public function getActionFormName($action_name)
    {
        return $this->actionResolver->getActionFormName($action_name);
    }
    /**
     *  エラーハンドラ
     *
     *  エラー発生時の追加処理を行いたい場合はこのメソッドをオーバーライドする
     *  (アラートメール送信等−デフォルトではログ出力時にアラートメール
     *  が送信されるが、エラー発生時に別にアラートメールをここで送信
     *  させることも可能)
     *
     *  @access public
     *  @param  object  Ethna_Error     エラーオブジェクト
     */
    public function handleError($error)
    {
        // ログ出力
        list ($log_level, $dummy) = $this->logger->errorLevelToLogLevel($error->getLevel());
        $message = $error->getMessage();
        $this->logger->log($log_level, sprintf("%s [ERROR CODE(%d)]", $message, $error->getCode()));
    }

    /**
     *  エラーメッセージを取得する
     *
     *  @access public
     *  @param  int     $code       エラーコード
     *  @return string  エラーメッセージ
     */
    public function getErrorMessage($code)
    {
        $message_list = $GLOBALS['_Ethna_error_message_list'];
        for ($i = count($message_list)-1; $i >= 0; $i--) {
            if (array_key_exists($code, $message_list[$i])) {
                return $message_list[$i][$code];
            }
        }
        return null;
    }




    /**
     *  アクション名を指定するクエリ/HTMLを生成する
     *
     *  @access public
     *  @param  string  $action action to request
     *  @param  string  $type   hidden, url...
     */
    public function getActionRequest($action, $type = "hidden")
    {
        $s = null;
        if ($type == "hidden") {
            $s = sprintf('<input type="hidden" name="action_%s" value="true" />', htmlspecialchars($action, ENT_QUOTES, mb_internal_encoding()));
        } else if ($type == "url") {
            $s = sprintf('action_%s=true', urlencode($action));
        }
        return $s;
    }



    /**
     *  getDefaultFormClass()で取得したクラス名からアクション名を取得する
     *
     *  getDefaultFormClass()をオーバーライドした場合、こちらも合わせてオーバーライド
     *  することを推奨(必須ではない)
     *
     *  @access public
     *  @param  string  $class_name     フォームクラス名
     *  @return string  アクション名
     */
    public function actionFormToName($class_name)
    {
        $prefix = sprintf("%s_Form_", $this->getAppId());
        if (preg_match("/$prefix(.*)/", $class_name, $match) == 0) {
            // 不明なクラス名
            return null;
        }
        $target = $match[1];

        $action_name = substr(preg_replace('/([A-Z])/e', "'_' . strtolower('\$1')", $target), 1);

        return $action_name;
    }


    /**
     *  テンプレートパス名から遷移名を取得する
     *
     *  getDefaultForwardPath()をオーバーライドした場合、こちらも合わせてオーバーライド
     *  することを推奨(必須ではない)
     *
     *  @access public
     *  @param  string  $forward_path   テンプレートパス名
     *  @return string  遷移名
     */
    public function forwardPathToName($forward_path)
    {
        $forward_path = preg_replace('/^\/+/', '', $forward_path);
        $forward_path = preg_replace(sprintf('/\.%s$/', $this->getExt('tpl')), '', $forward_path);

        return str_replace('/', '_', $forward_path);
    }


    /**
     *  レンダラを取得する
     *
     *  @access public
     *  @return object  Ethna_Renderer  レンダラオブジェクト
     */
    public function getRenderer()
    {
        if ($this->renderer instanceof Ethna_Renderer) {
            return $this->renderer;
        }

        $this->renderer = $this->class_factory->getObject('renderer');
        if ($this->renderer === null) {
            trigger_error("cannot get renderer", E_USER_ERROR);
        }
        return $this->renderer;
    }

    /**
     *  typeに対応するアプリケーションマネージャオブジェクトを返す
     *  注意： typeは大文字小文字を区別しない
     *         (PHP自体が、クラス名の大文字小文字を区別しないため)
     *
     *  マネジャークラスをincludeすることはしないので、
     *  アプリケーション側でオートロードする必要がある。
     *
     *  @access public
     *  @param  string  $type   アプリケーションマネージャー名
     *  @return object  Ethna_AppManager    マネージャオブジェクト
     */
    public function getManager($type)
    {
        //   アプリケーションIDと、渡された名前のはじめを大文字にして、
        //   組み合わせたものが返される
        $manager_id = preg_replace_callback('/_(.)/', function(array $matches){return strtoupper($matches[1]);}, ucfirst($type));
        $class_name = sprintf('%s_%sManager', $this->getAppId(), ucfirst($manager_id));

        //  PHPのクラス名は大文字小文字を区別しないので、
        //  同じクラス名と見做されるものを指定した場合には
        //  同じインスタンスが返るようにする
        $type = strtolower($type);

        //  キャッシュがあればそれを利用
        if (isset($this->manager[$type]) && is_object($this->manager[$type])) {
            return $this->manager[$type];
        }

        $obj = new $class_name($this->getBackend(),$this->getConfig(), $this->getI18N(), $this->getSession(), $this->getActionForm());

        //  生成したオブジェクトはキャッシュする
        if (isset($this->manager[$type]) == false || is_object($this->manager[$type]) == false) {
            $this->manager[$type] = $obj;
        }

        return $obj;
    }

    /**
     *  アプリケーションの設定ディレクトリを取得する
     *
     *  @access public
     *  @return string  設定ディレクトリのパス名
     */
    public function getEtcdir()
    {
        return $this->getDirectory('etc');
    }

    /**
     *  アプリケーションのテンポラリディレクトリを取得する
     *
     *  @access public
     *  @return string  テンポラリディレクトリのパス名
     */
    public function getTmpdir()
    {
        return $this->getDirectory('tmp');
    }

    /**
     *  アプリケーションのテンプレートファイル拡張子を取得する
     *
     *  @access public
     *  @return string  テンプレートファイルの拡張子
     */
    public function getTemplateext()
    {
        return $this->getExt('tpl');
    }

    /**
     *  ログを出力する
     *
     *  @access public
     *  @param  int     $level      ログレベル(LOG_DEBUG, LOG_NOTICE...)
     *  @param  string  $message    ログメッセージ(printf形式)
     */
    public function log($level, $message)
    {
        $args = func_get_args();
        if (count($args) > 2) {
            array_splice($args, 0, 2);
            $message = vsprintf($message, $args);
        }
        $this->getLogger()->log($level, $message);
    }


}

class_alias('Ethna_Kernel', 'Ethna_Controller');
