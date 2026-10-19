<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function api_blog_http_url(mixed $value, string $field, int $max = 1000): string {
    $url = api_string($value, $field, 0, $max);
    if ($url === '') {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException("O campo {$field} precisa ser uma URL válida.");
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException("O campo {$field} aceita apenas HTTP ou HTTPS.");
    }
    return $url;
}

function api_blog_datetime(mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value);
        $timezone = new DateTimeZone(date_default_timezone_get());
        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        throw new InvalidArgumentException('published_at precisa ser uma data ISO 8601 válida.');
    }
}

function api_blog_tags(mixed $value): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new InvalidArgumentException('tags precisa ser uma lista de textos.');
    }
    if (count($value) > 20) {
        throw new InvalidArgumentException('Cada post aceita no máximo 20 tags.');
    }

    $tags = [];
    $seen = [];
    foreach ($value as $item) {
        $tag = api_string($item, 'tags[]', 2, 120);
        $slug = blog_slugify($tag);
        if ($slug === '' || isset($seen[$slug])) {
            continue;
        }
        $seen[$slug] = true;
        $tags[] = ['tag' => $tag, 'slug' => substr($slug, 0, 120)];
    }
    return $tags;
}

function api_blog_sources(mixed $value): array {
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new InvalidArgumentException('sources precisa ser uma lista de objetos.');
    }
    if (count($value) > 20) {
        throw new InvalidArgumentException('Cada post aceita no máximo 20 fontes.');
    }

    $sources = [];
    $seen = [];
    foreach ($value as $item) {
        if (!is_array($item) || array_is_list($item)) {
            throw new InvalidArgumentException('Cada item de sources precisa ser um objeto.');
        }
        $url = api_blog_http_url($item['url'] ?? '', 'sources[].url');
        if ($url === '') {
            throw new InvalidArgumentException('sources[].url é obrigatório.');
        }
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $title = api_string($item['title'] ?? '', 'sources[].title', 0, 255);
        if ($title === '') {
            $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
            $title = $host !== '' ? $host : 'Fonte';
        }
        $sources[] = ['title' => $title, 'url' => $url];
    }
    return $sources;
}

function api_blog_public_ip(string $host): string {
    $host = trim($host, '[]');
    $lower = strtolower($host);
    if ($host === '' || $lower === 'localhost' || str_ends_with($lower, '.local')) {
        throw new InvalidArgumentException('A imagem aponta para um host não permitido.');
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            $records = [];
        }
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = (string)$record['ip'];
            } elseif (!empty($record['ipv6'])) {
                $ips[] = (string)$record['ipv6'];
            }
        }
    }

    if ($ips === []) {
        throw new InvalidArgumentException('Não foi possível resolver o host da imagem.');
    }

    foreach (array_unique($ips) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }

    throw new InvalidArgumentException('A imagem não pode apontar para rede privada, local ou reservada.');
}

function api_blog_validate_image_url(string $url): array {
    $url = api_blog_http_url($url, 'image.url', 2000);
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new InvalidArgumentException('image.url precisa ser uma URL válida.');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('image.url não pode conter usuário ou senha.');
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? '')));
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($host === '') {
        throw new InvalidArgumentException('image.url precisa informar um host.');
    }
    if (!in_array($port, [80, 443], true)) {
        throw new InvalidArgumentException('image.url aceita somente as portas 80 e 443.');
    }

    return [
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'ip' => api_blog_public_ip($host),
    ];
}

