# namespaceify

Add a namespace to the file and replace the function call in the specified directory with a function call in the specified namespace


# Installation

```bash
composer require ajiho/namespaceify
```

# Usage

Use special use cases to demonstrate usage

```php
$filename = dirname(__DIR__) . '/vendor/cakephp/core/functions.php';
$pkgDir = dirname(__DIR__) . '/vendor/cakephp';
$parser = new \ajiho\namespaceify\Parser($pkgDir, $filename, 'Cake\Core');
$parser->run();
```

```php

$filename = dirname(__DIR__) . '/vendor/illuminate/support/helpers.php';
$pkgDir = dirname(__DIR__) . '/vendor/illuminate';
$parser = new \ajiho\namespaceify\Parser($pkgDir, $filename, 'Illuminate\Support');
$parser->run();
```

