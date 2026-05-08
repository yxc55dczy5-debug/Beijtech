const express = require('express');
const { run, get, all, transaction } = require('../db');
const requireAuth = require('../middleware/auth');

const router = express.Router();

const VALID_STATUSES = ['nieuw', 'in_behandeling', 'offerte_verstuurd', 'geboekt', 'afgewezen'];

// POST /api/quotes — public, submit a quote request
router.post('/', async (req, res) => {
  try {
    const {
      naam, email, telefoon, bedrijf, locatie,
      date_from, date_to, levering_heen, levering_retour,
      opmerkingen, items
    } = req.body;

    if (!naam || !email) {
      return res.status(400).json({ error: 'Naam en e-mail zijn verplicht.' });
    }

    const quoteId = await transaction(async ({ run: txRun }) => {
      const result = await txRun(
        `INSERT INTO quote_requests
          (naam, email, telefoon, bedrijf, locatie, date_from, date_to, levering_heen, levering_retour, opmerkingen)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          naam, email,
          telefoon || null, bedrijf || null, locatie || null,
          date_from || null, date_to || null,
          levering_heen ? 1 : 0,
          levering_retour ? 1 : 0,
          opmerkingen || null
        ]
      );

      const id = result.lastID;

      if (Array.isArray(items) && items.length > 0) {
        for (const item of items) {
          await txRun(
            `INSERT INTO quote_items (quote_id, product_id, product_name, quantity) VALUES (?, ?, ?, ?)`,
            [id, item.product_id || null, item.product_name || 'Onbekend product', item.quantity || 1]
          );
        }
      }

      return id;
    });

    return res.status(201).json({ id: quoteId, message: 'Aanvraag ontvangen' });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// GET /api/quotes — auth, all quotes with item count
router.get('/', requireAuth, async (req, res) => {
  try {
    const quotes = await all(`
      SELECT qr.*, COUNT(qi.id) as item_count
      FROM quote_requests qr
      LEFT JOIN quote_items qi ON qi.quote_id = qr.id
      GROUP BY qr.id
      ORDER BY qr.created_at DESC
    `);
    return res.json(quotes);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// GET /api/quotes/:id — auth, full quote with items
router.get('/:id', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;

    const quote = await get('SELECT * FROM quote_requests WHERE id = ?', [id]);
    if (!quote) return res.status(404).json({ error: 'Aanvraag niet gevonden.' });

    quote.items = await all('SELECT * FROM quote_items WHERE quote_id = ?', [id]);
    return res.json(quote);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// PUT /api/quotes/:id/status — auth, update status and/or admin_notes
router.put('/:id/status', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;
    const { status, admin_notes } = req.body;

    if (!status) return res.status(400).json({ error: 'Status is verplicht.' });

    if (!VALID_STATUSES.includes(status)) {
      return res.status(400).json({
        error: `Ongeldige status. Geldige waarden: ${VALID_STATUSES.join(', ')}.`
      });
    }

    const quote = await get('SELECT id FROM quote_requests WHERE id = ?', [id]);
    if (!quote) return res.status(404).json({ error: 'Aanvraag niet gevonden.' });

    await run(
      `UPDATE quote_requests SET status = ?, admin_notes = ? WHERE id = ?`,
      [status, admin_notes !== undefined ? admin_notes : null, id]
    );

    const updated = await get('SELECT * FROM quote_requests WHERE id = ?', [id]);
    updated.items = await all('SELECT * FROM quote_items WHERE quote_id = ?', [id]);
    return res.json(updated);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// DELETE /api/quotes/:id — auth, delete (cascades to items)
router.delete('/:id', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;

    const quote = await get('SELECT id FROM quote_requests WHERE id = ?', [id]);
    if (!quote) return res.status(404).json({ error: 'Aanvraag niet gevonden.' });

    await run('DELETE FROM quote_requests WHERE id = ?', [id]);
    return res.json({ message: 'Aanvraag verwijderd.' });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

module.exports = router;