function api_blog_redirect_url(string $base, string $location): string {
    $location = trim($location);
    if ($location === '') {
        throw new InvalidArgumentException('A imagem respondeu com redirecionamento inválido.');
    }
    if (preg_match('~^https?://~i', $location)) {
        return $location;
    }

    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new InvalidArgumentException('Não foi possível resolver o redirecionamento da imagem.');
    }

    $origin = (string)$parts['scheme'] . '://' . (string)$parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int)$parts['port'];
    }

    if (str_starts_with($location, '//')) {
        return (string)$parts['scheme'] . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }

    $path = (string)($parts['path'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    $segments = [];
    foreach (explode('/', ($dir === '' ? '' : $dir) . '/' . $location) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return $origin . '/' . implode('/', $segments);
}

function api_blog_image_input(array $input): array {
    $provided = array_key_exists('image', $input)
        || array_key_exists('image_url', $input)
        || array_key_exists('cover_image_url', $input)
        || api_bool($input['remove_image'] ?? null, false);

    $image = $input['image'] ?? null;
    $url = '';
    $alt = '';
    $credit = '';
    $sourceUrl = '';

    if (is_array($image) && !array_is_list($image)) {
        $url = api_string($image['url'] ?? '', 'image.url', 0, 2000);
        $alt = api_string($image['alt'] ?? '', 'image.alt', 0, 255);
        $credit = api_string($image['credit'] ?? '', 'image.credit', 0, 255);
        $sourceUrl = api_blog_http_url($image['source_url'] ?? '', 'image.source_url');
    } elseif ($image !== null && !is_array($image)) {
        $url = api_string($image, 'image', 0, 2000);
    }

    if ($url === '') {
        $url = api_string(
            $input['image_url'] ?? ($input['cover_image_url'] ?? ''),
            'image_url',
            0,
            2000
        );
    }
    if ($url !== '') {
        $url = api_blog_http_url($url, 'image.url', 2000);
    }
    if ($sourceUrl === '' && $url !== '') {
        $sourceUrl = $url;
    }

    return [
        'provided' => $provided,
        'remove' => api_bool($input['remove_image'] ?? null, false),
        'url' => $url,
        'alt' => $alt,
        'credit' => $credit,
        'source_url' => $sourceUrl,
    ];
}

function api_blog_download_image(string $url, bool $persist): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP é necessária para baixar image.url.');
    }

    $current = $url;
    for ($redirect = 0; $redirect <= 3; $redirect++) {
        $target = api_blog_validate_image_url($current);
        $tmp = tempnam(sys_get_temp_dir(), 'pergunta-blog-');
        if ($tmp === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para a imagem.');
        }

        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível abrir arquivo temporário para a imagem.');
        }

        $received = 0;
        $tooLarge = false;
        $location = '';
        $ch = curl_init($current);
        if ($ch === false) {
            fclose($fp);
            @unlink($tmp);
            throw new RuntimeException('Não foi possível iniciar o download da imagem.');
        }

        $resolvedIp = str_contains((string)$target['ip'], ':')
            ? '[' . $target['ip'] . ']'
            : (string)$target['ip'];

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Pergunta.Online Blog Image Fetcher/1.1',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $resolvedIp],
            CURLOPT_HEADERFUNCTION => static function($curl, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function($curl, string $chunk) use ($fp, &$received, &$tooLarge): int {
                $length = strlen($chunk);
                $received += $length;
                if ($received > 8 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $written = fwrite($fp, $chunk);
                return $written === false ? 0 : $written;
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if ($tooLarge) {
            @unlink($tmp);
            throw new InvalidArgumentException('A imagem remota ultrapassa o limite de 8 MB.');
        }

        if ($status >= 300 && $status < 400 && $location !== '') {
            @unlink($tmp);
            if ($redirect >= 3) {
                throw new InvalidArgumentException('A imagem excedeu o limite de redirecionamentos.');
            }
            $current = api_blog_redirect_url($current, $location);
            continue;
        }

        if ($ok === false || $status < 200 || $status >= 300 || $received <= 0) {
            @unlink($tmp);
            throw new InvalidArgumentException('Não foi possível baixar a imagem.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $dimensions = @getimagesize($tmp);
        if (!in_array($mime, $allowed, true) || !is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
            @unlink($tmp);
            throw new InvalidArgumentException('O arquivo remoto não é uma imagem JPG, PNG, WEBP ou GIF válida.');
        }

        $width = (int)$dimensions[0];
        $height = (int)$dimensions[1];
        if ($width > 12000 || $height > 12000 || ($width * $height) > 60000000) {
            @unlink($tmp);
            throw new InvalidArgumentException('A resolução da imagem é muito grande.');
        }

        $path = '';
        if ($persist) {
            try {
                $path = blog_store_downloaded_image($tmp, $received);
            } catch (Throwable $e) {
                @unlink($tmp);
                throw new InvalidArgumentException($e->getMessage(), 0, $e);
            }
        } else {
            @unlink($tmp);
        }

        return [
            'source_url' => $url,
            'final_url' => $current,
            'path' => $path,
            'mime' => $mime,
            'size' => $received,
            'width' => $width,
            'height' => $height,
        ];
    }

    throw new InvalidArgumentException('Não foi possível baixar a imagem.');
}

function api_blog_find_existing(string $externalId, string $sourceUrl, string $title): ?array {
    if ($externalId !== '') {
        $stmt = db()->prepare('SELECT * FROM blog_posts WHERE external_id=? LIMIT 1');
        $stmt->execute([$externalId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    if ($sourceUrl !== '') {
        $stmt = db()->prepare('SELECT * FROM blog_posts WHERE source_url=? ORDER BY id LIMIT 1');
        $stmt->execute([$sourceUrl]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    if ($externalId === '' && $sourceUrl === '') {
        $stmt = db()->prepare('SELECT * FROM blog_posts WHERE title=? ORDER BY id LIMIT 1');
        $stmt->execute([$title]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    return null;
}

function api_blog_status(array $input, ?string $publishedAt): array {
    if (array_key_exists('status', $input)) {
        $status = strtolower(api_string($input['status'], 'status', 1, 20));
        if (!in_array($status, ['draft', 'scheduled', 'published'], true)) {
            throw new InvalidArgumentException('status precisa ser draft, scheduled ou published.');
        }
    } else {
        $published = api_bool($input['published'] ?? null, true);
        $status = $published ? 'published' : 'draft';
    }

    if ($status === 'scheduled' && $publishedAt === null) {
        throw new InvalidArgumentException('published_at é obrigatório quando status=scheduled.');
    }

    if ($status === 'published' && $publishedAt === null) {
        $publishedAt = date('Y-m-d H:i:s');
    }

    if ($status !== 'draft' && $publishedAt !== null) {
        $status = strtotime($publishedAt) > time() ? 'scheduled' : 'published';
    }

    return [
        'status' => $status,
        'published' => $status === 'draft' ? 0 : 1,
        'published_at' => $publishedAt,
    ];
}

function api_blog_prepare(array $input, array $actor): array {
    $title = api_string($input['title'] ?? '', 'title', 3, 255);
    $content = api_string($input['content'] ?? ($input['body'] ?? ''), 'content', 1);
    $safeContent = blog_sanitize_html($content);
    if ($safeContent === '') {
        throw new InvalidArgumentException('content ficou vazio depois da validação HTML.');
    }

    $externalIdInput = api_string($input['external_id'] ?? '', 'external_id', 0, 190);
    $sourceUrlInput = api_blog_http_url($input['source_url'] ?? '', 'source_url');
    $existing = api_blog_find_existing($externalIdInput, $sourceUrlInput, $title);

    $externalId = array_key_exists('external_id', $input)
        ? $externalIdInput
        : (string)($existing['external_id'] ?? '');
    $sourceUrl = array_key_exists('source_url', $input)
        ? $sourceUrlInput
        : (string)($existing['source_url'] ?? '');

    $excerpt = array_key_exists('excerpt', $input)
        ? api_string($input['excerpt'], 'excerpt')
        : (string)($existing['excerpt'] ?? '');
    $category = array_key_exists('category', $input)
        ? api_string($input['category'], 'category', 0, 120)
        : (string)($existing['category'] ?? '');

    $slugProvided = array_key_exists('slug', $input);
    $requestedSlug = $slugProvided
        ? api_string($input['slug'], 'slug', 0, 190)
        : (string)($existing['slug'] ?? '');

    $language = array_key_exists('language', $input)
        ? api_string($input['language'], 'language', 2, 20)
        : (string)($existing['language'] ?? 'pt_BR');
    if (!preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z]{2})?$/', $language)) {
        throw new InvalidArgumentException('language precisa usar formato como pt_BR, en ou es.');
    }

    $metaTitle = array_key_exists('meta_title', $input)
        ? api_string($input['meta_title'], 'meta_title', 0, 255)
        : (string)($existing['meta_title'] ?? '');
    $metaDescription = array_key_exists('meta_description', $input)
        ? api_string($input['meta_description'], 'meta_description', 0, 500)
        : (string)($existing['meta_description'] ?? '');
    $canonicalUrl = array_key_exists('canonical_url', $input)
        ? api_blog_http_url($input['canonical_url'], 'canonical_url')
        : (string)($existing['canonical_url'] ?? '');

    $publicationProvided = array_key_exists('status', $input)
        || array_key_exists('published', $input)
        || array_key_exists('published_at', $input);

    if ($existing && !$publicationProvided) {
        $visibility = [
            'status' => (string)($existing['status'] ?? ((int)$existing['published'] === 1 ? 'published' : 'draft')),
            'published' => (int)($existing['published'] ?? 0),
            'published_at' => $existing['published_at'] ?? null,
        ];
    } else {
        $publishedAt = api_blog_datetime($input['published_at'] ?? null);
        $visibility = api_blog_status($input, $publishedAt);
    }

    $authorId = (int)($input['author_user_id'] ?? ($input['user_id'] ?? ($existing['author_user_id'] ?? $actor['id'])));
    $authorQuery = db()->prepare('SELECT created_at FROM users WHERE id=? LIMIT 1');
    $authorQuery->execute([$authorId]);
    $authorCreated = $authorQuery->fetchColumn();
    if (!$authorCreated) {
        throw new InvalidArgumentException('author_user_id não corresponde a um usuário cadastrado.');
    }
    $now = date('Y-m-d H:i:s');
    $publishedAt = $visibility['published_at'];
    $suggestedCreated = $publishedAt !== null && $publishedAt <= $now ? $publishedAt : $now;
    $createdAt = api_historical_date($input['created_at'] ?? null, 'created_at')
        ?? (string)($existing['created_at'] ?? $suggestedCreated);
    if ($createdAt < (string)$authorCreated) {
        throw new InvalidArgumentException('created_at não pode anteceder o cadastro do autor.');
    }
    $updatedAt = api_historical_date($input['updated_at'] ?? null, 'updated_at', $createdAt)
        ?? ($existing ? $now : $createdAt);
    if ($publishedAt !== null && $visibility['status'] === 'published' && $publishedAt < $createdAt) {
        throw new InvalidArgumentException('published_at não pode anteceder created_at.');
    }
    if ($publishedAt !== null && $visibility['status'] === 'published' && $updatedAt < $publishedAt) {
        throw new InvalidArgumentException('updated_at não pode anteceder published_at.');
    }

    $tagsProvided = array_key_exists('tags', $input);
    $sourcesProvided = array_key_exists('sources', $input);
    $tags = api_blog_tags($input['tags'] ?? null);
    $sources = api_blog_sources($input['sources'] ?? null);

    $image = api_blog_image_input($input);
    $updateExisting = api_bool($input['update_existing'] ?? null, false);
    $dryRun = api_bool($input['dry_run'] ?? null, false);

    if ($existing && !$slugProvided) {
        $slug = (string)$existing['slug'];
    } else {
        $slugSource = $requestedSlug !== '' ? $requestedSlug : $title;
        $slug = blog_unique_slug(db(), $slugSource, $existing ? (int)$existing['id'] : 0);
    }

    $willWrite = !$dryRun && (!$existing || $updateExisting);
    $download = null;
    if (!$image['remove'] && $image['url'] !== '' && (!$existing || $updateExisting)) {
        $download = api_blog_download_image($image['url'], $willWrite);
    }

    return [
        'actor_id' => (int)$actor['id'],
        'author_id' => $authorId,
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
        'existing' => $existing,
        'update_existing' => $updateExisting,
        'dry_run' => $dryRun,
        'title' => $title,
        'external_id' => $externalId,
        'source_url' => $sourceUrl,
        'slug' => $slug,
        'excerpt' => $excerpt,
        'category' => $category,
        'content' => $safeContent,
        'language' => str_replace('-', '_', $language),
        'meta_title' => $metaTitle,
        'meta_description' => $metaDescription,
        'canonical_url' => $canonicalUrl,
        'status' => $visibility['status'],
        'published' => $visibility['published'],
        'published_at' => $visibility['published_at'],
        'tags_provided' => $tagsProvided,
        'sources_provided' => $sourcesProvided,
        'tags' => $tags,
        'sources' => $sources,
        'image' => $image,
        'download' => $download,
    ];
}

function api_blog_replace_tags(int $postId, array $tags): void {
    $delete = db()->prepare('DELETE FROM blog_post_tags WHERE post_id=?');
    $delete->execute([$postId]);
    if ($tags === []) {
        return;
    }

    $insert = db()->prepare('INSERT INTO blog_post_tags (post_id,tag,tag_slug) VALUES (?,?,?)');
    foreach ($tags as $tag) {
        $insert->execute([$postId, $tag['tag'], $tag['slug']]);
    }
}

function api_blog_replace_sources(int $postId, array $sources): void {
    $delete = db()->prepare('DELETE FROM blog_post_sources WHERE post_id=?');
    $delete->execute([$postId]);
    if ($sources === []) {
        return;
    }

    $insert = db()->prepare('INSERT INTO blog_post_sources (post_id,title,url) VALUES (?,?,?)');
    foreach ($sources as $source) {
        $insert->execute([$postId, $source['title'], $source['url']]);
    }
}

function api_blog_post_tags(int $postId): array {
    $stmt = db()->prepare('SELECT tag FROM blog_post_tags WHERE post_id=? ORDER BY tag');
    $stmt->execute([$postId]);
    return array_map(static fn(array $row): string => (string)$row['tag'], $stmt->fetchAll());
}

function api_blog_post_sources(int $postId): array {
    $stmt = db()->prepare('SELECT title,url FROM blog_post_sources WHERE post_id=? ORDER BY id');
    $stmt->execute([$postId]);
    return array_map(static fn(array $row): array => [
        'title' => (string)$row['title'],
        'url' => (string)$row['url'],
    ], $stmt->fetchAll());
}

function api_blog_public_url(string $path): string {
    $root = rtrim(api_root_url(), '/');
    if ($path === '') {
        return $root . '/assets/post-sem-image.jpg';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return $root . '/' . ltrim($path, '/');
}

function api_blog_result(array $row, string $mode, ?array $download = null): array {
    $id = (int)($row['id'] ?? 0);
    $result = [
        'id' => $id,
        'created' => $mode === 'created',
        'updated' => $mode === 'updated',
        'reused' => $mode === 'reused',
        'dry_run' => $mode === 'dry_run',
        'title' => (string)$row['title'],
        'author_user_id' => isset($row['author_user_id']) ? (int)$row['author_user_id'] : null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'external_id' => (string)($row['external_id'] ?? ''),
        'source_url' => (string)($row['source_url'] ?? ''),
        'slug' => (string)$row['slug'],
        'category' => (string)($row['category'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'published' => (int)($row['published'] ?? 0) === 1,
        'published_at' => $row['published_at'] ?? null,
        'language' => (string)($row['language'] ?? 'pt_BR'),
        'tags' => $id > 0 ? api_blog_post_tags($id) : [],
        'sources' => $id > 0 ? api_blog_post_sources($id) : [],
        'cover_image_url' => api_blog_public_url((string)($row['cover_image_url'] ?? '')),
        'url' => rtrim(api_root_url(), '/') . '/blog/' . rawurlencode((string)$row['slug']),
    ];

    if ($download !== null) {
        $result['image'] = [
            'downloaded' => true,
            'source_url' => (string)$download['source_url'],
            'final_url' => (string)$download['final_url'],
            'path' => (string)$download['path'],
            'url' => (string)$download['path'] !== ''
                ? api_blog_public_url((string)$download['path'])
                : null,
            'mime' => (string)$download['mime'],
            'size' => (int)$download['size'],
            'width' => (int)$download['width'],
            'height' => (int)$download['height'],
        ];
    }

    return $result;
}

function api_blog_apply(array $prepared, array &$oldImagesToDelete): array {
    $existing = $prepared['existing'];
    if ($prepared['dry_run']) {
        $preview = $existing ?: [
            'id' => 0,
            'author_user_id' => $prepared['author_id'],
            'created_at' => $prepared['created_at'],
            'updated_at' => $prepared['updated_at'],
            'title' => $prepared['title'],
            'external_id' => $prepared['external_id'],
            'source_url' => $prepared['source_url'],
            'slug' => $prepared['slug'],
            'category' => $prepared['category'],
            'status' => $prepared['status'],
            'published' => $prepared['published'],
            'published_at' => $prepared['published_at'],
            'language' => $prepared['language'],
            'cover_image_url' => '',
        ];
        $result = api_blog_result($preview, 'dry_run', $prepared['download']);
        $result['would_create'] = !$existing;
        $result['would_update'] = (bool)$existing && $prepared['update_existing'];
        if (!$existing || $prepared['tags_provided']) {
            $result['tags'] = array_column($prepared['tags'], 'tag');
        }
        if (!$existing || $prepared['sources_provided']) {
            $result['sources'] = $prepared['sources'];
        }
        return $result;
    }

    if ($existing && !$prepared['update_existing']) {
        return api_blog_result($existing, 'reused');
    }

    $imagePath = (string)($existing['cover_image_url'] ?? '');
    $imageAlt = (string)($existing['cover_image_alt'] ?? '');
    $imageCredit = (string)($existing['cover_image_credit'] ?? '');
    $imageSourceUrl = (string)($existing['cover_image_source_url'] ?? '');

    if ($prepared['image']['provided']) {
        if ($prepared['image']['remove']) {
            $imagePath = '';
            $imageAlt = '';
            $imageCredit = '';
            $imageSourceUrl = '';
        } elseif ($prepared['download'] !== null) {
            $imagePath = (string)$prepared['download']['path'];
            $imageAlt = (string)$prepared['image']['alt'];
            $imageCredit = (string)$prepared['image']['credit'];
            $imageSourceUrl = (string)$prepared['image']['source_url'];
        }
    }

    if ($existing) {
        $oldImage = (string)($existing['cover_image_url'] ?? '');
        $stmt = db()->prepare(
            'UPDATE blog_posts SET
                author_user_id=?,external_id=?,source_url=?,title=?,slug=?,excerpt=?,category=?,content=?,
                cover_image_url=?,cover_image_alt=?,cover_image_credit=?,cover_image_source_url=?,
                published=?,status=?,published_at=?,language=?,meta_title=?,meta_description=?,canonical_url=?,updated_at=?
             WHERE id=?'
        );
        $stmt->execute([
            $prepared['author_id'],
            $prepared['external_id'] !== '' ? $prepared['external_id'] : null,
            $prepared['source_url'] !== '' ? $prepared['source_url'] : null,
            $prepared['title'],
            $prepared['slug'],
            $prepared['excerpt'] !== '' ? $prepared['excerpt'] : null,
            $prepared['category'] !== '' ? $prepared['category'] : null,
            $prepared['content'],
            $imagePath !== '' ? $imagePath : null,
            $imageAlt !== '' ? $imageAlt : null,
            $imageCredit !== '' ? $imageCredit : null,
            $imageSourceUrl !== '' ? $imageSourceUrl : null,
            $prepared['published'],
            $prepared['status'],
            $prepared['published_at'],
            $prepared['language'],
            $prepared['meta_title'] !== '' ? $prepared['meta_title'] : null,
            $prepared['meta_description'] !== '' ? $prepared['meta_description'] : null,
            $prepared['canonical_url'] !== '' ? $prepared['canonical_url'] : null,
            $prepared['updated_at'],
            (int)$existing['id'],
        ]);
        $postId = (int)$existing['id'];
        $mode = 'updated';

        if ($oldImage !== '' && $oldImage !== $imagePath) {
            $oldImagesToDelete[] = $oldImage;
        }
    } else {
        $stmt = db()->prepare(
            'INSERT INTO blog_posts
                (author_user_id,external_id,source_url,title,slug,excerpt,category,content,
                 cover_image_url,cover_image_alt,cover_image_credit,cover_image_source_url,
                 published,status,published_at,language,meta_title,meta_description,canonical_url,created_at,updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $prepared['author_id'],
            $prepared['external_id'] !== '' ? $prepared['external_id'] : null,
            $prepared['source_url'] !== '' ? $prepared['source_url'] : null,
            $prepared['title'],
            $prepared['slug'],
            $prepared['excerpt'] !== '' ? $prepared['excerpt'] : null,
            $prepared['category'] !== '' ? $prepared['category'] : null,
            $prepared['content'],
            $imagePath !== '' ? $imagePath : null,
            $imageAlt !== '' ? $imageAlt : null,
            $imageCredit !== '' ? $imageCredit : null,
            $imageSourceUrl !== '' ? $imageSourceUrl : null,
            $prepared['published'],
            $prepared['status'],
            $prepared['published_at'],
            $prepared['language'],
            $prepared['meta_title'] !== '' ? $prepared['meta_title'] : null,
            $prepared['meta_description'] !== '' ? $prepared['meta_description'] : null,
            $prepared['canonical_url'] !== '' ? $prepared['canonical_url'] : null,
            $prepared['created_at'],
            $prepared['updated_at'],
        ]);
        $postId = (int)db()->lastInsertId();
        $mode = 'created';
    }

    if (!$existing || $prepared['tags_provided']) {
        api_blog_replace_tags($postId, $prepared['tags']);
    }
    if (!$existing || $prepared['sources_provided']) {
        api_blog_replace_sources($postId, $prepared['sources']);
    }

    api_touch_user_activity((int)$prepared['author_id'],
        $mode === 'created' ? (string)$prepared['created_at'] : (string)$prepared['updated_at']);

    audit_log(
        $prepared['actor_id'],
        $mode === 'created' ? 'api.blog.created' : 'api.blog.updated',
        'blog_post',
        $postId,
        $prepared['title']
    );

    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE id=? LIMIT 1');
    $stmt->execute([$postId]);
    $row = $stmt->fetch();
    return api_blog_result($row, $mode, $prepared['download']);
}

function api_blog_batch(array $items, array $actor): array {
    if ($items === [] || count($items) > 10) {
        throw new InvalidArgumentException('Envie de 1 a 10 posts.');
    }
    $prepared = [];
    $newImages = [];

    try {
        foreach ($items as $item) {
            $entry = api_blog_prepare($item, $actor);
            $prepared[] = $entry;
            if (!$entry['dry_run'] && !empty($entry['download']['path'])) {
                $newImages[] = (string)$entry['download']['path'];
            }
        }
    } catch (Throwable $e) {
        foreach ($newImages as $image) {
            blog_delete_uploaded_image($image);
        }
        throw $e;
    }

    $writeCount = count(array_filter(
        $prepared,
        static fn(array $entry): bool => !$entry['dry_run']
            && (!$entry['existing'] || $entry['update_existing'])
    ));

    $results = [];
    $oldImagesToDelete = [];

    if ($writeCount > 0) {
        db()->beginTransaction();
    }

    try {
        foreach ($prepared as $entry) {
            $results[] = api_blog_apply($entry, $oldImagesToDelete);
        }
        if (db()->inTransaction()) {
            db()->commit();
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        foreach ($newImages as $image) {
            blog_delete_uploaded_image($image);
        }
        throw $e;
    }

    foreach (array_unique($oldImagesToDelete) as $image) {
        blog_delete_uploaded_image($image);
    }

    return [
        'received' => count($items),
        'written' => $writeCount,
        'posts' => $results,
    ];
}
