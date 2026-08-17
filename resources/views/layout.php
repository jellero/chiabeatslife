<?php
declare(strict_types=1);

/** @var array<string,mixed> $page */
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$attrs = static function (mixed $values) use ($escape): string {
    if (!is_array($values)) {
        return '';
    }
    $out = '';
    foreach ($values as $name => $value) {
        if (!is_string($name) || $name === '' || $value === null || $value === false) {
            continue;
        }
        if ($value === true || $value === '') {
            $out .= ' ' . $escape($name);
            continue;
        }
        $out .= ' ' . $escape($name) . '="' . $escape($value) . '"';
    }
    return $out;
};

$lang = (string) ($page['lang'] ?? 'it-IT');
$title = (string) ($page['title'] ?? 'chiabeatslife');
$headHtml = (string) ($page['head_html'] ?? '');
$bodyHtml = (string) ($page['body_html'] ?? '');
$htmlAttributes = is_array($page['html_attributes'] ?? null) ? $page['html_attributes'] : [];
$bodyAttributes = is_array($page['body_attributes'] ?? null) ? $page['body_attributes'] : [];
$htmlAttributes['lang'] = $htmlAttributes['lang'] ?? $lang;
?>
<!doctype html>
<html<?= $attrs($htmlAttributes) ?>>
<head>
<meta charset="UTF-8">
<title><?= $escape($title) ?></title>
<?= $headHtml ?>
</head>
<body<?= $attrs($bodyAttributes) ?>>
<?= $bodyHtml ?>
</body>
</html>
