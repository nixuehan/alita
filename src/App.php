<?php
namespace Alita;
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\ConnectionPoolTrait;
use Smf\ConnectionPool\Connectors\CoroutineMySQLConnector;
use Smf\ConnectionPool\Connectors\PhpRedisConnector;
use Swoole\Http\Server;

final class AUTHOR
{
    const AUTHOR = '妖';
    const VERSION = 'Alpha.0.0.4';
}


class ORM
{
    private $_tableName = '';
    private $_db = null;

    private $_where = [];
    private $_raw = '';

    //设置数据源
    public function setConnect($conn)
    {
        $this->_db = $conn;
        return $this;
    }

    public function getConnect()
    {
        return $this->_db;
    }

    public function table($tableName) {
        $this->_tableName = $tableName;
        return $this;
    }

    public function getTable() {
        return $this->_tableName;
    }

    public function query(string $sql,array $params = []) {

        $conn = static::getConnect();
        $stmt = $conn->prepare($sql);

        if ($stmt == false) {
            throw new MysqlException("{$conn->errno} {$conn->error}");
        }

        if(false == $stmt->execute($params)) {
            return false;
        }

        return $stmt;
    }

    public function insert_id() {
        return static::getConnect()->insert_id;
    }

    public function insert(array $data) {

        $fn = function () use($data){
            $_field = $_value = [];

            foreach($data as $k => $v) {
                $_field[] = $k;
                $_sign[] = '?';
                $_params[] = $v;
            }

            $field = implode(',',$_field);
            $_sign = implode(',',$_sign);

            return ["sql" => sprintf("(%s) VALUES (%s)",$field,$_sign),"value" => $_params];
        };

        $val = $fn->call($this);

        list($sql,$params) = $this->build("INSERT INTO {$this->getTable()} %s",$val);

        $stmt = $this->query($sql,$params);

        if ($stmt === false) { return false; }

        return $this->insert_id();
    }

    //清除各种ORM临时中间变量
    private function clean()
    {
        $this->clearWhere();
    }

    private function fetchAll(string $sql,array $params = []) {

        $stmt = $this->query($sql,$params);
        $this->clean();

        if ($stmt === false) { return false; }

        return $stmt->fetchAll();
    }

    private function fetch(string $sql,array $params = []) {

        $stmt = $this->query($sql,$params);
        $this->clean();

        if ($stmt === false) { return false; }

        return $stmt->fetch();
    }


    public function first(array $params = null) {

        $field = is_array($params) ? $this->getField($params) : '*';

        list($sql,$value) = $this->build("SELECT {$field} FROM {$this->getTable()} %s  LIMIT 1",$this->getWhere());

        return $this->fetch($sql,$value);
    }

    public function where(...$params) {

        $_params = [];

        $this->_where['sql'] = ' WHERE ' . $params[0];

        if(isset($params[1]) && is_array($params[1])) {
            $_params = $params[1];
        }

        $this->_where['value'] = $_params;

        return $this;
    }

    public function and(...$params) {

        $_params = [];

        $this->_where['sql'] .= ' AND ' . $params[0];

        if(isset($params[1]) && is_array($params[1])) {
            $_params = $params[1];
        }

        $this->_where['value'] = array_merge($this->_where['value'],$_params);

        return $this;
    }

    public function or(...$params) {
        $_params = [];

        $this->_where['sql'] .= ' OR ' . $params[0];

        if(isset($params[1]) && is_array($params[1])) {
            $_params = $params[1];
        }

        $this->_where['value'] = array_merge($this->_where['value'],$_params);

        return $this;
    }

    public function in(string $field,array $values) {

        $val = $_params = [];

        foreach($values as $value) {
            $val[] = '?';
            $_params[] = $value;
        }

        $val = implode(',',$val);

        $this->_where['sql'] = ' WHERE '.$field . " IN (".$val.")";

        $this->_where['value'] = $_params;

        return $this;
    }

    public function notin(string $field,array $values) {

        $val = $_params = [];

        foreach($values as $value) {
            $val[] = '?';
            $_params[] = $value;
        }

        $val = implode(',',$val);

        $this->_where['sql'] = ' WHERE '.$field . " NOT IN (".$val.")";

        $this->_where['value'] = $_params;

        return $this;
    }

