<?php
declare(strict_types=1);

$host = $_SERVER['HTTP_HOST'] ?? '';
header('Content-Type: text/plain; charset=utf-8');

$isStaging = stripos($host, 'staging') !== false;

echo "User-agent: *\n";

if ($isStaging) {
    echo "Disallow: /\n";
} else {
    echo "Allow: /\n";
}

echo "\nSitemap: https://" . $host . "/sitemap.xml\n";