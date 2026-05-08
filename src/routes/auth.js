const express = require('express');
const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const { run, get, all } = require('../db');
const requireAuth = require('../middleware/auth');

const router = express.Router();

// POST /api/auth/login
router.post('/login', async (req, res) => {
  try {
    const { username, email, password } = req.body;

    if (!password || (!username && !email)) {
      return res.status(400).json({ error: 'Gebruikersnaam/e-mail en wachtwoord zijn verplicht.' });
    }

    const identifier = username || email;
    const user = await get(
      `SELECT * FROM users WHERE username = ? OR email = ?`,
      [identifier, identifier]
    );

    if (!user) {
      return res.status(401).json({ error: 'Ongeldige inloggegevens.' });
    }

    const valid = bcrypt.compareSync(password, user.password_hash);
    if (!valid) {
      return res.status(401).json({ error: 'Ongeldige inloggegevens.' });
    }

    const token = crypto.randomBytes(32).toString('hex');
    const expiresAt = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString();

    await run(
      `INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)`,
      [token, user.id, expiresAt]
    );

    await run(`UPDATE users SET last_login = datetime('now') WHERE id = ?`, [user.id]);

    return res.json({
      token,
      user: { id: user.id, username: user.username, email: user.email, role: user.role }
    });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// POST /api/auth/logout
router.post('/logout', requireAuth, async (req, res) => {
  try {
    await run('DELETE FROM sessions WHERE token = ?', [req.token]);
    return res.json({ message: 'Uitgelogd.' });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// GET /api/auth/me
router.get('/me', requireAuth, async (req, res) => {
  try {
    const user = await get(
      `SELECT id, username, email, role, last_login FROM users WHERE id = ?`,
      [req.user.id]
    );
    if (!user) return res.status(404).json({ error: 'Gebruiker niet gevonden.' });
    return res.json(user);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// GET /api/auth/users
router.get('/users', requireAuth, async (req, res) => {
  try {
    const users = await all(
      `SELECT id, username, email, role, created_at, last_login FROM users ORDER BY id`
    );
    return res.json(users);
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

// POST /api/auth/users
router.post('/users', requireAuth, async (req, res) => {
  try {
    const { username, email, password, role } = req.body;

    if (!username || !email || !password) {
      return res.status(400).json({ error: 'Gebruikersnaam, e-mail en wachtwoord zijn verplicht.' });
    }

    const passwordHash = bcrypt.hashSync(password, 10);
    const validRole = role || 'admin';

    const result = await run(
      `INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)`,
      [username, email, passwordHash, validRole]
    );

    const newUser = await get(
      `SELECT id, username, email, role, created_at FROM users WHERE id = ?`,
      [result.lastID]
    );

    return res.status(201).json(newUser);
  } catch (e) {
    if (e.message && e.message.includes('UNIQUE')) {
      return res.status(409).json({ error: 'Gebruikersnaam of e-mail is al in gebruik.' });
    }
    return res.status(500).json({ error: e.message });
  }
});

// PUT /api/auth/users/:id
router.put('/users/:id', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;
    const existing = await get('SELECT * FROM users WHERE id = ?', [id]);
    if (!existing) return res.status(404).json({ error: 'Gebruiker niet gevonden.' });

    const { username, email, role, password } = req.body;

    if (password) {
      const passwordHash = bcrypt.hashSync(password, 10);
      await run(
        `UPDATE users SET username = ?, email = ?, role = ?, password_hash = ? WHERE id = ?`,
        [username || existing.username, email || existing.email, role || existing.role, passwordHash, id]
      );
    } else {
      await run(
        `UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?`,
        [username || existing.username, email || existing.email, role || existing.role, id]
      );
    }

    const updated = await get(
      `SELECT id, username, email, role, created_at, last_login FROM users WHERE id = ?`,
      [id]
    );
    return res.json(updated);
  } catch (e) {
    if (e.message && e.message.includes('UNIQUE')) {
      return res.status(409).json({ error: 'Gebruikersnaam of e-mail is al in gebruik.' });
    }
    return res.status(500).json({ error: e.message });
  }
});

// DELETE /api/auth/users/:id
router.delete('/users/:id', requireAuth, async (req, res) => {
  try {
    const { id } = req.params;

    if (parseInt(id, 10) === req.user.id) {
      return res.status(400).json({ error: 'Je kunt je eigen account niet verwijderen.' });
    }

    const existing = await get('SELECT id FROM users WHERE id = ?', [id]);
    if (!existing) return res.status(404).json({ error: 'Gebruiker niet gevonden.' });

    await run('DELETE FROM users WHERE id = ?', [id]);
    return res.json({ message: 'Gebruiker verwijderd.' });
  } catch (e) {
    return res.status(500).json({ error: e.message });
  }
});

module.exports = router;
