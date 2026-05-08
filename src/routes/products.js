const express = require('express');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const { run, get, all } = require('../db');
const requireAuth = require('../middleware/auth');

const router = express.Router();

const uploadDir = path.join(__dirname, '..', '..', 'uploads', 'products');
fs.mkdirSync(uploadDir, { recursive: true });

const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, uploadDir),
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname).toLowerCase();
    cb(null, `product-${Date.now()}-${Math.round(Math.random() * 1e6)}${ext}`);
  }
});

const ALLOWED_MIMETYPES = ['image/jpeg', 'image/png', 'image/webp'];

const upload = multer({
  storage,
  fileFilter: (req, file, cb) => {
    if (ALLOWED_MIMETYPES.includes(file.mimetype)) cb(null, true);
    else cb(new Error('Alleen jpg, png en webp afbeeldingen zijn toegestaan.'), false);
  }
});

// GET /api/products — public, active only
router.get('/', async (req, res) => {
  try {
    const products = await all(
      `SELECT * FROM products WHERE active = 1 ORDER BY sort_order ASC, id ASC`
    );
    return res.json(products);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// GET /api/products/all — auth, all products
router.get('/all', requireAuth, async (req, res) => {
  try {
    const products = await all(
      `SELECT * FROM products ORDER BY sort_order ASC, id ASC`
    );
    return res.json(products);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// POST /api/products — auth, create
router.post('/', requireAuth, async (req, res) => {
  try {
    const { name, category, description, popular, active, sort_order } = req.body;

    if (!name || !category) {
      return res.status(400).json({ error: 'Naam en categorie zijn verplicht.' });
    }

    const result = await run(
      `INSERT INTO products (name, category, description, popular, active, sort_order) VALUES (?, ?, ?, ?, ?, ?)`,
      [
        name,
        category,
        description || null,
        popular !== undefined ? (popular ? 1 : 0) : 0,
        active !== undefined ? (active ? 1 : 0) : 1,
        sort_order || 0
      ]
    );

    const product = await get('SELECT * FROM products WHERE id = ?', [result.lastID]);
    return res.status(201).json(product);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// PUT /api/products/:id — auth, update
router.put('/:id', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;
    const existing = await get('SELECT * FROM products WHERE id = ?', [id]);
    if (!existing) return res.status(404).json({ error: 'Product niet gevonden.' });

    const { name, category, description, popular, active, sort_order } = req.body;

    await run(
      `UPDATE products SET name = ?, category = ?, description = ?, popular = ?, active = ?, sort_order = ?, updated_at = datetime('now') WHERE id = ?`,
      [
        name !== undefined ? name : existing.name,
        category !== undefined ? category : existing.category,
        description !== undefined ? description : existing.description,
        popular !== undefined ? (popular ? 1 : 0) : existing.popular,
        active !== undefined ? (active ? 1 : 0) : existing.active,
        sort_order !== undefined ? sort_order : existing.sort_order,
        id
      ]
    );

    const updated = await get('SELECT * FROM products WHERE id = ?', [id]);
    return res.json(updated);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// DELETE /api/products/:id — auth, delete with image cleanup
router.delete('/:id', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;
    const product = await get('SELECT * FROM products WHERE id = ?', [id]);
    if (!product) return res.status(404).json({ error: 'Product niet gevonden.' });

    if (product.image_path) {
      const filePath = path.join(__dirname, '..', '..', product.image_path.replace(/^\//, ''));
      if (fs.existsSync(filePath)) fs.unlinkSync(filePath);
    }

    await run('DELETE FROM products WHERE id = ?', [id]);
    return res.json({ message: 'Product verwijderd.' });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// POST /api/products/:id/image — auth, upload image
router.post('/:id/image', requireAuth, async (req, res) => {
  const { id } = req.params;

  try {
    const product = await get('SELECT * FROM products WHERE id = ?', [id]);
    if (!product) return res.status(404).json({ error: 'Product niet gevonden.' });

    upload.single('image')(req, res, async (err) => {
      if (err) return res.status(400).json({ error: err.message });
      if (!req.file) return res.status(400).json({ error: 'Geen afbeelding ontvangen.' });

      try {
        if (product.image_path) {
          const oldPath = path.join(__dirname, '..', '..', product.image_path.replace(/^\//, ''));
          if (fs.existsSync(oldPath)) fs.unlinkSync(oldPath);
        }

        const imageUrl = `/uploads/products/${req.file.filename}`;
        await run(
          `UPDATE products SET image_path = ?, updated_at = datetime('now') WHERE id = ?`,
          [imageUrl, id]
        );

        return res.json({ image_url: imageUrl });
      } catch (e2) {
        return res.status(500).json({ error: e2.message });
      }
    });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// DELETE /api/products/:id/image — auth, remove image
router.delete('/:id/image', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;
    const product = await get('SELECT * FROM products WHERE id = ?', [id]);
    if (!product) return res.status(404).json({ error: 'Product niet gevonden.' });

    if (product.image_path) {
      const filePath = path.join(__dirname, '..', '..', product.image_path.replace(/^\//, ''));
      if (fs.existsSync(filePath)) fs.unlinkSync(filePath);
    }

    await run(
      `UPDATE products SET image_path = NULL, updated_at = datetime('now') WHERE id = ?`,
      [id]
    );

    return res.json({ message: 'Afbeelding verwijderd.' });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

module.exports = router;
