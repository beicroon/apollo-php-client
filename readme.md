### 安装
```zsh
composer require beicroon/apollo-php-client
```

### 使用
```php
use Beicroon\ApolloClient;
use GuzzleHttp\Exception\GuzzleException;

$server = 'http://127.0.0.1:8080';

$appId = 'your-app-id';

$namespaces = ['common', 'application'];

$client = ApolloClient::make($server, $appId, $namespaces)
    ->setCluster('default')
    ->setClientIp('127.0.0.1');

$env = __DIR__.DIRECTORY_SEPARATOR.'.env';

while (true) {
    try {
        if ($client->listen($env)) {
            // success
        }
    } catch (GuzzleException $exception) {
        // http error
    }

    sleep(60);
}
```