<?php

namespace Beicroon;

use GuzzleHttp\RequestOptions;
use GuzzleHttp\Client as HttpClient;

class ApolloClient
{
    protected string $server;

    protected string $appId;

    protected string $cluster;

    protected string $clientIp;

    protected array $namespaces = [];

    protected array $environments = [];

    protected array $notifications = [];

    protected array $releaseKeys = [];

    protected HttpClient $httpClient;

    public static function make(string $server, string $appId, array $namespaces)
    {
        return new static($server, $appId, $namespaces);
    }

    public function __construct(string $server, string $appId, array $namespaces)
    {
        $this->server = $server;

        $this->appId = $appId;

        $this->namespaces = $namespaces;

        $this->httpClient = new HttpClient();
    }

    /**
     * 设置集群名
     *
     * @param  string  $cluster
     * @return $this
     */
    public function setCluster(string $cluster)
    {
        $this->cluster = $cluster;

        return $this;
    }

    /**
     * 设置客户端 ip
     *
     * @param  string  $ip
     * @return $this
     */
    public function setClientIp(string $ip)
    {
        $this->clientIp = $ip;

        return $this;
    }

    /**
     * 监听配置变化写入配置文件
     *
     * @param  string  $file
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function listen(string $file)
    {
        // 获取更新信息
        if ($notifications = $this->compareNotifications()) {
            // 获取变动后的配置
            if (!empty($environments = $this->pullEnvironments($this->sortNotifications($notifications)))) {
                // 写入文件
                if ($this->putEnvironments($file, $this->filter($environments))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 直接拉取最新的配置
     *
     * @param  string  $file
     * @return false|int
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function pull(string $file)
    {
        $environments = [];

        foreach ($this->namespaces as $namespace) {
            // 获取最新的数据
            if ($configurations = $this->pullConfigurations($namespace, false)) {
                // 写入数组
                $environments[$namespace] = $configurations;
            }
        }

        // 写入文件
        return $this->putEnvironments($file, $this->filter($environments));
    }

    /**
     * 获取版本配置
     *
     * @return array
     */
    public function getNotifications()
    {
        return $this->notifications;
    }

    /**
     * 设置版本配置
     *
     * @param  array  $notifications
     * @return $this
     */
    public function setNotifications(array $notifications)
    {
        $this->notifications = $notifications;

        return $this;
    }

    /**
     * 获取带命名空间的配置
     *
     * @return array
     */
    public function getOriginalEnvironments()
    {
        return $this->environments;
    }

    /**
     * 获取最后的配置信息
     *
     * @return array
     */
    public function getEnvironments()
    {
        return $this->filter($this->environments);
    }

    /**
     * 获取最后的版本信息
     *
     * @return array
     */
    public function getReleaseKeys()
    {
        return $this->releaseKeys;
    }

    /**
     * 过滤和去重
     *
     * @param  array  $environments
     * @return array
     */
    protected function filter(array $environments)
    {
        $cache = $results = [];

        foreach ($environments as $namespace => $environment) {
            $result = [];

            foreach ($environment as $key => $value) {
                if (isset($cache[$key])) {
                    unset($results[$cache[$key]][$key]);
                }

                $cache[$key] = $namespace;

                if (isset($results[$key])) {
                    unset($results[$key]);
                }

                $result[$key] = $value;
            }

            $results[$namespace] = $result;
        }

        return $results;
    }

    /**
     * 将配置写入文件
     *
     * @param  string  $file
     * @param  array  $environments
     * @return false|int
     */
    protected function putEnvironments(string $file, array $environments)
    {
        $this->mkdir($file);

        $contents = '';

        foreach ($environments as $namespace => $environment) {
            $contents .= "\n##### {$namespace} #####\n";

            foreach ($environment as $key => $value) {
                $contents .= "{$key}={$value}\n";
            }
        }

        return file_put_contents($file, $contents);
    }

    /**
     * 创建配置文件路径
     *
     * @param  string  $file
     * @return string
     */
    protected function mkdir(string $file)
    {
        if (!is_dir($path = dirname($file))) {
            mkdir($path, 0775);
        }

        return $path;
    }