    private function orderby($val) {
        if(strpos($val,'ORDER BY') === false) {
            return ' ORDER BY ';
        }else{
            return ',';
        }
    }

    public function desc(...$params) {

        $val = implode(',',array_map(function($k){
            return $k . ' DESC';
        },$params));

        $this->_where['sql'] .= $this->orderby($this->_where['sql']) . $val;
        return $this;
    }

    public function asc(...$params) {

        $val = implode(',',array_map(function($k){
            return $k . ' ASC';
        },$params));

        $this->_where['sql'] .= $this->orderby($this->_where['sql']) . $val;
        return $this;
    }

    private function getField(array $data) {
        return implode(',',$data);
    }

    public function forUpdate() {
        $this->_where['sql'] .= ' FOR UPDATE';
        return $this;
    }

    public function group(...$params) {
        $this->_where['sql'] .= ' GROUP BY ' . $this->getField($params);
        return $this;
    }

    private function getWhere() {
        return $this->_where ?? $this->_raw;
    }

    public function limit($start,$end=0) {
        if($end){
            $_sql = " LIMIT $start,$end";
        }else{
            $_sql = " LIMIT $start";
        }

        $this->_where['sql'] .= $_sql;
        return $this;
    }

    private function noWhere() {
        if(!$this->_where && !$this->_raw){
            throw new MysqlAlitaException('sql conditions miss!');
        }
    }

    private function clearWhere() {
        $this->_where = $this->_raw = '';
    }

    public function delete(bool $force=false) {

        if($force) $this->noWhere();

        list($sql,$value) = $this->build("DELETE FROM {$this->getTable()} %s",$this->getWhere());

        $stmt = $this->query($sql,$value);

        if (false === $stmt) { return false; }

        $this->clean();

        return $stmt;
    }

    private function getFieldValuePair(array $data) :array {
        $_pair = $_params = [];

        foreach($data as $k => $v) {
            $_pair[] = $k . '= ?';
            $_params[] = $v;
        }

        return ["sql" => implode(',', $_pair),"value" => $_params];
    }

    //组装sql和对应?的值
    private function build($tpl,...$params)
    {
        $sqlstr = '';
        $value = [];

        foreach($params as $param) {
            $sqlstr .= $param['sql'];
            $value = array_merge($value,$param['value']);
        }

        $sql = sprintf($tpl,$sqlstr);
        return [$sql,$value];
    }

    public function update(array $params) {

        $this->noWhere($params);

        $pair = $this->getFieldValuePair($params);

        list($sql,$params) = $this->build("UPDATE {$this->getTable()} SET %s",$pair,$this->getWhere());

        $stmt = $this->query($sql,$params);

        if (false === $stmt) { return false; }

        $this->clean();

        return $stmt;
    }

    public function find($params = null) {

        $field = is_array($params) ? $this->getField($params) : '*';

        list($sql,$value) = $this->build("SELECT {$field} FROM {$this->getTable()} %s",$this->getWhere());

        return $this->fetchAll($sql,$value);
    }

    public function transaction(callable $fn) {

        $db = static::getConnect();

        $db->begin();
        $data = $fn();

        if(!$data){
            $db->rollback();
            return false;
        }
        $db->commit();
        return $data;
    }
}

class Log
{
    public static function print(array $message)
    {

        $message = implode('    ',$message);

        go(function () use($message){
            fwrite(STDOUT,$message ."\n");
        });
    }
}

class AlitaException extends \Exception
{
    public function __construct($e)
    {
        $msg = "\n#{$this->getFile()}  {$this->getLine()}\n";
        foreach($e as $key => $val) {
            $msg .= "#{$key} : {$val}\n";
        }

        parent::__construct($msg);
    }
}

class HttpNotFound extends AlitaException{}
class NoFoundRule extends AlitaException{}
class NoFoundController extends AlitaException{}
class NoFoundAction extends AlitaException{}
class MysqlException extends \Exception{}
class ConfigException extends \Exception{}

