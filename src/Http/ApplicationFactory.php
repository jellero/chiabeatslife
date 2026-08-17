<?php
declare(strict_types=1);

namespace Chiabeatslife\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

final class ApplicationFactory
{
    public static function create(string $root): App
    {
        $app = SlimAppFactory::create();

        $basePath = trim((string) getenv('APP_BASE_PATH'));
        if ($basePath !== '') {
            $app->setBasePath('/' . trim($basePath, '/'));
        }

        $pages = new PageAction($root);
        $definitions = require $root . '/config/routes.php';
        if (!is_array($definitions)) {
            throw new RuntimeException('Configurazione route non valida.');
        }

        $registeredAliases = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $name = (string) ($definition['name'] ?? 'page');
            $path = (string) ($definition['path'] ?? '');
            $pageId = (string) ($definition['page'] ?? '');
            if ($path === '' || $pageId === '') {
                continue;
            }

            $route = $app->map(['GET', 'HEAD'], $path, static fn (
                ServerRequestInterface $request,
                ResponseInterface $response
            ): ResponseInterface => $pages->render($request, $response, $pageId));
            $route->setName($name);

            // WordPress usa normalmente lo slash finale: lo conserviamo come URL canonico.
            if ($path !== '/' && str_ends_with($path, '/')) {
                $alias = rtrim($path, '/');
                if ($alias !== '' && !isset($registeredAliases[$alias])) {
                    $registeredAliases[$alias] = true;
                    $app->map(['GET', 'HEAD'], $alias, static function (
                        ServerRequestInterface $request,
                        ResponseInterface $response
                    ) use ($path): ResponseInterface {
                        $query = $request->getUri()->getQuery();
                        $target = $path . ($query !== '' ? '?' . $query : '');
                        return $response->withHeader('Location', $target)->withStatus(308);
                    });
                }
            }
        }

        $app->get('/api/v1/health', static function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ): ResponseInterface {
            $payload = json_encode([
                'status' => 'ok',
                'application' => 'chiabeatslife',
                'framework' => 'slim-4',
                'architecture' => 'front-controller',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response
                ->withHeader('Content-Type', 'application/json; charset=UTF-8')
                ->withHeader('Cache-Control', 'no-store');
        });

        $app->get('/sitemap.xml', static function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($definitions): ResponseInterface {
            $uri = $request->getUri();
            $origin = $uri->getScheme() . '://' . $uri->getHost();
            if ($uri->getPort() !== null) {
                $origin .= ':' . $uri->getPort();
            }

            $urls = [];
            foreach ($definitions as $definition) {
                if (!is_array($definition)) {
                    continue;
                }
                $path = (string) ($definition['path'] ?? '');
                if ($path !== '') {
                    $urls[] = $origin . $path;
                }
            }
            $urls = array_values(array_unique($urls));

            $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
            foreach ($urls as $url) {
                $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
            }
            $xml .= "</urlset>\n";
            $response->getBody()->write($xml);
            return $response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
        });

        $app->map(['GET', 'HEAD'], '/{path:.*}', static fn (
            ServerRequestInterface $request,
            ResponseInterface $response
        ): ResponseInterface => $pages->notFound($request, $response));

        $app->addRoutingMiddleware();
        $debug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);
        $app->addErrorMiddleware($debug, true, true);

        return $app;
    }
}