    /**
     * 获取最新的配置
     *
     * @param  array  $notifications
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function pullEnvironments(array $notifications)
    {
        foreach ($notifications as $notification) {
            if (isset($notification['namespaceName'])) {
                // 获取最新的数据
                if ($configurations = $this->pullConfigurations($namespace = $notification['namespaceName'])) {

                    // 写入数组
                    $this->environments[$namespace] = $configurations;

                    // 更新缓存中的 notificationId
                    if (isset($notification['notificationId'])) {
                        $this->notifications[$namespace] = $notification['notificationId'];
                    }
                }
            }
        }

        return $this->environments;
    }

    /**
     * 将配置按传入命名空间排序
     *
     * @param  array  $notifications
     * @return array
     */
    protected function sortNotifications(array $notifications)
    {
        $results = [];

        $notifications = $this->keyByNamespace($notifications);

        foreach ($this->namespaces as $namespace) {
            // 有变更的才写入
            if (isset($notifications[$namespace])) {
                $results[] = $notifications[$namespace];
            }
        }

        return $results;
    }

    /**
     * 按命名空间排序
     *
     * @param  array  $notifications
     * @return array
     */
    protected function keyByNamespace(array $notifications)
    {
        $results = [];

        foreach ($notifications as $notification) {
            $results[$notification['namespaceName']] = $notification;
        }

        return $results;
    }

    /**
     * 获取命名空间下最新的配置
     *
     * @param  string  $namespace
     * @param  bool  $cache
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function pullConfigurations(string $namespace, bool $cache = true)
    {
        // 获取最新的配置
        $config = $this->httpGet($this->getNotificationUrl($namespace), [
            'ip' => $this->getClientIp(),
            'releaseKey' => $this->getReleaseKey($namespace),
        ]);

        if (empty($config)) {
            return [];
        }

        if ($cache && isset($config['releaseKey'])) {
            $this->releaseKeys[$namespace] = $config['releaseKey'];
        }

        return $config['configurations'] ?? [];
    }

    /**
     * 获取用于灰度发布的 ip
     *
     * @return string
     */
    protected function getClientIp()
    {
        return $this->clientIp ?? '';
    }

    /**
     * 获取版本 key
     *
     * @param  string  $namespace
     * @return string
     */
    protected function getReleaseKey(string $namespace)
    {
        return $this->releaseKeys[$namespace] ?? '';
    }

    /**
     * 获取配置的 url
     *
     * @param  string  $namespace
     * @return string
     */
    protected function getNotificationUrl(string $namespace)
    {
        return $this->server.sprintf('/configs/%s/%s/%s', $this->appId, $this->getCluster(), $namespace);
    }

    /**
     * 比较版本信息
     *
     * @return array|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function compareNotifications()
    {
        return $this->httpGet($this->getUrl('/notifications/v2'), [
            'appId' => $this->appId,
            'cluster' => $this->getCluster(),
            'notifications' => $this->getNotificationString(),
        ]);
    }

    /**
     * 获取集群名
     *
     * @return string
     */
    protected function getCluster()
    {
        return $this->cluster ?? 'default';
    }

    /**
     * 将命名空间遍历为 notifications 的 json 字符串
     *
     * @return string
     */
    protected function getNotificationString()
    {
        return json_encode(array_map([$this, 'getNotification'], $this->namespaces));
    }

    /**
     * 获取 notification 参数
     *
     * @param  string  $namespace
     * @return array
     */
    protected function getNotification(string $namespace)
    {
        return ['namespaceName' => $namespace, 'notificationId' => $this->getNotificationId($namespace)];
    }

    /**
     * 获取对应 namespace 的 notificationId
     *
     * @param  string  $namespace
     * @return int
     */
    protected function getNotificationId(string $namespace)
    {
        return $this->notifications[$namespace] ?? -3;
    }

    /**
     * 获取请求的 url
     *
     * @param  string  $uri
     * @return string
     */
    protected function getUrl(string $uri)
    {
        return trim($this->server, '/').'/'.trim($uri, '/');
    }

    /**
     * 发起 http 请求
     *
     * @param  string  $url
     * @param  array  $parameters
     * @return array|mixed|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function httpGet(string $url, array $parameters = [])
    {
        // 发起 http 请求
        $response = $this->httpClient->get($url, [
            RequestOptions::QUERY => $parameters,
            RequestOptions::HEADERS => $this->getHeaders(),
        ]);

        // 成功获取最新的配置
        if (200 === $status = $response->getStatusCode()) {
            return json_decode($response->getBody()->getContents(), true);
        }

        // 未更改 不做变更
        if (304 === $status) {
            return [];
        }

        return null;
    }

    /**
     * 默认 headers
     *
     * @return string[]
     */
    protected function getHeaders()
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }
}