class WorkerException extends \Exception{} //普通业务异常

//中间件接口
interface Middleware
{
    function handle(Request $request,Response $response);
}

interface Event
{
    function handle(array $params);
}


//路由接口
interface Route
{
    function initialize();
    function getControllerActionParams(string $pathInfo); //控制器
}

//自定义路由
class RulesRoute implements Route
{
    public $rules = [];
    public $controllerAction = '';
    public $isMatch = false;
    private $_coreRules = [];

    public function __construct($coreRules)
    {
        $this->_coreRules = $coreRules;
    }

    public function initialize()
    {
        $this->import(Setting::$ROOT_DIR . '/Application/Route');
    }

    //导入路由规则
    public function import(string $path)
    {
        //导入硬路由规则
        if(!empty($this->_coreRules)) {
            $this->rules = $this->_coreRules;
        }else{
            //导入路由规则
            $paths = glob($path . '/*.php');
            if (empty($paths)) {
                throw new ConfigException("Configuration file: *.php cannot be found");
            }

            foreach($paths as $path) {
                $this->rules = array_merge($this->rules,require $path);
            }
        }
    }


    public function getCbParams(string $pathInfo) :array
    {
        foreach ($this->rules as $rule => $do) {

            if (preg_match($rule, $pathInfo, $matches)) {

                $_cb = $do;
                $this->isMatch = true;

                array_shift($matches);

                return [$_cb,$matches];
            }
        }
        return [null,[]];
    }

    //处理路由规则
    public function getControllerActionParams(string $pathInfo) :array
    {
        foreach ($this->rules as $rule => $do) {

            if(is_callable($do)) {

                if (preg_match($rule, $pathInfo, $matches)) {
                    $_cb = $do;
                    $this->isMatch = true;

                    array_shift($matches);

                    return [$_cb,$matches];
                }

            }else{
                if (preg_match($rule, $pathInfo, $matches)) {
                    list($controller,$action) = explode("@",$do);
                    $this->controllerAction = $do;
                    $this->isMatch = true;

                    array_shift($matches);

                    return ['\Application\Controllers\\' . ucfirst($controller),$action,$matches];
                }
            }
        }
        return ['','',[]];
    }
}

//内置路由
class PathRoute implements Route
{
    public function initialize()
    {

    }

    public function getControllerActionParams(string $pathInfo) :array
    {
        $pathInfo = trim($pathInfo,"/");

        $pos = strrpos($pathInfo,"/");

        if(false === $pos){
            // /home
            $controller = $pathInfo;
            $action = "Index";
        }else{
            // /home/profile
            $controller = substr($pathInfo,0,$pos);
            $action = substr($pathInfo,$pos+1);
        }

        return ['\Application\Controllers\\' . ucfirst($controller),$action,[]];
    }
}

class ConnPool
{
    public function addMysqlConnectionPool($mysqlSetting)
    {
        return function() use($mysqlSetting){
            // All MySQL connections: [4 workers * 2 = 8, 4 workers * 10 = 40]
            $pool1 = new ConnectionPool(
                [
                    'minActive' => 2,
                    'maxActive' => 100,
                ],
                new CoroutineMySQLConnector,$mysqlSetting);
            $pool1->init();

            $this->addConnectionPool('mysql', $pool1);
        };
    }


    public function addRedisConnectionPool($redisSetting)
    {
        return function() use($redisSetting){


            // All Redis connections: [4 workers * 5 = 20, 4 workers * 20 = 80]
            $pool2 = new ConnectionPool(
                [
                    'minActive' => 5,
                    'maxActive' => 20,
                ],

                new PhpRedisConnector,$redisSetting);
            $pool2->init();
            $this->addConnectionPool('redis', $pool2);
        };
    }

    public function closeConnectionPools()
    {
        return function () {
            $this->closeConnectionPools();
        };
    }
}

class RuntimeException extends \Exception
{

}

//事件
class Events
{
    use getInstance;

    //todo csp 异步
    public function emit(array &$events,string $eventName,array $params)
    {
        if(isset($events[$eventName])) {
            foreach ($events[$eventName] as $event) {

                $obj = new $event();

                if (!method_exists($obj,'handle')) {
                    throw new RuntimeException("{$event} handle method Not Found");
                }

                $obj->handle($params);
            }
        }
    }
}

