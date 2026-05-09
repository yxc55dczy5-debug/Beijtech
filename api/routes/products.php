<?php
declare(strict_types=1);

function handle_products(string $method, string $sub): void
{
    // GET / — public, active products
    if ($method === 'GET' && $sub === '') {
        json_ok(db_all('SELECT * FROM products WHERE active = 1 ORDER BY sort_order ASC, id ASC'));
    }

    // GET /all — auth, all products including inactive
    if ($method === 'GET' && $sub === '/all') {
        require_auth();
        json_ok(db_all('SELECT * FROM products ORDER BY sort_order ASC, id ASC'));
    }

    // POST / — auth, create product
    if ($method === 'POST' && $sub === '') {
        require_auth();
        $b = get_body();

        if (!($b['name'] ?? '') || !($b['category'] ?? '')) {
            json_error('Naam en categorie zijn verplicht.', 400);
        }

        $id = db_insert(
            'INSERT INTO products (name, category, description, popular, active, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $b['name'],
                $b['category'],
                $b['description'] ?? null,
                isset($b['popular']) ? (int)(bool)$b['popular'] : 0,
                isset($b['active'])  ? (int)(bool)$b['active']  : 1,
                (int)($b['sort_order'] ?? 0),
            ]
        );
        json_ok(db_get('SELECT * FROM products WHERE id = ?', [$id]), 201);
    }

    // PUT /:id — auth, update product
    if ($method === 'PUT' && preg_match('#^/(\d+)$#', $sub, $m)) {
        require_auth();
        $id  = (int)$m[1];
        $row = db_get('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$row) json_error('Product niet gevonden.', 404);

        $b = get_body();
        db_run(
            'UPDATE products SET name=?, category=?, description=?, popular=?, active=?, sort_order=?, updated_at=NOW() WHERE id=?',
            [
                $b['name']        ?? $row['name'],
                $b['category']    ?? $row['category'],
                array_key_exists('description', $b) ? $b['description'] : $row['description'],
                isset($b['popular']) ? (int)(bool)$b['popular'] : (int)$row['popular'],
                isset($b['active'])  ? (int)(bool)$b['active']  : (int)$row['active'],
                isset($b['sort_order']) ? (int)$b['sort_order'] : (int)$row['sort_order'],
                $id,
            ]
        );
        json_ok(db_get('SELECT * FROM products WHERE id = ?', [$id]));
    }

    // DELETE /:id — auth, delete product + image file
    if ($method === 'DELETE' && preg_match('#^/(\d+)$#', $sub, $m)) {
        require_auth();
        $id  = (int)$m[1];
        $row = db_get('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$row) json_error('Product niet gevonden.', 404);

        if ($row['image_path']) {
            $file = BASE_DIR . '/' . ltrim($row['image_path'], '/');
            if (file_exists($file)) unlink($file);
        }

        db_run('DELETE FROM products WHERE id = ?', [$id]);
        json_ok(['message' => 'Product verwijderd.']);
    }

    // POST /:id/image — auth, upload product image
    if ($method === 'POST' && preg_match('#^/(\d+)/image$#', $sub, $m)) {
        require_auth();
        $id  = (int)$m[1];
        $row = db_get('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$row) json_error('Product niet gevonden.', 404);

        if (empty($_FILES['image'])) json_error('Geen afbeelding ontvangen.', 400);
        $f = $_FILES['image'];

        if ($f['error'] !== UPLOAD_ERR_OK) {
            json_error('Upload mislukt (code ' . $f['error'] . ').', 400);
        }

        // Use finfo for real MIME detection, not the client-supplied type
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);

        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extMap[$mimeType])) {
            json_error('Alleen jpg, png en webp afbeeldingen zijn toegestaan.', 400);
        }

        $filename  = 'product-' . time() . '-' . mt_rand(100000, 999999) . '.' . $extMap[$mimeType];
        $uploadDir = BASE_DIR . '/uploads/products/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if ($row['image_path']) {
            $old = BASE_DIR . '/' . ltrim($row['image_path'], '/');
            if (file_exists($old)) unlink($old);
        }

        if (!move_uploaded_file($f['tmp_name'], $uploadDir . $filename)) {
            json_error('Afbeelding opslaan mislukt.', 500);
        }

        $imageUrl = '/uploads/products/' . $filename;
        db_run('UPDATE products SET image_path=?, updated_at=NOW() WHERE id=?', [$imageUrl, $id]);
        json_ok(['image_url' => $imageUrl]);
    }

    // DELETE /:id/image — auth, remove product image
    if ($method === 'DELETE' && preg_match('#^/(\d+)/image$#', $sub, $m)) {
        require_auth();
        $id  = (int)$m[1];
        $row = db_get('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$row) json_error('Product niet gevonden.', 404);

        if ($row['image_path']) {
            $file = BASE_DIR . '/' . ltrim($row['image_path'], '/');
            if (file_exists($file)) unlink($file);
        }

        db_run('UPDATE products SET image_path=NULL, updated_at=NOW() WHERE id=?', [$id]);
        json_ok(['message' => 'Afbeelding verwijderd.']);
    }

    json_error('Route niet gevonden.', 404);
}
