<?php
/** .-------------------------------------------------------------------
 * |  Site: www.skytosky.cn
 * |-------------------------------------------------------------------
 * |  Author: 虫不知 <2388456065@qq.com>
 * |  Copyright (c) 2021-2029, www.skytosky.cn. All Rights Reserved.
 * '-------------------------------------------------------------------*/

namespace yeagan\addons;

use Closure;
use think\App;
use think\exception\HttpException;
use think\Request;
use think\Response;

class MultiAddon
{
    /** @var App */
    protected $app;

    /**
     * 应用名称
     * @var string
     */
    protected $name;

    /**
     * 应用名称
     * @var string
     */
    protected $appName;

    /**
     * 应用路径
     * @var string
     */
    protected $path;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->name = $this->app->http->getName();
        $this->path = $this->app->http->getPath();
    }

    /**
     * 多应用解析
     * @access public
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        if (!$this->parseMultiAddon()) {
            return $next($request);
        }

        return $this->app->middleware->pipeline('addons')
            ->send($request)
            ->then(function ($request) use ($next) {
                return $next($request);
            });
    }

    /**
     * 获取路由目录
     * @access protected
     * @return string
     */
    protected function getRoutePath(): string
    {
        return $this->app->getAppPath() . 'route' . DIRECTORY_SEPARATOR;
    }

    /**
     * 解析多应用
     * @return bool
     */
    protected function parseMultiAddon(): bool
    {
        if ($this->name) return false;

        $addonBind = $this->app->config->get('app.addon_domain_bind', []);
        $appName = '';

        if (!empty($addonBind)) {
            $this->app->http->setBind(false);

            // 获取当前子域名
            $subDomain = $this->app->request->subDomain();
            $domain = $this->app->request->host(true);

            if (isset($addonBind[$domain])) {
                $appName = $addonBind[$domain];
                $this->app->http->setBind();
            } elseif (isset($addonBind[$subDomain])) {
                $appName = $addonBind[$subDomain];
                $this->app->http->setBind();
            }
        }

        $path = $this->app->request->pathinfo();
        $pathArray = explode('/', $path);

        $addonFlag = current($pathArray);

        if ($appName) {
            // 已经绑定域名
            $addonFlag = $appName;
            $addonFrontendPath = $this->getAddonBasePath() . $addonFlag . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR;

            if (is_dir($addonFrontendPath)) {
                $this->app->request->setAddonName($appName);
                $this->setAppByAddonBindFrontend($addonFlag, 'frontend');
                return true;
            }

        } else {

            // 没有绑定域名
            if ($addonFlag === 'addons') {
                $addonName = $pathArray[1] ?? '';
                if (!empty($addonName)) {
                    $addonBackendDir = $this->getAddonBasePath() . $addonName;
                    if (is_dir($addonBackendDir)) {
                        $this->app->request->setAddonName($addonName);
                        $this->setAppByAddonBackend($addonName, 'backend');
                        return true;
                    }
                }
            } else {

                $addonFrontendPath = $this->getAddonBasePath() . $addonFlag . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR;
                if (is_dir($addonFrontendPath)) {
                    $this->app->request->setAddonName($appName);
                    $this->setAppByAddonFrontend($addonFlag, 'frontend');
                    return true;
                }
            }
        }


        return false;
    }


    protected function setAppByFrontend(string $appName, $type = 'frontend'): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->getAddonBasePath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;

        $this->app->setAppPath($appPath);
        // 设置应用命名空间
        $nameSpace = ($this->app->config->get('app.addon_namespace') ?: 'addons\\' . $appName) . '\\' . $type;
        $this->app->setNamespace($nameSpace);

        if (is_dir($appPath)) {

            $pathinfo = $this->app->request->pathinfo();
            $this->app->request->setPathinfo(strpos($pathinfo, '/') ? ltrim(strstr($pathinfo, '/'), '/') : '');

            $this->app->setRuntimePath($this->app->getRuntimePath() . 'addons' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR);
            $routePath = $this->getAddonBasePath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR;
            $this->app->http->setRoutePath($routePath);

            //加载应用
            $this->loadApp($appName, $appPath);

            if ($type == 'backend') {
                // 后台Common
                if (is_file($this->app->getBasePath() . 'admin' . DIRECTORY_SEPARATOR . 'common.php')) {
                    include_once $this->app->getBasePath() . 'admin' . DIRECTORY_SEPARATOR . 'common.php';
                }
            }
        }
    }

    /**
     * 获取应用基础目录
     * @access public
     * @return string
     */
    public function getAddonBasePath(): string
    {
        return $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
    }

    /**
     * 获取当前运行入口名称
     * @access protected
     * @codeCoverageIgnore
     * @return string
     */
    protected function getScriptName(): string
    {
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $file = $_SERVER['SCRIPT_FILENAME'];
        } elseif (isset($_SERVER['argv'][0])) {
            $file = realpath($_SERVER['argv'][0]);
        }

        return isset($file) ? pathinfo($file, PATHINFO_FILENAME) : '';
    }

    /**
     * 设置应用
     * @param string $appName
     * @param string $type
     */
    protected function setAppByAddonFrontend(string $appName, $type = 'frontend'): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->getAddonBasePath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
        $this->app->setAppPath($appPath);

        // 设置应用命名空间
        $nameSpace = ($this->app->config->get('app.addon_namespace') ?: 'addons\\' . $appName) . '\\' . $type;
        $this->app->setNamespace($nameSpace);

        if (is_dir($appPath)) {

            $runtimePath = $this->app->getRuntimePath() . 'addons' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
            $this->app->setRuntimePath($runtimePath);

            $this->app->http->setRoutePath($this->getRoutePath());

            $this->app->request->setRoot('/' . 'addons/' . $appName . '/frontend');

            $path = $this->app->request->pathinfo();

            $path = strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '';
            $this->app->request->setPathinfo($path);

            //加载应用
            $this->loadApp($appName, $appPath);
        }
    }


    /**
     * 设置应用
     * @param string $appName
     * @param string $type
     */
    protected function setAppByAddonBindFrontend(string $appName, $type = 'frontend'): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->getAddonBasePath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
        $this->app->setAppPath($appPath);

        // 设置应用命名空间
        $nameSpace = ($this->app->config->get('app.addon_namespace') ?: 'addons\\' . $appName) . '\\' . $type;
        $this->app->setNamespace($nameSpace);

        if (is_dir($appPath)) {

            $runtimePath = $this->app->getRuntimePath() . 'addons' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;

            $this->app->setRuntimePath($runtimePath);

            $this->app->http->setRoutePath($this->getRoutePath());

            $this->app->request->setRoot('/' . 'addons/' . $appName . '/frontend');

            //加载应用
            $this->loadApp($appName, $appPath);
        }
    }


    /**
     * 设置应用
     * @param string $appName
     * @param string $type
     */
    protected function setAppByAddonBackend(string $appName, $type = 'backend'): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->getAddonBasePath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;

        $this->app->setAppPath($appPath);

        // 设置应用命名空间
        $nameSpace = ($this->app->config->get('app.addon_namespace') ?: 'addons\\' . $appName) . '\\' . $type;
        $this->app->setNamespace($nameSpace);

        if (is_dir($appPath)) {

            $runtimePath = $this->app->getRuntimePath() . 'addons' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;

            $this->app->setRuntimePath($runtimePath);

            $this->app->http->setRoutePath($this->getRoutePath());

            $this->app->request->setRoot('/' . 'addons/' . $appName . '/backend');

            $path = $this->app->request->pathinfo();

            $path = strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '';
            $path = strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '';

            $this->app->request->setPathinfo($path);

            //加载应用
            $this->loadApp($appName, $appPath);

            $adminAppPath = $this->app->getBasePath() . 'admin' . DIRECTORY_SEPARATOR;

            if (is_file($adminAppPath . 'common.php')) {
                include_once $adminAppPath . 'common.php';
            }
        }
    }

    /**
     * 加载应用文件
     * @param string $appName 应用名
     * @return void
     */
    protected function loadApp(string $appName, string $appPath): void
    {
        if (is_file($appPath . 'common.php')) {
            include_once $appPath . 'common.php';
        }

        $files = [];

        $files = array_merge($files, glob($appPath . 'config' . DIRECTORY_SEPARATOR . '*' . $this->app->getConfigExt()));

        foreach ($files as $file) {
            $this->app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }

        if (is_file($appPath . 'event.php')) {
            $this->app->loadEvent(include $appPath . 'event.php');
        }

        if (is_file($appPath . 'middleware.php')) {
            $this->app->middleware->import(include $appPath . 'middleware.php', 'addons');
        }

        if (is_file($appPath . 'provider.php')) {
            $this->app->bind(include $appPath . 'provider.php');
        }

        // 加载应用默认语言包
        $this->app->loadLangPack($this->app->lang->defaultLangSet());
    }
}