//配置
class Setting
{
    public static $ROOT_DIR = ''; //项目路径
    public static $SETTING = []; //服务配置

    public static $app_mysql = false;
    public static $app_redis = false;

    //服务器配置
    public static function server(array $project = [])
    {
        self::$ROOT_DIR = $project['project_root'];

        if (isset($project['server'])) {
            self::$SETTING = $project;
            return;
        }

        if (file_exists(self::$ROOT_DIR . '/.env.php')) {
            $setting = require self::$ROOT_DIR . '/.env.php';


            if(!isset($setting['server'])) {
                print("server config not found\n");
                exit;
            }

            self::$SETTING = $setting;
            return;
        }

        self::$SETTING = [
            'server' => [
                'host' => '',
                'port' => 9521,
                'daemonize'             => false,
                'dispatch_mode'         => 3,
            ]
        ];
    }

    //应用的配置
    public static function app($conf)
    {
        if (!empty($conf))  {

            if (isset($conf['mysql']) && !empty($conf['mysql'])) {
                self::$app_mysql = true;
            }

            if (isset($conf['redis']) && !empty($conf['redis'])) {
                self::$app_redis = true;
            }

            self::$SETTING = array_merge(self::$SETTING,$conf);
        }

    }

    //获取配置选项
    public static function get(string $key = '')
    {
        
        if (empty($key)) {
            return self::$SETTING;
        }

        $values = explode('.',$key);

        switch (sizeof($values))
        {
            case 1:
                return self::$SETTING[$values[0]];
                break;
            case 2:
                return self::$SETTING[$values[0]][$values[1]];
                break;
        }
    }
}


//侏罗纪
class App
{

    private $_providers = [];

    private $_service = []; //请求过程里的全局对象
    private $_middleware = []; //中间件处理

    //启动初始化
    private $_startInitialize = null;

    private $_prod = false; //目前运行模式

    use ConnectionPoolTrait;

    public function __construct(array $project = [])
    {

        Setting::server($project);

        $this->setProvider([
            ConnPool::class,
        ]);

    }


    public function startInitialize(callable $fn)
    {
        $this->_startInitialize = $fn;
    }

    //设置开发模式
    public function prod(bool $mode)
    {
        $this->_prod = $mode;
    }

    public function getMode() :string
    {
        return $this->_prod ? 'prod' : 'dev';
    }

    public function setting(callable $fn)
    {
        Setting::app($fn());
    }

    public $_coreRULEs = []; //路由

    private function ruleRework($regEx,$method)
    {
        return substr_replace($regEx,$method,2,0);
    }

    private function baseMethod(string $regEx,\Closure $cb,$method)
    {
        $filing = $this->ruleRework($regEx,"{$method} ");
        $this->_coreRULEs[$filing] = $cb;
    }

    public function GET(string $regEx,\Closure $cb)
    {
        $this->baseMethod($regEx,$cb,'GET');
    }

    public function POST(string $regEx,\Closure $cb)
    {
        $this->baseMethod($regEx,$cb,'POST');
    }

    public function PUT(string $regEx,\Closure $cb)
    {
        $this->baseMethod($regEx,$cb,'PUT');
    }

    public function PATCH(string $regEx,\Closure $cb)
    {
        $this->baseMethod($regEx,$cb,'PATCH');
    }

    public function DELETE(string $regEx,\Closure $cb)
    {
        $this->baseMethod($regEx,$cb,'DELETE');
    }

    //提供者
    private function setProvider(array $providers)
    {
        foreach ($providers as $cls => $provider) {

            if(is_object($provider)) {
                $this->_providers[$cls] = $provider;
                $provider = $cls;
            }else{
                $this->_providers[$provider] = new $provider();
            }

            if (method_exists($this->_providers[$provider],'initialize')) {
                $this->_providers[$provider]->initialize();
            }
        }
    }

    //获取提供者
    private function getProvider(string $providerName)
    {
        return isset($this->_providers[$providerName]) ? $this->_providers[$providerName] : false;
    }

