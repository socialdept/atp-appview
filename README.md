<h1 align="center">ATP AppView</h1>

<h3 align="center">
    Build lexicon-driven AT Protocol AppViews in Laravel.
</h3>

<p align="center">
    <br>
    <a href="https://packagist.org/packages/socialdept/atp-appview" title="Latest Version on Packagist"><img src="https://img.shields.io/packagist/v/socialdept/atp-appview.svg?style=flat-square"></a>
    <a href="https://github.com/socialdept/atp-appview/actions/workflows/tests.yml" title="GitHub Tests Action Status"><img src="https://img.shields.io/github/actions/workflow/status/socialdept/atp-appview/tests.yml?branch=main&label=tests&style=flat-square"></a>
    <a href="LICENSE" title="Software License"><img src="https://img.shields.io/github/license/socialdept/atp-appview?style=flat-square"></a>
</p>

---

> [!WARNING]
> **In early development.** Nothing here is ready to use yet. Expect breaking changes until the first tagged release.

This package turns a Laravel application into an AT Protocol AppView. It syncs the collections you name from the network, keeps every record in your own database, and serves them through XRPC methods built from your lexicons. To learn all about it, head over to [the documentation](https://socialde.pt/docs/atp-appview).

Here are a few short examples of what you can do. Name the collections to sync from the network:

```php
// config/atp-appview.php
'ingest' => [
    'collections' => ['app.example.post', 'app.example.comment', 'app.example.like'],
],
```

Query what arrives with Eloquent:

```php
Record::collection('app.example.post')->by($did)->latest('sort_at')->get();
```

Serve a collection straight from the index, with no handler to write:

```php
// config/atp-appview.php
'xrpc' => [
    'collections' => ['com.example.listThings' => 'com.example.thing'],
],
```

Or write a handler of your own when the default is not enough:

```php
#[Xrpc('com.example.getTimeline', auth: Auth::Required)]
class GetTimeline
{
    public function __invoke(XrpcRequest $request): array
    {
        return ['feed' => []];
    }
}
```

Beyond the core, turn on what your app needs, from feeds and search to moderation and notifications:

```php
'features' => [
    Features::moderation(),
    Features::notifications(['latest' => 5]),
    Features::graph(),
],
```

It builds on [atp-client](https://github.com/socialdept/atp-client) for OAuth, service auth and forwarding, and on [atp-schema](https://github.com/socialdept/atp-schema) for lexicons.

## Documentation

You'll find the documentation on [https://socialde.pt/docs/atp-appview](https://socialde.pt/docs/atp-appview).

Find yourself stuck using the package? Found a bug? Do you have general questions or suggestions for improving it? Feel free to [create an issue on GitHub](https://github.com/socialdept/atp-appview/issues).

## Testing

```bash
vendor/bin/phpunit
npm test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
