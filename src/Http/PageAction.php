<?php
declare(strict_types=1);

namespace Chiabeatslife\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class PageAction
{
    public function __construct(private readonly string $root)
    {
    }

    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $pageId,
        int $status = 200
    ): ResponseInterface {
        $pageFile = $this->root . '/storage/pages/' . basename($pageId) . '.json';
        if (!is_file($pageFile)) {
            return $this->notFound($request, $response);
        }

        $raw = file_get_contents($pageFile);
        if ($raw === false) {
            throw new RuntimeException('Impossibile leggere la pagina: ' . $pageId);
        }
        $page = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($page)) {
            throw new RuntimeException('Snapshot pagina non valido: ' . $pageId);
        }

        $html = $this->renderLayout($page);
        $response->getBody()->write($html);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'public, max-age=120');
    }

    public function notFound(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $page = [
            'lang' => 'it-IT',
            'title' => 'Pagina non trovata – chiabeatslife',
            'head_html' => '<meta name="robots" content="noindex,follow">',
            'body_class' => 'chiabeatslife-not-found',
            'body_html' => '<main style="max-width:960px;margin:10vh auto;padding:2rem;font-family:system-ui,sans-serif"><h1>Pagina non trovata</h1><p>La pagina richiesta non esiste.</p><p><a href="/">Torna alla home</a></p></main>',
            'source_url' => $path,
        ];
        $response->getBody()->write($this->renderLayout($page));

        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $page */
    private function renderLayout(array $page): string
    {
        $layout = $this->root . '/resources/views/layout.php';
        if (!is_file($layout)) {
            throw new RuntimeException('Layout applicativo mancante.');
        }

        ob_start();
        require $layout;
        $html = ob_get_clean();
        if ($html === false) {
            throw new RuntimeException('Rendering della pagina non riuscito.');
        }
        return $html;
    }
}