    public function Service(array $obj)
    {
        $this->_service = $obj;
    }

    private $_events = [];

    //事件
    public function events(\Closure $fn)
    {
        $this->_events = $fn();
    }

    public function middleware(array $middleware)
    {
        $this->_middleware = $middleware;
    }

    private function getMiddleware(string $key = '')
    {
        return $key ? $this->_middleware[$key] : $this->_middleware;
    }

    //中间件处理
    public function process(array $handle)
    {
        $this->_pipeline = $handle;
    }

    private function getPipeline(string $key = '')
    {
        return $key ? $this->_pipeline[$key] : $this->_pipeline;
    }

    //中间件处理
    private function middlewareProcess(string $type,Request $request,Response $response,$route=null)
    {
        $_pipeline = $this->getPipeline($type);
        $_middleware = $this->getMiddleware();


        $fn = function ($request,$response,&$_pipeline,&$_middleware) {

            array_walk($_pipeline,function(&$value,$key) use($_middleware){

                $value = $_middleware[$value];
            });

            //因为全局共享一个request  response 对象,虽有每一层的修改 都直接产生影响
            array_reduce($_pipeline,function($context, $next) use($request,$response){

                $next($request,$response);
                return $context;
            });

        };

        if(!empty($_middleware) && !empty($_pipeline)) {

            //路由级别
            if($type === 'route') {
                $_handle = [];

                foreach ($_pipeline as $key => $val) {

                    //* 包含了所有路由
                    if($val == '*') {
                        $_handle[] = $key;
                    }

                    if(is_array($val)){

                        //只有这些路由需要执行
                        if(isset($val['only'])) {
                            if(in_array($route->controllerAction,$val['only'])) {
                                $_handle[] = $key;
                            }
                        }

                        //取反
                        if(isset($val['except'])) {

                            if(!in_array($route->controllerAction,$val['except'])) {
                                $_handle[] = $key;
                            }
                        }

                        //手机要执行的 中间件
                        if(in_array($route->controllerAction,$val)) {
                            $_handle[] = $key;
                        }
                    }
                }

                $fn($request,$response,$_handle,$_middleware);

            }else {
                $fn($request,$response,$_pipeline,$_middleware);
            }
        }
    }

    //引擎
    private function engine(callable $dispatch)
    {
        //初始化路由
        $this->setProvider([
            RulesRoute::class => new RulesRoute($this->_coreRULEs),
        ]);

        //初始化
        ($this->_startInitialize)();

        $http = new Server(Setting::get('server.host'), Setting::get('server.port'));

        $http->set(Setting::get('server'));

        $http->on('Start', function (Server $http) {
            swoole_set_process_name("App Master");
        });

        $http->on('ManagerStart', function (Server $http) {
            swoole_set_process_name("App Manager");
        });

        $http->on('WorkerStart', function (Server $http, int $workerId) {
            swoole_set_process_name("App Worker #{$workerId}");

            if (Setting::$app_mysql) {
                $connPool = $this->getProvider(ConnPool::class);
                $connPool->addMysqlConnectionPool(Setting::get('mysql'))->call($this);
            }

            if (Setting::$app_redis) {
                $connPool = $this->getProvider(ConnPool::class);
                $connPool->addRedisConnectionPool(Setting::get('redis'))->call($this);
            }

        });

        $http->on('WorkerError',function () {
            if(Setting::$app_mysql || Setting::$app_redis) {
                $connPool = $this->getProvider(ConnPool::class);
                $connPool->closeConnectionPools()->call($this);
            }
        });

        $http->on('WorkerStop', function () {
            if(Setting::$app_mysql || Setting::$app_redis) {
                $connPool = $this->getProvider(ConnPool::class);
                $connPool->closeConnectionPools()->call($this);
            }
        });

        $http->on('request',$dispatch);

        \Swoole\Runtime::enableCoroutine(true);
        $http->start();
    }

    private function getConnPool($type = 'mysql')
    {
        return $this->getConnectionPool($type);
    }

    private function dispatch()
    {
        $_dispatch = function(\Alita\Request $request,\Alita\Response $response)
        {
            $service = Service::instance();

            //默认走目录寻址
            $Router = $this->getProvider(RulesRoute::class);

            $pathInfo = $request->server('request_method') . " " .$request->server('path_info');

            try {

                //获取连接池
                if (Setting::$app_mysql) {
                    $mysqlConn = $this->getConnPool('mysql');
                    $mysql = $mysqlConn->borrow();
                    $service->set('mysql', $mysql);
                    $service->set('orm', new ORM());
                }

                if (Setting::$app_redis) {
                    $redisConn = $this->getConnPool('redis');
                    $redis = $redisConn->borrow();
                    $service->set('redis', $redis);
                }

                //硬路由
                if (!empty($this->_coreRULEs)) {
                    list($cb,$params) = $Router->getCbParams($pathInfo);

                    if ($cb !== null) {
                        call_user_func_array($cb,array_merge([$request,$response],$params));

                        //todo 后面要重整
                        if (Setting::$app_mysql) {
                            $mysqlConn->return($mysql);
                        }

                        if (Setting::$app_redis) {
                            $redisConn->return($redis);
                        }

                        return $content;
                    }

                    throw new NoFoundRule([
                        'path_info' => $pathInfo,
                        'message' => "{{$pathInfo}} Routing Rules No Found",
                    ]);
                }

                list($controller,$action,$params) = $Router->getControllerActionParams($pathInfo);

                //路由级中间件
                if($Router->isMatch) {
                    $this->middlewareProcess('route',$request,$response,$Router);
                }

                //找不到路由匹配
                if (!$Router->isMatch) {
                    throw new HttpNotFound([
                        'path_info' => $pathInfo,
                        'controller' => $controller,
                        'action' => $action,
                        'params' => implode(',', $params),
                        'message' => "Http Not found",
                    ]);
                }


                if (!class_exists($controller)) {

                    throw new NoFoundController([
                        'path_info' => $pathInfo,
                        'controller' => $controller,
                        'action' => $action,
                        'params' => implode(',', $params),
                        'message' => "{{$controller}} No Found Controller",
                    ]);
                }

                $controllerObj = new $controller();

                if (!method_exists($controllerObj, $action)) {
                    throw new NoFoundAction([
                        'path_info' => $pathInfo,
                        'controller' => $controller,
                        'action' => $action,
                        'params' => implode(',', $params),
                        'message' => "{{$action}} No Found Action",
                    ]);
                }

                $content = call_user_func_array([
                    $controllerObj,
                    $action
                ],
                    $params
                );

                if (empty($content)) {
                    $content = '';
                }

                if (Setting::$app_mysql) {
                    $mysqlConn->return($mysql);
                }

                if (Setting::$app_redis) {
                    $redisConn->return($redis);
                }

                return $content;

            }catch(WorkerException $e) { //普通业务中断
                return $e;
            }catch (\Throwable $e) {
                return $e;
            }
        };

        return function (\Swoole\Http\Request $request,\Swoole\Http\Response $response) use($_dispatch)
        {
            //http请求开始
            $service = Service::instance();

            $_request = new Request($request);
            $_response = new Response($response,$request);

            $service->set('Request',$_request);
            $service->set('Response',$_response);

            //注册全局对象
            foreach($this->_service as $k => $v) {
                $service->set($k,$v());
            }

            //注册全局中间件
            //中间件 兼容函数和对象
            foreach($this->_middleware as $name => &$handle) {
                if(!is_callable($handle)) {
                    //类
                    $handle = function ($request,$response) use($handle){
                        return (new $handle())->handle($request,$response);
                    };
                }
            }

//            //系统级中间件
            $this->middlewareProcess('system',$_request,$_response);

            $_response->end($_dispatch($_request,$_response));

        };
    }

    private function slogan($version)
    {
        print <<<SLOGAN

   .--,       .--,
  ( (  \.---./  ) )
   '.__/o   o\__.'
      {=  ^  =}
       >  -  <
      /       \
     //       \\
    //|   .   |\\
    "'\       /'"_.-~^`'-.
       \  _  /--'         `
     ___)( )(___
    (((__) (__)))    山穷水尽疑无路 柳暗花明又一村

Alita Server {$version} Started ....

SLOGAN;
    }

    public function Run()
    {
        $this->slogan(AUTHOR::VERSION);

        $this->engine($this->dispatch());
    }
}

//用户级对象
//外部对象
class Service
{
    private $o = [];

    use getInstance;

    public function get($key)
    {
        return $this->o[$key];
    }

    public function set($key,$val)
    {
        $this->o[$key] = $val;
    }

    public static function __callStatic($name, $arguments)
    {
        return (static::instance()->get($name));
    }
}

class Request
{
    private $container = [];
    private $request = null;

    public function __construct(\Swoole\Http\Request $request)
    {
        $this->request = $request;
    }

    public function input(string $key='')
    {
        $_get = $this->request->get ?? [];
        $_post = $this->request->post ?? [];

        $request = array_merge($_get,$_post);

        return $key ? $request[$key] : $request;
    }

    public function server(string $key='')
    {
        return $key ? $this->request->server[$key] : $this->request->server;
    }

    //设置中间值
    public function set($key,$val)
    {
        $this->container[$key] = $val;
    }

    public function get($key)
    {
        return $this->container[$key];
    }
}

trait getInstance
{
    public static $_instance = null;

    public static function instance()
    {
        if (self::$_instance == null) {
            self::$_instance = new static();
        }
        return self::$_instance;
    }
}

class Response
{

    private $response = null;

    public function __construct(\Swoole\Http\Response $response,\Swoole\Http\Request $request)
    {
        $this->response = $response;
        $this->request = $request;
    }

    //给错误用
    private function getTraceAsString($file,$line,$message)
    {
        $_message = <<<EOD
            #1 : $file : $line
            #2 : $message
EOD;
        return $_message;

    }

    private function write($httpCode = 200,$msg)
    {

        Log::print([
            'protocol' => $this->request->server['server_protocol'],
            'client_ip' => $this->request->server['remote_addr'],
            'method' => $this->request->server['request_method'],
            'code' => $httpCode,
            'path' => $this->request->server['path_info'],
            'user_agent' => $this->request->header['user-agent'],
        ]);

        $this->response->status($httpCode);
        return $this->response->end($msg."\n");
    }

    public function redirect($url)
    {
        $this->response->header("Location", $url);
        $this->response->status(302);
        throw new WorkerException();
    }

    //中断
    public function abort()
    {
        $this->end();
        throw new WorkerException();
    }

    //输出字符串
    public function string(string $val)
    {
        $this->end($val);
        throw new WorkerException();
    }

    //输出json
    public function json(array $val)
    {
        $this->end($val);
        throw new WorkerException();
    }

    //最后输出
    public function end($content = '')
    {
        if (is_array($content)) {

            $this->response->header('Content-type', 'application/json');
            $this->write(200,json_encode($content));

        }elseif (is_string($content)) {

            $this->write(200,$content);

        }elseif($content instanceof WorkerException) {

            return ;

        }elseif($content instanceof NoFoundRule){

            $this->write(404,$content->getMessage());

        }elseif($content instanceof HttpNotFound){
            //404
            $this->write(404,$content->getMessage());

        }elseif($content instanceof NoFoundController) {

            $this->write(404,$content->getMessage());

        }elseif($content instanceof NoFoundAction) {

            $this->write(404,$content->getMessage());

        }elseif($content instanceof \Exception ){

            $this->write(500,$content->getTraceAsString());

        }elseif($content instanceof \Error) {

            $this->write(500,$this->getTraceAsString($content->getFile(),$content->getLine(),$content->getMessage()));

        }
    }
}

class BaseController
{
    protected $Request = null;
    protected $Response = null;

    public function __construct()
    {
        $this->Request = Service::Request();
        $this->Response = Service::Response();
    }
}

class BaseModel
{
    protected $db = null;

    public function __construct()
    {
        static::initialize();
    }

    protected function initialize()
    {
        $this->db = Service::orm()->setConnect(Service::mysql());
    }

    protected function db()
    {
        return $this->db;
    }
}